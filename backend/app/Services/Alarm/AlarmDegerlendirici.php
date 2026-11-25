<?php

declare(strict_types=1);

namespace App\Services\Alarm;

use App\Models\AlarmBildirimi;
use App\Models\AlarmKurali;
use App\Models\AlarmOlayi;
use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * e-Fatura alarm kurallarını değerlendirir (EFAT-13, faz 1 — ERP'siz).
 *
 * İlkeler:
 * - Yalnız AKTİF entegratör hesabı değerlendirilir; olay ve bildirimler
 *   hesaba bağlıdır (test ve canlı karışmaz).
 * - Aynı açık olay için tek bildirim: açık olay kısmi unique indeksle,
 *   bildirim `anahtar` unique ile korunur (eşzamanlı çalışma tekrar üretemez).
 * - Güncel olmayan veriyle iş alarmı üretilmez (ERP okumadı); senkronun
 *   kendisi bozuksa bunu ayrı teknik alarm (senkron arızası) bildirir.
 * - Burada yalnız kayıt oluşturulur; gönderim kuyruk işinde yapılır.
 */
final class AlarmDegerlendirici
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaDurumServisi $durum,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    /**
     * @return list<AlarmBildirimi> gönderilmeyi bekleyen yeni bildirimler
     */
    public function calistir(): array
    {
        $tanim = $this->baglantilar->aktif();

        if ($tanim === null) {
            return [];
        }

        $simdi = CarbonImmutable::now();
        $bildirimler = [];

        foreach (AlarmKurali::query()->where('aktif', true)->orderBy('id')->get() as $kural) {
            $yeni = match ($kural->tur) {
                AlarmKurali::SENKRON_ARIZASI => $this->senkronArizasi($kural, $tanim, $simdi),
                AlarmKurali::GUNLUK_OZET => $this->gunlukOzet($kural, $tanim, $simdi),
                AlarmKurali::ERP_OKUMADI => $this->erpOkumadi($kural, $tanim, $simdi),
                AlarmKurali::ISLENMEMIS_HATIRLATMA => $this->islenmemisHatirlatma($kural, $tanim, $simdi),
                default => [],
            };

            array_push($bildirimler, ...$yeni);
        }

        return array_values(array_filter(
            $bildirimler,
            fn (AlarmBildirimi $b): bool => $b->durum === AlarmBildirimi::BEKLIYOR,
        ));
    }

    /**
     * Teknik alarm: art arda N çalışma başarısız/eksik ya da veri N saattir
     * güncellenmedi. Senkron bilerek kapatılmışsa (EFATURA_SENKRON_AKTIF)
     * alarm üretilmez; ekran bunu ayrıca gösterir.
     *
     * @return list<AlarmBildirimi>
     */
    private function senkronArizasi(AlarmKurali $kural, EntegratorBaglanti $tanim, CarbonImmutable $simdi): array
    {
        if (! config('entegrator.izibiz.senkron_aktif')) {
            return [];
        }

        $esik = (int) $kural->parametre('ardisik_hata');
        $gecikme = (int) $kural->parametre('gecikme_saat');
        $bildirimler = [];

        foreach (FaturaYonu::cases() as $yon) {
            $d = $this->durum->yonDurumu($tanim, $yon);
            $gecikmeli = $d['veri_zamani'] === null || $d['veri_zamani']->lt($simdi->subHours($gecikme));
            $sonCalisma = $d['son_calisma'];
            $ayrinti = [
                'yon' => $yon->value,
                'ardisik_hata' => $d['ardisik_hata'],
                'veri_zamani' => $d['veri_zamani']?->toIso8601String(),
                'son_hata' => $sonCalisma?->hata_kodu ?? $sonCalisma?->eksik_nedeni,
            ];
            $anahtar = "senkron:{$yon->value}";

            $ariza = $d['ardisik_hata'] >= $esik || $gecikmeli;

            // Olay değişimi ile bildirimi atomik: süreç arada ölürse olay açık
            // kalıp açılış maili hiç üretilmemiş olmaz
            $bildirim = DB::transaction(function () use ($ariza, $kural, $tanim, $anahtar, $ayrinti, $simdi): ?AlarmBildirimi {
                $olay = $ariza
                    ? $this->olayAc($kural, $tanim, $anahtar, $ayrinti, $simdi)
                    : $this->olayKapat($kural, $tanim, $anahtar, $simdi);

                if ($olay === null) {
                    return null;
                }

                $tur = $ariza ? AlarmBildirimi::TUR_ACILDI : AlarmBildirimi::TUR_COZULDU;

                return $this->bildirim($kural, $tanim, $olay, $tur, "olay:{$olay->id}:{$tur}", $ayrinti);
            });

            if ($bildirim !== null) {
                $bildirimler[] = $bildirim;
            }
        }

        return $bildirimler;
    }

    /**
     * Ayarlanan saatten sonra, önceki günün (İstanbul) İLK KEZ görülen
     * faturalarının adet ve tutar özeti — gün başına bir kez. Fatura satırı
     * maile girmez; ayrıntı ekranda.
     *
     * @return list<AlarmBildirimi>
     */
    private function gunlukOzet(AlarmKurali $kural, EntegratorBaglanti $tanim, CarbonImmutable $simdi): array
    {
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $yerel = $simdi->setTimezone($saatDilimi);

        if ($yerel->format('H:i') < (string) $kural->parametre('saat')) {
            return [];
        }

        $gun = $yerel->subDay()->toDateString();
        $anahtar = "gunluk_ozet:{$tanim->id}:{$gun}";

        if (AlarmBildirimi::query()->where('anahtar', $anahtar)->exists()) {
            return [];
        }

        // Gün sınırları İstanbul takviminden (UTC'de +1 gün eklenmez)
        $baslangic = CarbonImmutable::parse($gun, $saatDilimi)->startOfDay()->utc();
        $bitis = CarbonImmutable::parse($gun, $saatDilimi)->addDay()->startOfDay()->utc();
        $yonler = [];

        foreach (FaturaYonu::cases() as $yon) {
            $sorgu = EFatura::query()
                ->where('entegrator_baglanti_id', $tanim->id)
                ->where('yon', $yon->value)
                ->where('ilk_gorulme', '>=', $baslangic)
                ->where('ilk_gorulme', '<', $bitis);
            $ozet = $this->sorgu->ozet($sorgu);

            $yonler[$yon->value] = [
                'adet' => array_sum(array_column($ozet, 'adet')),
                'para_birimleri' => $ozet,
                // Eksik senkronda "fatura yok" iddia edilmez; mail uyarı taşır
                'guncel' => $this->durum->yonDurumu($tanim, $yon)['guncel'],
            ];
        }

        // O gün ilk tarama yapıldıysa sayılar geçmişin toplu yüklemesidir;
        // mail bunu söyler ("dün gelen yeni faturalar" sanılmasın)
        $ilkTarama = EFaturaSenkronCalismasi::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('tetikleyen', EFaturaSenkronCalismasi::TETIKLEYEN_ILK_TARAMA)
            ->where('basladi', '>=', $baslangic)
            ->where('basladi', '<', $bitis)
            ->exists();

        $bildirim = $this->bildirim($kural, $tanim, null, AlarmBildirimi::TUR_OZET, $anahtar, [
            'gun' => $gun,
            'ilk_tarama' => $ilkTarama,
            'yonler' => $yonler,
        ]);

        return $bildirim === null ? [] : [$bildirim];
    }

    /**
     * Gelen fatura İzibiz'e ulaşalı N gün oldu, ERP hâlâ okumadı
     * (`erpReadFlag` — yalnız okunur). Günde bir kez; her fatura ayrı olay.
     * Mail yalnız YENİ açılan olay varsa gider (hatırlatma yok); ERP okuyunca
     * olay çözülür, tekrar okunmamışa dönerse yeni olay açılır.
     *
     * @return list<AlarmBildirimi>
     */
    private function erpOkumadi(AlarmKurali $kural, EntegratorBaglanti $tanim, CarbonImmutable $simdi): array
    {
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $yerel = $simdi->setTimezone($saatDilimi);

        if ($yerel->format('H:i') < (string) $kural->parametre('saat')) {
            return [];
        }

        $anahtar = "erp_okumadi:{$tanim->id}:{$yerel->toDateString()}";

        if (AlarmBildirimi::query()->where('anahtar', $anahtar)->exists()) {
            return [];
        }

        // Bayraklar senkronla gelir; güncel değilse okunmadı sonucu güvenilmez
        if (! $this->durum->yonDurumu($tanim, FaturaYonu::Gelen)['guncel']) {
            return [];
        }

        $gun = (int) $kural->parametre('gun');

        // Çalışma düzeyinde güncellik yetmez: eşiği geçmiş faturanın bayrağını
        // yalnız gece DOCUMENT senkronu tazeler. Yalnız son N saatte YENİDEN
        // OKUNMUŞ satırların bayrağına güvenilir; eski satır ne olay açar ne çözer.
        $taze = $simdi->subHours((int) config('efatura.erp_okundu_tazelik_saat'));

        /** @var list<int> $okunmayanlar */
        $okunmayanlar = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->where('erp_okundu', false)
            // Gizlenen fatura bize ait değil (yanlış posta kutusu): ERP'nin okuması beklenmez
            ->whereNull('gizlenme_zamani')
            ->where('son_gorulme', '>=', $taze)
            ->where('olusturma_zamani', '<=', $simdi->subDays($gun))
            ->where('belge_tarihi', '>=', (string) config('entegrator.izibiz.ilk_tarama_tarihi'))
            ->pluck('id')
            ->all();

        $gerekenler = [];
        foreach ($okunmayanlar as $id) {
            $gerekenler["fatura:{$id}"] = true;
        }

        /** @var array<string, int> $aciklar anahtar => olay id */
        $aciklar = AlarmOlayi::query()
            ->where('alarm_kurali_id', $kural->id)
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('durum', AlarmOlayi::ACIK)
            ->pluck('id', 'anahtar')
            ->all();

        $yeniler = array_keys(array_diff_key($gerekenler, $aciklar));
        $cozulenler = $this->cozulenErpOlaylari(array_diff_key($aciklar, $gerekenler), $taze);

        $ayrinti = [
            'esik_gun' => $gun,
            'yeni_adet' => count($yeniler),
            'acik_adet' => count($gerekenler),
            'cozulen_adet' => count($cozulenler),
        ];

        try {
            $bildirim = DB::transaction(function () use ($kural, $tanim, $simdi, $yeniler, $cozulenler, $anahtar, $ayrinti): AlarmBildirimi {
                foreach (array_chunk($yeniler, 500) as $parti) {
                    AlarmOlayi::query()->insert(array_map(fn (string $a): array => [
                        'alarm_kurali_id' => $kural->id,
                        'entegrator_baglanti_id' => $tanim->id,
                        'anahtar' => $a,
                        'durum' => AlarmOlayi::ACIK,
                        'acildi' => $simdi,
                        'created_at' => $simdi,
                        'updated_at' => $simdi,
                    ], $parti));
                }

                foreach (array_chunk($cozulenler, 500) as $parti) {
                    AlarmOlayi::query()->whereIn('id', $parti)->update([
                        'durum' => AlarmOlayi::COZULDU,
                        'cozuldu' => $simdi,
                        'updated_at' => $simdi,
                    ]);
                }

                // Yeni olay yoksa kayıt "değerlendirildi" izi olarak atlandı açılır
                return AlarmBildirimi::query()->create([
                    'alarm_kurali_id' => $kural->id,
                    'entegrator_baglanti_id' => $tanim->id,
                    'tur' => AlarmBildirimi::TUR_ACILDI,
                    'anahtar' => $anahtar,
                    'durum' => $yeniler === [] ? AlarmBildirimi::ATLANDI : AlarmBildirimi::BEKLIYOR,
                    'hata_kodu' => $yeniler === [] ? 'YENI_OLAY_YOK' : null,
                    'ayrinti' => $ayrinti,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Eşzamanlı başka değerlendirme aynı günü işledi; hepsi geri alındı
            return [];
        }

        return [$bildirim];
    }

    /**
     * İşlenmemiş fatura hatırlatması (EK-10): ayarlanan saatten sonra, son
     * gönderilen hatırlatmadan `aralik_gun` gün geçtiyse; belge tarihi `gun`
     * günden eski ve hâlâ "İşlenecek" (EFatura::islenecek — panoyla aynı tanım)
     * gizlenmemiş gelen faturaların portal bazında adedi ve en eski tarihi.
     * Mailde fatura satırı, unvan, VKN yok; panoya bağlantı verilir. Bekleyen
     * fatura yoksa mail gitmez (kayıt "atlandı"), ertesi gün yeniden bakılır.
     *
     * Bildirim, değerlendiricinin hesabına (aktif İzibiz tanımı) bağlanır;
     * sayılar Tüm Faturalar'ın bütün kaynaklarından gelir.
     *
     * @return list<AlarmBildirimi>
     */
    private function islenmemisHatirlatma(AlarmKurali $kural, EntegratorBaglanti $tanim, CarbonImmutable $simdi): array
    {
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $yerel = $simdi->setTimezone($saatDilimi);

        if ($yerel->format('H:i') < (string) $kural->parametre('saat')) {
            return [];
        }

        $anahtar = "islenmemis:{$kural->id}:{$yerel->toDateString()}";
        if (AlarmBildirimi::query()->where('anahtar', $anahtar)->exists()) {
            return [];
        }

        // Sıklık: son gönderilen (ya da gönderilmeyi bekleyen) hatırlatmadan bu yana gün
        $son = AlarmBildirimi::query()
            ->where('alarm_kurali_id', $kural->id)
            ->whereIn('durum', [AlarmBildirimi::BEKLIYOR, AlarmBildirimi::GONDERILDI])
            ->latest('id')
            ->value('created_at');
        $aralik = (int) $kural->parametre('aralik_gun');
        if ($son !== null && CarbonImmutable::parse($son)->setTimezone($saatDilimi)->startOfDay()->diffInDays($yerel->startOfDay()) < $aralik) {
            return [];
        }

        $esikGun = (int) $kural->parametre('gun');
        $kesim = $yerel->subDays($esikGun)->toDateString();
        $istenen = explode(',', (string) $kural->parametre('portallar'));
        $tanimlar = array_values(array_filter(
            $this->baglantilar->gelenTanimlari(),
            fn (EntegratorBaglanti $t): bool => in_array($t->saglayici, $istenen, true),
        ));
        $saglayicilar = array_column(array_map(fn (EntegratorBaglanti $t): array => [$t->id, $t->saglayici], $tanimlar), 1, 0);

        $satirlar = EFatura::query()
            ->whereIn('entegrator_baglanti_id', array_keys($saglayicilar))
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('gizlenme_zamani')
            // Ertesi günden küçük: SQLite tarihi saatle saklar, '<= gün' o günü dışarıda bırakırdı
            ->where('belge_tarihi', '<', CarbonImmutable::parse($kesim)->addDay()->toDateString())
            ->where('belge_tarihi', '>=', (string) config('entegrator.izibiz.ilk_tarama_tarihi'))
            ->islenecek()
            ->toBase()
            ->selectRaw('entegrator_baglanti_id, count(*) as adet, min(belge_tarihi) as en_eski')
            ->groupBy('entegrator_baglanti_id')
            ->get()
            ->keyBy('entegrator_baglanti_id');

        $portallar = [];
        foreach (EntegratorBaglanti::GELEN_SAGLAYICILARI as $saglayici) {
            $id = array_search($saglayici, $saglayicilar, true);
            $satir = $id === false ? null : $satirlar->get($id);
            if ($satir !== null) {
                $portallar[] = ['saglayici' => $saglayici, 'adet' => (int) $satir->adet, 'en_eski' => substr((string) $satir->en_eski, 0, 10)];
            }
        }
        $toplam = array_sum(array_column($portallar, 'adet'));
        $ayrinti = ['esik_gun' => $esikGun, 'kesim' => $kesim, 'toplam' => $toplam, 'portallar' => $portallar];

        try {
            $bildirim = DB::transaction(fn (): AlarmBildirimi => AlarmBildirimi::query()->create([
                'alarm_kurali_id' => $kural->id,
                'entegrator_baglanti_id' => $tanim->id,
                'tur' => AlarmBildirimi::TUR_OZET,
                'anahtar' => $anahtar,
                'durum' => $toplam === 0 ? AlarmBildirimi::ATLANDI : AlarmBildirimi::BEKLIYOR,
                'hata_kodu' => $toplam === 0 ? 'ISLENECEK_YOK' : null,
                'ayrinti' => $ayrinti,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Eşzamanlı başka değerlendirme aynı günü işledi
            return [];
        }

        return [$bildirim];
    }

    /**
     * Artık "okunmadı" listesinde olmayan açık olaylardan yalnız KANITLA
     * çözülenler: fatura silinmiş ya da taze okunmuş ve ERP bayrağı açıkça
     * true olmuş. Bilinmeyen (null) veya bayat bayrak olayı kapatmaz.
     *
     * @param  array<string, int>  $adaylar  anahtar ('fatura:ID') => olay id
     * @return list<int> çözülecek olay kimlikleri
     */
    private function cozulenErpOlaylari(array $adaylar, CarbonImmutable $taze): array
    {
        $faturaOlaylari = [];
        foreach ($adaylar as $anahtar => $olayId) {
            $faturaOlaylari[(int) substr($anahtar, strlen('fatura:'))] = $olayId;
        }

        $cozulenler = [];
        foreach (array_chunk(array_keys($faturaOlaylari), 1000) as $parti) {
            $faturalar = EFatura::query()->whereIn('id', $parti)->get(['id', 'erp_okundu', 'son_gorulme', 'gizlenme_zamani'])->keyBy('id');

            foreach ($parti as $faturaId) {
                $fatura = $faturalar->get($faturaId);

                // Gizlenen fatura bize ait değil: olayı kapanır
                if ($fatura === null
                    || $fatura->gizlenme_zamani !== null
                    || ($fatura->erp_okundu === true && $fatura->getAttribute('son_gorulme')?->gte($taze))) {
                    $cozulenler[] = $faturaOlaylari[$faturaId];
                }
            }
        }

        return $cozulenler;
    }

    /**
     * @param  array<string, mixed>  $ayrinti
     */
    private function olayAc(AlarmKurali $kural, EntegratorBaglanti $tanim, string $anahtar, array $ayrinti, CarbonImmutable $simdi): ?AlarmOlayi
    {
        $acikVar = AlarmOlayi::query()
            ->where('alarm_kurali_id', $kural->id)
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('anahtar', $anahtar)
            ->where('durum', AlarmOlayi::ACIK)
            ->exists();

        if ($acikVar) {
            return null;
        }

        try {
            // Savepoint: PostgreSQL'de tekillik ihlali dış transaction'ı bozmasın
            return DB::transaction(fn (): AlarmOlayi => AlarmOlayi::query()->create([
                'alarm_kurali_id' => $kural->id,
                'entegrator_baglanti_id' => $tanim->id,
                'anahtar' => $anahtar,
                'durum' => AlarmOlayi::ACIK,
                'acildi' => $simdi,
                'ayrinti' => $ayrinti,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function olayKapat(AlarmKurali $kural, EntegratorBaglanti $tanim, string $anahtar, CarbonImmutable $simdi): ?AlarmOlayi
    {
        $olay = AlarmOlayi::query()
            ->where('alarm_kurali_id', $kural->id)
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('anahtar', $anahtar)
            ->where('durum', AlarmOlayi::ACIK)
            ->first();

        if ($olay === null) {
            return null;
        }

        // Koşullu güncelleme: eşzamanlı ikinci değerlendirme aynı olayı kapatamaz
        $kapandi = AlarmOlayi::query()
            ->whereKey($olay->id)
            ->where('durum', AlarmOlayi::ACIK)
            ->update(['durum' => AlarmOlayi::COZULDU, 'cozuldu' => $simdi, 'updated_at' => $simdi]);

        return $kapandi === 1 ? $olay->refresh() : null;
    }

    /**
     * @param  array<string, mixed>  $ayrinti
     */
    private function bildirim(AlarmKurali $kural, EntegratorBaglanti $tanim, ?AlarmOlayi $olay, string $tur, string $anahtar, array $ayrinti): ?AlarmBildirimi
    {
        try {
            // Savepoint: PostgreSQL'de tekillik ihlali dış transaction'ı bozmasın
            return DB::transaction(fn (): AlarmBildirimi => AlarmBildirimi::query()->create([
                'alarm_kurali_id' => $kural->id,
                'entegrator_baglanti_id' => $tanim->id,
                'alarm_olayi_id' => $olay?->id,
                'tur' => $tur,
                'anahtar' => $anahtar,
                'durum' => AlarmBildirimi::BEKLIYOR,
                'ayrinti' => $ayrinti,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
