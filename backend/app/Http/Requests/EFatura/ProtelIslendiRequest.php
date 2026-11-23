<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Protel faturasını "İşlendi" işaretleme / işareti kaldırma. Yetki rota
 * middleware'indedir (`can:protel_faturalari.isle`).
 */
final class ProtelIslendiRequest extends FormRequest
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
            'islendi' => ['required', 'boolean'],
        ];
    }
}
