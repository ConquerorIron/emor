<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\MalzemeKesintisi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MalzemeKesintisi>
 */
class MalzemeKesintisiFactory extends Factory
{
    protected $model = MalzemeKesintisi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kesinti_turu_id' => KesintiTuru::factory(),
            'firma_id' => Firma::factory(),
            'yansitma_turu' => YansitmaTuru::Taseron,
            'proje_id' => Proje::factory(),
            // Migration'ın eklediği TL
            'para_birimi_id' => fn (): int => (int) ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'mahal' => 'ZONE 10',
            'tarih' => '2026-01-01',
            'kaynak_firma' => 'İSG BİRİMİ',
            'kaynak_irsaliye_no' => '006.06.04.0000060501',
            'urun_tanimi' => 'HALAT TUTUCU',
            'malzeme_birim_fiyati' => '350.0000',
            'miktar' => '15.00',
            'birim' => 'ADET',
            'toplam_tutar' => '5250.00',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '5250.00',
            'kesilen_tutar' => '5250.00',
            'kalan_tutar' => '0.00',
        ];
    }
}
