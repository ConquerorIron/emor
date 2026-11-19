<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;

/**
 * Bir sağlayıcının fatura listesini ortak biçimde okuyan kaynak (İzibiz,
 * Protel). Senkron servisi tanımın sağlayıcısına göre seçer.
 */
interface FaturaKaynagi
{
    /** Belge tarihine göre okuma */
    public const TARIH_BELGE = 'DOCUMENT';

    /** Entegratöre ulaşma tarihine göre okuma (artımlı senkron) */
    public const TARIH_ULASMA = 'DELIVERY';

    /**
     * HTTP hataları istisna olarak iletilir (EntegratorHatasi); hiçbir zaman
     * boş liste gibi görünmez.
     */
    public function oku(
        EntegratorBaglanti $tanim,
        FaturaYonu $yon,
        CarbonImmutable $baslangic,
        CarbonImmutable $bitis,
        string $tarihTuru = self::TARIH_BELGE,
    ): FaturaOkumaSonucu;
}
