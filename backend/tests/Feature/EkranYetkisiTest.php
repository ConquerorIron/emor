<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EkranTasarimi;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sol menüdeki ekranların görüntüle/güncelle izinleri (kullanıcı isteği
 * 2026-09-24) ve yetki devrinin sınırı (App\Yetki\YetkiSiniri).
 */
final class EkranYetkisiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $izinler  rol_izinleri'ne olduğu gibi yazılır
     */
    private function izinli(array $izinler): User
    {
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($this->rol('Rol '.$kullanici->id, $izinler));

        return $kullanici;
    }

    /**
     * @param  list<string>  $izinler
     */
    private function rol(string $ad, array $izinler): Rol
    {
        $rol = Rol::query()->create(['ad' => $ad]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));

        return $rol;
    }

    public function test_yonetici_olmayan_kendinden_yetkili_pasif_hesabi_rol_gondermeden_de_acamaz(): void
    {
        $veren = $this->izinli(['kullanicilar.goruntule', 'kullanicilar.guncelle']);
        $hedef = $this->izinli(['sql_baglantilari.goruntule', 'sql_baglantilari.guncelle']);
        $hedef->update(['aktif_mi' => false]);

        $this->actingAs($veren)->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['aktif_mi' => true])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.aktif_mi.0', 'Yalnız kendinizde olan izinleri verebilir ya da değiştirebilirsiniz.');
        $this->assertFalse($hedef->fresh()->aktif_mi);

        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['aktif_mi' => true])->assertOk();
        $this->assertTrue($hedef->fresh()->aktif_mi);
    }

    public function test_yonetici_olmayan_kendi_yetki_sinirindaki_pasif_hesabi_acabilir(): void
    {
        $veren = $this->izinli(['kullanicilar.goruntule', 'kullanicilar.guncelle', 'efatura.goruntule']);
        $hedef = $this->izinli(['efatura.goruntule']);
        $hedef->update(['aktif_mi' => false]);

        $this->actingAs($veren)->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['aktif_mi' => true])->assertOk();
        $this->assertTrue($hedef->fresh()->aktif_mi);
    }

    /**
     * [ekran, okuma ucu, yazma ucu (metot, adres)]
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function ekranlar(): array
    {
        return [
            'satınalma talebi' => ['satinalma_talebi', '/api/v1/satinalma/ozellikler', 'post', '/api/v1/satinalma/talepler'],
            'SQL bağlantıları' => ['sql_baglantilari', '/api/v1/ayarlar/sql-baglantilari', 'put', '/api/v1/ayarlar/sql-baglantilari/test'],
            'entegratör bağlantıları' => ['entegrator_baglantilari', '/api/v1/ayarlar/entegrator-baglantilari', 'put', '/api/v1/ayarlar/entegrator-baglantilari/test'],
            'mail ayarları' => ['mail_ayarlari', '/api/v1/ayarlar/mail', 'put', '/api/v1/ayarlar/mail'],
            'alarm kuralları' => ['alarm_kurallari', '/api/v1/ayarlar/alarm-kurallari', 'put', '/api/v1/ayarlar/alarm-kurallari/gunluk_ozet'],
            'kullanıcılar' => ['kullanicilar', '/api/v1/ayarlar/kullanicilar', 'post', '/api/v1/ayarlar/kullanicilar'],
            'roller' => ['roller', '/api/v1/ayarlar/izinler', 'post', '/api/v1/ayarlar/roller'],
            'ekran tasarımı' => ['ekran_tasarimi', '/api/v1/ekranlar/satinalma.talep/surumler', 'put', '/api/v1/ekranlar/satinalma.talep/taslak'],
        ];
    }

    #[DataProvider('ekranlar')]
    public function test_izni_olmayan_ekrani_okuyamaz_ve_guncelleyemez(string $ekran, string $okuma, string $metot, string $yazma): void
    {
        $kullanici = $this->izinli([]);

        $this->actingAs($kullanici)
            ->getJson($okuma)
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
        $this->actingAs($kullanici)->json($metot, $yazma, [])->assertForbidden();
    }

    #[DataProvider('ekranlar')]
    public function test_goruntuleme_izni_okutur_ama_guncelletmez(string $ekran, string $okuma, string $metot, string $yazma): void
    {
        $kullanici = $this->izinli(["{$ekran}.goruntule"]);

        // Okuma ucu ERP'ye gidebilir (aktif ortam yoksa 422); önemli olan yetki engeli olmaması
        $this->assertNotSame(403, $this->actingAs($kullanici)->getJson($okuma)->status());

        $this->actingAs($kullanici)
            ->json($metot, $yazma, [])
            ->assertForbidden();
    }

    #[DataProvider('ekranlar')]
    public function test_guncelleme_izni_yazma_ucunu_acar(string $ekran, string $okuma, string $metot, string $yazma): void
    {
        $kullanici = $this->izinli(["{$ekran}.goruntule", "{$ekran}.guncelle"]);

        // Boş gövde doğrulamadan döner; yetki engeli yok
        $this->assertNotSame(403, $this->actingAs($kullanici)->json($metot, $yazma, [])->status());
    }

    public function test_rol_listesi_kullanicilar_ekraninda_da_okunur_ama_izin_katalogu_okunmaz(): void
    {
        $kullanici = $this->izinli(['kullanicilar.goruntule']);

        $this->actingAs($kullanici)->getJson('/api/v1/ayarlar/roller')->assertOk();
        $this->actingAs($kullanici)->getJson('/api/v1/ayarlar/izinler')->assertForbidden();
    }

    public function test_yalniz_goruntuleme_izniyle_tasarim_taslagi_acilmaz(): void
    {
        $this->actingAs($this->izinli(['ekran_tasarimi.goruntule']))
            ->getJson('/api/v1/ekranlar/satinalma.talep/taslak')
            ->assertOk()
            ->assertJsonPath('data.ekran', 'satinalma.talep');

        $this->assertSame(0, EkranTasarimi::query()->where('durum', EkranTasarimi::DURUM_TASLAK)->count());
    }

    // --- Yetki devrinin sınırı ------------------------------------------------------

    public function test_yonetici_olmayan_kendinde_olmayan_izni_role_veremez(): void
    {
        $kullanici = $this->izinli(['roller.goruntule', 'roller.guncelle']);

        $this->actingAs($kullanici)
            ->postJson('/api/v1/ayarlar/roller', ['ad' => 'Yeni', 'izinler' => ['sql_baglantilari.guncelle']])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.izinler.0', 'Yalnız kendinizde olan izinleri verebilir ya da değiştirebilirsiniz.');

        $this->actingAs($kullanici)
            ->postJson('/api/v1/ayarlar/roller', ['ad' => 'Yeni', 'izinler' => ['roller.goruntule']])
            ->assertCreated();
    }

    public function test_yonetici_olmayan_kendinden_yetkili_rolu_duzenleyemez_ve_silemez(): void
    {
        $kullanici = $this->izinli(['roller.goruntule', 'roller.guncelle']);
        $guclu = $this->rol('Güçlü', ['sql_baglantilari.goruntule', 'sql_baglantilari.guncelle']);

        $this->actingAs($kullanici)
            ->putJson("/api/v1/ayarlar/roller/{$guclu->id}", ['ad' => 'Güçlü', 'izinler' => []])
            ->assertForbidden();
        $this->actingAs($kullanici)
            ->deleteJson("/api/v1/ayarlar/roller/{$guclu->id}")
            ->assertForbidden();

        $this->assertModelExists($guclu);
    }

    public function test_sistem_yoneticisi_her_izni_verebilir(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson('/api/v1/ayarlar/roller', ['ad' => 'Tam', 'izinler' => ['sql_baglantilari.guncelle', 'roller.guncelle']])
            ->assertCreated();
    }

    public function test_yonetici_olmayan_kendinden_yetkili_rolu_kullaniciya_atayamaz(): void
    {
        $kullanici = $this->izinli(['kullanicilar.goruntule', 'kullanicilar.guncelle']);
        $guclu = $this->rol('Güçlü', ['roller.goruntule', 'roller.guncelle']);
        $hedef = User::factory()->create();

        $this->actingAs($kullanici)
            ->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['rol_idleri' => [$guclu->id]])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.rol_idleri.0', 'Yalnız kendinizde olan izinleri verebilir ya da değiştirebilirsiniz.');

        // Kendinden yetkili rolü mevcut kullanıcıdan da çıkaramaz
        $hedef->roller()->attach($guclu);
        $this->actingAs($kullanici)
            ->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['rol_idleri' => []])
            ->assertUnprocessable();

        $this->assertSame([$guclu->id], $hedef->roller()->pluck('roller.id')->all());
    }

    public function test_yonetici_olmayan_sistem_yoneticisi_hesabini_degistiremez(): void
    {
        $kullanici = $this->izinli(['kullanicilar.goruntule', 'kullanicilar.guncelle']);
        $yonetici = User::factory()->yonetici()->create();

        $this->actingAs($kullanici)
            ->putJson("/api/v1/ayarlar/kullanicilar/{$yonetici->id}", ['aktif_mi' => false])
            ->assertForbidden();

        $this->assertTrue($yonetici->fresh()->aktif_mi);
    }
}
