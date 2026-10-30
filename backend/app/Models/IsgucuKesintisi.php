<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IcmalSatiri;
use App\Services\Kesinti\MesaiTuru;
use App\Services\Kesinti\Vasif;
use App\Services\Kesinti\YansitmaTuru;
use Carbon\CarbonImmutable;
use Database\Factories\IsgucuKesintisiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * İşgücü Kesintileri icmalinin bir satırı. Tutarlar decimal metin olarak
 * okunur (float yuvarlaması olmasın).
 *
 * @property int $id
 * @property int|null $yukleme_id Elle girilen satırda null
 * @property int $kesinti_turu_id
 * @property int $firma_id Taşeron
 * @property int $proje_id
 * @property int $para_birimi_id
 * @property string|null $mahal
 * @property CarbonImmutable $tarih
 * @property string|null $kaynak_firma
 * @property string|null $kaynak_fis_no
 * @property string $adi_soyadi
 * @property Vasif $vasif
 * @property MesaiTuru $mesai_turu
 * @property YansitmaTuru $yansitma_turu
 * @property string|null $tanim
 * @property string|null $gorev
 * @property string|null $departman
 * @property string|null $aktivite
 * @property string $miktar_saat
 * @property string $miktar_yevmiye
 * @property string $birim_fiyat
 * @property string $toplam_tutar
 * @property string $iptal_edilen_tutar
 * @property string $yansitilan_tutar
 * @property string $kesilen_tutar
 * @property string $kalan_tutar
 * @property int|null $kaydeden_id
 */
final class IsgucuKesintisi extends Model
{
    /** @use HasFactory<IsgucuKesintisiFactory> */
    use HasFactory;

    use IcmalSatiri;

    protected $table = 'isgucu_kesintileri';

    protected $fillable = [
        'yukleme_id', 'kesinti_turu_id', 'firma_id', 'proje_id', 'para_birimi_id',
        'mahal', 'tarih', 'kaynak_firma', 'kaynak_fis_no', 'adi_soyadi',
        'vasif', 'mesai_turu', 'yansitma_turu', 'tanim', 'gorev', 'departman', 'aktivite',
        'miktar_saat', 'miktar_yevmiye', 'birim_fiyat',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
        'kaydeden_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tarih' => 'immutable_date',
            'vasif' => Vasif::class,
            'mesai_turu' => MesaiTuru::class,
            'yansitma_turu' => YansitmaTuru::class,
            'miktar_saat' => 'decimal:2',
            'miktar_yevmiye' => 'decimal:2',
            'birim_fiyat' => 'decimal:4',
            'toplam_tutar' => 'decimal:2',
            'iptal_edilen_tutar' => 'decimal:2',
            'yansitilan_tutar' => 'decimal:2',
            'kesilen_tutar' => 'decimal:2',
            'kalan_tutar' => 'decimal:2',
        ];
    }
}
