<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yedekleme denetim kaydı: kim, ne, ne zaman, sonuç (README §4.9). Salt
 * eklemeli — yalnız `created_at` vardır. Aynı kayıt günlük dosyasına da
 * yazılır; veritabanı geri yüklenince buradaki geçmiş de eskir (§7.3).
 *
 * @property int $id
 * @property int|null $kullanici_id Boşsa sistem
 * @property string $islem
 * @property int|null $yedek_id
 * @property int|null $geri_yukleme_id
 * @property string $sonuc
 * @property int|null $sure_ms
 * @property array<string, mixed>|null $ayrinti
 * @property string|null $hata_mesaji
 * @property CarbonImmutable $created_at
 */
final class YedekIslemKaydi extends Model
{
    public const SONUC_BASARILI = 'basarili';

    public const SONUC_BASARISIZ = 'basarisiz';

    public const UPDATED_AT = null;

    protected $table = 'yedek_islem_kayitlari';

    /** Yalnız uygulama kodu doldurur (istekten toplu atama yok) */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function kullanici(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kullanici_id');
    }

    /**
     * @return BelongsTo<Yedek, $this>
     */
    public function yedek(): BelongsTo
    {
        return $this->belongsTo(Yedek::class, 'yedek_id');
    }

    /**
     * @return BelongsTo<GeriYukleme, $this>
     */
    public function geriYukleme(): BelongsTo
    {
        return $this->belongsTo(GeriYukleme::class, 'geri_yukleme_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sure_ms' => 'integer',
            'ayrinti' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
