<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use App\Services\Entegrator\EFaturaExcelAktarici;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EmorDurumu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * e-Fatura listesi ve Excel çıktısının ortak filtresi (EFAT-11). Yetki rota
 * middleware'indedir (`can:efatura.goruntule` / `can:efatura.disari_aktar`);
 * liste ve çıktı aynı kurallardan geçer, bu yüzden aynı sonucu verir.
 */
final class EFaturaListeRequest extends FormRequest
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
            'ara' => ['nullable', 'string', 'max:100'],
            'durum' => ['nullable', 'string', 'max:64'],
            'erp_okundu' => ['nullable', Rule::in(['evet', 'hayir', 'bilinmiyor'])],
            'emor' => ['nullable', Rule::in([...array_column(EmorDurumu::cases(), 'value'), 'bilinmiyor', 'islenmemis'])],
            'tip' => ['nullable', 'string', 'max:64'],
            'istisna_kodu' => ['nullable', 'string', 'max:50'],
            'istisnali' => ['nullable', Rule::in(['evet'])],
            // Gizlenen faturalar varsayılan listede yok; 'dahil' ile onlar da gelir
            'gizlenenler' => ['nullable', Rule::in(['dahil'])],
            'para_birimi' => ['nullable', 'string', 'size:3'],
            'sirala' => ['nullable', Rule::in(EFaturaSorgusu::SIRALAMALAR)],
            'yon' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'sayfa_boyutu' => ['nullable', 'integer', Rule::in([0, 25, 50, 100, 200])],
            // Excel: ekrandaki görünür kolonlar ve başlıkları, aynı sırada
            'kolonlar' => [Rule::requiredIf(fn (): bool => $this->routeIs('efatura.faturalar.excel')), 'array', 'max:'.count(EFaturaExcelAktarici::KOLONLAR)],
            'kolonlar.*' => ['string', 'distinct', Rule::in(array_keys(EFaturaExcelAktarici::KOLONLAR))],
            'basliklar' => ['required_with:kolonlar', 'array', 'size:'.count((array) $this->input('kolonlar', []))],
            'basliklar.*' => ['required', 'string', 'max:100'],
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

                $azami = (int) config('efatura.liste_azami_gun');
                $gun = CarbonImmutable::parse((string) $this->input('baslangic'))
                    ->diffInDays(CarbonImmutable::parse((string) $this->input('bitis')));

                if ($gun + 1 > $azami) {
                    $validator->errors()->add('bitis', __('hata.efatura_aralik_cok_genis', ['gun' => $azami]));
                }
            },
        ];
    }

    /**
     * @return array{baslangic: string, bitis: string, ara?: string|null, durum?: string|null, erp_okundu?: string|null, emor?: string|null, para_birimi?: string|null, tip?: string|null, istisna_kodu?: string|null, istisnali?: string|null, gizlenenler?: string|null}
     */
    public function filtre(): array
    {
        /** @var array{baslangic: string, bitis: string, ara?: string|null, durum?: string|null, erp_okundu?: string|null, emor?: string|null, para_birimi?: string|null, tip?: string|null, istisna_kodu?: string|null, istisnali?: string|null, gizlenenler?: string|null} */
        return $this->safe()->only(['baslangic', 'bitis', 'ara', 'durum', 'erp_okundu', 'emor', 'para_birimi', 'tip', 'istisna_kodu', 'istisnali', 'gizlenenler']);
    }

    /**
     * Excel kolonları: ekrandaki anahtar + başlık, sırasıyla.
     *
     * @return list<array{anahtar: string, baslik: string}>
     */
    public function excelKolonlari(): array
    {
        /** @var list<string> $anahtarlar */
        $anahtarlar = array_values((array) $this->validated('kolonlar', []));
        /** @var list<string> $basliklar */
        $basliklar = array_values((array) $this->validated('basliklar', []));

        return array_map(
            fn (string $anahtar, string $baslik): array => ['anahtar' => $anahtar, 'baslik' => $baslik],
            $anahtarlar,
            $basliklar,
        );
    }

    /** "Hepsi" (0) da sayfalıdır; üst sınır kötüye kullanımı keser. */
    public function sayfaBoyutu(): int
    {
        // Varsayılan frontend'deki VARSAYILAN_BOYUT ile aynı (utils/sayfaBoyutu.ts)
        $boyut = $this->integer('sayfa_boyutu', 50);

        return $boyut === 0 ? 1000 : $boyut;
    }
}
