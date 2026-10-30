<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\IcmalSorgusu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kesinti icmali listesi: filtre, sıralama, sayfa. Yetki rota middleware'indedir
 * (`can:<icmal>_kesintileri.goruntule`); sıralama anahtarları icmalin sorgusundan.
 */
abstract class IcmalListeRequest extends FormRequest
{
    /**
     * @return class-string<IcmalSorgusu>
     */
    abstract protected function sorgu(): string;

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
            'ara' => ['nullable', 'string', 'max:100'],
            'firma_id' => ['nullable', 'integer'],
            'proje_id' => ['nullable', 'integer'],
            'yukleme_id' => ['nullable', 'integer'],
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('baslangic'), ['after_or_equal:baslangic'])],
            'sirala' => ['nullable', Rule::in(($this->sorgu())::siralamalar())],
            'yon' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'sayfa_boyutu' => ['nullable', 'integer', Rule::in([0, 25, 50, 100, 200])],
        ];
    }

    /**
     * @return array{ara?: string|null, firma_id?: int|string|null, proje_id?: int|string|null, yukleme_id?: int|string|null, baslangic?: string|null, bitis?: string|null}
     */
    public function filtre(): array
    {
        /** @var array{ara?: string|null, firma_id?: int|string|null, proje_id?: int|string|null, yukleme_id?: int|string|null, baslangic?: string|null, bitis?: string|null} */
        return $this->safe()->only(['ara', 'firma_id', 'proje_id', 'yukleme_id', 'baslangic', 'bitis']);
    }

    public function sayfaBoyutu(): int
    {
        // Varsayılan frontend'deki VARSAYILAN_BOYUT ile aynı (utils/sayfaBoyutu.ts); 0 = "Hepsi"
        $boyut = $this->integer('sayfa_boyutu', 50);

        return $boyut === 0 ? 1000 : $boyut;
    }
}
