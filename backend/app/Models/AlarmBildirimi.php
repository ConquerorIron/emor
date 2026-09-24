<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alarm bildirimi (EFAT-13). Kayıt önce `bekliyor` açılır, kuyruk işi
 * gönderince `gonderildi` olur; "gönderildi" hiçbir zaman gönderimden önce
 * işaretlenmez. Gönderilmemesi gereken durumlar `atlandi` + neden koduyla kalır.
 *
 * @property int $id
 * @property int $alarm_kurali_id
 * @property int $entegrator_baglanti_id
 * @property int|null $alarm_olayi_id
 * @property string $tur
 * @property string $anahtar
 * @property string $durum
 * @property array<string, mixed> $ayrinti
 * @property int $deneme
 * @property string|null $hata_kodu
 * @property string|null $hata_mesaji
 * @property CarbonImmutable|null $gonderildi
 * @property CarbonImmutable $created_at
 */
final class AlarmBildirimi extends Model
{
    public const TUR_ACILDI = 'acildi';

    public const TUR_COZULDU = 'cozuldu';

    public const TUR_OZET = 'ozet';

    public const BEKLIYOR = 'bekliyor';

    public const GONDERILDI = 'gonderildi';

    public const BASARISIZ = 'basarisiz';

    public const ATLANDI = 'atlandi';

    protected $table = 'alarm_bildirimleri';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<AlarmKurali, $this>
     */
    public function kural(): BelongsTo
    {
        return $this->belongsTo(AlarmKurali::class, 'alarm_kurali_id');
    }

    /**
     * @return BelongsTo<EntegratorBaglanti, $this>
     */
    public function entegratorBaglanti(): BelongsTo
    {
        return $this->belongsTo(EntegratorBaglanti::class);
    }

    /**
     * @return BelongsTo<AlarmOlayi, $this>
     */
    public function olay(): BelongsTo
    {
        return $this->belongsTo(AlarmOlayi::class, 'alarm_olayi_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ayrinti' => 'array',
            'deneme' => 'integer',
            'gonderildi' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
