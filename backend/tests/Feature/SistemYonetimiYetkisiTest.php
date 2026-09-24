<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `sistem-yonetimi` Gate'inin tam yetki matrisi. Uçların bu Gate'i
 * kullandığını SqlBaglantiTest ve EkranTasarimTest gösterir.
 */
final class SistemYonetimiYetkisiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{callable(): User, bool}>
     */
    public static function kullanicilar(): array
    {
        return [
            'ERP sistem yöneticisi' => [fn (): User => User::factory()->erp()->yonetici()->create(), true],
            'lokal sistem yöneticisi' => [fn (): User => User::factory()->yonetici()->create(), true],
            'ERP standart kullanıcı' => [fn (): User => User::factory()->erp()->create(), false],
            'lokal standart kullanıcı' => [fn (): User => User::factory()->create(), false],
        ];
    }

    /**
     * @param  callable(): User  $kullaniciUret
     */
    #[DataProvider('kullanicilar')]
    public function test_yalniz_sistem_yoneticisi_bayragi_yetki_verir(callable $kullaniciUret, bool $beklenen): void
    {
        $this->assertSame($beklenen, Gate::forUser($kullaniciUret())->allows('sistem-yonetimi'));
    }
}
