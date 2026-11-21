<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Models\YedekKopyasi;
use App\Services\Yedekleme\DepoIsaretiYok;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSilmeKaydi;
use App\Services\Yedekleme\KullanimDenetimi;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekDeposu;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YerelDosyaSilici;
use App\Yetki\Izin;
use Throwable;

/**
 * Elle silme (`sil`; README §4.3, F7): yerel dosya ve her hedefteki kopya.
 * Sabitlenmiş ya da kullanımdaki (kopyalanan, doğrulanan, geri yüklenen)
 * yedek silinmez. Bir hedef silinemezse diğerleri sürer; yedek ancak
 * hiçbir yerde kopyası kalmayınca `silindi` olur (YerelDosyaSilici), kayıt
 * ve imzalı manifest kalır. Kopya silme sonucunu saklamayla aynı adım yazar
 * (KopyaSilmeKaydi; BY-025-R3). Yeri doğrulanamayan (`konum_belirsiz`)
 * kopya silinmez, sonuçta "silinemedi" olarak listelenir (BY-028-R5); yedek
 * de `silindi` olmaz, "tamamen silindi" olayı yazılmaz — yalnız yerel dosya
 * gider, yedek listede kopyanın uyarısıyla kalır (BY-028-R7).
 */
final class SilIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly KullanimDenetimi $kullanim,
        private readonly YerelDosyaSilici $yerelSilici,
        private readonly YedekDeposu $depo,
        private readonly KopyaSilmeKaydi $silmeKaydi,
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

        // Bağlanmamış diskte dosya "yok" görünür; yedek katalogdan düşmesin (BY-011-R3, G1)
        try {
            $this->depo->hazirla();
        } catch (DepoIsaretiYok $hata) {
            throw new IstekReddi('YEDEK_SILME_DEPO_DOGRULANAMADI', $hata->getMessage());
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
                $this->silmeKaydi->kaydet($kopya);
            } catch (HedefHatasi $hata) {
                // Değiştirilemez saklama: hedefin kendi kuralı temizler (README §4.6)
                if ($hata->kod === HedefHatasi::SILME_YETKISI_YOK) {
                    $this->silmeKaydi->kaydet($kopya, $hata->kod);

                    continue;
                }
                $hatalar[] = $kopya->hedef->ad.': '.$hata->kod;
            } catch (Throwable $hata) {
                report($hata);
                $hatalar[] = $kopya->hedef->ad.': '.$hata::class;
            }
        }

        // Yeri doğrulanamayan kopya silinmez — hedefin bugünkü yerindeki başka bir dosyaya
        // dokunulabilirdi — ve silinmiş de sayılmaz: sonuçta görünür (BY-028-R5). Hedefi
        // tanımsız kopya da böyledir; adı olaydan, kodu kendi kaydından (BY-028-R9)
        YedekKopyasi::query()->with('hedef')->where('yedek_id', $yedek->id)->where('durum', KopyaDurumu::KonumBelirsiz->value)->orderBy('id')->get()
            ->each(function (YedekKopyasi $kopya) use (&$hatalar): void {
                $hatalar[] = ($kopya->hedefAdi() ?? '?').': '.($kopya->hata_kodu ?? HedefKonumu::DOGRULANAMADI);
            });

        // F7: başka bir yerde doğrulanmış kopya kaldıysa durum `silindi` olmaz
        $this->yerelSilici->sil($yedek->refresh());
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
}
