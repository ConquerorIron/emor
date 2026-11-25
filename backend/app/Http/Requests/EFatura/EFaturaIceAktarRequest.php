<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Statik kaynağın Excel listesi (EK-06): "Kontrol Et" ve "Kaydet" aynı
 * dosyayla gelir. Yetki rota middleware'indedir (`can:logo_faturalari.ice_aktar`, …).
 * İçerik PhpSpreadsheet ile açılır; açılamayan dosya önizlemede
 * `dosya_okunamadi` olur (Kesinti içe aktarımıyla aynı kalıp).
 */
final class EFaturaIceAktarRequest extends FormRequest
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
            'dosya' => ['required', 'file', 'extensions:xlsx', 'max:20480'],
        ];
    }

    public function yol(): string
    {
        return (string) $this->file('dosya')?->getRealPath();
    }

    public function dosyaAdi(): string
    {
        return (string) $this->file('dosya')?->getClientOriginalName();
    }
}
