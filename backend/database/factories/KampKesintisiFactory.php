<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\KampKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KampKesintisi>
 */
class KampKesintisiFactory extends Factory
{
    protected $model = KampKesintisi::class;

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
            'tarih' => '2026-01-01',
            'kaynak_firma' => null,
            'oda_tipi' => fake()->randomElement(['4 KİŞİLİK ODA', '6 KİŞİLİK ODA']),
            'toplam_oda' => '17.00',
            'birim_fiyat' => '1200.0000',
            'toplam_tutar' => '20400.00',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '20400.00',
            'kesilen_tutar' => '20000.00',
            'kalan_tutar' => '400.00',
        ];
    }
}
