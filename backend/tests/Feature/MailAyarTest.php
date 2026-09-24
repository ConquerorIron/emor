<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TestMaili;
use App\Models\MailAyari;
use App\Models\User;
use Database\Seeders\MailAyarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MailAyarTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create(['ad' => 'Deneme Yönetici']);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $degisen
     */
    private function kayitliAyar(array $degisen = []): MailAyari
    {
        return MailAyari::query()->create([
            'anahtar' => MailAyari::ANAHTAR,
            'sunucu' => 'smtp.ornek.test',
            'port' => 587,
            'sifreleme' => MailAyari::SIFRELEME_TLS,
            'kullanici_adi' => 'gonderen@ornek.test',
            'sifre' => 'kayitli-sifre',
            'gonderen_adres' => 'gonderen@ornek.test',
            'gonderen_ad' => 'eMOR ERP',
            ...$degisen,
        ]);
    }

    /**
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function govde(array $degisen = []): array
    {
        return [
            'sunucu' => 'smtp.ornek.test',
            'port' => 587,
            'sifreleme' => 'tls',
            'kullanici_adi' => 'gonderen@ornek.test',
            'sifre' => '',
            'gonderen_adres' => 'gonderen@ornek.test',
            'gonderen_ad' => 'eMOR ERP',
            'yonlendirme_adresi' => null,
            ...$degisen,
        ];
    }

    public function test_oturumsuz_istek_401_doner(): void
    {
        $this->getJson('/api/v1/ayarlar/mail')->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function yoneticiUclari(): array
    {
        return [
            'görüntüleme' => ['GET', '/api/v1/ayarlar/mail', []],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/mail', ['sunucu' => 'baska.test', 'port' => 25, 'sifreleme' => 'yok', 'gonderen_adres' => 'a@b.test', 'gonderen_ad' => 'x']],
            'test maili' => ['POST', '/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test']],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('yoneticiUclari')]
    public function test_standart_kullanici_403_alir_ayar_degismez_mail_gitmez(string $yontem, string $url, array $govde): void
    {
        Mail::fake();
        $ayar = $this->kayitliAyar();
        $this->actingAs(User::factory()->create());

        $this->json($yontem, $url, $govde)
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');

        $this->assertSame('smtp.ornek.test', $ayar->refresh()->sunucu);
        Mail::assertNothingSent();
    }

    public function test_tanim_yoksa_null_doner(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ayarlar/mail')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_tanim_kaydedilir_sifre_sifreli_saklanir_ve_donmez(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/mail', $this->govde(['sifre' => 'yeni-sifre']))
            ->assertOk()
            ->assertJsonPath('data.sunucu', 'smtp.ornek.test')
            ->assertJsonPath('data.sifre_dolu', true)
            ->assertJsonMissingPath('data.sifre');

        $ayar = MailAyari::query()->firstOrFail();
        $this->assertSame('yeni-sifre', $ayar->sifre);
        $this->assertNotSame('yeni-sifre', $ayar->getRawOriginal('sifre'));
    }

    public function test_ayni_hedefte_bos_sifre_kayitli_sifreyi_korur(): void
    {
        $this->yonetici();
        $this->kayitliAyar();

        $this->putJson('/api/v1/ayarlar/mail', $this->govde(['gonderen_ad' => 'Yeni Ad']))
            ->assertOk()
            ->assertJsonPath('data.gonderen_ad', 'Yeni Ad');

        $this->assertSame('kayitli-sifre', MailAyari::query()->firstOrFail()->sifre);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function farkliHedefler(): array
    {
        return [
            'sunucu' => [['sunucu' => 'saldirgan.example']],
            'port' => [['port' => 2525]],
            'kullanıcı' => [['kullanici_adi' => 'baska@ornek.test']],
            // Şifrelemesiz bağlantıya geçiş kayıtlı şifreyi açık metinle göndermesin
            'şifreleme' => [['sifreleme' => 'yok']],
        ];
    }

    public function test_port_metin_olarak_gelse_de_ayni_hedefte_kayit_yapilir(): void
    {
        $this->yonetici();
        $this->kayitliAyar();

        $this->putJson('/api/v1/ayarlar/mail', $this->govde(['port' => '587']))
            ->assertOk()
            ->assertJsonPath('data.port', 587);

        $this->assertSame('kayitli-sifre', MailAyari::query()->firstOrFail()->sifre);
    }

    /**
     * @param  array<string, mixed>  $degisen
     */
    #[DataProvider('farkliHedefler')]
    public function test_hedef_degisince_bos_sifreyle_kayit_422_ile_reddedilir(array $degisen): void
    {
        $this->yonetici();
        $this->kayitliAyar();

        $this->putJson('/api/v1/ayarlar/mail', $this->govde($degisen))
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'Sunucu, port, kullanıcı adı veya şifreleme değiştiğinde SMTP şifresi yeniden girilmelidir.');

        $this->assertSame('smtp.ornek.test', MailAyari::query()->value('sunucu'));
    }

    public function test_sunucu_adina_sema_veya_bosluk_girilemez(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/mail', $this->govde(['sunucu' => 'smtp://x.test:25']))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('sunucu', 'hatalar');

        $this->assertDatabaseCount('mail_ayarlari', 0);
    }

    public function test_test_maili_tanimdaki_mailer_ile_aliciya_gider(): void
    {
        Mail::fake();
        $this->yonetici();
        $this->kayitliAyar();

        $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test'])
            ->assertOk()
            ->assertExactJson(['data' => ['gonderildi' => true]]);

        Mail::assertSent(TestMaili::class, fn (TestMaili $mail): bool => $mail->hasTo('alici@ornek.test')
            && $mail->mailer === 'uygulama'
            && $mail->gonderenKullanici === 'Deneme Yönetici');
    }

    public function test_yonlendirme_adresi_doluysa_mail_yalniz_oraya_gider(): void
    {
        Mail::fake();
        $this->yonetici();
        $this->kayitliAyar(['yonlendirme_adresi' => 'test-kutusu@ornek.test']);

        $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'gercek-alici@ornek.test'])->assertOk();

        Mail::assertSent(TestMaili::class, fn (TestMaili $mail): bool => $mail->hasTo('test-kutusu@ornek.test')
            && ! $mail->hasTo('gercek-alici@ornek.test'));
    }

    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function sifrelemeler(): array
    {
        return [
            'STARTTLS zorunlu' => ['tls', 'smtp', true, true],
            'SSL' => ['ssl', 'smtps', false, true],
            'şifresiz' => ['yok', 'smtp', false, false],
        ];
    }

    #[DataProvider('sifrelemeler')]
    public function test_sifreleme_secimi_mailer_ayarina_yansir(string $sifreleme, string $sema, bool $tlsZorunlu, bool $otomatikTls): void
    {
        Mail::fake();
        $this->yonetici();
        $this->kayitliAyar(['sifreleme' => $sifreleme]);

        $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test'])->assertOk();

        $this->assertSame($sema, config('mail.mailers.uygulama.scheme'));
        $this->assertSame($tlsZorunlu, config('mail.mailers.uygulama.require_tls'));
        $this->assertSame($otomatikTls, config('mail.mailers.uygulama.auto_tls'));
        $this->assertSame(['address' => 'gonderen@ornek.test', 'name' => 'eMOR ERP'], config('mail.mailers.uygulama.from'));
    }

    public function test_sifre_girilmemisse_test_maili_422_ile_reddedilir(): void
    {
        Mail::fake();
        $this->yonetici();
        $this->kayitliAyar(['sifre' => null]);

        $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'SMTP şifresi girilmemiş. Ayarlar → Mail (SMTP) ekranından şifreyi girin.');

        Mail::assertNothingSent();
    }

    public function test_tanim_yokken_test_maili_422_ile_reddedilir(): void
    {
        Mail::fake();
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.mail.0', 'Mail (SMTP) ayarı tanımlanmamış. Ayarlar → Mail (SMTP) ekranından tanımlayın.');
    }

    public function test_smtp_baglantisi_kurulamazsa_sunucu_hatasi_422_ile_gosterilir_sifre_gosterilmez(): void
    {
        $this->yonetici();
        // Kapalı yerel port: bağlantı hemen reddedilir, dış ağa çıkılmaz
        $this->kayitliAyar(['sunucu' => '127.0.0.1', 'port' => 1, 'sifreleme' => 'yok']);

        $yanit = $this->postJson('/api/v1/ayarlar/mail/test', ['alici' => 'alici@ornek.test'])
            ->assertUnprocessable();

        $this->assertStringStartsWith('Mail gönderilemedi:', (string) $yanit->json('hatalar.alici.0'));
        $this->assertStringNotContainsString('kayitli-sifre', $yanit->getContent());
    }

    public function test_test_maili_icerigi_gonderen_kullaniciyi_anar(): void
    {
        $mail = new TestMaili('Deneme Yönetici');

        $mail->assertSeeInText('Deneme Yönetici');
        $mail->assertHasSubject('eMOR ERP — SMTP test maili');
    }

    public function test_seeder_ilk_tanimi_sifresiz_acar_ve_var_olani_ezmez(): void
    {
        $this->seed(MailAyarSeeder::class);

        $ayar = MailAyari::query()->firstOrFail();
        $this->assertSame('smtp.office365.com', $ayar->sunucu);
        $this->assertSame(587, $ayar->port);
        $this->assertSame('tls', $ayar->sifreleme);
        $this->assertSame('emor@tersane-istanbul.com', $ayar->kullanici_adi);
        $this->assertSame('eMOR ERP', $ayar->gonderen_ad);
        $this->assertNull($ayar->sifre);

        $ayar->update(['gonderen_ad' => 'Elle Değişti']);
        $this->seed(MailAyarSeeder::class);

        $this->assertDatabaseCount('mail_ayarlari', 1);
        $this->assertSame('Elle Değişti', MailAyari::query()->value('gonderen_ad'));
    }
}
