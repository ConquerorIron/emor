<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EntegratorBaglanti;
use App\Models\SqlBaglanti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tek seferlik içe aktarma komutu — sentetik veriyle; gerçek hesap bilgisi girmez.
 */
final class EntegratorTanimAktarTest extends TestCase
{
    use RefreshDatabase;

    private ?string $dosya = null;

    protected function tearDown(): void
    {
        if ($this->dosya !== null && is_file($this->dosya)) {
            unlink($this->dosya);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $degisen
     */
    private function tanimDosyasi(array $degisen = []): string
    {
        $this->dosya = (string) tempnam(sys_get_temp_dir(), 'entegrator');
        file_put_contents($this->dosya, json_encode([
            'kullanici_adi' => 'aktarim-kullanici',
            'sifre' => 'aktarim-sifre',
            'vkn' => '1234567890',
            'posta_kutusu' => 'urn:mail:aktarim-pk@ornek.test',
            'gonderici_birim' => 'urn:mail:aktarim-gb@ornek.test',
            ...$degisen,
        ]));

        return $this->dosya;
    }

    public function test_tanim_sifreli_olarak_olusturulur_ve_cikti_sifreyi_icermez(): void
    {
        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $this->tanimDosyasi()])
            ->expectsOutput('test tanımı oluşturuldu.')
            ->doesntExpectOutputToContain('aktarim-sifre')
            ->assertSuccessful();

        $tanim = EntegratorBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('aktarim-kullanici', $tanim->kullanici_adi);
        $this->assertSame('aktarim-sifre', $tanim->sifre);
        $this->assertNotSame('aktarim-sifre', $tanim->getRawOriginal('sifre'));
        $this->assertFalse($tanim->aktif);
    }

    public function test_ayni_tanim_tekrar_aktarilinca_kayit_cogalmaz_ve_kimlik_surumu_degismez(): void
    {
        $dosya = $this->tanimDosyasi();
        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $dosya])->assertSuccessful();

        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $dosya])
            ->expectsOutput('test tanımı zaten güncel; değişiklik yapılmadı.')
            ->assertSuccessful();

        $this->assertDatabaseCount('entegrator_baglantilari', 1);
        $this->assertSame(1, EntegratorBaglanti::query()->value('kimlik_surumu'));
    }

    public function test_elle_degismis_tanim_uzerine_yaz_verilmeden_ezilmez(): void
    {
        EntegratorBaglanti::factory()->create(['kullanici_adi' => 'elle-girilen']);

        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $this->tanimDosyasi()])
            ->expectsOutputToContain('farklı bir tanım var; dokunulmadı')
            ->assertFailed();

        $tanim = EntegratorBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('elle-girilen', $tanim->kullanici_adi);
        $this->assertSame('deneme-sifre', $tanim->sifre);
    }

    public function test_uzerine_yaz_ile_farkli_tanim_guncellenir(): void
    {
        EntegratorBaglanti::factory()->create(['kullanici_adi' => 'elle-girilen']);

        $this->artisan('entegrator:tanim-aktar', [
            'ortam' => 'test',
            '--dosya' => $this->tanimDosyasi(),
            '--uzerine-yaz' => true,
        ])->expectsOutput('test tanımı güncellendi.')->assertSuccessful();

        $tanim = EntegratorBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('aktarim-kullanici', $tanim->kullanici_adi);
        $this->assertSame('aktarim-sifre', $tanim->sifre);
    }

    public function test_aktif_yap_yalniz_entegrator_ortamini_degistirir_sql_ortamina_dokunmaz(): void
    {
        $sql = SqlBaglanti::query()->create([
            'ortam' => 'canli', 'sunucu' => 'sql.local', 'veritabani' => 'ERP',
            'kullanici_adi' => 'sa', 'sifre' => 's', 'aktif' => true,
        ]);

        $this->artisan('entegrator:tanim-aktar', [
            'ortam' => 'test',
            '--dosya' => $this->tanimDosyasi(),
            '--aktif-yap' => true,
        ])->assertSuccessful();

        $this->assertTrue(EntegratorBaglanti::query()->where('ortam', 'test')->value('aktif'));
        $this->assertTrue($sql->refresh()->aktif);
    }

    public function test_sifresiz_tanim_reddedilir_ve_kayit_olusmaz(): void
    {
        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $this->tanimDosyasi(['sifre' => ''])])
            ->assertExitCode(2);

        $this->assertDatabaseCount('entegrator_baglantilari', 0);
    }

    public function test_bozuk_json_icerigi_hata_mesajina_yazilmaz(): void
    {
        $this->dosya = (string) tempnam(sys_get_temp_dir(), 'entegrator');
        file_put_contents($this->dosya, '{"sifre": "gizli-deger"');

        $this->artisan('entegrator:tanim-aktar', ['ortam' => 'test', '--dosya' => $this->dosya])
            ->expectsOutput('Tanım dosyası geçerli JSON değil.')
            ->doesntExpectOutputToContain('gizli-deger')
            ->assertExitCode(2);
    }
}
