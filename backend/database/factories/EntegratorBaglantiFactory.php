<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EntegratorBaglanti;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sentetik tanımlar — gerçek hesap bilgisi testlere girmez.
 *
 * @extends Factory<EntegratorBaglanti>
 */
class EntegratorBaglantiFactory extends Factory
{
    protected $model = EntegratorBaglanti::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'saglayici' => EntegratorBaglanti::SAGLAYICI_IZIBIZ,
            'ortam' => EntegratorBaglanti::ORTAM_TEST,
            'kullanici_adi' => 'deneme-kullanici',
            'sifre' => 'deneme-sifre',
            'vkn' => '1234567890',
            'posta_kutusu' => 'urn:mail:deneme-pk@ornek.test',
            'gonderici_birim' => 'urn:mail:deneme-gb@ornek.test',
            'aktif' => false,
        ];
    }

    public function canli(): static
    {
        return $this->state(fn (array $attributes) => [
            'ortam' => EntegratorBaglanti::ORTAM_CANLI,
        ]);
    }

    public function aktif(): static
    {
        return $this->state(fn (array $attributes) => [
            'aktif' => true,
        ]);
    }

    /** Protel tanımı (PRT-03): kullanıcı e-posta, adres ve İzibiz alanları yok */
    public function protel(): static
    {
        return $this->state(fn (array $attributes) => [
            'saglayici' => EntegratorBaglanti::SAGLAYICI_PROTEL,
            'kullanici_adi' => 'deneme@ornek.test',
            'posta_kutusu' => null,
            'gonderici_birim' => null,
            'api_url' => null,
        ]);
    }
}
