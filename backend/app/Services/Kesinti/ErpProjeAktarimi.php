<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Proje;
use App\Support\Metin;

/**
 * Canlı ERP projelerini `projeler` tablosuna aktarır (akış: ErpAktarimi).
 *
 * Eşleşme anahtarı ERP GUID'idir; GUID'i olmayan (elle açılmış) aynı kodlu
 * proje ERP projesine bağlanır.
 */
final class ErpProjeAktarimi extends ErpAktarimi
{
    /** projeler tablosunun kolon sınırları */
    private const KOD_SINIRI = 50;

    private const AD_SINIRI = 200;

    public function __construct(
        private readonly ErpProjeKaynagi $kaynak,
    ) {}

    protected function erpSatirlari(): array
    {
        return $this->kaynak->projeler();
    }

    /**
     * @param  list<array<string, string|null>>  $erpSatirlari
     * @return list<array{sira: int, guid: string, kod: string, ad: string, durum: string, neden: string|null, mevcut: array{kod: string, ad: string}|null, kayit_id: int|null}>
     */
    protected function planla(array $erpSatirlari): array
    {
        $satirlar = array_map(fn (array $satir): array => [
            'kod' => Metin::buyukHarf(trim((string) $satir['kod'])),
            'ad' => trim((string) $satir['ad']),
            'guid' => self::guid($satir['guid']),
        ], $erpSatirlari);

        // ERP içinde tekrarlanan GUID/kodun hangisinin doğru olduğu bilinemez: aktarılmaz
        $guidSayisi = array_count_values(array_column($satirlar, 'guid'));
        $kodSayisi = array_count_values(array_column($satirlar, 'kod'));

        $projeler = Proje::query()->get(['id', 'kod', 'ad', 'erp_guid']);
        $guidIle = $projeler->whereNotNull('erp_guid')->keyBy('erp_guid');
        $kodIle = $projeler->keyBy('kod');

        $plan = [];

        foreach ($satirlar as $sira => $satir) {
            $neden = match (true) {
                ! self::gecerliGuid($satir['guid']) => 'guid_gecersiz',
                $satir['kod'] === '' => 'kod_bos',
                $satir['ad'] === '' => 'ad_bos',
                mb_strlen($satir['kod']) > self::KOD_SINIRI => 'kod_uzun',
                mb_strlen($satir['ad']) > self::AD_SINIRI => 'ad_uzun',
                $guidSayisi[$satir['guid']] > 1 => 'guid_tekrar',
                $kodSayisi[$satir['kod']] > 1 => 'kod_tekrar',
                default => null,
            };
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
                'neden' => $durum === self::CAKISMA ? 'kod_baska_kayitta' : $neden,
                'mevcut' => $hedef === null ? null : ['kod' => $hedef->kod, 'ad' => $hedef->ad],
                'kayit_id' => $hedef?->id,
            ];
        }

        usort($plan, fn (array $a, array $b): int => strcmp($a['kod'], $b['kod']));

        return $plan;
    }

    protected function uygula(array $satir): void
    {
        match ($satir['durum']) {
            self::YENI => Proje::query()->create(['kod' => $satir['kod'], 'ad' => $satir['ad'], 'erp_guid' => $satir['guid']]),
            self::GUNCELLENECEK => Proje::query()->whereKey($satir['kayit_id'])->update(['kod' => $satir['kod'], 'ad' => $satir['ad']]),
            self::ESLESECEK => Proje::query()->whereKey($satir['kayit_id'])->update(['ad' => $satir['ad'], 'erp_guid' => $satir['guid']]),
        };
    }
}
