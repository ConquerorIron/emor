<?php

declare(strict_types=1);

/**
 * scripts/gecici-gunluk-yedek.sh için .env okuyucu (BY-001).
 *
 * .env'i kabukta elle ayrıştırmak yerine uygulamanın kendi ayrıştırıcısını
 * (vlucas/phpdotenv) kullanır: tırnak, kaçış ve satır sonu yorumu kuralları
 * uygulamanın gördüğüyle aynıdır. Ardından Laravel'in env() dönüşümünün metin
 * değerleri etkileyen kısmı uygulanır: "empty" / "null" (parantezli hâlleriyle)
 * boş değer olur, değeri saran artık tırnaklar atılır.
 *
 * Kullanım: php gecici-env-oku.php <vendor/autoload.php> <.env> ANAHTAR [ANAHTAR…]
 * Çıktı:    istenen sırayla, her değerin sonunda bir NUL baytı (kabuk bunu
 *           eval / source etmeden okur). Tanımsız anahtar boş değer verir.
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
    $deger = (string) ($degerler[$anahtar] ?? '');

    if (in_array(strtolower($deger), ['empty', '(empty)', 'null', '(null)'], true)) {
        $deger = '';
    } elseif (preg_match('/\A([\'"])(.*)\1\z/s', $deger, $eslesme) === 1) {
        $deger = $eslesme[2];
    }

    if (str_contains($deger, "\0")) {
        $durdur("{$anahtar} değeri NUL baytı içeriyor.");
    }

    echo $deger."\0";
}
