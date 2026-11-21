<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\GeriYukleme;
use Carbon\CarbonImmutable;
use LogicException;
use Throwable;

/**
 * Farklı ada geri yüklenen kopyaların (`erp_geri_*`) silinmesi (README
 * §4.5-A; BY-025, BY-026): elle (`kopya_sil` isteği), bekleme süresi
 * (`kopya_bekleme_gun`) dolunca ve yarıda kalmış geri yüklemenin artığı.
 *
 * Yalnız kopya kalıbındaki ve `geri_yuklemeler`'de bu sistemin açtığı
 * kaydı olan veritabanı silinir; başka hiçbir ad (`erp` dahil) silinemez.
 * `veritabani_silinme_zamani` veritabanının SİLİNDİĞİ andır; otomatik
 * silinme zamanı `bitti + kopya_bekleme_gun`'dür (silinecegiZaman).
 *
 * Kilit çağıranda: kilit tutulurken başka geri yükleme yürüyemez; bu yüzden
 * `calisiyor` görünen kayıt ölmüş bir süreçten kalmıştır.
 */
final class KopyaVeritabanlari
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly GeriYuklemeVeritabani $vt,
        private readonly YedekDenetimi $denetim,
    ) {}

    /**
     * Elle silme.
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi Ad kalıp dışı, böyle bir bekleyen kopya yok ya da silinemedi
     */
    public function sil(YedekKilidi $kilit, string $ad, ?int $kullaniciId): GeriYukleme
    {
        $this->kilitDenetle($kilit);

        if (! VeritabaniAdi::kopyaMi($ad)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_ADI_GECERSIZ, 'Yalnız farklı ada geri yüklenmiş kopyalar (erp_geri_*) silinebilir.');
        }

        $geri = GeriYukleme::query()
            ->where('tur', GeriYuklemeTuru::Kopya->value)
            ->where('hedef_veritabani', $ad)
            ->where('durum', GeriYuklemeDurumu::Tamam->value)
            ->whereNull('veritabani_silinme_zamani')
            ->orderByDesc('id')
            ->first();

        if ($geri === null) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KOPYA_YOK, 'Bu adla bekleyen bir geri yükleme kopyası yok.');
        }

        $this->dusur($geri, $kullaniciId, 'elle');

        return $geri->refresh();
    }

    /** Kopyanın otomatik silineceği an (README §4.5-A: öneri 7 gün). */
    public function silinecegiZaman(GeriYukleme $geri): ?CarbonImmutable
    {
        return $geri->durum === GeriYuklemeDurumu::Tamam && $geri->veritabani_silinme_zamani === null
            ? $geri->bitti?->addDays($this->yapilandirma->kopyaBeklemeGunu())
            : null;
    }

    /** Temizlenecek kayıt var mı (kilidi boşuna almamak için; kilit altında yeniden sorulur). */
    public function temizlenecekVarMi(): bool
    {
        return $this->adaylar() !== [];
    }

    /**
     * Süresi dolan kopyaları ve yarıda kalmış geri yüklemelerin artıklarını
     * siler. Bir kaydın hatası diğerlerini durdurmaz.
     *
     * @return array{silinen: int, hatali: int}
     *
     * @throws LogicException Kilit tutulmuyor
     */
    public function temizle(YedekKilidi $kilit): array
    {
        $this->kilitDenetle($kilit);
        $sonuc = ['silinen' => 0, 'hatali' => 0];

        foreach ($this->adaylar() as $geri) {
            try {
                if ($geri->durum === GeriYuklemeDurumu::Calisiyor) {
                    $this->yaridaKalaniKapat($geri, $kilit);
                }

                if ($geri->durum === GeriYuklemeDurumu::Basarisiz && ! $geri->veritabaniKalmisOlabilirMi()) {
                    continue;
                }

                $this->dusur($geri, null, $geri->durum === GeriYuklemeDurumu::Tamam ? 'sure_doldu' : 'yarim');
                $sonuc['silinen']++;
            } catch (Throwable $hata) {
                $sonuc['hatali']++;
                report($hata);
            }
        }

        return $sonuc;
    }

    /**
     * @return list<GeriYukleme>
     */
    private function adaylar(): array
    {
        $simdi = CarbonImmutable::now();

        return GeriYukleme::query()
            ->where('tur', GeriYuklemeTuru::Kopya->value)
            ->whereNull('veritabani_silinme_zamani')
            ->whereIn('durum', [GeriYuklemeDurumu::Tamam->value, GeriYuklemeDurumu::Basarisiz->value, GeriYuklemeDurumu::Calisiyor->value])
            ->orderBy('id')
            ->get()
            ->filter(fn (GeriYukleme $g): bool => match ($g->durum) {
                GeriYuklemeDurumu::Calisiyor => true,
                GeriYuklemeDurumu::Basarisiz => $g->veritabaniKalmisOlabilirMi(),
                default => ($zaman = $this->silinecegiZaman($g)) !== null && $zaman->lessThanOrEqualTo($simdi),
            })
            ->values()
            ->all();
    }

    /**
     * @throws GeriYuklemeHatasi
     */
    private function dusur(GeriYukleme $geri, ?int $kullaniciId, string $neden): void
    {
        $ad = $geri->hedef_veritabani;

        if (! VeritabaniAdi::kopyaMi($ad)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_ADI_GECERSIZ, 'Kayıttaki ad bir geri yükleme kopyasının adı değil; silinmedi.');
        }

        try {
            $this->vt->parolaylaCalis(fn () => $this->vt->dusur($ad));
        } catch (Throwable $hata) {
            $mesaj = $hata instanceof GeriYuklemeHatasi ? $hata->getMessage() : $hata::class;
            $this->denetim->kaydet('kopya_sil', IslemSonucu::Basarisiz, $geri->yedek_id, $kullaniciId, null, ['hedef' => $ad, 'neden' => $neden], $mesaj, $geri->id);

            throw $hata instanceof GeriYuklemeHatasi ? $hata : new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_SILINEMEDI, 'Kopya veritabanı silinemedi.');
        }

        $geri->update(['veritabani_silinme_zamani' => CarbonImmutable::now()]);
        $this->denetim->kaydet('kopya_sil', IslemSonucu::Basarili, $geri->yedek_id, $kullaniciId, null, ['hedef' => $ad, 'neden' => $neden], geriYuklemeId: $geri->id);
    }

    /**
     * Ölmüş süreçten kalan `calisiyor` kaydı kapatılır; işlem durumu dosyası
     * hâlâ onu yürüyor gösteriyorsa o da kapatılır (yedek "kullanımda" kalmasın).
     */
    private function yaridaKalaniKapat(GeriYukleme $geri, YedekKilidi $kilit): void
    {
        $mesaj = 'Süreç işlem sürerken durdu.';
        $geri->update([
            'durum' => GeriYuklemeDurumu::Basarisiz,
            'hata_kodu' => GeriYuklemeHatasi::YARIDA_KALDI,
            'hata_mesaji' => $mesaj,
            'bitti' => CarbonImmutable::now(),
        ]);
        $this->denetim->kaydet('geri_yukle_kopya', IslemSonucu::Basarisiz, $geri->yedek_id, null, null, ['hedef' => $geri->hedef_veritabani, 'kod' => GeriYuklemeHatasi::YARIDA_KALDI], $mesaj, $geri->id);

        $durum = $this->yapilandirma->islemDurumu();
        $kayit = $durum->oku();
        if ($kayit !== null && $kayit->kimlik === $geri->kimlik && ! in_array($kayit->adim, [GeriYuklemeAdimi::Bitti->value, GeriYuklemeAdimi::Basarisiz->value], true)) {
            $durum->yaz(new IslemKaydi(
                kimlik: $kayit->kimlik,
                tur: $kayit->tur,
                adim: GeriYuklemeAdimi::Basarisiz->value,
                acik: false,
                baslangic: $kayit->baslangic,
                sonGuncelleme: CarbonImmutable::now('UTC'),
                hata: ['kod' => GeriYuklemeHatasi::YARIDA_KALDI, 'mesaj' => $mesaj],
                erisimAnahtariOzeti: null,
                yedekKimligi: $kayit->yedekKimligi,
                hedefVeritabani: $kayit->hedefVeritabani,
                isteyenKullaniciId: $kayit->isteyenKullaniciId,
            ), $kilit);
        }
    }

    private function kilitDenetle(YedekKilidi $kilit): void
    {
        if (! $kilit->dosyayiTutuyorMu($this->yapilandirma->durumKlasoru().'/kilit')) {
            throw new LogicException('Kopya veritabanları yalnız yedekleme kilidini tutan süreç tarafından silinir.');
        }
    }
}
