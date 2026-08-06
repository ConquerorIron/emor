<?php

declare(strict_types=1);

namespace App\Ekranlar;

use Illuminate\Validation\ValidationException;

/**
 * Tasarlanabilir ekranların kayıt defteri. Yeni ekran eklemek için buraya
 * katalog sınıfını yazmak yeterli.
 */
final class EkranKataloglari
{
    /** @var array<string, EkranKatalogu>|null */
    private static ?array $onbellek = null;

    /**
     * @return array<string, EkranKatalogu> ekran anahtarı => katalog
     */
    public static function tumu(): array
    {
        if (self::$onbellek === null) {
            $kataloglar = [new SatinalmaTalebiKatalogu];

            self::$onbellek = [];
            foreach ($kataloglar as $katalog) {
                self::$onbellek[$katalog->anahtar()] = $katalog;
            }
        }

        return self::$onbellek;
    }

    public static function bul(string $ekranAnahtari): EkranKatalogu
    {
        $katalog = self::tumu()[$ekranAnahtari] ?? null;

        if ($katalog === null) {
            throw ValidationException::withMessages([
                'ekran' => __('hata.ekran_tanimsiz'),
            ]);
        }

        return $katalog;
    }
}
