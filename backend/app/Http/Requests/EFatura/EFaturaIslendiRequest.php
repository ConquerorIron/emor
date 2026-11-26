<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "SAP (Manuel İşle)": faturayı işlendi işaretleme / işareti kaldırma — bütün
 * gelen fatura ekranları ve Tüm Faturalar. Yetki rota middleware'indedir
 * (`can:efatura.isle`, `can:protel_faturalari.isle`, `can:tum_faturalar.isle`, …).
 */
final class EFaturaIslendiRequest extends FormRequest
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
