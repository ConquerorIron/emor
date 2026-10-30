<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\KesintiYuklemesi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Kesinti icmali içe aktarımı (önizleme ve kayıt aynı girdiyle): Excel
 * dosyası YA DA Excel'den kopyalanıp yapıştırılan metin. Her icmal yetki
 * iznini verir.
 */
abstract class IcmalIceAktarRequest extends FormRequest
{
    /** İçe aktarımı açan izin (ör. isgucu_kesintileri.guncelle) */
    abstract protected function izin(): string;

    public function authorize(): bool
    {
        return $this->user()?->can($this->izin()) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'dosya' => ['required_without:metin', 'prohibits:metin', 'file', 'extensions:xlsx', 'max:20480'],
            // ~2.400 satırlık İşgücü yapıştırması ~500 KB
            'metin' => ['required_without:dosya', 'string', 'max:10000000'],
        ];
    }

    public function dosya(): ?UploadedFile
    {
        $dosya = $this->file('dosya');

        return $dosya instanceof UploadedFile ? $dosya : null;
    }

    public function kaynak(): string
    {
        return $this->dosya() !== null ? KesintiYuklemesi::KAYNAK_EXCEL : KesintiYuklemesi::KAYNAK_YAPISTIRMA;
    }

    public function dosyaAdi(): ?string
    {
        $ad = $this->dosya()?->getClientOriginalName();

        return $ad === null ? null : mb_substr($ad, 0, 255);
    }
}
