<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sentetik e-Fatura özetleri — gerçek fatura verisi testlere girmez.
 *
 * @extends Factory<EFatura>
 */
class EFaturaFactory extends Factory
{
    protected $model = EFatura::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sira = fake()->unique()->numberBetween(1, 999_999);

        return [
            'entegrator_baglanti_id' => EntegratorBaglanti::factory()->aktif(),
            'yon' => 'gelen',
            'kaynak_id' => $sira,
            'ettn' => fake()->uuid(),
            'belge_no' => sprintf('ABC2026%09d', $sira),
            'belge_tarihi' => '2026-01-10',
            'belge_saati' => '10:15:00',
            'olusturma_zamani' => '2026-01-10 07:15:00',
            'fatura_tipi' => 'SATIS',
            'senaryo' => 'TICARIFATURA',
            'para_birimi' => 'TRY',
            'tutar' => '1250.5000',
            'vergi_tutari' => '208.4200',
            'gonderici_vkn' => '0123456789',
            'gonderici_unvan' => 'Gönderen A.Ş.',
            'alici_vkn' => '9876543210',
            'alici_unvan' => 'Alıcı A.Ş.',
            'durum' => 'RECEIVED',
            'durum_aciklamasi' => 'Alındı',
            'erp_okundu' => true,
            'okundu' => false,
            'ilk_gorulme' => now(),
            'son_gorulme' => now(),
        ];
    }

    public function giden(): static
    {
        return $this->state(fn (array $attributes) => ['yon' => 'giden']);
    }
}
