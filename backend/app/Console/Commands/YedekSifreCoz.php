<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\SifrelemeHatasi;
use App\Services\Yedekleme\YedekDenetimi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Sunucu dışından indirilen şifreli kopyayı (ya da kurtarma dosyasını)
 * çözer (README §4.7, §9; BY-019, kurtarma.md). Özel anahtar o anda dosya
 * olarak verilir; sunucuda saklanmaz, uygulama klasörlerine kopyalanmaz.
 * Parola komut satırında verilmez, yalnız dosyasının yolu (D2). Kesilirse
 * (Ctrl-C, SIGTERM) özel anahtarın alındığı geçici anahtarlık silinir.
 */
#[Signature('yedek:sifre-coz
    {sifreli : İndirilen şifreli dosya (.gpg)}
    {cikis : Çözülmüş dosyanın yazılacağı yol}
    {--anahtar= : Özel anahtar dosyası (ASCII zırhlı ya da ikili dışa aktarım)}
    {--parola-dosyasi= : Anahtar parolalıysa parolanın bulunduğu dosya (izin 0600)}')]
#[Description('Sunucu dışı şifreli yedek kopyasını verilen özel anahtarla çözer')]
final class YedekSifreCoz extends Command
{
    public function handle(KopyaSifreleyici $sifreleyici, YedekDenetimi $denetim): int
    {
        $anahtar = $this->option('anahtar');
        $parola = $this->option('parola-dosyasi');

        if (! is_string($anahtar) || $anahtar === '') {
            $this->error('--anahtar ile özel anahtar dosyasını verin.');

            return self::FAILURE;
        }

        $sifreli = (string) $this->argument('sifreli');
        $cikis = (string) $this->argument('cikis');

        // Ctrl-C / SIGTERM'de `finally` çalışmaz: içe alınmış özel anahtarlı geçici anahtarlık silinip çıkılır (YF-28)
        // Sinyal sabitleri pcntl ister: liste ancak sinyaller kullanılabilirken üretilir
        $this->trap(fn (): array => [SIGINT, SIGTERM], function (int $sinyal) use ($sifreleyici): never {
            $sifreleyici->acikAnahtarliklariSil();

            exit(128 + $sinyal);
        });

        try {
            $sifreleyici->coz($sifreli, $cikis, $anahtar, is_string($parola) && $parola !== '' ? $parola : null);
        } catch (SifrelemeHatasi $hata) {
            $denetim->kaydet('sifre_coz', IslemSonucu::Basarisiz, ayrinti: ['dosya' => basename($sifreli), 'kod' => $hata->kod], hata: $hata->getMessage());
            $this->error("{$hata->kod}: {$hata->getMessage()}");

            return self::FAILURE;
        }

        $denetim->kaydet('sifre_coz', IslemSonucu::Basarili, ayrinti: ['dosya' => basename($sifreli)]);
        $this->info("Çözüldü: {$cikis}");
        $this->line('Özeti manifestteki sha256 ile karşılaştırın: sha256sum '.escapeshellarg($cikis));

        return self::SUCCESS;
    }
}
