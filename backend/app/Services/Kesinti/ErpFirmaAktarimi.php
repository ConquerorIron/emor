<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Support\Metin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as Koleksiyon;

/**
 * Canlı ERP taşeronlarını `firmalar` tablosuna aktarır (akış: ErpAktarimi).
 * Kullanıcı kararları 2026-10-30:
 *
 * - Firmanın kimliği yalnız ERP GUID'idir; ERP'de aynı firmanın birden fazla
 *   kartı aynı kodu ve VKN'yi taşıyabilir (MFK KOÇ'un 4 kartı).
 * - GUID'i tutan firma yoksa VKN ile eşleşilir, ama YALNIZ tek aday varsa:
 *   ERP'de o VKN tek satırda ve uygulamada tek, henüz ERP'ye bağlanmamış
 *   firmada olmalı. Aksi hâlde kayıt yeni firma olarak gelir.
 * - Kod uygulamaya aittir: Excel'le eşleşsin diye burada düzenlenir. ERP kodu
 *   yalnız firma ilk aktarılırken alınır; sonraki aktarımlar kodu ezmez.
 */
final class ErpFirmaAktarimi extends ErpAktarimi
{
    /** Alan sınırları (firmalar tablosu) */
    private const SINIRLAR = [
        'kod' => 50,
        'ad' => 200,
        'vergi_kimlik_no' => 20,
        'vergi_dairesi' => 100,
        'eposta' => 255,
        'gsm_no' => 30,
    ];

    /** ERP'nin sahip olduğu, aktarımda güncellenen bilgiler (kod hariç) */
    private const ERP_BILGILERI = ['ad', 'vergi_kimlik_no', 'vergi_dairesi', 'eposta', 'gsm_no'];

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

        $guidSayisi = array_count_values(array_column($satirlar, 'guid'));
        $erpVknSayisi = array_count_values(array_filter(array_column($satirlar, 'vergi_kimlik_no'), fn (?string $vkn): bool => $vkn !== null));

        $firmalar = Firma::query()->get();
        $guidIle = $firmalar->whereNotNull('erp_guid')->keyBy('erp_guid');
        $vknIle = $firmalar->whereNotNull('vergi_kimlik_no')->groupBy('vergi_kimlik_no');

        $plan = [];

        foreach ($satirlar as $sira => $satir) {
            $neden = $this->gecersizlikNedeni($satir, $guidSayisi);
            [$durum, $hedef] = $neden === null
                ? $this->durum($satir, $guidIle, $vknIle, $erpVknSayisi)
                : [self::GECERSIZ, null];

            $plan[] = [
                'sira' => $sira,
                ...$satir,
                'durum' => $durum,
                'neden' => $neden,
                'mevcut' => $hedef?->only(['kod', ...self::ERP_BILGILERI]),
                'kayit_id' => $hedef?->id,
            ];
        }

        usort($plan, fn (array $a, array $b): int => strcmp($a['ad'], $b['ad']));

        return $plan;
    }

    protected function uygula(array $satir): void
    {
        $bilgiler = Arr::only($satir, self::ERP_BILGILERI);

        match ($satir['durum']) {
            self::YENI => Firma::query()->create([...$bilgiler, 'kod' => $satir['kod'], 'erp_guid' => $satir['guid']]),
            self::GUNCELLENECEK => Firma::query()->whereKey($satir['kayit_id'])->update($bilgiler),
            self::ESLESECEK => Firma::query()->whereKey($satir['kayit_id'])->update([...$bilgiler, 'erp_guid' => $satir['guid']]),
        };
    }

    /**
     * @param  array<string, string|null>  $satir
     * @param  array<array-key, int>  $guidSayisi
     */
    private function gecersizlikNedeni(array $satir, array $guidSayisi): ?string
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

        foreach (self::SINIRLAR as $alan => $sinir) {
            if (mb_strlen((string) $satir[$alan]) > $sinir) {
                return "{$alan}_uzun";
            }
        }

        return $guidSayisi[$satir['guid']] > 1 ? 'guid_tekrar' : null;
    }

    /**
     * @param  array<string, string|null>  $satir
     * @param  Collection<array-key, Firma>  $guidIle
     * @param  Koleksiyon<array-key, Collection<int, Firma>>  $vknIle
     * @param  array<array-key, int>  $erpVknSayisi
     * @return array{0: string, 1: Firma|null} [durum, eşleşen firma]
     */
    private function durum(array $satir, Collection $guidIle, Koleksiyon $vknIle, array $erpVknSayisi): array
    {
        $mevcut = $guidIle->get($satir['guid']);

        if ($mevcut !== null) {
            foreach (self::ERP_BILGILERI as $alan) {
                if ($mevcut->{$alan} !== $satir[$alan]) {
                    return [self::GUNCELLENECEK, $mevcut];
                }
            }

            return [self::AYNI, $mevcut];
        }

        $vkn = $satir['vergi_kimlik_no'];
        $adaylar = $vkn === null ? null : $vknIle->get($vkn);

        // VKN ile bağ yalnız tek aday varsa: ERP'de tek satır, uygulamada tek bağsız firma
        if ($adaylar !== null && $erpVknSayisi[$vkn] === 1 && $adaylar->count() === 1 && $adaylar->first()?->erp_guid === null) {
            return [self::ESLESECEK, $adaylar->first()];
        }

        return [self::YENI, null];
    }
}
