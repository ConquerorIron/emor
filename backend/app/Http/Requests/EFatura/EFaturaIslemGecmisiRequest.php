<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaIslemTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * İşlem Geçmişi ekranının süzgeci (EK-03). Yetki rota middleware'indedir
 * (`can:efatura_islem_gecmisi.goruntule`).
 */
final class EFaturaIslemGecmisiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'baslangic' => ['required', 'date_format:Y-m-d'],
            'bitis' => ['required', 'date_format:Y-m-d', 'after_or_equal:baslangic'],
            'kullanici_id' => ['nullable', 'integer'],
            'islem' => ['nullable', Rule::enum(EFaturaIslemTuru::class)],
            'saglayici' => ['nullable', Rule::in(EntegratorBaglanti::GELEN_SAGLAYICILARI)],
            'ara' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            // Ortak sayfa boyutu tercihi (frontend utils/sayfaBoyutu.ts); 0 = "Hepsi"
            'sayfa_boyutu' => ['nullable', 'integer', Rule::in([0, 25, 50, 100, 200])],
        ];
    }

    /** "Hepsi" (0) da sayfalıdır; üst sınır kötüye kullanımı keser (EFaturaListeRequest ile aynı). */
    public function sayfaBoyutu(): int
    {
        $boyut = $this->integer('sayfa_boyutu', 50);

        return $boyut === 0 ? 1000 : $boyut;
    }
}
