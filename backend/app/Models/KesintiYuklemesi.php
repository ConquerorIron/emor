<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kesinti icmaline yapılan bir içe aktarım ("parti"). Silinince satırları da
 * silinir (icmal tablolarında cascade) — yanlış yüklemenin geri alınması.
 *
 * @property int $id
 * @property string $icmal ICMAL_* sabitlerinden biri
 * @property string $kaynak KAYNAK_* sabitlerinden biri
 * @property string|null $dosya_adi
 * @property int $satir_sayisi
 * @property int|null $yukleyen_id
 */
final class KesintiYuklemesi extends Model
{
    public const ICMAL_ISGUCU = 'isgucu';

    public const ICMAL_KAMP_MALZEME = 'kamp_malzeme';

    public const ICMAL_KAMP = 'kamp';

    /** Kesinti türünün bağlanabileceği icmal ekranları (menü sırasıyla) */
    public const ICMALLER = [self::ICMAL_ISGUCU, self::ICMAL_KAMP, self::ICMAL_KAMP_MALZEME];

    /** Excel dosyası yüklendi */
    public const KAYNAK_EXCEL = 'excel';

    /** Excel'den kopyalanıp yapıştırıldı */
    public const KAYNAK_YAPISTIRMA = 'yapistirma';

    protected $table = 'kesinti_yuklemeleri';

    protected $fillable = ['icmal', 'kaynak', 'dosya_adi', 'satir_sayisi', 'yukleyen_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'satir_sayisi' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function yukleyen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'yukleyen_id');
    }
}
