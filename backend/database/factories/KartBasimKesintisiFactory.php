<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\KartBasimKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KartBasimKesintisi>
 */
class KartBasimKesintisiFactory extends Factory
{
    protected $model = KartBasimKesintisi::class;

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
            'kaynak_firma' => 'GÜVENLİK BİRİMİ',
            'personel_adi_soyadi' => fake()->name(),
            'urun_tanimi' => 'PERSONEL GİRİŞ KARTI',
            'kart_basim_nedeni' => 'KAYIP',
            'malzeme_birim_fiyati' => '150.0000',
            'miktar' => '1.00',
            'birim' => 'ADET',
            'toplam_tutar' => '150.00',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '150.00',
            'kesilen_tutar' => '150.00',
            'kalan_tutar' => '0.00',
        ];
    }
}
