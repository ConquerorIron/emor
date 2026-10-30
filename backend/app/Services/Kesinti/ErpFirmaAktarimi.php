<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Support\Metin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Canlı ERP taşeronlarını `firmalar` tablosuna aktarır (akış: ErpAktarimi).
 *
 * Eşleşme GUID ya da VKN ile (kullanıcı kararı 2026-10-30): GUID'i tutan
 * firma yoksa, henüz ERP'ye bağlanmamış aynı VKN'li firma ERP firmasına
 * bağlanır. Kod ve VKN uygulamada benzersizdir; başka bir firmada olan
 * değer çakışma sayılır ve aktarılmaz.
 */
final class ErpFirmaAktarimi extends ErpAktarimi
{
    /** Firma bilgisi alanları ve firmalar tablosundaki sınırları */
    private const ALANLAR = [
        'kod' => 50,
        'ad' => 200,
        'vergi_kimlik_no' => 20,
        'vergi_dairesi' => 100,
        'eposta' => 255,
        'gsm_no' => 30,
    ];

    public function __construct(
        private readonly ErpFirmaKaynagi $kaynak,
    ) {}

    protected function erpSatirlari(): array
    {
        return $this->kaynak->firmalar();
    }

    /**
     * @param  list<array<string, string|null>>  $erpSatirlari
     * @return list<array<string, mixed>>
     */
    protected function planla(array $erpSatirlari): array
    {
        $satirlar = array_map(fn (array $satir): array => [
            'guid' => self::guid($satir['guid']),
            'kod' => Metin::buyukHarf(trim((string) $satir['kod'])),
            'ad' => trim((string) $satir['ad']),
            'vergi_kimlik_no' => self::bosIseNull(Metin::bosluksuz((string) $satir['vergi_kimlik_no'])),
            'vergi_dairesi' => self::bosIseNull($satir['vergi_dairesi']),
            'eposta' => self::bosIseNull($satir['eposta']),
            'gsm_no' => self::bosIseNull($satir['gsm_no']),
        ], $erpSatirlari);

        // ERP içinde tekrarlanan GUID/VKN/kodun hangisinin doğru olduğu bilinemez: aktarılmaz
        $sayilar = [
            'guid' => array_count_values(array_column($satirlar, 'guid')),
            'kod' => array_count_values(array_column($satirlar, 'kod')),
            'vergi_kimlik_no' => array_count_values(array_filter(array_column($satirlar, 'vergi_kimlik_no'), fn (?string $vkn): bool => $vkn !== null)),
        ];

        $firmalar = Firma::query()->get();
        $guidIle = $firmalar->whereNotNull('erp_guid')->keyBy('erp_guid');
        $kodIle = $firmalar->keyBy('kod');
        $vknIle = $firmalar->whereNotNull('vergi_kimlik_no')->keyBy('vergi_kimlik_no');

        $plan = [];

        foreach ($satirlar as $sira => $satir) {
            $neden = $this->gecersizlikNedeni($satir, $sayilar);
            [$durum, $hedef, $cakisma] = $neden === null
                ? $this->durum($satir, $guidIle, $kodIle, $vknIle)
                : [self::GECERSIZ, null, null];

            $plan[] = [
                'sira' => $sira,
                ...$satir,
                'durum' => $durum,
                'neden' => $neden ?? $cakisma,
                'mevcut' => $hedef === null ? null : $hedef->only(array_keys(self::ALANLAR)),
                'kayit_id' => $hedef?->id,
            ];
        }

        usort($plan, fn (array $a, array $b): int => strcmp($a['ad'], $b['ad']));

        return $plan;
    }

    protected function uygula(array $satir): void
    {
        $bilgiler = Arr::only($satir, array_keys(self::ALANLAR));

        match ($satir['durum']) {
            self::YENI => Firma::query()->create([...$bilgiler, 'erp_guid' => $satir['guid']]),
            self::GUNCELLENECEK => Firma::query()->whereKey($satir['kayit_id'])->update($bilgiler),
            self::ESLESECEK => Firma::query()->whereKey($satir['kayit_id'])->update([...$bilgiler, 'erp_guid' => $satir['guid']]),
        };
    }

    /**
     * @param  array<string, string|null>  $satir
     * @param  array{guid: array<array-key, int>, kod: array<array-key, int>, vergi_kimlik_no: array<array-key, int>}  $sayilar
     */
    private function gecersizlikNedeni(array $satir, array $sayilar): ?string
    {
        if (! self::gecerliGuid((string) $satir['guid'])) {
            return 'guid_gecersiz';
        }

        if ($satir['kod'] === '') {
            return 'kod_bos';
        }

        if ($satir['ad'] === '') {
            return 'ad_bos';
        }

        foreach (self::ALANLAR as $alan => $sinir) {
            if (mb_strlen((string) $satir[$alan]) > $sinir) {
                return "{$alan}_uzun";
            }
        }

        return match (true) {
            $sayilar['guid'][$satir['guid']] > 1 => 'guid_tekrar',
            $satir['vergi_kimlik_no'] !== null && $sayilar['vergi_kimlik_no'][$satir['vergi_kimlik_no']] > 1 => 'vkn_tekrar',
            $sayilar['kod'][$satir['kod']] > 1 => 'kod_tekrar',
            default => null,
        };
    }

    /**
     * @param  array<string, string|null>  $satir
     * @param  Collection<array-key, Firma>  $guidIle
     * @param  Collection<array-key, Firma>  $kodIle
     * @param  Collection<array-key, Firma>  $vknIle
     * @return array{0: string, 1: Firma|null, 2: string|null} [durum, eşleşen firma, çakışma nedeni]
     */
    private function durum(array $satir, Collection $guidIle, Collection $kodIle, Collection $vknIle): array
    {
        $mevcut = $guidIle->get($satir['guid']);
        $kodSahibi = $kodIle->get($satir['kod']);
        $vknSahibi = $satir['vergi_kimlik_no'] === null ? null : $vknIle->get($satir['vergi_kimlik_no']);

        // GUID eşleşmezse VKN: yalnız henüz ERP'ye bağlanmamış firma bağlanabilir
        if ($mevcut === null && $vknSahibi?->erp_guid !== null) {
            return [self::CAKISMA, null, 'vkn_baska_firmada'];
        }

        $hedef = $mevcut ?? $vknSahibi;

        if ($kodSahibi !== null && $kodSahibi->id !== $hedef?->id) {
            return [self::CAKISMA, $hedef, 'kod_baska_kayitta'];
        }

        if ($hedef === null) {
            return [self::YENI, null, null];
        }

        if ($vknSahibi !== null && $vknSahibi->id !== $hedef->id) {
            return [self::CAKISMA, $hedef, 'vkn_baska_firmada'];
        }

        if ($mevcut === null) {
            return [self::ESLESECEK, $hedef, null];
        }

        foreach (array_keys(self::ALANLAR) as $alan) {
            if ($mevcut->{$alan} !== $satir[$alan]) {
                return [self::GUNCELLENECEK, $mevcut, null];
            }
        }

        return [self::AYNI, $mevcut, null];
    }
}
