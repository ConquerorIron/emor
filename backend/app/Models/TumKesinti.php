<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IcmalSatiri;
use App\Services\Kesinti\YansitmaTuru;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tüm Kesintiler raporunun bir satırı: icmal tablolarının ortak kolonları.
 * Tablosu yoktur; TumKesintilerSorgusu'nun birleşiminden okunur, yazılmaz.
 * id yalnız kendi icmal tablosunda benzersizdir — satırı id + icmal tanıtır.
 *
 * @property int $id
 * @property string $icmal KesintiYuklemesi::ICMAL_* sabitlerinden biri
 * @property int|null $yukleme_id
 * @property int $kesinti_turu_id
 * @property int $firma_id Taşeron
 * @property YansitmaTuru $yansitma_turu
 * @property int $proje_id
 * @property int $para_birimi_id
 * @property string|null $mahal
 * @property CarbonImmutable $tarih
 * @property string|null $kaynak_firma
 * @property string $toplam_tutar
 * @property string $iptal_edilen_tutar
 * @property string $yansitilan_tutar
 * @property string $kesilen_tutar
 * @property string $kalan_tutar
 */
final class TumKesinti extends Model
{
    use IcmalSatiri;

    protected $table = 'tum_kesintiler';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tarih' => 'immutable_date',
            'yansitma_turu' => YansitmaTuru::class,
            'toplam_tutar' => 'decimal:2',
            'iptal_edilen_tutar' => 'decimal:2',
            'yansitilan_tutar' => 'decimal:2',
            'kesilen_tutar' => 'decimal:2',
            'kalan_tutar' => 'decimal:2',
        ];
    }
}
