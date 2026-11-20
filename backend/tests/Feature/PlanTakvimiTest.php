<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekPlani;
use App\Services\Yedekleme\PlanTakvimi;
use App\Services\Yedekleme\SiklikTuru;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-009: plan vade hesabı (README §4.2, §7.8). Beklenen anlar İstanbul
 * saatiyle yazılır; hesap UTC döner.
 */
final class PlanTakvimiTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $siklik
     */
    private function plan(SiklikTuru $tur, string $saat, array $siklik = []): YedekPlani
    {
        return new YedekPlani(['ad' => 'P', 'aktif' => true, 'siklik_turu' => $tur, 'saat' => $saat, 'siklik' => $siklik]);
    }

    private function ist(string $an): CarbonImmutable
    {
        return CarbonImmutable::parse($an, PlanTakvimi::SAAT_DILIMI);
    }

    /**
     * @return array<string, array{SiklikTuru, string, array<string, mixed>, string, string}>
     */
    public static function ornekler(): array
    {
        return [
            'günlük, saat gelmedi' => [SiklikTuru::Gunluk, '02:00', [], '2026-11-21 01:00', '2026-11-21 02:00'],
            'günlük, saat geçti' => [SiklikTuru::Gunluk, '02:00', [], '2026-11-21 02:00', '2026-11-22 02:00'],
            '3 günde bir' => [SiklikTuru::NGunluk, '02:00', ['aralik' => 3, 'baslangic_tarihi' => '2026-11-01'], '2026-11-02 00:00', '2026-11-04 02:00'],
            '3 günde bir, ay geçişi' => [SiklikTuru::NGunluk, '02:00', ['aralik' => 3, 'baslangic_tarihi' => '2026-11-01'], '2026-11-28 03:00', '2026-12-01 02:00'],
            'haftalık Pazar' => [SiklikTuru::Haftalik, '03:00', ['gunler' => [7]], '2026-11-21 10:00', '2026-11-22 03:00'],
            'aylık 31, 30 çeken ay' => [SiklikTuru::Aylik, '02:00', ['ayin_gunu' => 31], '2026-11-15 00:00', '2026-11-30 02:00'],
            'aylık 31, sonraki ay' => [SiklikTuru::Aylik, '02:00', ['ayin_gunu' => 31], '2026-11-30 02:00', '2026-12-31 02:00'],
            'aylık 30, Şubat' => [SiklikTuru::Aylik, '02:00', ['ayin_gunu' => 30], '2027-02-01 00:00', '2027-02-28 02:00'],
            'saatlik, aralık içinde' => [SiklikTuru::Saatlik, '08:00', ['aralik' => 2, 'saat_baslangic' => '08:00', 'saat_bitis' => '19:00', 'gunler' => [1, 2, 3, 4, 5]], '2026-11-20 10:30', '2026-11-20 12:00'],
            'saatlik, cuma akşamından pazartesiye' => [SiklikTuru::Saatlik, '08:00', ['aralik' => 1, 'saat_baslangic' => '08:00', 'saat_bitis' => '19:00', 'gunler' => [1, 2, 3, 4, 5]], '2026-11-20 19:00', '2026-11-23 08:00'],
            'saatlik, gece yarısını geçmez' => [SiklikTuru::Saatlik, '20:00', ['aralik' => 3], '2026-11-21 23:30', '2026-11-22 20:00'],
        ];
    }

    /**
     * @param  array<string, mixed>  $siklik
     */
    #[DataProvider('ornekler')]
    public function test_her_siklik_turunde_sonraki_calisma_istanbul_saatiyle_hesaplanir(SiklikTuru $tur, string $saat, array $siklik, string $sonra, string $beklenen): void
    {
        $an = app(PlanTakvimi::class)->sonraki($this->plan($tur, $saat, $siklik), $this->ist($sonra));

        $this->assertSame($beklenen, $an->setTimezone(PlanTakvimi::SAAT_DILIMI)->format('Y-m-d H:i'));
        $this->assertSame('UTC', $an->getTimezone()->getName());
    }

    public function test_hesap_sunucunun_ve_uygulamanin_saat_diliminden_bagimsizdir(): void
    {
        $varsayilan = date_default_timezone_get();
        $plan = $this->plan(SiklikTuru::Gunluk, '02:00');
        $beklenen = app(PlanTakvimi::class)->sonraki($plan, $this->ist('2026-11-21 01:00'));

        try {
            date_default_timezone_set('America/New_York');
            config(['app.timezone' => 'America/New_York']);
            $an = app(PlanTakvimi::class)->sonraki($plan, CarbonImmutable::parse('2026-11-20 22:00:00', 'UTC'));
        } finally {
            date_default_timezone_set($varsayilan);
        }

        $this->assertTrue($beklenen->equalTo($an));
    }

    public function test_siradaki_calismalar_onizlemesi_ardisik_anlari_verir(): void
    {
        $anlar = app(PlanTakvimi::class)->siradakiler($this->plan(SiklikTuru::Haftalik, '03:00', ['gunler' => [3, 7]]), 3, $this->ist('2026-11-21 00:00'));

        $this->assertSame(
            ['2026-11-22 03:00', '2026-11-25 03:00', '2026-11-29 03:00'],
            array_map(fn (CarbonImmutable $a): string => $a->setTimezone(PlanTakvimi::SAAT_DILIMI)->format('Y-m-d H:i'), $anlar),
        );
    }

    public function test_ayin_29_31ine_kurulu_aylik_plan_uyari_alir(): void
    {
        $takvim = app(PlanTakvimi::class);

        $this->assertTrue($takvim->ayinSonunaKayabilir($this->plan(SiklikTuru::Aylik, '02:00', ['ayin_gunu' => 29])));
        $this->assertFalse($takvim->ayinSonunaKayabilir($this->plan(SiklikTuru::Aylik, '02:00', ['ayin_gunu' => 28])));
    }
}
