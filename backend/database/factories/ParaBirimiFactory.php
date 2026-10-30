<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ParaBirimi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Migration TL / USD / EUR'yu zaten ekler; factory başka kodlar üretir.
 *
 * @extends Factory<ParaBirimi>
 */
class ParaBirimiFactory extends Factory
{
    protected $model = ParaBirimi::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kod' => fake()->unique()->lexify('X??'),
            'ad' => fake()->word(),
        ];
    }
}
