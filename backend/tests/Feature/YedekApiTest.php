<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KopyaDurumu;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-014: Yedekler API'si (README §4.10 (1), §5.2 "istek yalnız kaydedilir").
 */
final class YedekApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.implode(',', $izinler).random_int(1, 99999)]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * Uç → gereken izin. Her uç izinsiz 403, girişsiz 401.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function uclar(): array
    {
        $k = '00000000-0000-4000-8000-000000000001';

        return [
            'liste' => ['get', '/yedekler', 'yedekler.goruntule'],
            'özet' => ['get', '/yedekler/ozet', 'yedekler.goruntule'],
            'istek durumu' => ['get', "/yedekler/istekler/{$k}", 'yedekler.goruntule'],
            'işlem günlüğü' => ['get', '/yedek-islem-kayitlari', 'yedekler.goruntule'],
            'yedek al' => ['post', '/yedekler', 'yedekler.al'],
            'tara' => ['post', '/yedekler/tara', 'yedekler.al'],
            'doğrula' => ['post', "/yedekler/{$k}/dogrula", 'yedekler.al'],
            'açıklama' => ['put', "/yedekler/{$k}/aciklama", 'yedekler.al'],
            'sabitle' => ['post', "/yedekler/{$k}/sabit", 'yedekler.sil'],
            'sabit çöz' => ['delete', "/yedekler/{$k}/sabit", 'yedekler.sil'],
            'sil' => ['delete', "/yedekler/{$k}", 'yedekler.sil'],
        ];
    }

    #[DataProvider('uclar')]
    public function test_her_uc_girissiz_401_izinsiz_403(string $yontem, string $yol, string $izin): void
    {
        $this->json($yontem, self::K.$yol)->assertUnauthorized()->assertJsonPath('kod', 'YETKISIZ');

        // Görüntüleme izni değiştiren uçları açmaz; başka ekranın izni hiçbirini açmaz
        $baska = $izin === 'yedekler.goruntule' ? 'yedek_planlari.goruntule' : 'yedekler.goruntule';
        $this->actingAs($this->izinli($baska))->json($yontem, self::K.$yol)->assertForbidden()->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }

    public function test_modul_kapaliyken_uclar_409_yedekleme_kapali(): void
    {
        config(['yedekleme.aktif' => false]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler')
            ->assertStatus(409)->assertJsonPath('kod', 'YEDEKLEME_KAPALI');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_elle_yedek_istegi_pg_dump_calistirmaz_istek_birakir_denetime_yazar(): void
    {
        Process::fake();
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');

        $yanit = $this->actingAs($kullanici)->postJson(self::K.'/yedekler', ['aciklama' => 'Ay sonu'])
            ->assertStatus(202)->assertJsonPath('data.durum', 'kuyrukta');

        Process::assertNothingRan();
        $kutu = app(IstekKutusu::class);
        $bekleyen = $kutu->bekleyenler();
        $this->assertCount(1, $bekleyen);
        $istek = json_decode((string) file_get_contents($bekleyen[0]), true);
        $this->assertSame(['tur' => 'yedek_al', 'parametreler' => ['kaynak' => 'elle', 'aciklama' => 'Ay sonu'], 'kullanici_id' => $kullanici->id],
            ['tur' => $istek['tur'], 'parametreler' => $istek['parametreler'], 'kullanici_id' => $istek['kullanici_id']]);
        $this->assertSame($istek['kimlik'], $yanit->json('data.istek_kimligi'));

        $kayit = YedekIslemKaydi::query()->sole();
        $this->assertSame(['istek', $kullanici->id, 'yedek_al'], [$kayit->islem, $kayit->kullanici_id, $kayit->ayrinti['tur']]);

        // Yoklama: kuyrukta; sonuç yazılınca tamam (serbest metin mesaj dönmez)
        $this->getJson(self::K.'/yedekler/istekler/'.$istek['kimlik'])->assertOk()->assertJsonPath('data.durum', 'kuyrukta');
        $kutu->sil($bekleyen[0]);
        $kutu->sonucYaz($istek['kimlik'], 'tamam', null, '/var/lib/emor-yedek/yedekler/gizli-yol', ['yedek_kimligi' => 'abc']);
        $durum = $this->getJson(self::K.'/yedekler/istekler/'.$istek['kimlik'])->assertOk();
        $durum->assertJsonPath('data.durum', 'tamam')->assertJsonPath('data.yedek_kimligi', 'abc')->assertJsonMissingPath('data.mesaj');
    }

    public function test_denetim_kaydi_yazilamazsa_istek_yayinlanmaz_500(): void
    {
        // Günlük klasörü düz bir dosyanın altında: kanal yazamaz
        touch($this->yedekKoku.'/dosya');
        config(['logging.channels.yedekleme.path' => $this->yedekKoku.'/dosya/yedekleme.log']);
        Log::forgetChannel('yedekleme');

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler/tara')
            ->assertStatus(500)->assertJsonPath('kod', 'BILINMEYEN');

        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
        $this->assertSame(0, YedekIslemKaydi::query()->count());
    }

    public function test_istek_yayinlanamazsa_ayni_kimlikle_basarisiz_izleme_kaydi_eklenir_500(): void
    {
        // İstek klasörü düz bir dosyanın altında: istek yazılamaz
        touch($this->yedekKoku.'/dosya');
        config(['yedekleme.istek_klasoru' => $this->yedekKoku.'/dosya/istekler']);
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');

        $this->actingAs($kullanici)->postJson(self::K.'/yedekler/tara')->assertStatus(500);

        $kayitlar = YedekIslemKaydi::query()->orderBy('id')->get();
        $this->assertSame([['istek', 'basarili'], ['istek', 'basarisiz']], $kayitlar->map(fn (YedekIslemKaydi $k): array => [$k->islem, $k->sonuc->value])->all());
        $this->assertSame($kayitlar[0]->ayrinti['istek'], $kayitlar[1]->ayrinti['istek']);
        $this->assertSame(['tara', 'ISTEK_YAYINLANAMADI', $kullanici->id], [$kayitlar[1]->ayrinti['tur'], $kayitlar[1]->ayrinti['kod'], $kayitlar[1]->kullanici_id]);
        $this->assertFileDoesNotExist($this->yedekKoku.'/dosya/istekler');
    }

    public function test_webden_geri_yukleme_kaynagi_yerel_sifresiz_uzak_ve_yalniz_sifreli_kopyaya_gore_bildirilir(): void
    {
        $yerel = Yedek::factory()->create();
        $uzak = Yedek::factory()->create(['yerel_silinme_zamani' => now()]);
        $sifreli = Yedek::factory()->create(['yerel_silinme_zamani' => now()]);
        $ikinciDisk = YedekHedefi::factory()->create(['ad' => 'İkinci disk']);
        $bulut = YedekHedefi::factory()->create(['ad' => 'Bulut']);
        YedekKopyasi::query()->create(['yedek_id' => $uzak->id, 'yedek_hedefi_id' => $ikinciDisk->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'a.dump', 'sifreli' => false]);
        YedekKopyasi::query()->create(['yedek_id' => $sifreli->id, 'yedek_hedefi_id' => $bulut->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'b.dump.gpg', 'sifreli' => true]);
        // Başarısız şifresiz kopya kaynak sayılmaz
        YedekKopyasi::query()->create(['yedek_id' => $sifreli->id, 'yedek_hedefi_id' => $ikinciDisk->id, 'durum' => KopyaDurumu::Basarisiz, 'sifreli' => false]);

        $satirlar = collect($this->actingAs($this->izinli('yedekler.goruntule'))->getJson(self::K.'/yedekler')->assertOk()->json('data'))->keyBy('kimlik');

        $durum = fn (Yedek $y): array => [$satirlar[$y->kimlik]['webden_geri_yuklenebilir'], $satirlar[$y->kimlik]['geri_yukleme_engeli']];
        $this->assertSame([true, null], $durum($yerel));
        $this->assertSame([true, null], $durum($uzak));
        $this->assertSame([false, 'GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI'], $durum($sifreli));
        // İmzalı ve tamam: eski bayrak yine true (doğrulama gibi işlemler onunla açılır)
        $this->assertTrue($satirlar[$sifreli->kimlik]['geri_yuklenebilir']);
    }

    public function test_ozet_canli_veritabani_adini_verir(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule'))->getJson(self::K.'/yedekler/ozet')
            ->assertOk()->assertJsonPath('data.canli_veritabani', 'erp')->assertJsonStructure(['data' => ['son_yerel', 'son_uzak', 'son_sinama', 'siradaki']]);
    }

    public function test_gecersiz_girdi_422_kod_alaniyla(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler', ['aciklama' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        $this->getJson(self::K.'/yedekler?siralama=dosya_adi;drop')->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
    }

    public function test_sabitlenmis_yedek_silinemez_409(): void
    {
        $yedek = Yedek::factory()->create(['sabit' => true]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.sil'))
            ->deleteJson(self::K.'/yedekler/'.$yedek->kimlik)
            ->assertStatus(409)->assertJsonPath('kod', 'YEDEK_SABIT');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_kopyalanmakta_olan_yedek_silinemez_409(): void
    {
        $yedek = Yedek::factory()->create();
        YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => YedekHedefi::factory()->create()->id, 'durum' => KopyaDurumu::Kopyalaniyor]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.sil'))
            ->deleteJson(self::K.'/yedekler/'.$yedek->kimlik)
            ->assertStatus(409)->assertJsonPath('kod', 'YEDEK_KULLANIMDA');
    }

    public function test_silme_istegi_birakilir(): void
    {
        $yedek = Yedek::factory()->create();

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.sil'))
            ->deleteJson(self::K.'/yedekler/'.$yedek->kimlik)->assertStatus(202);

        $istek = json_decode((string) file_get_contents(app(IstekKutusu::class)->bekleyenler()[0]), true);
        $this->assertSame(['sil', ['yedek_kimligi' => $yedek->kimlik]], [$istek['tur'], $istek['parametreler']]);
        $this->assertSame($yedek->id, YedekIslemKaydi::query()->sole()->yedek_id);
    }

    public function test_imzasiz_yedek_dogrulanamaz_409(): void
    {
        $yedek = Yedek::factory()->create(['imzali' => false]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/dogrula')
            ->assertStatus(409)->assertJsonPath('kod', 'YEDEK_DOGRULANAMAZ');
    }

    public function test_yalniz_sifreli_kopyasi_kalan_yedek_ekrandan_dogrulanamaz_istek_birakilmaz(): void
    {
        $yedek = Yedek::factory()->create(['yerel_silinme_zamani' => now()]);
        YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => YedekHedefi::factory()->create()->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'b.dump.gpg', 'sifreli' => true]);

        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler/'.$yedek->kimlik.'/dogrula')
            ->assertStatus(409)->assertJsonPath('kod', 'GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI');

        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_liste_dosya_yolu_ve_hata_metni_dondurmez_kopyalari_gosterir(): void
    {
        $yedek = Yedek::factory()->create(['hata_kodu' => 'PG_DUMP', 'hata_mesaji' => 'pg_dump: '.config('yedekleme.klasor').'/x.dump yazılamadı']);
        $hedef = YedekHedefi::factory()->create(['ad' => 'İkinci disk']);
        YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => '/mnt/ikinci/'.$yedek->dosya_adi]);
        Yedek::factory()->create(['imzali' => false]);

        $yanit = $this->actingAs($this->izinli('yedekler.goruntule'))->getJson(self::K.'/yedekler')->assertOk();

        $govde = (string) $yanit->getContent();
        $this->assertStringNotContainsString(config('yedekleme.klasor'), $govde);
        $this->assertStringNotContainsString('/mnt/ikinci', $govde);
        $this->assertStringNotContainsString('yazılamadı', $govde);
        $satir = collect($yanit->json('data'))->firstWhere('kimlik', $yedek->kimlik);
        $this->assertSame('PG_DUMP', $satir['hata_kodu']);
        $this->assertSame([['hedef_id' => $hedef->id, 'hedef_ad' => 'İkinci disk', 'hedef_tur' => 'yerel', 'durum' => 'tamam', 'sifreli' => false, 'tamamlanma_zamani' => null, 'hata_kodu' => null]], $satir['kopyalar']);
        // Süzgeç: yalnız güvenilmeyenler
        $this->getJson(self::K.'/yedekler?guvenilmeyen=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.geri_yuklenebilir', false);
    }
}
