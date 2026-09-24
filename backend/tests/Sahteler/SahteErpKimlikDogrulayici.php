<?php

declare(strict_types=1);

namespace Tests\Sahteler;

use App\Services\ErpKimlikDogrulayici;
use RuntimeException;

/**
 * Testlerde MSSQL yerine bağlanan ERP kimlik doğrulayıcısı.
 * `erpKullanicilari` null ise ERP'ye ulaşılamıyormuş gibi davranır;
 * `dogrulanan` doluysa `dogrula` onu döner (şifre denetlenmez).
 */
final class SahteErpKimlikDogrulayici implements ErpKimlikDogrulayici
{
    /**
     * @param  list<array{erp_kullanici_id: int, kullanici_adi: string, ad: string, sistem_yoneticisi: bool}>|null  $erpKullanicilari
     * @param  array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null  $dogrulanan
     */
    public function __construct(
        public ?array $erpKullanicilari = [],
        public ?array $dogrulanan = null,
    ) {}

    public function yapilandirildi(): bool
    {
        return $this->erpKullanicilari !== null;
    }

    public function dogrula(string $kullaniciAdi, string $sifre): ?array
    {
        return $this->dogrulanan;
    }

    public function kullanicilar(): array
    {
        return $this->erpKullanicilari ?? throw new RuntimeException('ERP erişilemiyor');
    }
}
