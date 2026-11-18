<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\SiklikTuru;
use Carbon\CarbonImmutable;
use Database\Factories\YedekPlaniFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Yedek planı: ne zaman, nereye, ne kadar süre (README §4.2). Hedefler ve
 * hedef başına saklama kuralı `yedek_plan_hedefleri` ara tablosunda
 * (pivot: sakla_son_adet, sakla_gun). Kopyası olan plan silinmez (409);
 * kullanılmayan plan pasife alınır.
 *
 * @property int $id
 * @property string $ad
 * @property bool $aktif
 * @property SiklikTuru $siklik_turu
 * @property string $saat İstanbul saati, SS:DD
 * @property array<string, mixed> $siklik
 * @property CarbonImmutable|null $son_calisma
 * @property CarbonImmutable|null $sonraki_calisma
 */
final class YedekPlani extends Model
{
    /** @use HasFactory<YedekPlaniFactory> */
    use HasFactory;

    protected $table = 'yedek_planlari';

    protected $fillable = [
        'ad',
        'aktif',
        'siklik_turu',
        'saat',
        'siklik',
    ];

    /**
     * @return BelongsToMany<YedekHedefi, $this>
     */
    public function hedefler(): BelongsToMany
    {
        return $this->belongsToMany(YedekHedefi::class, 'yedek_plan_hedefleri', 'yedek_plani_id', 'yedek_hedefi_id')
            ->withPivot(['sakla_son_adet', 'sakla_gun'])
            ->withTimestamps();
    }

    /**
     * Planın tuttuğu kopyalar (kopya birden çok plana bağlı olabilir).
     *
     * @return BelongsToMany<YedekKopyasi, $this>
     */
    public function kopyalar(): BelongsToMany
    {
        return $this->belongsToMany(YedekKopyasi::class, 'yedek_kopya_planlari', 'yedek_plani_id', 'yedek_kopyasi_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'siklik_turu' => SiklikTuru::class,
            'siklik' => 'array',
            'son_calisma' => 'immutable_datetime',
            'sonraki_calisma' => 'immutable_datetime',
        ];
    }
}
