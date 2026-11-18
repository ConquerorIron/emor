<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\KopyaDurumu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YedekKopyasi>
 */
class YedekKopyasiFactory extends Factory
{
    protected $model = YedekKopyasi::class;

    /**
     * Varsayılan: bir planın yerel hedefe başarıyla kopyaladığı yedek.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'yedek_id' => Yedek::factory(),
            'yedek_hedefi_id' => YedekHedefi::factory(),
            'yedek_plani_id' => YedekPlani::factory(),
            'durum' => KopyaDurumu::Tamam,
            'tamamlanma_zamani' => now(),
        ];
    }
}
