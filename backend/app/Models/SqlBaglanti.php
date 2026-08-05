<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ERP MSSQL bağlantı tanımı — ortam ('test' | 'canli') başına tek satır.
 * Şifre encrypted cast ile saklanır ve serileştirmede gizlidir
 * (secret pattern — puantaj MailAyari::sifre ile aynı disiplin).
 *
 * Aktiflik globaldir: `aktif=true` olan tek satır, store proc'ların
 * çalıştırılacağı ortamı belirler (kısmi unique indeks garantiler).
 *
 * @property int $id
 * @property string $ortam
 * @property string $sunucu
 * @property int|null $port
 * @property string $veritabani
 * @property string $kullanici_adi
 * @property string $sifre
 * @property bool $aktif
 */
final class SqlBaglanti extends Model
{
    public const ORTAM_TEST = 'test';

    public const ORTAM_CANLI = 'canli';

    public const ORTAMLAR = [self::ORTAM_TEST, self::ORTAM_CANLI];

    protected $table = 'sql_baglantilari';

    /** @var array<string, mixed> */
    protected $attributes = [
        'aktif' => false,
    ];

    protected $fillable = [
        'ortam',
        'sunucu',
        'port',
        'veritabani',
        'kullanici_adi',
        'sifre',
        'aktif',
    ];

    /** @var list<string> */
    protected $hidden = ['sifre'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'sifre' => 'encrypted',
            'aktif' => 'boolean',
        ];
    }
}
