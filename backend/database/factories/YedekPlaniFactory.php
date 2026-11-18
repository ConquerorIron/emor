<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\YedekPlani;
use App\Services\Yedekleme\SiklikTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YedekPlani>
 */
class YedekPlaniFactory extends Factory
{
    protected $model = YedekPlani::class;

    /**
     * Varsayılan: her gün 02:00 (README §4.2 örneği).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad' => 'Günlük '.fake()->unique()->numberBetween(1, 99999),
            'aktif' => true,
            'siklik_turu' => SiklikTuru::Gunluk,
            'saat' => '02:00',
            'siklik' => [],
        ];
    }
}
