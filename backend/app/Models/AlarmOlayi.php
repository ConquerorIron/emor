<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Alarm olayı (EFAT-13): kural + hesap + anahtar başına en fazla bir açık olay.
 *
 * @property int $id
 * @property int $alarm_kurali_id
 * @property int $entegrator_baglanti_id
 * @property string $anahtar
 * @property string $durum
 * @property CarbonImmutable $acildi
 * @property CarbonImmutable|null $cozuldu
 * @property array<string, mixed>|null $ayrinti
 */
final class AlarmOlayi extends Model
{
    public const ACIK = 'acik';

    public const COZULDU = 'cozuldu';

    protected $table = 'alarm_olaylari';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acildi' => 'immutable_datetime',
            'cozuldu' => 'immutable_datetime',
            'ayrinti' => 'array',
        ];
    }
}
