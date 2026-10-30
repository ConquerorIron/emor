<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Firma;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Firma>
 */
class FirmaFactory extends Factory
{
    protected $model = Firma::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kod' => fake()->unique()->numerify('##########'),
            'ad' => fake()->company(),
            'vergi_kimlik_no' => fake()->unique()->numerify('##########'),
            'vergi_dairesi' => null,
            'eposta' => null,
            'gsm_no' => null,
            'erp_guid' => null,
        ];
    }

    /** ERP'den aktarılmış firma */
    public function erp(): static
    {
        return $this->state(fn (array $attributes) => [
            'erp_guid' => fake()->uuid(),
        ]);
    }
}
