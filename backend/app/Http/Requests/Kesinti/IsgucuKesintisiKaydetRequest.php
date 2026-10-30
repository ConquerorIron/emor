<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\MesaiTuru;
use App\Services\Kesinti\Vasif;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * İşgücü kesintisinin elle girilmesi / düzeltilmesi (tutar hesabı:
 * HesaplananTutarlar).
 */
final class IsgucuKesintisiKaydetRequest extends FormRequest
{
    use HesaplananTutarlar;

    public function authorize(): bool
    {
        return $this->user()?->can('isgucu_kesintileri.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $metin = fn (int $sinir, bool $zorunlu = false): array => [$zorunlu ? 'required' : 'nullable', 'string', "max:{$sinir}"];
        $sayi = fn (int $basamak, string $ust): array => ['required', 'numeric', "decimal:0,{$basamak}", 'min:0', "max:{$ust}"];

        return [
            'kesinti_turu_id' => ['required', 'integer', Rule::exists('kesinti_turleri', 'id')],
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'mahal' => $metin(200),
            'kaynak_firma' => $metin(200),
            'kaynak_fis_no' => $metin(50),
            'adi_soyadi' => $metin(200, true),
            'vasif' => ['required', Rule::enum(Vasif::class)],
            'mesai_turu' => ['required', Rule::enum(MesaiTuru::class)],
            'yansitma_turu' => ['required', Rule::enum(YansitmaTuru::class)],
            'tanim' => $metin(200),
            'gorev' => $metin(200),
            'departman' => $metin(200),
            'aktivite' => $metin(200),
            'miktar_saat' => $sayi(2, '99999999.99'),
            'miktar_yevmiye' => $sayi(2, '99999999.99'),
            'birim_fiyat' => $sayi(4, '99999999999999.9999'),
            'toplam_tutar' => $sayi(2, '9999999999999999.99'),
            'iptal_edilen_tutar' => $sayi(2, '9999999999999999.99'),
            'kesilen_tutar' => $sayi(2, '9999999999999999.99'),
        ];
    }
}
