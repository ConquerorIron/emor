<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\HedefKonumu;
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

    /**
     * Uzak kimliği ve konum izi olmayan `tamam` kopya: üretimde oluşmaz (kopyalayıcı
     * ikisini her zaman yazar), yalnız eski / yeri doğrulanamayan kayıt kurgusu için.
     */
    public function izsiz(): static
    {
        return $this->state(fn (array $attributes): array => ['uzak_kimlik' => null, 'konum_izi' => null]);
    }

    /**
     * Varsayılan `tamam` kopya gerçek kopyalayıcının ürettiği gibidir (T10, YF-56):
     * uzak kimliği yedeğin dosya adı, konum izi hedefin bugünkü izi. Plan yedeğinin
     * kopyası planına bağlanır; zamanlanmamış yedeğinki bağsız kalır.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (YedekKopyasi $kopya): void {
            if ($kopya->durum !== KopyaDurumu::Tamam || $kopya->yedek_hedefi_id === null) {
                return;
            }
            if (! $kopya->isDirty('uzak_kimlik') && $kopya->uzak_kimlik === null) {
                $kopya->uzak_kimlik = Yedek::query()->whereKey($kopya->yedek_id)->value('dosya_adi');
            }
            if (! $kopya->isDirty('konum_izi') && $kopya->konum_izi === null && ($hedef = YedekHedefi::query()->find($kopya->yedek_hedefi_id)) !== null) {
                $kopya->konum_izi = app(HedefKonumu::class)->iz($hedef);
            }
        })->afterCreating(function (YedekKopyasi $kopya): void {
            if ($kopya->yedek?->kaynak !== YedekKaynagi::Plan) {
                return;
            }

            $plan = YedekPlani::factory()->create();
            $plan->hedefler()->attach($kopya->yedek_hedefi_id, ['sakla_son_adet' => 14]);
            $kopya->planlar()->attach($plan, ['yedek_hedefi_id' => $kopya->yedek_hedefi_id]);
        });
    }
}
