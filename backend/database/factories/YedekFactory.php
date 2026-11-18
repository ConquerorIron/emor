<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Yedek;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Yedek>
 */
class YedekFactory extends Factory
{
    protected $model = Yedek::class;

    /**
     * Varsayılan: başarıyla alınmış, 1. düzeyde doğrulanmış elle yedek.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $zaman = fake()->dateTimeBetween('-30 days', '-1 hour');

        return [
            'kimlik' => (string) Str::uuid(),
            'dosya_adi' => 'erp_elle_'.$zaman->format('Y-m-d_His').'_'.fake()->unique()->numberBetween(1, 99999).'.dump',
            'kaynak' => YedekKaynagi::Elle,
            'durum' => YedekDurumu::Tamam,
            'dogrulama_durumu' => DogrulamaDurumu::OzetTamam,
            'imzali' => true,
            'boyut' => 1_600_000,
            'sha256' => hash('sha256', fake()->uuid()),
            'alinma_zamani' => $zaman,
            'bitti' => $zaman,
        ];
    }

    /** Planın aldığı yedek; dosya adı öneki kaynakla aynı (README §5.5, T10) */
    public function plan(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kaynak' => YedekKaynagi::Plan,
            'dosya_adi' => preg_replace('/^erp_elle_/', 'erp_plan_', (string) $attributes['dosya_adi']),
        ]);
    }
}
