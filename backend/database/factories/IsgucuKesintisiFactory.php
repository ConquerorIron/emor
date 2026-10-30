<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\MesaiTuru;
use App\Services\Kesinti\Vasif;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IsgucuKesintisi>
 */
class IsgucuKesintisiFactory extends Factory
{
    protected $model = IsgucuKesintisi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kesinti_turu_id' => KesintiTuru::factory(),
            'firma_id' => Firma::factory(),
            'proje_id' => Proje::factory(),
            // Migration'ın eklediği TL
            'para_birimi_id' => fn (): int => (int) ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'mahal' => 'ZONE 10',
            'tarih' => '2026-03-25',
            'kaynak_firma' => 'MFK',
            'kaynak_fis_no' => (string) fake()->numberBetween(10000, 99999),
            'adi_soyadi' => fake()->name(),
            'vasif' => Vasif::Niteliksiz,
            'mesai_turu' => MesaiTuru::Normal,
            'yansitma_turu' => YansitmaTuru::Hab,
            'tanim' => 'DÜZ İŞÇİ',
            'gorev' => 'KABA',
            'departman' => 'KABA YAPI',
            'aktivite' => null,
            'miktar_saat' => '8.00',
            'miktar_yevmiye' => '1.00',
            'birim_fiyat' => '2562.1521',
            'toplam_tutar' => '2562.15',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '2562.15',
            'kesilen_tutar' => '2562.15',
            'kalan_tutar' => '0.00',
        ];
    }
}
