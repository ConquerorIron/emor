<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\IslemSonucu;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Yedekleme denetim kaydı: kim, ne, ne zaman, sonuç (README §4.9). Aynı
 * kayıt günlük dosyasına da yazılır; veritabanı geri yüklenince buradaki
 * geçmiş de eskir (§7.3).
 *
 * Salt eklemelidir: model güncellemeyi ve silmeyi reddeder (Dikkat_Edilecekler
 * K3). Toplu sorgu (`query()->update()`) model olaylarını atlar; kayıtlar
 * yalnız bu model üzerinden yazılır. Kullanıcı / yedek / geri yükleme
 * silinince bağ alanının boşalması (nullOnDelete) veritabanının işidir ve
 * kuralın dışındadır.
 *
 * @property int $id
 * @property int|null $kullanici_id Boşsa sistem
 * @property string $islem
 * @property int|null $yedek_id
 * @property int|null $geri_yukleme_id
 * @property IslemSonucu $sonuc
 * @property int|null $sure_ms
 * @property array<string, mixed>|null $ayrinti
 * @property string|null $hata_mesaji
 * @property CarbonImmutable $created_at
 */
final class YedekIslemKaydi extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'yedek_islem_kayitlari';

    /** Yalnız uygulama kodu doldurur (istekten toplu atama yok) */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Yedekleme denetim kaydı değiştirilemez (salt eklemeli).'));
        self::deleting(fn (): never => throw new LogicException('Yedekleme denetim kaydı silinemez (salt eklemeli).'));
    }

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sonuc' => IslemSonucu::class,
            'sure_ms' => 'integer',
            'ayrinti' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
