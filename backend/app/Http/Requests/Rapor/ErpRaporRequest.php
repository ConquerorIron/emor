<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ERP raporlarının ortak parametreleri (proc girdileri: ErpRaporKaynagi).
 * Yetki rota middleware'indedir (`can:<rapor>.goruntule`).
 */
class ErpRaporRequest extends FormRequest
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
            'ilk_tarih' => ['required', 'date_format:Y-m-d'],
            'son_tarih' => ['required', 'date_format:Y-m-d', 'after_or_equal:ilk_tarih'],
            'proje_kodu' => ['nullable', 'string', 'max:100'],
            'firma_adi' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Tarih aralığının en çok kaç gün olabileceği; null = sınırsız. ERP
     * raporlarını proc kendisi sınırlar; uygulamanın tablolarını okuyan rapor
     * (Gelen Fatura Raporu) e-Fatura listeleriyle aynı sınırı koyar.
     */
    protected function azamiGun(): ?int
    {
        return null;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $azami = $this->azamiGun();
                if ($azami === null || $validator->errors()->isNotEmpty()) {
                    return;
                }

                $gun = CarbonImmutable::parse($this->string('ilk_tarih')->value())
                    ->diffInDays(CarbonImmutable::parse($this->string('son_tarih')->value()));

                // EFaturaListeRequest::after() ile aynı kural ve mesaj
                if ($gun + 1 > $azami) {
                    $validator->errors()->add('son_tarih', __('hata.efatura_aralik_cok_genis', ['gun' => $azami]));
                }
            },
        ];
    }

    /**
     * Boş proje / firma proc'a NULL gider (= hepsi).
     *
     * @return array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}
     */
    public function parametreler(): array
    {
        return [
            'ilk_tarih' => $this->string('ilk_tarih')->value(),
            'son_tarih' => $this->string('son_tarih')->value(),
            'proje_kodu' => $this->filled('proje_kodu') ? $this->string('proje_kodu')->trim()->value() : null,
            'firma_adi' => $this->filled('firma_adi') ? $this->string('firma_adi')->trim()->value() : null,
        ];
    }
}
