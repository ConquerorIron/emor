<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Models\Rol;
use App\Yetki\Izin;
use App\Yetki\YetkiSiniri;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class RolKaydetRequest extends FormRequest
{
    /** Yönetici olmayan, kendinden yetkili bir rolü düzenleyemez (YetkiSiniri). */
    public function authorize(): bool
    {
        $kullanici = $this->user();
        $rol = $this->route('rol');

        return $kullanici !== null
            && $kullanici->can('roller.guncelle')
            && (! $rol instanceof Rol || YetkiSiniri::asanRolIzinleri($kullanici, [$rol->id]) === []);
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

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var list<string> $izinler */
                $izinler = $this->input('izinler');

                if (YetkiSiniri::asanIzinler($this->user(), $izinler) !== []) {
                    $validator->errors()->add('izinler', __('hata.izin_verme_siniri'));
                }
            },
        ];
    }
}
