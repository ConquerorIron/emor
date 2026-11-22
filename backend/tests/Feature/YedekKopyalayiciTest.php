<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KullanimDenetimi;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKopyalayici;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-008: hedef soyutlaması, yerel disk, kopyalama (README §4.6, §6.1 6. adım).
 */
final class YedekKopyalayiciTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        BellekHedefi::sifirla();
        Sleep::fake();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /** Yedek klasöründe gerçek dosyası olan yedek */
    private function yedek(string $icerik = 'PGDMP-yedek-icerigi'): Yedek
    {
        $yedek = Yedek::factory()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik)]);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, $icerik);

        return $yedek;
    }

    private function bellekHedefi(string $ad = 'Bellek'): YedekHedefi
    {
        return YedekHedefi::factory()->create(['ad' => $ad, 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
    }

    private function yerelHedef(): YedekHedefi
    {
        $klasor = $this->yedekKoku.'/ikinci-disk';
        mkdir($klasor, 0750);

        return YedekHedefi::factory()->create(['baglanti' => ['klasor' => $klasor]]);
    }

    private function kopyalayici(): YedekKopyalayici
    {
        return app(YedekKopyalayici::class);
    }

    public function test_yeni_hedef_turu_yalniz_bir_baglayici_sinifiyla_kopyalanir_ve_sinanir(): void
    {
        $yedek = $this->yedek();
        $hedef = $this->bellekHedefi();

        $kopya = $this->kopyalayici()->kopyala($yedek, $hedef);

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame('PGDMP-yedek-icerigi', BellekHedefi::$dosyalar[$kopya->uzak_kimlik]);
        $this->assertTrue(HedefSinayici::basariliMi(app(HedefSinayici::class)->sina(app(HedefKatalogu::class)->olustur($hedef))));
        // Ekrandaki tür listesi bağlayıcılardan gelir
        $this->assertContains('s3', array_map(fn ($t) => $t->tur->value, app(HedefKatalogu::class)->tanimlar()));
    }

    public function test_iki_hedeften_biri_hata_verince_digerinin_kopyasi_tamam_yedek_etkilenmez(): void
    {
        $yedek = $this->yedek();
        $yerel = $this->yerelHedef();
        $bellek = $this->bellekHedefi();
        BellekHedefi::$hataAdimi = 'yaz';

        $saglam = $this->kopyalayici()->kopyala($yedek, $yerel);
        $hatali = $this->kopyalayici()->kopyala($yedek, $bellek);

        $this->assertSame(KopyaDurumu::Tamam, $saglam->durum);
        $this->assertFileExists($yerel->baglanti['klasor'].'/'.$yedek->dosya_adi);
        $this->assertSame(KopyaDurumu::Basarisiz, $hatali->durum);
        $this->assertSame(HedefHatasi::YAZILAMADI, $hatali->hata_kodu);
        $this->assertSame(3, $hatali->deneme);
        $this->assertSame(YedekDurumu::Tamam, $yedek->refresh()->durum);
        $kopyalar = app(OlayGunlugu::class)->durumlar()[$yedek->kimlik]->kopyalar;
        $this->assertSame('tamam', $kopyalar['id:'.$yerel->id]['durum']);
        $this->assertSame('basarisiz', $kopyalar['id:'.$bellek->id]['durum']);
    }

    /**
     * BY-010-R7: eskiden kalmış ikinci hedef aynı klasörü noktalı yazımla
     * gösteriyor. İkinci "kopya" ilk hedefin dosyasının üstüne yazılır ve
     * bağımsız kopya sanılırdı: kalıcı hata, yeniden denenmez, ilk kopyaya dokunulmaz.
     */
    public function test_ayni_klasoru_gosteren_ikinci_hedefe_kopya_yazilmaz_ilk_kopya_korunur(): void
    {
        $yedek = $this->yedek();
        $ilk = $this->yerelHedef();
        $ikinci = YedekHedefi::factory()->create(['ad' => 'Aynı disk', 'baglanti' => ['klasor' => $ilk->baglanti['klasor'].'/.']]);
        $ilkKopya = $this->kopyalayici()->kopyala($yedek, $ilk);
        $dosya = $ilk->baglanti['klasor'].'/'.$yedek->dosya_adi;
        $dugum = fileinode($dosya);

        $ikinciKopya = $this->kopyalayici()->kopyala($yedek, $ikinci);

        $this->assertSame([KopyaDurumu::Basarisiz, HedefHatasi::AYNI_DEPO, 1], [$ikinciKopya->durum, $ikinciKopya->hata_kodu, $ikinciKopya->deneme]);
        $this->assertSame(KopyaDurumu::Tamam, $ilkKopya->refresh()->durum);
        clearstatcache();
        $this->assertSame($dugum, fileinode($dosya));
        $this->assertSame('PGDMP-yedek-icerigi', file_get_contents($dosya));
    }

    public function test_hedeften_okunan_kopyanin_ozeti_tutmazsa_kopya_basarisiz(): void
    {
        $yedek = $this->yedek();
        BellekHedefi::$bozukOku = true;

        $kopya = $this->kopyalayici()->kopyala($yedek, $this->bellekHedefi());

        $this->assertSame(KopyaDurumu::Basarisiz, $kopya->durum);
        $this->assertSame(HedefHatasi::OKUNAMADI, $kopya->hata_kodu);
    }

    /**
     * @return array<string, array{string, array<string, bool|null>}>
     */
    public static function sinamaHatalari(): array
    {
        return [
            'yazma' => ['yaz', ['baglan' => true, 'yaz' => false, 'oku' => null, 'dogrula' => null, 'sil' => null]],
            'okuma' => ['oku', ['baglan' => true, 'yaz' => true, 'oku' => false, 'dogrula' => null, 'sil' => true]],
            'silme' => ['sil', ['baglan' => true, 'yaz' => true, 'oku' => true, 'dogrula' => true, 'sil' => false]],
            'bağlantı' => ['baglan', ['baglan' => false, 'yaz' => null, 'oku' => null, 'dogrula' => null, 'sil' => null]],
        ];
    }

    /**
     * @param  array<string, bool|null>  $beklenen
     */
    #[DataProvider('sinamaHatalari')]
    public function test_sina_yazma_okuma_silme_hatasini_ayri_adim_olarak_raporlar(string $adim, array $beklenen): void
    {
        BellekHedefi::$hataAdimi = $adim;

        $sonuc = app(HedefSinayici::class)->sina(app(HedefKatalogu::class)->olustur($this->bellekHedefi()));

        $this->assertSame($beklenen, array_column($sonuc, 'basarili', 'adim'));
        $this->assertFalse(HedefSinayici::basariliMi($sonuc));
    }

    public function test_kopyalanmakta_olan_yedek_kullanimda_sayilir_biten_sayilmaz(): void
    {
        $yedek = $this->yedek();
        $kopya = YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $yedek->id, 'durum' => KopyaDurumu::Kopyalaniyor]);

        $this->assertTrue(app(KullanimDenetimi::class)->kullanimdaMi($yedek));

        $kopya->update(['durum' => KopyaDurumu::Tamam]);
        $this->assertFalse(app(KullanimDenetimi::class)->kullanimdaMi($yedek));
    }

    public function test_sunucu_disi_hedefe_sifreleme_yokken_kopya_gitmez(): void
    {
        BellekHedefi::$sunucuDisi = true;
        $yedek = $this->yedek();

        $kopya = $this->kopyalayici()->kopyala($yedek, $this->bellekHedefi());

        $this->assertSame(KopyaDurumu::Basarisiz, $kopya->durum);
        $this->assertSame(HedefHatasi::YAPILANDIRMA, $kopya->hata_kodu);
        $this->assertSame(0, BellekHedefi::$yazmaSayisi);
        // Yapılandırma eksiği yeniden denenmez (D8)
        $this->assertSame(1, $kopya->deneme);
    }

    /**
     * BY-028-R5, BY-021-R3: bağlı hesabı okunmamış Drive hedefinin konumu
     * belirlenemez; oraya yapılan kopyanın yeri hiçbir zaman doğrulanamazdı.
     * Kopya gönderilmez, hedefe istek gitmez, yeniden denenmez.
     */
    public function test_hesabi_bilinmeyen_drive_hedefine_kopya_gonderilmez(): void
    {
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir']);
        Http::preventStrayRequests();
        Http::fake([]);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'yenileme']]);

        $kopya = $this->kopyalayici()->kopyala($this->yedek(), $hedef);

        $this->assertSame([KopyaDurumu::Basarisiz, HedefHatasi::YAPILANDIRMA, 1], [$kopya->durum, $kopya->hata_kodu, $kopya->deneme]);
        // Şifreleme anahtarı eksikliği de aynı kodu verir: nedeni iletiden ayırt edilir
        $this->assertStringContainsString('konumu belirlenemiyor', (string) $kopya->hata_mesaji);
        Http::assertNothingSent();
    }

    /**
     * Hesabı bağlı ama klasörü seçilmemiş Drive hedefi: kopya gönderilmez,
     * Drive'da klasör aranmaz / açılmaz, yeniden denenmez; neden kodda açık.
     */
    public function test_klasoru_secilmemis_drive_hedefine_kopya_gonderilmez_klasor_secilmedi(): void
    {
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir']);
        Http::preventStrayRequests();
        Http::fake([]);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::GoogleDrive, 'baglanti' => ['eposta' => 'yedek@ornek.test'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'yenileme']]);

        $kopya = $this->kopyalayici()->kopyala($this->yedek(), $hedef);

        $this->assertSame([KopyaDurumu::Basarisiz, HedefHatasi::KLASOR_SECILMEDI, 1], [$kopya->durum, $kopya->hata_kodu, $kopya->deneme]);
        Http::assertNothingSent();
    }

    /** Aynı hedefi paylaşan iki plan tek fiziksel kopyaya bağlanır (F6). */
    public function test_ayni_hedefi_paylasan_iki_plan_tek_kopyaya_baglanir_dosya_bir_kez_yazilir(): void
    {
        $yedek = $this->yedek();
        $hedef = $this->bellekHedefi();
        [$gunluk, $haftalik] = YedekPlani::factory()->count(2)->create()->all();
        $gunluk->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);
        $haftalik->hedefler()->attach($hedef, ['sakla_son_adet' => 8]);

        $this->kopyalayici()->kopyala($yedek, $hedef, [$gunluk->id]);
        $kopya = $this->kopyalayici()->kopyala($yedek, $hedef, [$haftalik->id]);

        $this->assertSame(1, YedekKopyasi::query()->count());
        $this->assertSame(1, BellekHedefi::$yazmaSayisi);
        $this->assertEqualsCanonicalizing([$gunluk->id, $haftalik->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
    }
}
