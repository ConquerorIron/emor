<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\ManifestOkumaSonucu;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Elle geri yüklemeden ÖNCE yedek dosyasını imzalı manifestine karşı
 * doğrular (kurtarma.md §4.6; BY-024-R2): manifestin imzası (imza anahtarıyla),
 * manifestin bu dosyaya ait olduğu, boyut ve SHA-256. Tarama ve ekrandaki
 * geri yüklemenin kullandığı kodla aynı (ManifestDeposu). Veritabanına
 * bağlanmaz, hiçbir dosyayı değiştirmez. Başarıda `createdb` için gereken
 * veritabanı özelliklerini yazar; başarısızlıkta geri yükleme yapılmaz.
 */
#[Signature('yedek:manifest-dogrula
    {dump : Çözülmüş yedek dosyası (erp_….dump)}
    {--manifest= : Manifest dosyası (boşsa dump ile aynı klasörde aynı adlı .json)}')]
#[Description('Yedek dosyasını imzalı manifestine karşı doğrular (imza, boyut, SHA-256)')]
final class YedekManifestDogrula extends Command
{
    public function handle(ManifestDeposu $depo): int
    {
        $dump = (string) $this->argument('dump');
        $secenek = $this->option('manifest');
        $manifestYolu = is_string($secenek) && $secenek !== '' ? $secenek : ManifestDeposu::jsonYolu($dump);

        $sonuc = $depo->oku($manifestYolu);
        $manifest = $sonuc->manifest;

        if ($manifest === null) {
            $this->error('GERI_YUKLEME_IMZA_GECERSIZ: '.self::neden((string) $sonuc->neden).' Geri yüklemeyin.');

            return self::FAILURE;
        }

        if ($manifest->dosyaAdi !== basename($dump)) {
            $this->error("GERI_YUKLEME_IMZA_GECERSIZ: manifest başka bir yedeğin ({$manifest->dosyaAdi}). Geri yüklemeyin.");

            return self::FAILURE;
        }

        if (! ManifestDeposu::dosyaTutuyorMu($manifest, $dump)) {
            $this->error('GERI_YUKLEME_OZET_TUTMUYOR: yedek dosyasının boyutu ya da SHA-256 özeti manifestle aynı değil (bozuk / eksik indirme ya da başka dosya). Geri yüklemeyin.');

            return self::FAILURE;
        }

        $ozellik = $manifest->veritabaniOzellikleri;
        $this->info('DOGRULANDI: manifest imzası geçerli; dosyanın boyutu ve SHA-256 özeti manifestle aynı.');
        $this->line("Yedek: {$manifest->dosyaAdi} · kimlik {$manifest->kimlik} · alınma {$manifest->alinmaZamani->toIso8601String()} · veritabanı {$manifest->veritabani}");
        $this->line('PostgreSQL: '.($manifest->pgSunucuSurumu ?? 'manifestte yok').' · sahip rol: '.($manifest->sahipRol ?? 'manifestte yok').' · APP_KEY parmak izi: '.($manifest->appKeyParmakIzi ?? 'manifestte yok'));
        $this->line($ozellik === null
            ? 'Veritabanı özellikleri manifestte yok: kodlama / sıralama canlıdaki değerlerle verilir (kurtarma.md §3).'
            : "createdb için: --encoding={$ozellik['kodlama']} --lc-collate={$ozellik['siralama']} --lc-ctype={$ozellik['karakter_turu']}"
                .($ozellik['ayarlar'] === [] ? '' : ' · veritabanı ayarları: '.implode(', ', $ozellik['ayarlar'])));

        return self::SUCCESS;
    }

    private static function neden(string $neden): string
    {
        return match ($neden) {
            ManifestOkumaSonucu::NEDEN_YOK => 'manifest dosyası yok.',
            ManifestOkumaSonucu::NEDEN_OKUNAMADI => 'manifest dosyası okunamadı.',
            ManifestOkumaSonucu::NEDEN_JSON, ManifestOkumaSonucu::NEDEN_SEMA => 'manifest bozuk.',
            ManifestOkumaSonucu::NEDEN_IMZA_YOK => 'manifest imzasız.',
            ManifestOkumaSonucu::NEDEN_IMZA_TUTMUYOR => 'manifest imzası tutmuyor (manifest değiştirilmiş ya da imza anahtarı yanlış).',
            ManifestOkumaSonucu::NEDEN_ANAHTAR_YOK => 'imza anahtarı yok (YEDEKLEME_IMZA_ANAHTARI).',
            default => "manifest güvenilmeyen ({$neden}).",
        };
    }
}
