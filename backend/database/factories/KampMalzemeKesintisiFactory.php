<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\KampMalzemeKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KampMalzemeKesintisi>
 */
class KampMalzemeKesintisiFactory extends Factory
{
    protected $model = KampMalzemeKesintisi::class;

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
            'mahal' => null,
            'tarih' => '2026-01-05',
            'kaynak_firma' => null,
            'urun_tanimi' => fake()->randomElement(['BATTANİYE', 'YASTIK', 'NEVRESİM TAKIMI']),
            'birim_fiyat' => '350.0000',
            'miktar' => '4.00',
            'birim' => 'ADET',
            'toplam_tutar' => '1400.00',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '1400.00',
            'kesilen_tutar' => '1000.00',
            'kalan_tutar' => '400.00',
        ];
    }
}
