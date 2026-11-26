<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * Bir faturanın UBL'inden okunan rapor verileri (UblOkuyucu üretir). Tutarlar
 * belge para biriminde, ondalık metin (float yok); kolon sınırları içindedir.
 */
final readonly class UblVerisi
{
    /**
     * @param  string  $ettn  küçük harf
     * @param  list<array{tur: string, vergi_kodu: string|null, vergi_adi: string|null, oran: string|null, matrah: string|null, tutar: string, istisna_kodu: string|null, istisna_adi: string|null}>  $kalemler
     * @param  string|null  $istisnaKodlari  belgedeki bütün istisna kodları, virgülle
     */
    public function __construct(
        public string $ettn,
        public ?string $satirToplami,
        public ?string $indirimTutari,
        public ?string $vergiHaricTutar,
        public ?string $vergiDahilTutar,
        public ?string $kur,
        public ?string $saticiVergiDairesi,
        public array $kalemler,
        public ?string $istisnaKodlari,
    ) {}
}
