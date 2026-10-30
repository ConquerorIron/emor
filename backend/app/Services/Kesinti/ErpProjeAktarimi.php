<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Proje;
use App\Support\Metin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Canlı ERP projelerini `projeler` tablosuna aktarır.
 *
 * Eşleşme anahtarı ERP GUID'idir; GUID'i olmayan (elle açılmış) aynı kodlu
 * proje ERP projesine bağlanır. Aktarım önce önizlenir; kayıt yalnız seçilen
 * GUID'lere ve ERP'nin O ANKİ verisine göre yapılır. ERP'de artık olmayan
 * projeler silinmez.
 *
 * Önizleme satırı: sira (ERP sırası, satır anahtarı), guid, kod, ad, durum,
 * neden (gecersiz/cakisma için), mevcut_kod/mevcut_ad (eşleşen proje),
 * proje_id (yalnız sunucu içi).
 */
final class ErpProjeAktarimi
{
    /** ERP'de var, uygulamada yok: eklenir */
    public const YENI = 'yeni';

    /** Aynı GUID'li projenin kodu ya da adı ERP'de değişmiş: güncellenir */
    public const GUNCELLENECEK = 'guncellenecek';

    /** Elle açılmış aynı kodlu proje ERP projesine bağlanır */
    public const ESLESECEK = 'eslesecek';

    /** Değişiklik yok */
    public const AYNI = 'ayni';

    /** Kod uygulamada başka bir projede: aktarılamaz */
    public const CAKISMA = 'cakisma';

    /** ERP satırı aktarılamaz (neden alanında) */
    public const GECERSIZ = 'gecersiz';

    /** Uygulanan durum => sonuç sayacı */
    private const SAYACLAR = [
        self::YENI => 'eklenen',
        self::GUNCELLENECEK => 'guncellenen',
        self::ESLESECEK => 'eslesen',
    ];

    /** projeler tablosunun kolon sınırları */
    private const KOD_SINIRI = 50;

    private const AD_SINIRI = 200;

    public function __construct(
        private readonly ErpProjeKaynagi $kaynak,
    ) {}

    /**
     * @return list<array{sira: int, guid: string, kod: string, ad: string, durum: string, neden: string|null, mevcut_kod: string|null, mevcut_ad: string|null, proje_id: int|null}>
     */
    public function onizle(): array
    {
        return $this->planla($this->kaynak->projeler());
    }

    /**
     * @param  list<string>  $guidler
     * @return array{eklenen: int, guncellenen: int, eslesen: int, atlanan: int}
     */
    public function aktar(array $guidler): array
    {
        // ERP okuması işlemin dışında: PostgreSQL işlemi dış sorgu boyunca açık kalmaz
        $erpSatirlari = $this->kaynak->projeler();
        $secili = array_flip(array_map(mb_strtolower(...), $guidler));

        return DB::transaction(function () use ($erpSatirlari, $secili): array {
            $sonuc = ['eklenen' => 0, 'guncellenen' => 0, 'eslesen' => 0, 'atlanan' => 0];

            foreach ($this->planla($erpSatirlari) as $satir) {
                if (! isset($secili[$satir['guid']]) || ! array_key_exists($satir['durum'], self::SAYACLAR)) {
                    continue;
                }

                match ($satir['durum']) {
                    self::YENI => Proje::query()->create(['kod' => $satir['kod'], 'ad' => $satir['ad'], 'erp_guid' => $satir['guid']]),
                    self::GUNCELLENECEK => Proje::query()->whereKey($satir['proje_id'])->update(['kod' => $satir['kod'], 'ad' => $satir['ad']]),
                    self::ESLESECEK => Proje::query()->whereKey($satir['proje_id'])->update(['ad' => $satir['ad'], 'erp_guid' => $satir['guid']]),
                };

                $sonuc[self::SAYACLAR[$satir['durum']]]++;
            }

            // Seçildiği hâlde uygulanmayanlar: bu arada güncel hâle gelmiş, artık geçersiz ya da ERP'den kalkmış
            $sonuc['atlanan'] = count($secili) - $sonuc['eklenen'] - $sonuc['guncellenen'] - $sonuc['eslesen'];

            return $sonuc;
        });
    }

