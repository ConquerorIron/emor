<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad' => fake()->name(),
            'kullanici_adi' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'kaynak' => User::KAYNAK_LOKAL,
            'password' => static::$password ??= Hash::make('password'),
            'aktif_mi' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /** ERP kaynaklı kullanıcı: şifre yerel tutulmaz. */
    public function erp(): static
    {
        return $this->state(fn (array $attributes) => [
            'kaynak' => User::KAYNAK_ERP,
            'password' => null,
        ]);
    }

    public function yonetici(): static
    {
        return $this->state(fn (array $attributes) => [
            'sistem_yoneticisi' => true,
        ]);
    }

    public function pasif(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif_mi' => false,
        ]);
    }
}
