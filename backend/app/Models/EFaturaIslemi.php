<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Entegrator\EFaturaIslemTuru;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * e-Fatura işlem geçmişi kaydı (EK-03). SALT EKLEMELİ: güncelleme ve silme
 * modelde reddedilir (Dikkat_Edilecekler K3). Kayıt, işlediği durum
 * değişikliğiyle aynı veritabanı işleminde yazılır (EFaturaElleIslemleri).
 *
 * @property int $id
 * @property int|null $fatura_id
 * @property int|null $yukleme_id
 * @property string $saglayici
 * @property string|null $belge_no
 * @property string|null $belge_kimligi
 * @property EFaturaIslemTuru $islem
 * @property string|null $onceki
 * @property string|null $yeni
 * @property string|null $neden
 * @property array<string, mixed>|null $ayrinti
 * @property int|null $kullanici_id
 * @property string $kullanici_adi
 * @property CarbonImmutable $created_at
 */
final class EFaturaIslemi extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'efatura_islem_gecmisi';

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('e-Fatura işlem geçmişi değiştirilemez.'));
        self::deleting(fn (): never => throw new LogicException('e-Fatura işlem geçmişi silinemez.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'islem' => EFaturaIslemTuru::class,
            'ayrinti' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
