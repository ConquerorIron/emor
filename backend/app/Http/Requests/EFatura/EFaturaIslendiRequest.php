<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Faturayı "İşlendi" (SAP) işaretleme / işareti kaldırma — Protel ve statik
 * kaynak (Logo, e-Arşiv) ekranları. Yetki rota middleware'indedir
 * (`can:protel_faturalari.isle`, `can:logo_faturalari.isle`, …).
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
