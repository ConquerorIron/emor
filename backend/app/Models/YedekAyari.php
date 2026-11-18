<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yedekleme ayarları — tek satır (MailAyari kalıbı): zamanlanmamış yedeklerin
 * saklama süreleri, hedef başına en az yedek sayısı, disk eşiği, bildirim
 * alıcıları (README §4.3, §4.8). Varsayılanlar migration'dadır.
 *
 * @property int $id
 * @property string $anahtar
 * @property int $yayin_oncesi_son_adet
 * @property int $yayin_oncesi_gun
 * @property int $elle_gun
 * @property int $geri_yukleme_oncesi_gun
 * @property int $enaz_adet
 * @property int $enaz_bos_alan_yuzde
 * @property list<string> $bildirim_alicilari
 */
final class YedekAyari extends Model
{
    public const ANAHTAR = 'varsayilan';

    protected $table = 'yedek_ayarlari';

    protected $fillable = [
        'anahtar',
        'yayin_oncesi_son_adet',
        'yayin_oncesi_gun',
        'elle_gun',
        'geri_yukleme_oncesi_gun',
        'enaz_adet',
        'enaz_bos_alan_yuzde',
        'bildirim_alicilari',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yayin_oncesi_son_adet' => 'integer',
            'yayin_oncesi_gun' => 'integer',
            'elle_gun' => 'integer',
            'geri_yukleme_oncesi_gun' => 'integer',
            'enaz_adet' => 'integer',
            'enaz_bos_alan_yuzde' => 'integer',
            'bildirim_alicilari' => 'array',
        ];
    }
}
