<?php

declare(strict_types=1);

namespace Tests\Sahteler;

use App\Services\ErpKimlikDogrulayici;
use Illuminate\Validation\ValidationException;

/**
 * Testlerde MSSQL yerine bağlanan ERP kimlik doğrulayıcısı.
 * `erpKullanicilari` null ise ERP'ye ulaşılamıyormuş gibi davranır.
 */
final class SahteErpKimlikDogrulayici implements ErpKimlikDogrulayici
{
    /**
     * @param  list<string>|null  $erpKullanicilari
     */
    public function __construct(
        public ?array $erpKullanicilari = [],
    ) {}

    public function yapilandirildi(): bool
    {
        return $this->erpKullanicilari !== null;
    }

    public function dogrula(string $kullaniciAdi, string $sifre): ?array
    {
        return null;
    }

    public function kullaniciVarMi(string $kullaniciAdi): bool
    {
        if ($this->erpKullanicilari === null) {
            throw ValidationException::withMessages([
                'kullanici_adi' => __('hata.erp_kullanici_denetlenemedi'),
            ]);
        }

        // ERP collation'ı büyük/küçük harf duyarsız
        return in_array(mb_strtolower($kullaniciAdi), array_map('mb_strtolower', $this->erpKullanicilari), true);
    }
}
