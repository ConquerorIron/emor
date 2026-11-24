<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\YedekDurumu;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
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
            'işlem günlüğü' => ['get', '/yedek-islem-kayitlari', 'yedekler.goruntule'],
            'geri yükleme listesi' => ['get', '/geri-yuklemeler', 'yedekler.goruntule'],
            'yedek al' => ['post', '/yedekler', 'yedekler.al'],
            'tara' => ['post', '/yedekler/tara', 'yedekler.al'],
            'doğrula' => ['post', "/yedekler/{$k}/dogrula", 'yedekler.al'],
            'açıklama' => ['put', "/yedekler/{$k}/aciklama", 'yedekler.al'],
            'sabitle' => ['post', "/yedekler/{$k}/sabit", 'yedekler.sil'],
            'sabit çöz' => ['delete', "/yedekler/{$k}/sabit", 'yedekler.sil'],
            'sil' => ['delete', "/yedekler/{$k}", 'yedekler.sil'],
            // Hedefler, planlar, ayarlar (YF-16): {hedef} / {plan} testte gerçek kayıtla değişir
            'hedef listesi' => ['get', '/yedek-hedefleri', 'yedek_planlari.goruntule'],
            'hedef türleri' => ['get', '/yedek-hedefleri/turler', 'yedek_planlari.goruntule'],
            'plan listesi' => ['get', '/yedek-planlari', 'yedek_planlari.goruntule'],
            'plan önizleme' => ['post', '/yedek-planlari/onizleme', 'yedek_planlari.goruntule'],
            'ayarlar' => ['get', '/yedek-ayarlari', 'yedek_planlari.goruntule'],
            'hedef ekle' => ['post', '/yedek-hedefleri', 'yedek_planlari.guncelle'],
            'hedef güncelle' => ['put', '/yedek-hedefleri/{hedef}', 'yedek_planlari.guncelle'],
            'hedef sil' => ['delete', '/yedek-hedefleri/{hedef}', 'yedek_planlari.guncelle'],
            'hedef sına' => ['post', '/yedek-hedefleri/sina', 'yedek_planlari.guncelle'],
            'sunucu anahtarı sıfırla' => ['post', '/yedek-hedefleri/{hedef}/sunucu-anahtari-sifirla', 'yedek_planlari.guncelle'],
            'google bağlan' => ['post', '/yedek-hedefleri/{hedef}/google/baglan', 'yedek_planlari.guncelle'],
            'google dönüş' => ['post', '/yedek-hedefleri/google/donus', 'yedek_planlari.guncelle'],
            'google kes' => ['post', '/yedek-hedefleri/{hedef}/google/kes', 'yedek_planlari.guncelle'],
            'google seçici' => ['post', '/yedek-hedefleri/{hedef}/google/secici', 'yedek_planlari.guncelle'],
            'google klasör' => ['post', '/yedek-hedefleri/{hedef}/google/klasor', 'yedek_planlari.guncelle'],
            'plan ekle' => ['post', '/yedek-planlari', 'yedek_planlari.guncelle'],
            'plan güncelle' => ['put', '/yedek-planlari/{plan}', 'yedek_planlari.guncelle'],
            'plan sil' => ['delete', '/yedek-planlari/{plan}', 'yedek_planlari.guncelle'],
            'plan çalıştır' => ['post', '/yedek-planlari/{plan}/calistir', 'yedek_planlari.guncelle'],
            'ayar güncelle' => ['put', '/yedek-ayarlari', 'yedek_planlari.guncelle'],
            'şifreleme anahtarı yükle' => ['put', '/yedek-ayarlari/sifreleme-anahtari', 'yedek_planlari.guncelle'],
            'şifreleme anahtarı kaldır' => ['delete', '/yedek-ayarlari/sifreleme-anahtari', 'yedek_planlari.guncelle'],
        ];
    }

    #[DataProvider('uclar')]
    public function test_her_uc_girissiz_401_izinsiz_403(string $yontem, string $yol, string $izin): void
    {
        $yol = strtr($yol, ['{hedef}' => (string) YedekHedefi::factory()->create()->id, '{plan}' => (string) YedekPlani::factory()->create()->id]);
        $this->json($yontem, self::K.$yol)->assertUnauthorized()->assertJsonPath('kod', 'YETKISIZ');

        // Görüntüleme izni değiştiren uçları açmaz; başka ekranın izni hiçbirini açmaz
        $baska = match ($izin) {
            'yedekler.goruntule' => 'yedek_planlari.goruntule',
            'yedek_planlari.guncelle' => 'yedek_planlari.goruntule',
            default => 'yedekler.goruntule',
        };
        $this->actingAs($this->izinli($baska))->json($yontem, self::K.$yol)->assertForbidden()->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }

    /**
     * YF-16 (README §8): iş bırakan uçlar kullanıcı başına dakikada 10 istekle
     * sınırlıdır; 11. istek 429 döner ve istek dosyası yazmaz.
     */
    public function test_is_birakan_uclar_kullanici_basina_hiz_sinirlidir(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'));
        foreach (range(1, 10) as $_) {
            $this->postJson(self::K.'/yedekler/tara')->assertStatus(202);
        }

        $this->postJson(self::K.'/yedekler/tara')->assertStatus(429);
        $this->assertCount(10, app(IstekKutusu::class)->bekleyenler());
    }

    /**
     * YF-09: isteğin durumunu isteği bırakabilen her ekran okur (Yedekler ya da
     * Yedek Planları — "Şimdi Çalıştır"); başka ekranın izni okuyamaz.
     * YF-29: kimlik küçük harfli UUID'dir; büyük harf 500 değil 404.
     */
    public function test_istek_durumunu_yedek_ya_da_plan_izni_okur_buyuk_harfli_kimlik_404(): void
    {
        $k = '00000000-0000-4000-8000-0000000000ab';
        $this->getJson(self::K."/yedekler/istekler/{$k}")->assertUnauthorized();
        $this->actingAs($this->izinli('kullanicilar.goruntule'))->getJson(self::K."/yedekler/istekler/{$k}")->assertForbidden();

        foreach (['yedekler.goruntule', 'yedek_planlari.goruntule'] as $izin) {
            $this->actingAs($this->izinli($izin))->getJson(self::K."/yedekler/istekler/{$k}")
                ->assertNotFound()->assertJsonPath('kod', 'ISTEK_BULUNAMADI');
        }

        $this->getJson(self::K.'/yedekler/istekler/'.strtoupper($k))->assertNotFound();
        $this->getJson('/api/v1/yedekleme/ustune-geri-yukleme/'.strtoupper($k).'/durum', ['X-Erisim-Anahtari' => str_repeat('a', 43)])->assertNotFound();
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

    private function bekleyenKlasoru(): string
    {
        $klasor = config('yedekleme.istek_klasoru').'/bekleyen';
        @mkdir($klasor, 0770, true);

        return $klasor;
    }

    private function rootDegilse(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root klasör iznini aşar (T11).');
        }
    }

    /**
     * BY-014-R2: istek dosyası çalıştırıcıya göründükten sonra klasör
     * eşitlenemezse istek kabul edilmiştir (202, belirsizlik notu); aynı
     * anahtarla yeniden deneme ikinci dosya yazmaz.
     */
    public function test_klasor_esitlemesi_bozulsa_da_yayimlanan_istek_kabul_edilir_yeniden_deneme_ikinci_dosya_yazmaz(): void
    {
        $this->rootDegilse();
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');
        $anahtar = (string) Str::uuid();
        $klasor = $this->bekleyenKlasoru();
        // Yazılabilir ve geçilebilir ama okunamaz: taşıma olur, klasör eşitlemek için açılamaz
        chmod($klasor, 0300);

        try {
            $ilk = $this->actingAs($kullanici)->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])
                ->assertStatus(202)->json('data.istek_kimligi');
            $tekrar = $this->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])
                ->assertStatus(202)->json('data.istek_kimligi');
        } finally {
            chmod($klasor, 0770);
        }

        $this->assertSame($ilk, $tekrar);
        $this->assertSame([$ilk.'.json'], array_map('basename', app(IstekKutusu::class)->bekleyenler()));
        $kayitlar = YedekIslemKaydi::query()->orderBy('id')->get();
        $this->assertSame([['basarili', null], ['basarili', 'ISTEK_KALICILIGI_DOGRULANAMADI']], $kayitlar->map(fn (YedekIslemKaydi $k): array => [$k->sonuc->value, $k->ayrinti['kod'] ?? null])->all());
        $this->assertSame([$ilk, $ilk], $kayitlar->map(fn (YedekIslemKaydi $k): string => $k->ayrinti['istek'])->all());
        $this->getJson(self::K.'/yedekler/istekler/'.$ilk)->assertOk()->assertJsonPath('data.durum', 'kuyrukta');
    }

    public function test_ayni_istek_anahtariyla_yeniden_deneme_en_cok_bir_is_birakir_baska_eylem_ve_kullanici_ayri_kimlik_alir(): void
    {
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');
        $anahtar = (string) Str::uuid();
        $kutu = app(IstekKutusu::class);
        $tara = fn (User $kim, string $anahtar): string => $this->actingAs($kim)
            ->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])->assertStatus(202)->json('data.istek_kimligi');

        $kimlik = $tara($kullanici, $anahtar);
        $this->assertSame($kimlik, $tara($kullanici, $anahtar));
        $this->assertCount(1, $kutu->bekleyenler());

        // Çalıştırıcı işledi (üstlendi, sonucu yazdı): yeniden deneme yine aynı kabulü alır, yeni iş yok
        $yol = (string) $kutu->talepEt($kutu->bekleyenler()[0]);
        $kutu->sonucYaz($kimlik, 'tamam');
        $kutu->sil($yol);
        $this->assertSame($kimlik, $tara($kullanici, strtoupper($anahtar)));
        $this->assertSame([], $kutu->bekleyenler());
        $this->assertSame(1, YedekIslemKaydi::query()->count());

        // Aynı anahtar başka kullanıcıda ya da başka eylemde başka iştir
        $baska = $tara($this->izinli('yedekler.goruntule', 'yedekler.al'), $anahtar);
        $yeniYedek = $this->actingAs($kullanici)->postJson(self::K.'/yedekler', ['aciklama' => null], ['Idempotency-Key' => $anahtar])
            ->assertStatus(202)->json('data.istek_kimligi');
        $this->assertCount(3, array_unique([$kimlik, $baska, $yeniYedek]));
        $this->assertCount(2, $kutu->bekleyenler());

        $this->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => 'tekrar-1'])
            ->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
    }

    /** Taşımadan önceki hata: istek yayımlanmadı, başarısız döner; aynı anahtarla yeniden deneme yayınlar. */
    public function test_istek_tasinmadan_once_yazilamazsa_500_dosya_yok_ayni_anahtarla_yeniden_deneme_bir_kez_yayinlar(): void
    {
        $this->rootDegilse();
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');
        $anahtar = (string) Str::uuid();
        $klasor = $this->bekleyenKlasoru();
        // Kabul kilidi önceki bir istekten kalmış; klasöre yeni dosya yazılamıyor (geçici dosya açılamaz)
        touch($klasor.'/.kabul.kilit');
        chmod($klasor, 0500);

        try {
            $this->actingAs($kullanici)->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])->assertStatus(500);
        } finally {
            chmod($klasor, 0770);
        }

        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
        $kayitlar = YedekIslemKaydi::query()->orderBy('id')->get();
        $this->assertSame(['basarili', 'basarisiz'], $kayitlar->map(fn (YedekIslemKaydi $k): string => $k->sonuc->value)->all());
        $this->assertSame('ISTEK_YAYINLANAMADI', $kayitlar[1]->ayrinti['kod']);

        $kimlik = $this->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])->assertStatus(202)->json('data.istek_kimligi');
        $this->assertSame($kayitlar[0]->ayrinti['istek'], $kimlik);
        $this->assertSame([$kimlik.'.json'], array_map('basename', app(IstekKutusu::class)->bekleyenler()));
    }

    /**
     * G18: aynı anahtarla eşzamanlı ikinci istek kabul kilidini bekler ve
     * kilit altında yeniden bakar; ilk istek o arada yayınladıysa ikinci
     * dosyayı ve ikinci denetim kaydını yazmaz.
     */
    public function test_ayni_anahtarla_eszamanli_istek_kabul_kilidini_bekler_ilk_yayinlanmissa_yazmaz(): void
    {
        $kullanici = $this->izinli('yedekler.goruntule', 'yedekler.al');
        $anahtar = (string) Str::uuid();
        $kutu = app(IstekKutusu::class);
        $kimlik = $this->actingAs($kullanici)->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])->json('data.istek_kimligi');
        $yol = $this->bekleyenKlasoru().'/'.$kimlik.'.json';
        // İlk istek henüz yayınlamamış gibi: dosya kilit beklenirken yayınlanacak
        $ilkinIcerigi = (string) file_get_contents($yol);
        unlink($yol);

        $kilit = fopen($this->bekleyenKlasoru().'/.kabul.kilit', 'c');
        $this->assertTrue(flock($kilit, LOCK_EX));
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$kilit, $yol, $ilkinIcerigi): void {
            if ($kilit !== null) {
                file_put_contents($yol, $ilkinIcerigi);
                flock($kilit, LOCK_UN);
                fclose($kilit);
                $kilit = null;
            }
        });

        $this->postJson(self::K.'/yedekler/tara', [], ['Idempotency-Key' => $anahtar])
            ->assertStatus(202)->assertJsonPath('data.istek_kimligi', $kimlik);

        $this->assertNull($kilit, 'istek kilidi beklemeliydi');
        $this->assertSame($ilkinIcerigi, file_get_contents($yol));
        $this->assertCount(1, $kutu->bekleyenler());
        $this->assertSame(1, YedekIslemKaydi::query()->count());
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

    /** YF-63: üstüne geri yükleme yalnız servis çalıştırıcısında yapılabilir; ekran düğmeyi buna göre açar */
    public function test_ozet_ustune_geri_yuklemenin_yalniz_servis_calistiricisinda_hazir_oldugunu_soyler(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule'));

        config(['yedekleme.calistirici' => 'dogrudan']);
        $this->getJson(self::K.'/yedekler/ozet')->assertOk()->assertJsonPath('data.ustune_geri_yukleme_hazir', false);
        config(['yedekleme.calistirici' => 'servis']);
        $this->getJson(self::K.'/yedekler/ozet')->assertOk()->assertJsonPath('data.ustune_geri_yukleme_hazir', true);
    }

    public function test_gecersiz_girdi_422_kod_alaniyla(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule', 'yedekler.al'))
            ->postJson(self::K.'/yedekler', ['aciklama' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        $this->getJson(self::K.'/yedekler?siralama=dosya_adi;drop')->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        // Bozuk UTF-8 (form kodlu gövde) 500 değil 422; istek ve denetim kaydı yazılmaz (YF-32)
        $this->post(self::K.'/yedekler', ['aciklama' => "a\xC3\x28"], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
        $this->assertSame(0, YedekIslemKaydi::query()->count());
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

    /**
     * YF-17 (T6): alınma zamanı olmayan başarısız deneme, yapıldığı ana göre
     * sıralanır; boyutsuz kayıt iki yönde de sondadır. SQLite ve PostgreSQL'de
     * aynı sonucu vermeli (PostgreSQL NULL'ları DESC'te başa koyar).
     */
    public function test_liste_basarisiz_denemeyi_yapildigi_ana_gore_siralar_iki_veritabaninda_ayni(): void
    {
        $this->actingAs($this->izinli('yedekler.goruntule'));
        $t1 = Yedek::factory()->create(['alinma_zamani' => '2026-11-20 10:00:00', 'created_at' => '2026-11-20 10:00:00', 'boyut' => 300]);
        $basarisiz = Yedek::factory()->create(['durum' => YedekDurumu::Basarisiz, 'alinma_zamani' => null, 'boyut' => null, 'created_at' => '2026-11-21 10:00:00']);
        $t3 = Yedek::factory()->create(['alinma_zamani' => '2026-11-22 10:00:00', 'created_at' => '2026-11-22 10:00:00', 'boyut' => 100]);
        $sira = fn (string $sorgu): array => array_column($this->getJson(self::K.'/yedekler?'.$sorgu)->assertOk()->json('data'), 'kimlik');

        $this->assertSame([$t1->kimlik, $basarisiz->kimlik, $t3->kimlik], $sira('yon=asc'));
        $this->assertSame([$t3->kimlik, $basarisiz->kimlik, $t1->kimlik], $sira('yon=desc'));
        $this->assertSame([$t3->kimlik, $t1->kimlik, $basarisiz->kimlik], $sira('siralama=boyut&yon=asc'));
        $this->assertSame([$t1->kimlik, $t3->kimlik, $basarisiz->kimlik], $sira('siralama=boyut&yon=desc'));
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
        $this->assertSame([['hedef_id' => $hedef->id, 'hedef_ad' => 'İkinci disk', 'hedef_tur' => 'yerel', 'durum' => 'tamam', 'sifreli' => false, 'kurtarma_hazir' => true, 'tamamlanma_zamani' => null, 'hata_kodu' => null]], $satir['kopyalar']);
        // Süzgeç: yalnız güvenilmeyenler
        $this->getJson(self::K.'/yedekler?guvenilmeyen=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.geri_yuklenebilir', false);
    }
}
