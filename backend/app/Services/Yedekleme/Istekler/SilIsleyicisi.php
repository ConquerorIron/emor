<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KullanimDenetimi;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\OlayTuru;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedekOlayi;
use App\Services\Yedekleme\YerelDosyaSilici;
use App\Yetki\Izin;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Elle silme (`sil`; README §4.3, F7): yerel dosya ve her hedefteki kopya.
 * Sabitlenmiş ya da kullanımdaki (kopyalanan, doğrulanan, geri yüklenen)
 * yedek silinmez. Bir hedef silinemezse diğerleri sürer; yedek ancak
 * hiçbir yerde kopyası kalmayınca `silindi` olur (YerelDosyaSilici), kayıt
 * ve imzalı manifest kalır.
 */
final class SilIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly KullanimDenetimi $kullanim,
        private readonly YerelDosyaSilici $yerelSilici,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
    ) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerSil;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $yedek = $this->yedegiBul($istek);

        if ($yedek->sabit) {
            throw new IstekReddi('YEDEK_SABIT', 'Sabitlenmiş yedek silinmez; önce sabitlemeyi kaldırın.');
        }

        if ($this->kullanim->kullanimdaMi($yedek)) {
            throw new IstekReddi('YEDEK_KULLANIMDA', 'Yedek şu anda kullanımda (kopyalanıyor ya da geri yükleniyor).');
        }

        $hatalar = [];
        $kopyalar = YedekKopyasi::query()->with('hedef')->where('yedek_id', $yedek->id)->where('durum', KopyaDurumu::Tamam->value)->orderBy('id')->get();

        foreach ($kopyalar as $kopya) {
            try {
                $baglayici = $this->katalog->olustur($kopya->hedef);
                // Yedek klasörünün kendisi olan hedefin kopyası yerel dosyanın kendisidir
                $ayniDosya = $baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu();
                if (! $ayniDosya && $kopya->uzak_kimlik !== null) {
                    $baglayici->sil($kopya->uzak_kimlik);
                }
                $this->kopyaSilindi($kopya, null);
            } catch (HedefHatasi $hata) {
                // Değiştirilemez saklama: hedefin kendi kuralı temizler (README §4.6)
                if ($hata->kod === HedefHatasi::SILME_YETKISI_YOK) {
                    $this->kopyaSilindi($kopya, $hata->kod);

                    continue;
                }
                $hatalar[] = $kopya->hedef->ad.': '.$hata->kod;
            } catch (Throwable $hata) {
                report($hata);
                $hatalar[] = $kopya->hedef->ad.': '.$hata::class;
            }
        }

        // F7: başka bir yerde doğrulanmış kopya kaldıysa durum `silindi` olmaz
        $this->yerelSilici->sil($yedek->refresh(), uzakKopyaSart: false);
        $yedek->refresh();
        $ek = ['yedek_kimligi' => $yedek->kimlik, 'yedek_durumu' => $yedek->durum->value];

        if ($hatalar !== []) {
            $mesaj = 'Bazı kopyalar silinemedi: '.implode('; ', $hatalar);
            $this->denetim->kaydet('yedek_sil', IslemSonucu::Basarisiz, $yedek->id, $istek->kullaniciId, null, ['hedefler' => $hatalar], $mesaj);

            return IstekSonucu::basarisiz('YEDEK_KOPYA_SILINEMEDI', $mesaj, $ek);
        }

        $this->denetim->kaydet('yedek_sil', IslemSonucu::Basarili, $yedek->id, $istek->kullaniciId, null, ['kopya' => $kopyalar->count()]);

        return IstekSonucu::tamam($ek);
    }

    private function kopyaSilindi(YedekKopyasi $kopya, ?string $hataKodu): void
    {
        $kopya->update(['durum' => KopyaDurumu::Silindi, 'hata_kodu' => $hataKodu, 'silinme_zamani' => CarbonImmutable::now()]);
        $this->olaylar->ekle(YedekOlayi::simdi($kopya->yedek->kimlik, OlayTuru::KopyaSilindi, [
            'hedef_id' => $kopya->yedek_hedefi_id, 'hedef_ad' => $kopya->hedef->ad,
        ]));
    }
}
