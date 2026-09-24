<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * e-Fatura senkron çalışma günlüğü (EFAT-10).
 *
 * @property int $id
 * @property int $entegrator_baglanti_id
 * @property string $yon
 * @property string $tarih_turu
 * @property CarbonImmutable $baslangic
 * @property CarbonImmutable $bitis
 * @property string $tetikleyen
 * @property int|null $kullanici_id
 * @property string $durum
 * @property int|null $beklenen_adet
 * @property int|null $okunan_adet
 * @property int|null $yeni_adet
 * @property int|null $guncellenen_adet
 * @property int|null $hatali_adet
 * @property string|null $eksik_nedeni
 * @property string|null $hata_kodu
 * @property string|null $hata_mesaji
 * @property CarbonImmutable $basladi
 * @property CarbonImmutable|null $bitti
 */
final class EFaturaSenkronCalismasi extends Model
{
    public const DURUM_CALISIYOR = 'calisiyor';

    public const DURUM_TAM = 'tam';

    public const DURUM_EKSIK = 'eksik';

    public const DURUM_BASARISIZ = 'basarisiz';

    public const TETIKLEYEN_ZAMANLANMIS = 'zamanlanmis';

    public const TETIKLEYEN_MANUEL = 'manuel';

    public const TETIKLEYEN_ILK_TARAMA = 'ilk_tarama';

    protected $table = 'efatura_senkron_calismalari';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'baslangic' => 'immutable_date',
            'bitis' => 'immutable_date',
            'beklenen_adet' => 'integer',
            'okunan_adet' => 'integer',
            'yeni_adet' => 'integer',
            'guncellenen_adet' => 'integer',
            'hatali_adet' => 'integer',
            'basladi' => 'immutable_datetime',
            'bitti' => 'immutable_datetime',
        ];
    }
}
