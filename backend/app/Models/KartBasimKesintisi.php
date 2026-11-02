<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IcmalSatiri;
use App\Services\Kesinti\YansitmaTuru;
use Carbon\CarbonImmutable;
use Database\Factories\KartBasimKesintisiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Personel Kart Basım Kesintisi icmalinin bir satırı. Tutarlar decimal metin
 * olarak okunur (float yuvarlaması olmasın).
 *
 * @property int $id
 * @property int|null $yukleme_id Elle girilen satırda null
 * @property int $kesinti_turu_id
 * @property int $firma_id Taşeron
 * @property YansitmaTuru $yansitma_turu
 * @property int $proje_id
 * @property int $para_birimi_id
 * @property string|null $mahal
 * @property CarbonImmutable $tarih
 * @property string|null $kaynak_firma
 * @property string $personel_adi_soyadi
 * @property string $urun_tanimi
 * @property string|null $kart_basim_nedeni
 * @property string $malzeme_birim_fiyati
 * @property string $miktar
 * @property string|null $birim
 * @property string $toplam_tutar
 * @property string $iptal_edilen_tutar
 * @property string $yansitilan_tutar
 * @property string $kesilen_tutar
 * @property string $kalan_tutar
 * @property int|null $kaydeden_id
 */
final class KartBasimKesintisi extends Model
{
    /** @use HasFactory<KartBasimKesintisiFactory> */
    use HasFactory;

    use IcmalSatiri;

    protected $table = 'kart_basim_kesintileri';

    protected $fillable = [
        'yukleme_id', 'kesinti_turu_id', 'firma_id', 'yansitma_turu', 'proje_id', 'para_birimi_id',
        'mahal', 'tarih', 'kaynak_firma', 'personel_adi_soyadi', 'urun_tanimi', 'kart_basim_nedeni',
        'malzeme_birim_fiyati', 'miktar', 'birim',
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
            'yansitma_turu' => YansitmaTuru::class,
            'malzeme_birim_fiyati' => 'decimal:4',
            'miktar' => 'decimal:2',
            'toplam_tutar' => 'decimal:2',
            'iptal_edilen_tutar' => 'decimal:2',
            'yansitilan_tutar' => 'decimal:2',
            'kesilen_tutar' => 'decimal:2',
            'kalan_tutar' => 'decimal:2',
        ];
    }
}
