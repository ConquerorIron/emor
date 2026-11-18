<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\HedefTuru;
use Database\Factories\YedekHedefiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yedek hedefi: yedeğin kopyalanacağı yer (README §4.6). Sır olmayan bağlantı
 * bilgileri `baglanti`'da; parola, yenileme anahtarı gibi sırlar
 * `gizli_bilgiler`'de şifreli (encrypted:array) ve serileştirmede gizlidir
 * (SqlBaglanti / MailAyari deseni). Plana bağlı ya da kopyası olan hedef
 * silinmez (KayitSilme → 409).
 *
 * @property int $id
 * @property string $ad
 * @property HedefTuru $tur
 * @property bool $aktif
 * @property array<string, mixed> $baglanti
 * @property array<string, string>|null $gizli_bilgiler
 */
final class YedekHedefi extends Model
{
    /** @use HasFactory<YedekHedefiFactory> */
    use HasFactory;

    protected $table = 'yedek_hedefleri';

    protected $fillable = [
        'ad',
        'tur',
        'aktif',
        'baglanti',
        'gizli_bilgiler',
    ];

    /** @var list<string> */
    protected $hidden = ['gizli_bilgiler'];

    /**
     * @return BelongsToMany<YedekPlani, $this>
     */
    public function planlar(): BelongsToMany
    {
        return $this->belongsToMany(YedekPlani::class, 'yedek_plan_hedefleri', 'yedek_hedefi_id', 'yedek_plani_id')
            ->withPivot(['sakla_son_adet', 'sakla_gun'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<YedekKopyasi, $this>
     */
    public function kopyalar(): HasMany
    {
        return $this->hasMany(YedekKopyasi::class, 'yedek_hedefi_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tur' => HedefTuru::class,
            'aktif' => 'boolean',
            'baglanti' => 'array',
            'gizli_bilgiler' => 'encrypted:array',
        ];
    }
}
