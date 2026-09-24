<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use RuntimeException;

/**
 * Entegratör çağrısı hatası. `kod` API hata sözleşmesindeki makine okunur
 * koddur (bootstrap/app.php render eder, frontend api/errors.ts çevirir).
 * Mesaj ve bağlam sır içermez: token, şifre ve yanıt gövdesi taşınmaz.
 */
final class EntegratorHatasi extends RuntimeException
{
    public const ERISILEMEDI = 'ENTEGRATOR_ERISILEMEDI';

    public const KIMLIK_HATALI = 'ENTEGRATOR_KIMLIK_HATALI';

    public const YANIT_GECERSIZ = 'ENTEGRATOR_YANIT_GECERSIZ';

    public const HATA = 'ENTEGRATOR_HATA';

    public const AKTIF_YOK = 'ENTEGRATOR_AKTIF_YOK';

    private function __construct(
        public readonly string $kod,
        string $mesaj,
        public readonly int $httpDurumu,
        public readonly ?string $saglayiciKodu = null,
        public readonly ?int $saglayiciHttpDurumu = null,
    ) {
        parent::__construct($mesaj);
    }

    public static function erisilemedi(?int $saglayiciHttpDurumu = null): self
    {
        return new self(self::ERISILEMEDI, __('hata.entegrator_erisilemedi'), 502, saglayiciHttpDurumu: $saglayiciHttpDurumu);
    }

    public static function kimlikHatali(?string $saglayiciKodu = null): self
    {
        return new self(self::KIMLIK_HATALI, __('hata.entegrator_kimlik_hatali'), 422, $saglayiciKodu);
    }

    public static function yanitGecersiz(): self
    {
        return new self(self::YANIT_GECERSIZ, __('hata.entegrator_yanit_gecersiz'), 502);
    }

    public static function saglayiciHatasi(string $saglayiciKodu, ?int $saglayiciHttpDurumu = null): self
    {
        return new self(self::HATA, __('hata.entegrator_hata', ['kod' => $saglayiciKodu]), 502, $saglayiciKodu, $saglayiciHttpDurumu);
    }

    public static function aktifYok(): self
    {
        return new self(self::AKTIF_YOK, __('hata.entegrator_aktif_yok'), 422);
    }

    /**
     * Kullanıcı kaynaklı durumlar (hatalı şifre, seçilmemiş ortam) raporlanmaz;
     * true dönmek varsayılan raporlamayı bastırır. Diğerleri loga düşer.
     */
    public function report(): bool
    {
        return in_array($this->kod, [self::KIMLIK_HATALI, self::AKTIF_YOK], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'kod' => $this->kod,
            'saglayici_kodu' => $this->saglayiciKodu,
            'saglayici_http_durumu' => $this->saglayiciHttpDurumu,
        ], fn (mixed $deger): bool => $deger !== null);
    }
}
