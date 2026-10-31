<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use App\Models\IsgCezaKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IsgCezaKesintisi>
 */
class IsgCezaKesintisiFactory extends Factory
{
    protected $model = IsgCezaKesintisi::class;

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
            'tarih' => '2026-01-15',
            'kaynak_firma' => null,
            'toplam_tutar' => '5000.00',
            'iptal_edilen_tutar' => '0.00',
            'yansitilan_tutar' => '5000.00',
            'kesilen_tutar' => '5000.00',
            'kalan_tutar' => '0.00',
        ];
    }
}
