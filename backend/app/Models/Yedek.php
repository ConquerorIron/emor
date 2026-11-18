<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use Carbon\CarbonImmutable;
use Database\Factories\YedekFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yedek klasöründeki bir yedeğin DİZİN kaydı (README §5.2, §5.4). Doğruluk
 * kaynağı dosyadır (manifest + olay günlüğü); kayıt klasör taranarak yeniden
 * kurulabilir. Kayıt silinmez. Yedek klasöründeki dosya gidince
 * `yerel_silinme_zamani` dolar; uzak kopyası kalan yedek geri yüklenebilir
 * kalır. Durum ancak hiçbir yerde kopyası kalmayınca `silindi` olur.
 *
 * @property int $id
 * @property string $kimlik
 * @property string $dosya_adi
 * @property YedekKaynagi $kaynak
 * @property YedekDurumu $durum
 * @property DogrulamaDurumu $dogrulama_durumu
 * @property bool $imzali
 * @property int|null $boyut
 * @property string|null $sha256
 * @property CarbonImmutable|null $alinma_zamani
 * @property CarbonImmutable|null $bitti
 * @property bool $sabit
 * @property string|null $aciklama
 * @property int|null $alan_kullanici_id
 * @property string|null $hata_kodu
 * @property string|null $hata_mesaji
 * @property CarbonImmutable|null $yerel_silinme_zamani
 * @property CarbonImmutable|null $silinme_zamani
 */
final class Yedek extends Model
{
    /** @use HasFactory<YedekFactory> */
    use HasFactory;

    protected $table = 'yedekler';

    /** Yalnız uygulama kodu doldurur (istekten toplu atama yok) */
    protected $guarded = ['id'];

    /**
     * @return HasMany<YedekKopyasi, $this>
     */
    public function kopyalar(): HasMany
    {
        return $this->hasMany(YedekKopyasi::class, 'yedek_id');
    }

    /**
     * @return HasMany<GeriYukleme, $this>
     */
    public function geriYuklemeler(): HasMany
    {
        return $this->hasMany(GeriYukleme::class, 'yedek_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function alanKullanici(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alan_kullanici_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kaynak' => YedekKaynagi::class,
            'durum' => YedekDurumu::class,
            'dogrulama_durumu' => DogrulamaDurumu::class,
            'imzali' => 'boolean',
            'boyut' => 'integer',
            'alinma_zamani' => 'immutable_datetime',
            'bitti' => 'immutable_datetime',
            'sabit' => 'boolean',
            'yerel_silinme_zamani' => 'immutable_datetime',
            'silinme_zamani' => 'immutable_datetime',
        ];
    }
}
