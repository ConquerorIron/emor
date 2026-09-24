<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Models\User;
use App\Yetki\YetkiSiniri;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ad/e-posta/şifre yalnız lokal kullanıcıda değişir (servis denetler);
 * aktiflik ve roller her kullanıcıda.
 */
final class KullaniciGuncelleRequest extends FormRequest
{
    /** Sistem yöneticisi hesabını yalnız sistem yöneticisi değiştirir (YetkiSiniri). */
    public function authorize(): bool
    {
        $kullanici = $this->user();
        $hedef = $this->route('kullanici');

        return $kullanici !== null
            && $kullanici->can('kullanicilar.guncelle')
            && (! $hedef instanceof User || ! $hedef->sistem_yoneticisi || $kullanici->sistem_yoneticisi);
    }

    /**
     * Eklenen ya da çıkarılan roller, değiştirenin izinleri içinde kalmalı.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hedef = $this->route('kullanici');
                if ($validator->errors()->isNotEmpty() || ! $this->has('rol_idleri') || ! $hedef instanceof User) {
                    return;
                }

                /** @var list<int> $yeni */
                $yeni = array_map('intval', (array) $this->input('rol_idleri'));
                /** @var list<int> $mevcut */
                $mevcut = $hedef->roller()->pluck('roller.id')->all();
                $degisen = array_values([...array_diff($yeni, $mevcut), ...array_diff($mevcut, $yeni)]);

                if (YetkiSiniri::asanRolIzinleri($this->user(), $degisen) !== []) {
                    $validator->errors()->add('rol_idleri', __('hata.izin_verme_siniri'));
                }
            },
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $hedef = $this->route('kullanici');

        return [
            'ad' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($hedef instanceof User ? $hedef->id : null)],
            // Şifre serbest: uzunluk/karakter kuralı yok (kullanıcı isteği 2026-09-24)
            'sifre' => ['sometimes', 'nullable', 'string', 'max:255'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'rol_idleri' => ['sometimes', 'array'],
            'rol_idleri.*' => ['integer', 'distinct', 'exists:roller,id'],
        ];
    }
}
