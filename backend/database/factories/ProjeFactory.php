<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Proje;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proje>
 */
class ProjeFactory extends Factory
{
    protected $model = Proje::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kod' => fake()->unique()->bothify('PRJ-###'),
            'ad' => fake()->words(3, true),
            'erp_guid' => null,
        ];
    }

    /** ERP'den aktarılmış proje */
    public function erp(): static
    {
        return $this->state(fn (array $attributes) => [
            'erp_guid' => fake()->uuid(),
        ]);
    }
}
