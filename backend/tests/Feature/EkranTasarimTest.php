<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Ekranlar\SatinalmaTalebiKatalogu;
use App\Models\EkranTasarimi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EkranTasarimTest extends TestCase
{
    use RefreshDatabase;

    private const EKRAN = SatinalmaTalebiKatalogu::ANAHTAR;

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_tasarim_yokken_katalogun_varsayilan_duzeni_doner(): void
    {
        $this->actingAs(User::factory()->create());

        $yanit = $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/tasarim');

        $yanit->assertOk()
            ->assertJsonPath('data.ekran', self::EKRAN)
            ->assertJsonCount(2, 'data.duzen.bolumler');

        // Katalog alanları da gelir (frontend çizim için kullanır)
        $this->assertNotEmpty($yanit->json('data.katalog'));
    }

    public function test_tanimsiz_ekran_reddedilir(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/ekranlar/olmayan.ekran/tasarim')->assertStatus(422);
    }

    public function test_yonetici_olmayan_taslaga_erisemez(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak')->assertStatus(403);
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => ['bolumler' => []]])
            ->assertStatus(403);
        $this->postJson('/api/v1/ekranlar/'.self::EKRAN.'/yayinla')->assertStatus(403);
    }

    public function test_taslak_yayindakinden_acilir_ve_kaydedilir(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak')
            ->assertOk()
            ->assertJsonCount(2, 'data.duzen.bolumler');

        $this->assertDatabaseHas('ekran_tasarimlari', [
            'ekran_anahtari' => self::EKRAN,
            'durum' => EkranTasarimi::DURUM_TASLAK,
        ]);

        $duzen = [
            'bolumler' => [
                [
                    'anahtar' => 'talep',
                    'genislik' => 12,
                    'alanlar' => [
                        ['alan' => 'personel_adi', 'genislik' => 12, 'zorunlu' => true],
                        ['alan' => 'tarih', 'genislik' => 4],
                    ],
                ],
            ],
        ];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertOk()
            ->assertJsonPath('data.duzen.bolumler.0.alanlar.0.zorunlu', true)
            ->assertJsonPath('data.duzen.bolumler.0.alanlar.1.genislik', 4);
    }

    public function test_bilinmeyen_alan_reddedilir(): void
    {
        $this->yonetici();

        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [['alan' => 'personel_adi'], ['alan' => 'uydurma_alan']],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertStatus(422)
            ->assertJsonPath('kod', 'DOGRULAMA');
    }

    public function test_kaldirilamaz_alan_cikarilamaz(): void
    {
        $this->yonetici();

        // personel_adi ve tarih kaldırılamaz; yalnız tarih bırakılırsa hata
        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [['alan' => 'tarih']],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertStatus(422);
    }

    public function test_ayni_alan_iki_kez_yerlestirilemez(): void
    {
        $this->yonetici();

        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [
                ['alan' => 'personel_adi'],
                ['alan' => 'tarih'],
                ['alan' => 'personel_adi'],
            ],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertStatus(422);
    }

    public function test_gecersiz_genislik_reddedilir(): void
    {
        $this->yonetici();

        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [
                ['alan' => 'personel_adi', 'genislik' => 13],
                ['alan' => 'tarih'],
            ],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertStatus(422);
    }

    public function test_salt_okunur_sabit_alan_duzenlenebilir_yapilamaz(): void
    {
        $this->yonetici();

        // No alanı ERP tarafından üretilir; tasarım 'salt_okunur' olmasa da kilitli kalır
        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [
                ['alan' => 'personel_adi'],
                ['alan' => 'tarih'],
                ['alan' => 'no', 'salt_okunur' => false],
            ],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertOk()
            ->assertJsonPath('data.duzen.bolumler.0.alanlar.2.salt_okunur', true);
    }

    public function test_gizli_alan_varsayilanini_korur_zorunlulugu_dusurur(): void
    {
        $this->yonetici();

        // ERP deseni: alan formda görünmez ama değeri sabit gider
        $duzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [
                ['alan' => 'personel_adi'],
                ['alan' => 'tarih'],
                ['alan' => 'ilgi_konusu', 'gizli' => true, 'zorunlu' => true, 'varsayilan' => '7'],
            ],
        ]]];

        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $duzen])
            ->assertOk()
            ->assertJsonPath('data.duzen.bolumler.0.alanlar.2.gizli', true)
            ->assertJsonPath('data.duzen.bolumler.0.alanlar.2.varsayilan', '7')
            // Gizli alan doldurulamayacağı için zorunluluk düşürülür
            ->assertJsonMissingPath('data.duzen.bolumler.0.alanlar.2.zorunlu');
    }

    public function test_bolum_basligi_duzenlenebilir_ve_bos_birakilabilir(): void
    {
        $this->yonetici();

        $zorunlular = [['alan' => 'personel_adi'], ['alan' => 'tarih']];

        // Özel başlık
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', [
            'duzen' => ['bolumler' => [
                ['anahtar' => 'talep', 'baslik' => '  Genel Bilgiler  ', 'alanlar' => $zorunlular],
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.duzen.bolumler.0.baslik', 'Genel Bilgiler');

        // Boş başlık = başlık gösterme (anahtar korunur, i18n'e düşmez)
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', [
            'duzen' => ['bolumler' => [
                ['anahtar' => 'talep', 'baslik' => '', 'alanlar' => $zorunlular],
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.duzen.bolumler.0.baslik', '');

        // Anahtar hiç yoksa katalogun i18n başlığı kullanılsın diye alan yazılmaz
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', [
            'duzen' => ['bolumler' => [
                ['anahtar' => 'talep', 'alanlar' => $zorunlular],
            ]],
        ])
            ->assertOk()
            ->assertJsonMissingPath('data.duzen.bolumler.0.baslik');
    }

    public function test_yayinlama_akisi_ve_geri_alma(): void
    {
        $this->yonetici();

        $ilkDuzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [['alan' => 'personel_adi'], ['alan' => 'tarih']],
        ]]];
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $ilkDuzen])->assertOk();

        $this->postJson('/api/v1/ekranlar/'.self::EKRAN.'/yayinla')->assertOk();

        // Yayındaki düzen artık form çiziminde görünür
        $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/tasarim')
            ->assertOk()
            ->assertJsonCount(1, 'data.duzen.bolumler');

        $ilkSurum = (int) EkranTasarimi::query()
            ->where('durum', EkranTasarimi::DURUM_YAYINDA)->value('surum');

        // İkinci sürüm: aciklama da eklensin
        $ikinciDuzen = ['bolumler' => [[
            'anahtar' => 'talep',
            'alanlar' => [['alan' => 'personel_adi'], ['alan' => 'tarih'], ['alan' => 'aciklama']],
        ]]];
        $this->putJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak', ['duzen' => $ikinciDuzen])->assertOk();
        $this->postJson('/api/v1/ekranlar/'.self::EKRAN.'/yayinla')->assertOk();

        $this->assertSame(
            1,
            EkranTasarimi::query()->where('durum', EkranTasarimi::DURUM_YAYINDA)->count(),
            'Ekran başına yalnız bir yayında sürüm olmalı',
        );
        $this->assertSame(
            1,
            EkranTasarimi::query()->where('durum', EkranTasarimi::DURUM_ARSIV)->count(),
        );

        // İlk sürüme geri dön → yeni taslak olarak gelir
        $this->postJson('/api/v1/ekranlar/'.self::EKRAN.'/surumler/'.$ilkSurum.'/geri-al')
            ->assertOk()
            ->assertJsonCount(2, 'data.duzen.bolumler.0.alanlar');
    }

    public function test_surum_gecmisi_listelenir(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/taslak')->assertOk();
        $this->postJson('/api/v1/ekranlar/'.self::EKRAN.'/yayinla')->assertOk();

        $this->getJson('/api/v1/ekranlar/'.self::EKRAN.'/surumler')
            ->assertOk()
            ->assertJsonPath('data.0.durum', EkranTasarimi::DURUM_YAYINDA);
    }
}
