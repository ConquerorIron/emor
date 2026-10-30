<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ERP'den firma aktarımı: yalnız seçilen GUID'ler gelir; firma bilgileri
 * sunucuda ERP'den yeniden okunur (istemcinin gönderdiği değerlere güvenilmez).
 */
final class ErpFirmaAktarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('firmalar.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Canlıda ~1.700 taşeron var; tümü tek seferde seçilebilir
            'guidler' => ['required', 'array', 'list', 'max:20000'],
            'guidler.*' => ['required', 'string', 'uuid'],
        ];
    }
}
