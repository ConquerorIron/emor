<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KesintiTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KesintiTuru>
 */
class KesintiTuruFactory extends Factory
{
    protected $model = KesintiTuru::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kod' => fake()->unique()->bothify('KES-###'),
            'ad' => fake()->words(2, true),
            'kdv_orani' => '20.00',
            'aciklama' => null,
        ];
    }
}
