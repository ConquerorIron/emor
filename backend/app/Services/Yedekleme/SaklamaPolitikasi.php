<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saklama ve temizlik (README §4.3, §6.2; BY-010). Önce hesaplanır
 * (`hesapla`, ekrandaki önizleme), sonra AYNI kararlar uygulanır (`uygula`).
 *
 * Gruplar: her hedef ayrı; ayrıca "yedek klasörü" (hiçbir hedefin kopyası
 * olmayan yerel dosyalar — zamanlanmamış yedekler). Yedek klasörünün kendisi
 * olan yerel hedefin kopyaları o hedefin grubundadır.
 *
 * 1. Plan kuralı (plan + hedef): son N ve / veya N gün. Kopya, kuralın
 *    herhangi bir ölçütünü sağlıyorsa tutulur; sağlamıyorsa planın BAĞI
 *    kalkar (F6: her plan yalnız kendi bağını kaldırır).
 * 2. Dosya adayı: plan yedeğinin kopyası bağsız kaldıysa; zamanlanmamış
 *    yedek kendi kuralını aşmışsa (kural `yedekler.kaynak`'tan seçilir, bağ
 *    sayısından değil — BY-004-R9): elle N gün; yayın öncesi son N ya da N gün
 *    (ikisini de aşan silinir); geri yükleme öncesi N gün ve ilgili
 *    `erp_onceki_*` silinmişse.
 * 3. Koruma (asla silinmez): son başarılı yedek; sabitlenmiş; sınama geri
 *    yüklemesi geçmiş en yeni; kullanımdaki (kopyalanan / işlem dosyasındaki).
 * 4. En az sayı: her grupta en az `enaz_adet` başarılı kopya kalır (en
 *    eskiler önce silinir).
 * 5. Temkin: en son yedek denemesi başarısızsa HİÇBİR ŞEY silinmez — yeni
 *    başarılı yedek gelmedikçe eldekiler tutulur. İşlem durumu dosyası
 *    okunamıyorsa da (kullanımdakiler bilinmiyor) silinmez.
 */
