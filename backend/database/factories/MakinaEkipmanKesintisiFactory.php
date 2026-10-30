<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\MakinaEkipmanKesintisi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MakinaEkipmanKesintisi>
 */
class MakinaEkipmanKesintisiFactory extends Factory
{
    protected $model = MakinaEkipmanKesintisi::class;

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
            'kaynak_firma' => 'MFK',
            'kaynak_fis_no' => (string) fake()->numberBetween(10000, 99999),
            'makina_tanimi' => fake()->randomElement(['30 TON EKSKAVATÖR KOVALI', 'JCB', 'VİNÇ']),
            'aktivite' => 'HAFRİYAT AKTARMA',
            'disiplin' => 'HAFRİYAT',
            'toplam_tutar' => '15625.27',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '15625.27',
            'kesilen_tutar' => '15389.32',
            'kalan_tutar' => '235.95',
        ];
    }
}