    /**
     * @param  list<array{kod: string, ad: string, guid: string}>  $erpSatirlari
     * @return list<array{sira: int, guid: string, kod: string, ad: string, durum: string, neden: string|null, mevcut_kod: string|null, mevcut_ad: string|null, proje_id: int|null}>
     */
    private function planla(array $erpSatirlari): array
    {
        $satirlar = array_map(fn (array $satir): array => [
            'kod' => Metin::buyukHarf(trim($satir['kod'])),
            'ad' => trim($satir['ad']),
            'guid' => mb_strtolower(trim($satir['guid'], " \t\n\r\0\x0B{}")),
        ], $erpSatirlari);

        // ERP içinde tekrarlanan GUID/kodun hangisinin doğru olduğu bilinemez: aktarılmaz
        $guidSayisi = array_count_values(array_column($satirlar, 'guid'));
        $kodSayisi = array_count_values(array_column($satirlar, 'kod'));

        $projeler = Proje::query()->get(['id', 'kod', 'ad', 'erp_guid']);
        $guidIle = $projeler->whereNotNull('erp_guid')->keyBy('erp_guid');
        $kodIle = $projeler->keyBy('kod');

        $plan = [];

        foreach ($satirlar as $sira => $satir) {
            $neden = $this->gecersizlikNedeni($satir, $guidSayisi, $kodSayisi);
            $mevcut = $neden === null ? $guidIle->get($satir['guid']) : null;
            $kodSahibi = $kodIle->get($satir['kod']);

            $durum = match (true) {
                $neden !== null => self::GECERSIZ,
                $mevcut !== null && $mevcut->kod === $satir['kod'] && $mevcut->ad === $satir['ad'] => self::AYNI,
                $mevcut !== null => $kodSahibi === null || $kodSahibi->id === $mevcut->id ? self::GUNCELLENECEK : self::CAKISMA,
                $kodSahibi === null => self::YENI,
                $kodSahibi->erp_guid === null => self::ESLESECEK,
                default => self::CAKISMA,
            };

            $hedef = $mevcut ?? ($durum === self::ESLESECEK ? $kodSahibi : null);

            $plan[] = [
                'sira' => $sira,
                'guid' => $satir['guid'],
                'kod' => $satir['kod'],
                'ad' => $satir['ad'],
                'durum' => $durum,
                'neden' => $durum === self::CAKISMA ? 'kod_baska_projede' : $neden,
                'mevcut_kod' => $hedef?->kod,
                'mevcut_ad' => $hedef?->ad,
                'proje_id' => $hedef?->id,
            ];
        }

        usort($plan, fn (array $a, array $b): int => strcmp($a['kod'], $b['kod']));

        return $plan;
    }

    /**
     * @param  array{kod: string, ad: string, guid: string}  $satir
     * @param  array<array-key, int>  $guidSayisi
     * @param  array<array-key, int>  $kodSayisi
     */
    private function gecersizlikNedeni(array $satir, array $guidSayisi, array $kodSayisi): ?string
    {
        return match (true) {
            ! Str::isUuid($satir['guid']) => 'guid_gecersiz',
            $satir['kod'] === '' => 'kod_bos',
            $satir['ad'] === '' => 'ad_bos',
            mb_strlen($satir['kod']) > self::KOD_SINIRI => 'kod_uzun',
            mb_strlen($satir['ad']) > self::AD_SINIRI => 'ad_uzun',
            $guidSayisi[$satir['guid']] > 1 => 'guid_tekrar',
            $kodSayisi[$satir['kod']] > 1 => 'kod_tekrar',
            default => null,
        };
    }
}
