<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\Rol;
use App\Models\User;
use App\Models\Yedek;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\IstekKutusu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-027: Geri Yüklemeler API'si (farklı adla geri yükleme; README §4.5-A).
 */
final class GeriYuklemeApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true, 'yedekleme.kopya_bekleme_gun' => 7]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.Str::random(8)]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    private function kopya(string $veritabani, GeriYuklemeDurumu $durum = GeriYuklemeDurumu::Tamam): GeriYukleme
    {
        return GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(), 'yedek_id' => Yedek::factory()->create()->id, 'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => $veritabani, 'durum' => $durum,
            'basladi' => CarbonImmutable::parse('2026-11-20 10:00', 'UTC'), 'bitti' => CarbonImmutable::parse('2026-11-20 10:01', 'UTC'),
        ]);
    }

    public function test_izin_olmadan_farkli_adla_geri_yukleme_ve_kopya_silme_403(): void
    {
        $yedek = Yedek::factory()->create();
        $kopya = $this->kopya('erp_geri_20261120_100000');
        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al', 'yedekler.sil'));

        $this->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/kopya-geri-yukle')->assertForbidden();
        $this->deleteJson(self::K.'/geri-yuklemeler/'.$kopya->kimlik.'/kopya')->assertForbidden();
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_farkli_adla_geri_yukleme_istegi_ek_kalibiyla_birakilir(): void
    {
        $yedek = Yedek::factory()->create();
        $this->actingAs($this->izinli('yedekler.goruntule', 'geri_yukleme.kopya'));

        $this->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/kopya-geri-yukle', ['ek' => 'Fatura; DROP'])
            ->assertStatus(422)->assertJsonValidationErrors('ek', 'hatalar');
        $this->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/kopya-geri-yukle', ['ek' => 'inceleme1'])->assertStatus(202);

        $istek = json_decode((string) file_get_contents(app(IstekKutusu::class)->bekleyenler()[0]), true);
        $this->assertSame(['kopya_geri_yukle', ['yedek_kimligi' => $yedek->kimlik, 'ek' => 'inceleme1']], [$istek['tur'], $istek['parametreler']]);
    }

    public function test_imzasiz_yedek_geri_yuklenemez_409(): void
    {
        $yedek = Yedek::factory()->create(['imzali' => false]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'geri_yukleme.kopya'))
            ->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/kopya-geri-yukle')
            ->assertStatus(409)->assertJsonPath('kod', 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function silinemeyenAdlar(): array
    {
        return ['canlı veritabanı' => ['erp'], 'önceki veritabanı' => ['erp_onceki_20261120_100000'], 'kalıp dışı' => ['erp_geri_x']];
    }

    #[DataProvider('silinemeyenAdlar')]
    public function test_erp_ve_kalip_disi_adlar_ekrandan_silinemez(string $ad): void
    {
        $kayit = $this->kopya($ad);

        $this->actingAs($this->izinli('yedekler.goruntule', 'geri_yukleme.kopya'))
            ->deleteJson(self::K.'/geri-yuklemeler/'.$kayit->kimlik.'/kopya')
            ->assertStatus(409)->assertJsonPath('kod', 'GERI_YUKLEME_KOPYA_YOK');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_bekleyen_kopya_silme_istegi_birakilir_liste_otomatik_silme_tarihini_gosterir(): void
    {
        $kopya = $this->kopya('erp_geri_20261120_100000_inceleme');
        $this->kopya('erp_geri_20261119_100000', GeriYuklemeDurumu::Basarisiz);
        $this->actingAs($this->izinli('yedekler.goruntule', 'geri_yukleme.kopya'));

        $liste = collect($this->getJson(self::K.'/geri-yuklemeler')->assertOk()->json('data'))->keyBy('kimlik');
        $this->assertTrue($liste[$kopya->kimlik]['bekleyen_kopya']);
        $this->assertSame('2026-11-27T10:01:00+00:00', $liste[$kopya->kimlik]['silinecegi_zaman']);
        $this->assertSame(1, $liste->where('bekleyen_kopya', true)->count());

        $this->deleteJson(self::K.'/geri-yuklemeler/'.$kopya->kimlik.'/kopya')->assertStatus(202);
        $istek = json_decode((string) file_get_contents(app(IstekKutusu::class)->bekleyenler()[0]), true);
        $this->assertSame(['kopya_sil', ['veritabani' => 'erp_geri_20261120_100000_inceleme']], [$istek['tur'], $istek['parametreler']]);
    }
}
