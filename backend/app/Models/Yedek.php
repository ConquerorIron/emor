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
 * @property bool $ice_alindi deploy.sh'ın eski yedeğinden sonradan içeri alındı (BY-013)
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
 * @property list<array{plan: int, hedef: int}>|null $plan_hedefleri Plan yedeğinin alındığı anda kopyalanacağı plan / hedef çiftleri (G1-6-R2); eski yedeklerde boş
 */
final class Yedek extends Model
{
    /** @use HasFactory<YedekFactory> */
    use HasFactory;

    protected $table = 'yedekler';

    /** Yalnız uygulama kodu doldurur (istekten toplu atama yok) */
    protected $guarded = ['id'];

    /** Veritabanı varsayılanının kaydedilmemiş nesnedeki yansıması (Dikkat_Edilecekler K4) */
    protected $attributes = ['ice_alindi' => false];

    /**
     * Ekrandan geri yüklenebilir mi (README §4.5 güven sınırı): yalnız bu
     * sistemin imzaladığı ve tamamlanmış yedek. İmzasız ("güvenilmeyen")
     * dosya bu sunucuda geri yüklenmez.
     */
    public function geriYuklenebilirMi(): bool
    {
        return $this->imzali && $this->durum === YedekDurumu::Tamam;
    }

    /**
     * @return HasMany<YedekKopyasi, $this>
     */
    public function kopyalar(): HasMany
    {
        return $this->hasMany(YedekKopyasi::class, 'yedek_id');
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
            'ice_alindi' => 'boolean',
            'boyut' => 'integer',
            'alinma_zamani' => 'immutable_datetime',
            'bitti' => 'immutable_datetime',
            'sabit' => 'boolean',
            'yerel_silinme_zamani' => 'immutable_datetime',
            'silinme_zamani' => 'immutable_datetime',
            'plan_hedefleri' => 'array',
        ];
    }
}
