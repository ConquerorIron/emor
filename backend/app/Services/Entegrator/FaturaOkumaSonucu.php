<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use Carbon\CarbonImmutable;

/**
 * Bir tarih aralığının okuma sonucu. `tam` false ise liste kesin değildir:
 * yokluk çıkarımı (mutabakat, alarm) yapılmaz. HTTP hataları sonuç olarak
 * değil istisna olarak iletilir (EntegratorHatasi) — hiçbir zaman boş liste
 * gibi görünmez.
 */
final readonly class FaturaOkumaSonucu
{
    public const EKSIK_SAYFA_SINIRI = 'sayfa_siniri';

    public const EKSIK_SAYIM_UYUSMUYOR = 'sayim_uyusmuyor';

    public const EKSIK_VERI_HATASI = 'veri_hatasi';

    /**
     * @param  list<EntegratorFatura>  $faturalar
     * @param  list<array{kaynak_id: int|null, alanlar: list<string>}>  $hataliKayitlar
     */
    public function __construct(
        public FaturaYonu $yon,
        /** DOCUMENT | DELIVERY */
        public string $tarihTuru,
        public CarbonImmutable $baslangic,
        public CarbonImmutable $bitis,
        public array $faturalar,
        /** İlk sayfadaki `totalElements` */
        public int $beklenenAdet,
        public int $okunanSayfa,
        public array $hataliKayitlar,
        public ?string $eksikNedeni,
        public CarbonImmutable $basladi,
        public CarbonImmutable $bitti,
    ) {}

    public function tam(): bool
    {
        return $this->eksikNedeni === null;
    }
}