final class SaklamaPolitikasi
{
    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly KullanimDenetimi $kullanim,
        private readonly YerelDosyaSilici $yerelSilici,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly YedekBildirimcisi $bildirimci,
    ) {}

    /**
     * @return array{silinecek: list<SilmeKarari>, korunan: array<int, list<string>>, temkin: string|null}
     */
    public function hesapla(?CarbonImmutable $simdi = null): array
    {
        $simdi ??= CarbonImmutable::now();
        $ayar = YedekAyari::guncel();
        $korunan = [];

        $kullanimda = $this->kullanim->kullanimdakiler();
        if ($kullanimda === null) {
            return ['silinecek' => [], 'korunan' => [], 'temkin' => 'durum_bilinmiyor'];
        }

        $sonDeneme = Yedek::query()->whereIn('durum', [YedekDurumu::Tamam->value, YedekDurumu::Basarisiz->value])->orderByDesc('id')->first();
        if ($sonDeneme !== null && $sonDeneme->durum === YedekDurumu::Basarisiz) {
            return ['silinecek' => [], 'korunan' => [], 'temkin' => 'son_yedek_basarisiz'];
        }

        /** @var Collection<int, Yedek> $yedekler */
        $yedekler = Yedek::query()->where('durum', YedekDurumu::Tamam->value)->get()->keyBy('id');
        $kopyalar = YedekKopyasi::query()
            ->where('durum', KopyaDurumu::Tamam->value)
            ->whereIn('yedek_id', $yedekler->keys())
            ->with('planlar:id')
            ->get();

        // Koruma kuralları (genel)
        $sonBasarili = $yedekler->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)->first();
        $dogrulanmis = $yedekler->filter(fn (Yedek $y): bool => $y->dogrulama_durumu === DogrulamaDurumu::SinamaGecti)
            ->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)->first();
        foreach ($yedekler as $yedek) {
            $nedenler = array_keys(array_filter([
                'son_basarili' => $sonBasarili?->id === $yedek->id,
                'sabit' => $yedek->sabit,
                'dogrulanmis_en_yeni' => $dogrulanmis?->id === $yedek->id,
                'kullanimda' => isset($kullanimda[$yedek->id]),
            ]));
            if ($nedenler !== []) {
                $korunan[$yedek->id] = $nedenler;
            }
        }

        $anaHedefler = $this->anaKlasorHedefleri($kopyalar->pluck('yedek_hedefi_id')->unique()->all());
        $kararlar = [];

        // F7: uzak kopyası doğrulanmamış (başarısız / bekleyen) yedeğin yerel
        // dosyası silinmez — yeniden denemenin kaynağıdır
        $dogrulanmamisUzak = array_fill_keys(YedekKopyasi::query()
            ->whereIn('durum', [KopyaDurumu::Basarisiz->value, KopyaDurumu::Bekliyor->value])
            ->whereIn('yedek_id', $yedekler->keys())
            ->pluck('yedek_id')->map(fn ($id) => (int) $id)->all(), true);
        $yerelKorunan = $korunan;
        foreach (array_keys($dogrulanmamisUzak) as $id) {
            $yerelKorunan[$id][] = 'uzak_kopya_dogrulanmadi';
        }

        // 1. Plan kuralı → bağ kararları
        $kalanBag = $kopyalar->mapWithKeys(fn (YedekKopyasi $k) => [$k->id => $k->planlar->pluck('id')->all()])->all();
        $planlar = YedekPlani::query()->with('hedefler')->get();
        foreach ($planlar as $plan) {
            foreach ($plan->hedefler as $hedef) {
                $son = $hedef->pivot->sakla_son_adet;
                $gun = $hedef->pivot->sakla_gun;
                if ($son === null && $gun === null) {
                    continue;
                }

                $bagli = $kopyalar
                    ->filter(fn (YedekKopyasi $k): bool => $k->yedek_hedefi_id === $hedef->id && in_array($plan->id, $kalanBag[$k->id] ?? [], true))
                    ->sortByDesc(fn (YedekKopyasi $k) => $yedekler[$k->yedek_id]->alinma_zamani?->getTimestamp() ?? 0)
                    ->values();

                foreach ($bagli as $sira => $kopya) {
                    $alinma = $yedekler[$kopya->yedek_id]->alinma_zamani;
                    $sonIcinde = $son !== null && $sira < $son;
                    $gunIcinde = $gun !== null && $alinma !== null && $alinma->greaterThan($simdi->subDays($gun));
                    if ($sonIcinde || $gunIcinde) {
                        continue;
                    }

                    $kararlar[] = new SilmeKarari(SilmeKarari::BAG, $kopya->yedek_id, $hedef->id, $kopya->id, $plan->id, $son !== null && $sira >= $son ? SilmeKarari::NEDEN_SON_ADET : SilmeKarari::NEDEN_GUN);
                    $kalanBag[$kopya->id] = array_values(array_diff($kalanBag[$kopya->id], [$plan->id]));
                }
            }
        }

        // 2–4. Dosya kararları, grup başına
        $gruplar = $kopyalar->groupBy('yedek_hedefi_id')->map(fn (Collection $g) => $g->values());
        $anaKopyasiOlanlar = $kopyalar->filter(fn (YedekKopyasi $k): bool => isset($anaHedefler[$k->yedek_hedefi_id]))->pluck('yedek_id')->all();
        $klasorGrubu = $yedekler->filter(fn (Yedek $y): bool => $y->yerel_silinme_zamani === null && ! in_array($y->id, $anaKopyasiOlanlar, true))->values();
        $uzakKopyasiOlanlar = $kopyalar->filter(fn (YedekKopyasi $k): bool => ! isset($anaHedefler[$k->yedek_hedefi_id]))->pluck('yedek_id')->unique()->all();

        foreach ($gruplar as $hedefId => $grup) {
            $adaylar = [];
            foreach ($grup as $kopya) {
                $yedek = $yedekler[$kopya->yedek_id];
                $neden = $yedek->kaynak === YedekKaynagi::Plan
                    ? ($kalanBag[$kopya->id] === [] ? SilmeKarari::NEDEN_BAGSIZ : null)
                    : $this->zamanlanmamisNedeni($yedek, $grup->map(fn (YedekKopyasi $k) => $yedekler[$k->yedek_id]), $ayar, $simdi);
                if ($neden !== null) {
                    $adaylar[] = new SilmeKarari(SilmeKarari::DOSYA, $yedek->id, (int) $hedefId, $kopya->id, null, $neden);
                }
            }
            if (isset($anaHedefler[$hedefId])) {
                array_push($kararlar, ...$this->suz($adaylar, $grup->count(), $yedekler, $yerelKorunan, $ayar->enaz_adet));
                $korunan = $this->birlestir($korunan, $yerelKorunan);
            } else {
                array_push($kararlar, ...$this->suz($adaylar, $grup->count(), $yedekler, $korunan, $ayar->enaz_adet));
            }
        }

        $adaylar = [];
        foreach ($klasorGrubu as $yedek) {
            $neden = $yedek->kaynak === YedekKaynagi::Plan
                ? (in_array($yedek->id, $uzakKopyasiOlanlar, true) ? SilmeKarari::NEDEN_UZAK_KOPYA_VAR : null)
                : $this->zamanlanmamisNedeni($yedek, $klasorGrubu, $ayar, $simdi);
            if ($neden !== null) {
                $adaylar[] = new SilmeKarari(SilmeKarari::DOSYA, $yedek->id, null, null, null, $neden);
            }
        }
        array_push($kararlar, ...$this->suz($adaylar, $klasorGrubu->count(), $yedekler, $yerelKorunan, $ayar->enaz_adet));
        $korunan = $this->birlestir($korunan, $yerelKorunan);

        return ['silinecek' => $kararlar, 'korunan' => $korunan, 'temkin' => null];
    }

    /**
     * @param  array<int, list<string>>  $a
     * @param  array<int, list<string>>  $b
     * @return array<int, list<string>>
     */
    private function birlestir(array $a, array $b): array
    {
        foreach ($b as $id => $nedenler) {
            $a[$id] = array_values(array_unique([...($a[$id] ?? []), ...$nedenler]));
        }

        return $a;
    }

    /**
     * Hesaplanan kararları uygular. Erişilemeyen hedef atlanır, diğerleri
     * sürer. Her silme denetim kaydına ve olay günlüğüne nedeniyle yazılır.
     *
     * @return array{bag: int, dosya: int, atlanan_hedefler: list<int>, bilgi: list<string>, kararlar: list<SilmeKarari>}
     */
    public function uygula(?int $kullaniciId = null): array
    {
        $hesap = $this->hesapla();
        $sonuc = ['bag' => 0, 'dosya' => 0, 'atlanan_hedefler' => [], 'bilgi' => [], 'kararlar' => []];

        foreach ($hesap['silinecek'] as $karar) {
            if ($karar->tur !== SilmeKarari::BAG) {
                continue;
            }

            DB::table('yedek_kopya_planlari')->where('yedek_kopyasi_id', $karar->kopyaId)->where('yedek_plani_id', $karar->planId)->delete();
            $kopya = YedekKopyasi::query()->with(['yedek', 'hedef'])->find($karar->kopyaId);
            if ($kopya !== null) {
                $this->kopyaOlayi($kopya);
            }
            $sonuc['bag']++;
            $sonuc['kararlar'][] = $karar;
            $this->denetim->kaydet('saklama_bag', IslemSonucu::Basarili, $karar->yedekId, $kullaniciId, null, $karar->diziye());
        }

        $dosyaKararlari = array_values(array_filter($hesap['silinecek'], fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA));
        $hedefGruplari = [];
        foreach ($dosyaKararlari as $karar) {
            $hedefGruplari[$karar->hedefId ?? 0][] = $karar;
        }

        foreach ($hedefGruplari as $hedefId => $grup) {
            $hedef = $hedefId === 0 ? null : YedekHedefi::query()->find($hedefId);
            $baglayici = null;

            try {
                if ($hedef !== null) {
                    $baglayici = $this->katalog->olustur($hedef);
                }
            } catch (Throwable) {
                $sonuc['atlanan_hedefler'][] = (int) $hedefId;

                continue;
            }

            foreach ($grup as $karar) {
                $yedek = Yedek::query()->find($karar->yedekId);
                if ($yedek === null) {
                    continue;
                }

                try {
                    $this->dosyaSil($yedek, $karar, $baglayici);
                    $sonuc['dosya']++;
                    $sonuc['kararlar'][] = $karar;
                    $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarili, $yedek->id, $kullaniciId, null, $karar->diziye());
                } catch (HedefHatasi $hata) {
                    if ($hata->kod === HedefHatasi::SILME_YETKISI_YOK && $karar->kopyaId !== null) {
                        // Değiştirilemez saklama: uygulamanın silme yetkisi yok, hedefin kendi kuralı temizler (README §4.6)
                        YedekKopyasi::query()->whereKey($karar->kopyaId)->update([
                            'durum' => KopyaDurumu::Silindi->value, 'hata_kodu' => $hata->kod, 'silinme_zamani' => CarbonImmutable::now(),
                        ]);
                        $sonuc['bilgi'][] = "hedef {$hedefId}: silme yetkisi yok ({$yedek->dosya_adi}); hedefin kendi saklama kuralı temizler";
                        $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarili, $yedek->id, $kullaniciId, null, [...$karar->diziye(), 'bilgi' => $hata->kod]);

                        continue;
                    }

                    $sonuc['atlanan_hedefler'][] = (int) $hedefId;
                    $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, null, [...$karar->diziye(), 'kod' => $hata->kod], $hata->getMessage());
                    break;
                }
            }
        }

        $sonuc['atlanan_hedefler'] = array_values(array_unique($sonuc['atlanan_hedefler']));

        if ($sonuc['dosya'] > (int) config('yedekleme.beklenmedik_silme', 10)) {
            $this->bildirimci->bildir('beklenmedik_temizlik', ["Bu temizlikte {$sonuc['dosya']} dosya silindi; saklama kurallarını gözden geçirin."]);
        }

        return $sonuc;
    }

    private function dosyaSil(Yedek $yedek, SilmeKarari $karar, mixed $baglayici): void
    {
        if ($karar->hedefId === null) {
            $this->yerelSilici->sil($yedek, uzakKopyaSart: $yedek->kaynak === YedekKaynagi::Plan);

            return;
        }

        $kopya = YedekKopyasi::query()->with('hedef')->findOrFail($karar->kopyaId);

        if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
            $this->kopyaSilindi($kopya);
            $this->yerelSilici->sil($yedek, uzakKopyaSart: false, haricHedefId: $karar->hedefId);

            return;
        }

        $baglayici->sil((string) $kopya->uzak_kimlik);
        $this->kopyaSilindi($kopya);

        $kalan = YedekKopyasi::query()->where('yedek_id', $yedek->id)->where('durum', KopyaDurumu::Tamam->value)->exists();
        $yerelVar = $yedek->yerel_silinme_zamani === null;
        if (! $kalan && ! $yerelVar) {
            $yedek->update(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => CarbonImmutable::now()]);
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Silindi));
        }
    }

    private function kopyaSilindi(YedekKopyasi $kopya): void
    {
        $kopya->update(['durum' => KopyaDurumu::Silindi, 'silinme_zamani' => CarbonImmutable::now()]);
        $this->olaylar->ekle(YedekOlayi::simdi($kopya->yedek->kimlik, OlayTuru::KopyaSilindi, [
            'hedef_id' => $kopya->yedek_hedefi_id, 'hedef_ad' => $kopya->hedef->ad,
        ]));
    }

    /** Bağ kalktığında kopyanın güncel plan listesi olay günlüğüne (dizin yeniden kurulurken bağ geri gelmesin). */
    private function kopyaOlayi(YedekKopyasi $kopya): void
    {
        $this->olaylar->ekle(YedekOlayi::simdi($kopya->yedek->kimlik, OlayTuru::Kopya, [
            'hedef_id' => $kopya->yedek_hedefi_id,
            'hedef_ad' => $kopya->hedef->ad,
            'durum' => $kopya->durum->value,
            'uzak_kimlik' => $kopya->uzak_kimlik,
            'sifreli' => $kopya->sifreli,
            'sifreli_sha256' => $kopya->sifreli_sha256,
            'plan_idleri' => array_map('intval', $kopya->planlar()->pluck('yedek_planlari.id')->all()),
        ]));
    }

    /**
     * Zamanlanmamış yedeğin kuralı (yedek_ayarlari): aşılmışsa neden, değilse null.
     *
     * @param  Collection<int, Yedek>  $grup  Aynı gruptaki yedekler (yayın öncesi "son N" için)
     */
    private function zamanlanmamisNedeni(Yedek $yedek, Collection $grup, YedekAyari $ayar, CarbonImmutable $simdi): ?string
    {
        $alinma = $yedek->alinma_zamani;
        if ($alinma === null) {
            return null;
        }

        return match ($yedek->kaynak) {
            YedekKaynagi::Elle => $alinma->lessThan($simdi->subDays($ayar->elle_gun)) ? SilmeKarari::NEDEN_ELLE_GUN : null,
            YedekKaynagi::YayinOncesi => (function () use ($yedek, $grup, $ayar, $simdi, $alinma): ?string {
                $sira = $grup->filter(fn (Yedek $y): bool => $y->kaynak === YedekKaynagi::YayinOncesi)
                    ->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)
                    ->values()
                    ->search(fn (Yedek $y): bool => $y->id === $yedek->id);

                return $sira !== false && $sira >= $ayar->yayin_oncesi_son_adet && $alinma->lessThan($simdi->subDays($ayar->yayin_oncesi_gun))
                    ? SilmeKarari::NEDEN_YAYIN_ONCESI : null;
            })(),
            YedekKaynagi::GeriYuklemeOncesi => $alinma->lessThan($simdi->subDays($ayar->geri_yukleme_oncesi_gun))
                && ! GeriYukleme::query()->where('oncesi_yedek_id', $yedek->id)->whereNotNull('onceki_veritabani')->whereNull('veritabani_silinme_zamani')->exists()
                ? SilmeKarari::NEDEN_GERI_YUKLEME_ONCESI : null,
            YedekKaynagi::Plan => null,
        };
    }

    /**
     * Korunanları çıkarır, en az sayıyı uygular (en eskiler önce silinir).
     *
     * @param  list<SilmeKarari>  $adaylar
     * @param  Collection<int, Yedek>  $yedekler
     * @param  array<int, list<string>>  $korunan
     * @return list<SilmeKarari>
     */
    private function suz(array $adaylar, int $grupSayisi, Collection $yedekler, array &$korunan, int $enAz): array
    {
        $silinebilir = array_values(array_filter($adaylar, fn (SilmeKarari $k): bool => ! isset($korunan[$k->yedekId])));
        usort($silinebilir, fn (SilmeKarari $a, SilmeKarari $b): int => ($yedekler[$a->yedekId]->alinma_zamani?->getTimestamp() ?? 0) <=> ($yedekler[$b->yedekId]->alinma_zamani?->getTimestamp() ?? 0));

        $izin = max(0, $grupSayisi - max(1, $enAz));
        foreach (array_slice($silinebilir, $izin) as $tutulan) {
            $korunan[$tutulan->yedekId][] = 'enaz_adet';
        }

        return array_slice($silinebilir, 0, $izin);
    }

    /**
     * Yedek klasörünün kendisi olan yerel hedefler.
     *
     * @param  list<int>  $hedefIdleri
     * @return array<int, true>
     */
    private function anaKlasorHedefleri(array $hedefIdleri): array
    {
        $ana = [];
        foreach (YedekHedefi::query()->whereIn('id', $hedefIdleri)->get() as $hedef) {
            try {
                $baglayici = $this->katalog->olustur($hedef);
            } catch (Throwable) {
                continue;
            }
            if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
                $ana[$hedef->id] = true;
            }
        }

        return $ana;
    }
}
