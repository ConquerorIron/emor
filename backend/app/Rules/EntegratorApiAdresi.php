<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\EntegratorBaglanti;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Entegratör API adresi: yalnız `https://alan-adı[:port]` (yol, sorgu,
 * kullanıcı bilgisi yok) ve alan adı config'teki izinli listede
 * (`entegrator.izibiz.izinli_alan_adlari`, alt alan adları dahil).
 * Kayıtlı şifre ve token bu adrese gönderildiği için serbest adres kabul edilmez.
 */
final class EntegratorApiAdresi implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || EntegratorBaglanti::adresNormallestir($value) === null) {
            $fail(__('hata.entegrator_api_adresi_gecersiz'));

            return;
        }

        $host = strtolower((string) parse_url(trim($value), PHP_URL_HOST));
        /** @var list<string> $izinliler */
        $izinliler = config('entegrator.izibiz.izinli_alan_adlari', []);

        foreach ($izinliler as $izinli) {
            $izinli = strtolower($izinli);
            if ($host === $izinli || str_ends_with($host, '.'.$izinli)) {
                return;
            }
        }

        $fail(__('hata.entegrator_api_adresi_izinsiz', ['alanlar' => implode(', ', $izinliler)]));
    }
}
