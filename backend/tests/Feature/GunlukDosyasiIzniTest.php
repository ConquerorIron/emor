<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `storage/logs` iki hesapça yazılır: web (www-data) ve yedekleme servisi
 * (emor-yedek, UMask 0027 — BY-003). Günün dosyasını servis açarsa umask
 * yüzünden 0640 olur ve web o gün günlüğe yazamaz; tersinde servis yazamaz.
 * Dosya kanalları izni umask'tan bağımsız 0664 verir (Fable incelemesi F2,
 * Dikkat_Edilecekler D7).
 */
final class GunlukDosyasiIzniTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function dosyaKanallari(): array
    {
        return [
            'single' => ['single'],
            'daily' => ['daily'],
            'denetim (günlük, bir yıl)' => ['denetim'],
        ];
    }

    #[DataProvider('dosyaKanallari')]
    public function test_gunluk_dosyasini_kisitli_umaskla_acan_hesap_da_grubun_yazabilecegi_izinle_acar(string $kanal): void
    {
        $klasor = sys_get_temp_dir().'/gunluk-izni-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($klasor);
        $oncekiUmask = umask(0027);

        try {
            Log::build([...config("logging.channels.{$kanal}"), 'path' => "{$klasor}/deneme.log"])->info('deneme');
        } finally {
            umask($oncekiUmask);
        }

        $dosyalar = glob("{$klasor}/deneme*.log") ?: [];
        $izin = $dosyalar === [] ? null : substr(sprintf('%o', fileperms($dosyalar[0])), -3);
        File::deleteDirectory($klasor);

        $this->assertCount(1, $dosyalar);
        $this->assertSame('664', $izin);
    }
}
