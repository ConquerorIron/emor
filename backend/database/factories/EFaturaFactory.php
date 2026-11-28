<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\EntegratorBaglantiServisi;
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

    /**
     * Protel faturası (PRT-06): aktif Protel tanımına bağlı, gelen; Protel
     * durum değerleri ve Protel'in yazdırıldı / ERP durumu alanları dolu.
     */
    public function protel(): static
    {
        return $this->state(fn (array $attributes) => [
            'entegrator_baglanti_id' => EntegratorBaglanti::factory()->protel()->aktif(),
            'yon' => 'gelen',
            'senaryo' => 'TEMELFATURA',
            'durum' => 'Received',
            'durum_aciklamasi' => 'Alındı',
            'erp_durumu' => 'New',
            'yazdirildi' => true,
            // Protel bayrağı otelin ERP'sinindir; eMOR okuması (erp_okundu) Protel'de yok
            'erp_okundu' => null,
            'otel_erp_okundu' => true,
        ]);
    }

    /**
     * Logo listesinden içe aktarılmış fatura (EK-07): statik tanım, entegratör
     * kimliği yok; anahtar küçük harf ETTN'in kendisi. İzibiz'in okuma
     * bayrakları ve saati dosyada yok.
     */
    public function logo(): static
    {
        return $this->state(fn (array $attributes) => [
            ...$this->statik(EntegratorBaglanti::SAGLAYICI_LOGO),
            'ettn' => fake()->uuid(),
            'belge_anahtari' => fn (array $son): string => (string) $son['ettn'],
            'senaryo' => 'TEMELFATURA',
            'durum' => 'Alındı',
            'durum_aciklamasi' => null,
        ]);
    }

    /**
     * e-Arşiv faturası / e-SMM makbuzu listesinden (EK-08): ETTN yok, anahtar
     * `tür|gönderici VKN-TCKN|belge no` (no Türkçe büyük harf, boşluksuz);
     * alıcı, senaryo, tip ve oluşturma zamanı dosyada yok.
     */
    public function earsiv(string $tur = 'fatura'): static
    {
        return $this->state(fn (array $attributes) => [
            ...$this->statik(EntegratorBaglanti::SAGLAYICI_EARSIV),
            'belge_turu' => $tur,
            'ettn' => null,
            'belge_anahtari' => fn (array $son): string => $tur.'|'.$son['gonderici_vkn'].'|'.$son['belge_no'],
            'olusturma_zamani' => null,
            'alici_vkn' => null,
            'alici_unvan' => null,
            'senaryo' => null,
            'fatura_tipi' => null,
            'durum' => null,
            'durum_aciklamasi' => null,
            'brut_tutar' => '1042.0800',
        ]);
    }

    /** @return array<string, mixed> Statik kaynakların ortak alanları */
    private function statik(string $saglayici): array
    {
        return [
            // Tanım gerçek akıştaki gibi sağlayıcı başına tektir (ilk istenişte oluşur)
            'entegrator_baglanti_id' => fn (): int => app(EntegratorBaglantiServisi::class)->statikTanim($saglayici)->id,
            'yon' => 'gelen',
            'kaynak_id' => null,
            'belge_saati' => null,
            'erp_okundu' => null,
            'okundu' => null,
        ];
    }
}
