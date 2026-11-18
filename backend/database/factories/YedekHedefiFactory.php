<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sentetik hedefler — gerçek sunucu / hesap bilgisi testlere girmez.
 *
 * @extends Factory<YedekHedefi>
 */
class YedekHedefiFactory extends Factory
{
    protected $model = YedekHedefi::class;

    /**
     * Varsayılan: sırrı olmayan yerel disk hedefi.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ad' => 'Yerel disk '.fake()->unique()->numberBetween(1, 99999),
            'tur' => HedefTuru::Yerel,
            'aktif' => true,
            'baglanti' => ['klasor' => '/tmp/yedek-deneme'],
            'gizli_bilgiler' => null,
        ];
    }

    public function ftp(): static
    {
        return $this->state(fn (array $attributes) => [
            'ad' => 'FTP '.fake()->unique()->numberBetween(1, 99999),
            'tur' => HedefTuru::Ftp,
            'baglanti' => ['sunucu' => 'ftp.ornek.test', 'port' => 21, 'kullanici_adi' => 'deneme', 'klasor' => '/yedek'],
            'gizli_bilgiler' => ['sifre' => 'deneme-ftp-sifresi'],
        ]);
    }
}
