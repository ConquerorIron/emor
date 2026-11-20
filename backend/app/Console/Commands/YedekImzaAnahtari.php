<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\YedekImzasi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Manifest imza anahtarını üretir (README §5.3). Var olanın üstüne yazmaz:
 * anahtarı değiştirmek bütün eski yedekleri "güvenilmeyen" yapardı.
 * Anahtar ekrana yazılmaz; kurtarma paketinde saklanması gerekir (README §9).
 */
#[Signature('yedek:imza-anahtari')]
#[Description('Yedek manifest imza anahtarını (yoksa) üretir')]
final class YedekImzaAnahtari extends Command
{
    public function handle(YedekImzasi $imza, YedeklemeYapilandirmasi $yapilandirma): int
    {
        try {
            $uretildi = $imza->anahtarUret();
        } catch (RuntimeException $hata) {
            $this->error($hata->getMessage());

            return self::FAILURE;
        }

        $yol = $yapilandirma->imzaAnahtariYolu();
        $this->info($uretildi
            ? "İmza anahtarı üretildi: {$yol} (izin 0600). Kurtarma paketine kopyalayın."
            : "İmza anahtarı zaten var; değiştirilmedi: {$yol}");

        return self::SUCCESS;
    }
}
