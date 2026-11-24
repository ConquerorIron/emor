<?php

declare(strict_types=1);
use Illuminate\Support\Env;

/**
 * Kabuk betikleri için .env okuyucu (BY-001'de artık kaldırılmış
 * gecici-gunluk-yedek.sh için yazıldı; bugün scripts/sunucu-yedekleme-kurulumu.sh
 * kullanır: yalnız DB_HOST, DB_PORT, DB_USERNAME).
 *
 * .env'i kabukta elle ayrıştırmak yerine uygulamanın kendi kodunu kullanır:
 *  1. vlucas/phpdotenv dosyayı ayrıştırır (tırnak, kaçış, satır sonu yorumu);
 *  2. Laravel'in env() dönüşümü (Illuminate\Support\Env) değeri uygulamanın
 *     gördüğü hâle getirir: true / false / empty / null (parantezli ve
 *     büyük-küçük harfli biçimleriyle) ve saran artık tırnaklar.
 * Dönüşüm burada yeniden yazılmaz; çerçevenin fonksiyonu çağrılır (elle yazılan
 * ilk sürüm true / false'u atlamıştı — BY-001-R3).
 *
 * Veritabanı sürücüsüne metin olarak giden karşılık: true → "1", false ve
 * null → "" (PHP'nin metne çevirme kuralı; uygulamada PDO'ya böyle ulaşır).
 *
 * Değerler yalnız bu sürecin belleğindeki $_SERVER / $_ENV dizilerine konur;
 * putenv çağrılmaz, sürecin ortam değişkenlerine yazılmaz.
 *
 * Kullanım: php gecici-env-oku.php <vendor/autoload.php> <.env> ANAHTAR [ANAHTAR…]
 * Çıktı:    istenen sırayla, her değerin sonunda bir NUL baytı (kabuk bunu
 *           eval / source etmeden okur). Tanımsız anahtar boş değer verir:
 *           yalnız dosyaya bakılır, sürecin ortamındaki aynı adlı değişken
 *           okunmaz (Env::get getenv'e de bakar — Fable incelemesi F8).
 * Hata:     2 ile çıkar; stderr'e yalnız neden yazılır, hiçbir değer yazılmaz.
 */
$durdur = static function (string $neden): never {
    fwrite(STDERR, $neden.PHP_EOL);
    exit(2);
};

if ($argc < 4) {
    $durdur('Kullanım: php gecici-env-oku.php <autoload.php> <.env> ANAHTAR…');
}

[, $autoload, $envDosyasi] = $argv;
$anahtarlar = array_slice($argv, 3);

if (! is_file($autoload)) {
    $durdur("Bağımlılıklar kurulu değil: {$autoload}");
}

$icerik = @file_get_contents($envDosyasi);

if ($icerik === false) {
    $durdur("Okunamadı: {$envDosyasi}");
}

require $autoload;

try {
    $degerler = Dotenv\Dotenv::parse($icerik);
} catch (Throwable $hata) {
    // Ayrıştırıcının mesajı hatalı satırı (dolayısıyla bir sırrı) içerebilir: yalnız türü yazılır
    $durdur('.env ayrıştırılamadı ('.$hata::class.').');
}

foreach ($anahtarlar as $anahtar) {
    if (! array_key_exists($anahtar, $degerler)) {
        echo "\0";

        continue;
    }

    // Dosyadaki değer, sürecin devraldığı aynı adlı ortam değişkeninin önüne
    // geçer: Env önce $_SERVER'a bakar. Boş (null) değer de konur; yoksa okuma
    // getenv'e düşerdi.
    $_SERVER[$anahtar] = $_ENV[$anahtar] = $degerler[$anahtar] ?? '';

    $laravelDegeri = Env::get($anahtar);
    $deger = is_bool($laravelDegeri) ? ($laravelDegeri ? '1' : '') : (string) ($laravelDegeri ?? '');

    if (str_contains($deger, "\0")) {
        $durdur("{$anahtar} değeri NUL baytı içeriyor.");
    }

    echo $deger."\0";
}
