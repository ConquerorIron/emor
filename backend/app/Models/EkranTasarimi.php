<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bir ekranın form düzeni. Ekran başına tek 'yayinda' ve tek 'taslak' sürüm
 * bulunur (kısmi unique indeks); yayınlanan eski sürümler 'arsiv' olur ve
 * geri alınabilir.
 *
 * @property int $id
 * @property string $ekran_anahtari
 * @property int $surum
 * @property string $durum
 * @property array<string, mixed> $duzen
 * @property string|null $notlar
 */
final class EkranTasarimi extends Model
{
    public const DURUM_TASLAK = 'taslak';

    public const DURUM_YAYINDA = 'yayinda';

    public const DURUM_ARSIV = 'arsiv';

    protected $table = 'ekran_tasarimlari';

    protected $fillable = [
        'ekran_anahtari',
        'surum',
        'durum',
        'duzen',
        'notlar',
        'olusturan_id',
        'yayinlayan_id',
        'yayin_zamani',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duzen' => 'array',
            'surum' => 'integer',
            'yayin_zamani' => 'datetime',
        ];
    }
}
