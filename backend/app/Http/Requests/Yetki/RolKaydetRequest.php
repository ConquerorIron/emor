<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Models\Rol;
use App\Yetki\Izin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RolKaydetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sistem-yonetimi') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rol = $this->route('rol');

        return [
            'ad' => ['required', 'string', 'max:64', Rule::unique('roller', 'ad')->ignore($rol instanceof Rol ? $rol->id : null)],
            'aciklama' => ['nullable', 'string', 'max:255'],
            // Boş dizi geçerli: izinsiz rol (henüz yetki verilmemiş)
            'izinler' => ['present', 'array'],
            'izinler.*' => ['string', Rule::in(Izin::degerler())],
        ];
    }
}
