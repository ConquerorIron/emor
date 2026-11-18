<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YedekKopyasi>
 */
class YedekKopyasiFactory extends Factory
{
    protected $model = YedekKopyasi::class;

    /**
     * Varsayılan: bir planın kendi hedefine başarıyla kopyaladığı plan
     * yedeği. Kopya plana, hedef de plana saklama kuralıyla bağlanır
     * (configure), README §4.3.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'yedek_id' => Yedek::factory()->plan(),
            'yedek_hedefi_id' => YedekHedefi::factory(),
            'durum' => KopyaDurumu::Tamam,
            'tamamlanma_zamani' => now(),
        ];
    }

    /** Planı olmayan kopya: elle alınmış yedeğin bir hedefe kopyası */
    public function plansiz(): static
    {
        return $this->state(fn (array $attributes): array => [
            'yedek_id' => Yedek::factory(),
        ]);
    }

    /** Plan yedeğinin kopyası planına bağlanır; zamanlanmamış yedeğinki bağsız kalır. */
    public function configure(): static
    {
        return $this->afterCreating(function (YedekKopyasi $kopya): void {
            if ($kopya->yedek?->kaynak !== YedekKaynagi::Plan) {
                return;
            }

            $plan = YedekPlani::factory()->create();
            $plan->hedefler()->attach($kopya->yedek_hedefi_id, ['sakla_son_adet' => 14]);
            $kopya->planlar()->attach($plan, ['yedek_hedefi_id' => $kopya->yedek_hedefi_id]);
        });
    }
}
