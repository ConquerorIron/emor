<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\IceAktarim\StatikListeGirdisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Statik kaynağın listesi (EK-06): "Kontrol Et" ve "Kaydet" aynı girdiyle
 * gelir — Excel dosyası YA DA Excel'den kopyalanıp yapıştırılan metin
 * (Kesinti içe aktarımıyla aynı iki yol). İki türlü tanımda (e-Arşiv / e-SMM)
 * yapıştırma, tablonun kolonlarını belirleyen belge türüyle gelir. Yetki rota
 * middleware'indedir (`can:logo_faturalari.ice_aktar`, …). İçerik
 * PhpSpreadsheet ile açılır; açılamayan dosya önizlemede `dosya_okunamadi` olur.
 */
final class EFaturaIceAktarRequest extends FormRequest
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
        $ikiTurlu = EntegratorBaglantiServisi::rotaninSaglayicisi($this) === EntegratorBaglanti::SAGLAYICI_EARSIV;

        return [
            'dosya' => ['required_without:metin', 'prohibits:metin', 'file', 'extensions:xlsx', 'max:10240'],
            // 5.000 satırlık Logo yapıştırması ~2 MB
            'metin' => ['required_without:dosya', 'string', 'max:10000000'],
            'belge_turu' => $ikiTurlu
                ? ['required_with:metin', 'prohibits:dosya', Rule::in(['fatura', 'makbuz'])]
                : ['prohibited'],
        ];
    }

    public function girdi(): StatikListeGirdisi
    {
        $dosya = $this->file('dosya');

        return $dosya !== null
            ? StatikListeGirdisi::dosya((string) $dosya->getRealPath())
            : StatikListeGirdisi::metin($this->string('metin')->toString(), $this->input('belge_turu'));
    }

    /** Partinin adı: dosya adı ya da "Kopyala-yapıştır" */
    public function dosyaAdi(): string
    {
        $dosya = $this->file('dosya');

        return $dosya !== null ? (string) $dosya->getClientOriginalName() : __('efatura.yapistirma_partisi');
    }
}
