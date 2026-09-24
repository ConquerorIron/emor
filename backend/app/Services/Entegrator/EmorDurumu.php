<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * eMOR: e-faturanın ERP'deki aşaması (kullanıcı açıklaması 2026-09-24).
 * Gelen fatura önce TOHOM_E_FATURA havuzuna alınır; muhasebe kabulü ve hesap
 * hareketleri için TOHOM_FATURA'da olmalıdır. Giden faturada havuz aşaması yok.
 */
enum EmorDurumu: string
{
    /** Muhasebeye kabul edilmiş (gelen: TOHOM_FATURA, giden: gönderilen listesi) */
    case Islendi = 'islendi';

    /** ERP almış (TOHOM_E_FATURA), henüz muhasebeleşmemiş — yalnız gelen */
    case Havuzda = 'havuzda';

    /** ERP'de karşılığı yok */
    case Yok = 'yok';

    /** Liste sıralaması: işlendi > havuzda > yok (kontrol edilmemiş en sonda) */
    public function sira(): int
    {
        return match ($this) {
            self::Islendi => 3,
            self::Havuzda => 2,
            self::Yok => 1,
        };
    }
}
