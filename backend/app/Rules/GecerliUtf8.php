<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Metin geçerli UTF-8 olmalı. Laravel'in `string` kuralı bozuk baytı geçirir;
 * değer sonra JSON'a (istek dosyası, denetim kaydı) yazılırken düşer ve 422
 * yerine 500 olur. Okuyan (IstekKutusu) aynı denetimi yapar: yazan da ondan
 * geçsin (G10, YF-32).
 */
final class GecerliUtf8 implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            $fail(__('validation.string', ['attribute' => $attribute]));
        }
    }
}
