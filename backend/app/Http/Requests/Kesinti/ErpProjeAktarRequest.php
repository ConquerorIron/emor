<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ERP'den proje aktarımı: yalnız seçilen GUID'ler gelir; kod ve ad sunucuda
 * ERP'den yeniden okunur (istemcinin gönderdiği değerlere güvenilmez).
 */
final class ErpProjeAktarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('projeler.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'guidler' => ['required', 'array', 'list', 'max:5000'],
            'guidler.*' => ['required', 'string', 'uuid'],
        ];
    }
}
