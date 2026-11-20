<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Bir manifestin okunma sonucu. Güvenilmeyen (imzasız, imzası tutmayan,
 * bozuk, eksik) manifest istisna değil sonuçtur: tarama tek bir bozuk dosya
 * yüzünden durmaz (README §4.5, BY-006 / BY-011).
 */
final readonly class ManifestOkumaSonucu
{
    public const NEDEN_YOK = 'manifest_yok';

    public const NEDEN_OKUNAMADI = 'okunamadi';

    public const NEDEN_JSON = 'json_gecersiz';

    public const NEDEN_IMZA_YOK = 'imza_yok';

    public const NEDEN_IMZA_TUTMUYOR = 'imza_tutmuyor';

    public const NEDEN_ANAHTAR_YOK = 'imza_anahtari_yok';

    public const NEDEN_SEMA = 'sema_gecersiz';

    private function __construct(
        public ?YedekManifesti $manifest,
        public ?string $neden,
    ) {}

    public static function guvenilir(YedekManifesti $manifest): self
    {
        return new self($manifest, null);
    }

    public static function guvenilmeyen(string $neden): self
    {
        return new self(null, $neden);
    }

    public function guvenilirMi(): bool
    {
        return $this->manifest !== null;
    }
}
