<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Faturayı listede gizleme / listeye geri alma. Yetki rota
 * middleware'indedir (`can:efatura.gizle`).
 */
final class EFaturaGizlemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'gizli' => ['required', 'boolean'],
        ];
    }
}
