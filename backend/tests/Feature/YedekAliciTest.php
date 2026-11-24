<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SahipsizYedekler;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDeposu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Services\Yedekleme\YedekManifesti;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-007: yedek alma (README §6.1, §4.4 1. düzey, §5.2 parola dosyası).
 */
final class YedekAliciTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function al(YedekKaynagi $kaynak = YedekKaynagi::Elle): Yedek
    {
        return app(YedekAlici::class)->al($this->kilitAl(), $kaynak, 'Ay sonu');
    }

    public function test_basarili_yedek_dump_ve_imzali_manifest_uretir_kayit_tamam(): void
    {
        $this->sahtePgAraclari();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 00:40:07', 'UTC'));

        $yedek = $this->al();

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertSame(DogrulamaDurumu::OzetTamam, $yedek->dogrulama_durumu);
        $this->assertTrue($yedek->imzali);
        $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        // Dosya adı İstanbul saatini taşır; veritabanındaki an UTC ile aynıdır (G17, YF-01)
        $this->assertSame('erp_elle_2026-11-21_034007+0300.dump', $yedek->dosya_adi);
        $this->assertSame('2026-11-21 00:40:07', $yedek->refresh()->alinma_zamani->utc()->format('Y-m-d H:i:s'));
        $sonuc = app(ManifestDeposu::class)->oku(ManifestDeposu::jsonYolu($dump));
        $this->assertTrue($sonuc->guvenilirMi());
        $this->assertTrue(ManifestDeposu::dosyaTutuyorMu($sonuc->manifest, $dump));
        $this->assertSame(['users' => 3, 'migrations' => 2, 'sessions' => 0], $sonuc->manifest->satirSayilari);
        $this->assertSame('Ay sonu', $sonuc->manifest->aciklama);
        $this->assertSame('en_US.UTF-8', $sonuc->manifest->veritabaniOzellikleri['siralama']);
        // Yarım / geçici dosya yok: yalnız dump, manifest ve olay günlüğü
        $this->assertEqualsCanonicalizing([$yedek->dosya_adi, ManifestDeposu::jsonYolu($yedek->dosya_adi), 'olaylar.jsonl'], $this->klasordekiDosyalar());
        $this->assertSame(IslemSonucu::Basarili, YedekIslemKaydi::query()->sole()->sonuc);
        // Anlık görüntü tek kez açıldı; pg_dump o görüntüyle çalıştı, geçici tabloların verisi dışarıda
        $this->assertSame(1, $this->kaynakVeritabani->acilma);
        Process::assertRan(fn (PendingProcess $p): bool => in_array('--snapshot=00000003-0000001B-1', (array) $p->command, true)
            && in_array('--exclude-table-data=public.sessions', (array) $p->command, true));
    }

    public function test_pg_dump_hata_verirse_kayit_basarisiz_klasorde_yarim_dosya_kalmaz(): void
    {
        $this->sahtePgAraclari([
            '*pg_dump*--file=*' => function (PendingProcess $surec) {
                // Yarım dosya yazıp düşer
                $this->dosyaArgumaninaYaz($surec, 'PGDMP-yarim');

                return Process::result(errorOutput: 'pg_dump: error: connection to server failed', exitCode: 1);
            },
        ]);

        $yedek = $this->al();

        $this->assertSame(YedekDurumu::Basarisiz, $yedek->durum);
        $this->assertSame(YedekAlmaHatasi::PG_DUMP, $yedek->hata_kodu);
        $this->assertSame([], array_values(array_diff($this->klasordekiDosyalar(), ['olaylar.jsonl'])));
        $this->assertSame(IslemSonucu::Basarisiz, YedekIslemKaydi::query()->sole()->sonuc);
    }

    public function test_dogrulama_basarisizsa_yedek_basarisiz_dosya_listeye_girmez(): void
    {
        $this->sahtePgAraclari([
            '*pg_restore*--file=/dev/null*' => Process::result(errorOutput: 'pg_restore: error: could not read', exitCode: 1),
        ]);

        $yedek = $this->al();

        $this->assertSame(YedekDurumu::Basarisiz, $yedek->durum);
        $this->assertSame(YedekAlmaHatasi::DOGRULAMA, $yedek->hata_kodu);
        $this->assertSame([], $this->klasordekiDosyalar());
    }

    public function test_icindekiler_beklenen_tabloyu_tasimazsa_yedek_basarisiz(): void
    {
        $this->sahtePgAraclari(['*pg_restore*--list*' => ";\n; bos arsiv\n"]);

        $this->assertSame(YedekAlmaHatasi::DOGRULAMA, $this->al()->hata_kodu);
    }

    public function test_kilit_baska_surecteyken_komut_anlasilir_mesajla_cikar_ikinci_yedek_almaz(): void
    {
        $this->sahtePgAraclari();
        $this->kilitAl();
        config(['yedekleme.sure_siniri.kilit_bekleme' => 1]);

        $this->artisan('yedek:al')
            ->expectsOutputToContain('Başka bir yedekleme')
            ->assertExitCode(75);

        $this->assertSame(0, Yedek::query()->count());
        Process::assertNothingRan();
    }

    public function test_parola_komut_satirinda_ve_ortam_degiskeninde_yok_yalniz_parola_dosyasi_yolu(): void
    {
        $this->sahtePgAraclari();
        $parolaDosyasi = null;
        putenv('PGPASSWORD=devralinan');

        try {
            $this->al();
        } finally {
            putenv('PGPASSWORD');
        }

        Process::assertRan(function (PendingProcess $p) use (&$parolaDosyasi): bool {
            if (! str_contains(implode(' ', (array) $p->command), 'pg_dump') || in_array('--version', (array) $p->command, true)) {
                return false;
            }
            $parolaDosyasi = $p->environment['PGPASSFILE'] ?? null;

            return ! str_contains(implode(' ', (array) $p->command), 'gizli')
                && $p->environment['PGPASSWORD'] === false
                && ! in_array('gizli:parola\\1', $p->environment, true);
        });
        // Geçici parola dosyası iş bitince silindi
        $this->assertIsString($parolaDosyasi);
        $this->assertFileDoesNotExist($parolaDosyasi);
    }

    public function test_bos_disk_esigin_altindaysa_yedek_baslamaz(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_bos_alan_yuzde' => 100]);

        $yedek = $this->al();

        $this->assertSame(YedekAlmaHatasi::DISK_DOLU, $yedek->hata_kodu);
        Process::assertNothingRan();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function gecersizSureler(): array
    {
        return ['metin' => ['abc'], 'sıfır' => ['0'], 'eksi' => ['-10'], 'ondalık' => ['1.5'], 'sayı sıfır' => [0]];
    }

    /** `(int) 'abc'` = 0 = sınırsız olurdu (G9, BY-004-R5). */
    #[DataProvider('gecersizSureler')]
    public function test_gecersiz_sure_siniri_acik_hatayla_durur_sinirsiz_calismaya_donusmez(mixed $deger): void
    {
        $this->sahtePgAraclari();
        config(['yedekleme.sure_siniri.yedek' => $deger]);

        $yedek = $this->al();

        $this->assertSame(YedekAlmaHatasi::YAPILANDIRMA, $yedek->hata_kodu);
        Process::assertNothingRan();
    }

    public function test_gecerli_sure_siniri_metni_tam_sayiya_cevrilir(): void
    {
        config(['yedekleme.sure_siniri.yedek' => '900']);

        $this->assertSame(900, app(YedeklemeYapilandirmasi::class)->sureSiniri('yedek'));
    }

    public function test_acik_ustune_geri_yukleme_varken_yedek_alinmaz(): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: '5b0f6f2e-3c1d-4b8a-9f77-1d2e3f4a5b6c',
            tur: IslemTuru::GeriYuklemeUstune,
            adim: 'onay_bekliyor',
            acik: true,
            baslangic: now('UTC')->toImmutable(),
            sonGuncelleme: now('UTC')->toImmutable(),
            hata: null,
            erisimAnahtariOzeti: null,
        ), $kilit);

        $this->expectException(YedekAlmaHatasi::class);
        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
    }

    /** BY-007-R1: dump sırasında ölen süreçten kalan `calisiyor` kaydı kilidi yeni alan sonuçlandırır. */
    public function test_dump_sirasinda_olen_surecin_kaydi_sonraki_yedekte_yarida_kaldi_olur_gecici_dosya_silinir(): void
    {
        $this->sahtePgAraclari();
        $olen = Yedek::factory()->create(['durum' => YedekDurumu::Calisiyor, 'dosya_adi' => 'erp_elle_2026-11-21_101010+0300.dump', 'imzali' => false]);
        $gecici = config('yedekleme.klasor').'/.erp_elle_2026-11-21_101010+0300.dump.a1b2c3d4e5f6.tmp';
        file_put_contents($gecici, 'PGDMP-yarim');

        $yeni = $this->al();

        $olen->refresh();
        $this->assertSame([YedekDurumu::Basarisiz, SahipsizYedekler::YARIDA_KALDI], [$olen->durum, $olen->hata_kodu]);
        $this->assertNotNull($olen->bitti);
        $this->assertFileDoesNotExist($gecici);
        $this->assertSame(YedekDurumu::Tamam, $yeni->durum);
    }

    /** BY-007-R1: dosya asıl adına taşındıktan hemen sonra ölen sürecin yedeği doğrulanmış dosya + imzalı manifestle tamamlanır. */
    public function test_dosya_tasindiktan_sonra_olen_surecin_yedegi_imzali_manifestle_tamamlanir(): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        $olen = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        // Süreç rename'den sonra, kayıt güncellenmeden öldü
        $olen->update(['durum' => YedekDurumu::Calisiyor, 'imzali' => false, 'dogrulama_durumu' => DogrulamaDurumu::Dogrulanmadi, 'boyut' => null, 'sha256' => null, 'bitti' => null]);
        $dump = config('yedekleme.klasor').'/'.$olen->dosya_adi;

        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);

        $olen->refresh();
        $this->assertSame(YedekDurumu::Tamam, $olen->durum);
        $this->assertTrue($olen->imzali);
        $this->assertSame(DogrulamaDurumu::OzetTamam, $olen->dogrulama_durumu);
        $this->assertSame(hash_file('sha256', $dump), $olen->sha256);
        $this->assertFileExists(ManifestDeposu::jsonYolu($dump));
    }

    /**
     * YF-05 (D3): dump ve imzalı manifest yayımlandıktan sonra olay günlüğü
     * yazılamazsa yedek geçerlidir: kayıt `tamam`, dosyalar yerinde, denetim
     * kaydı başarılı ve uyarıyı taşır; "yedek başarısız" kaydı yok.
     */
    public function test_yayimlandiktan_sonra_olay_gunlugu_yazilamazsa_yedek_tamam_kalir_denetim_uyarir(): void
    {
        $this->sahtePgAraclari();
        $klasor = config('yedekleme.klasor');
        // Olay günlüğünün yerinde klasör: ekleme hata verir
        mkdir($klasor.'/olaylar.jsonl', 0750, true);

        $yedek = $this->al();

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertTrue($yedek->imzali);
        $dump = $klasor.'/'.$yedek->dosya_adi;
        $this->assertFileExists($dump);
        $this->assertFileExists(ManifestDeposu::jsonYolu($dump));
        $kayit = YedekIslemKaydi::query()->where('islem', 'yedek_al')->sole();
        $this->assertSame(IslemSonucu::Basarili, $kayit->sonuc);
        $this->assertSame(YedekAlici::YAYIM_SONRASI_UYARI, $kayit->ayrinti['kod']);
        $this->assertSame(['olay_gunlugu'], $kayit->ayrinti['uyarilar']);
    }

    /**
     * BY-011-R4: dosyası ve imzalı manifesti diskte yayımlanmış `calisiyor`
     * kaydı, disk ayrılmışken (boş bağlama noktası) alınmaya çalışılan yedekte
     * "yarıda kaldı" yapılmaz; disk geri gelince sonraki yedek onu tamamlar.
     */
    public function test_disk_ayrilmisken_sahipsiz_kayit_degismez_disk_gelince_manifestiyle_tamamlanir(): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $olen = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $olen->update(['durum' => YedekDurumu::Calisiyor, 'imzali' => false, 'dogrulama_durumu' => DogrulamaDurumu::Dogrulanmadi, 'boyut' => null, 'sha256' => null, 'bitti' => null]);
        $klasor = config('yedekleme.klasor');
        $dump = $klasor.'/'.$olen->dosya_adi;
        $ozet = hash_file('sha256', $dump);
        rename($klasor, $klasor.'-disk');
        mkdir($klasor, 0750);

        $diskYokken = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $olenDiskYokken = $olen->fresh();
        rmdir($klasor);
        rename($klasor.'-disk', $klasor);
        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);

        $this->assertSame([YedekDurumu::Basarisiz, YedekAlmaHatasi::DEPO], [$diskYokken->durum, $diskYokken->hata_kodu]);
        $this->assertSame([YedekDurumu::Calisiyor, null, null], [$olenDiskYokken->durum, $olenDiskYokken->hata_kodu, $olenDiskYokken->bitti]);
        $olen->refresh();
        $this->assertSame([YedekDurumu::Tamam, null], [$olen->durum, $olen->hata_kodu]);
        $this->assertTrue($olen->imzali);
        $this->assertSame($ozet, $olen->sha256);
        $this->assertFileExists(ManifestDeposu::jsonYolu($dump));
    }

    /**
     * BY-011-R5: tamamlanmış yedek YOKKEN ilk yedek dump'ı ve imzalı manifesti
     * yayımlayıp `calisiyor`da kaldıysa katalog "ilk kurulum" sayılmaz: disk
     * ayrılınca boş bağlama noktasına işaret yazılmaz, kayıt kapanmaz; disk
     * geri gelince sonraki yedek onu manifestiyle tamamlar.
     */
    public function test_ilk_yedek_yarida_kalmisken_disk_ayrilirsa_bos_baglama_noktasina_isaret_yazilmaz_disk_gelince_tamamlanir(): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        $olen = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $olen->update(['durum' => YedekDurumu::Calisiyor, 'imzali' => false, 'dogrulama_durumu' => DogrulamaDurumu::Dogrulanmadi, 'boyut' => null, 'sha256' => null, 'bitti' => null]);
        $klasor = config('yedekleme.klasor');
        $dump = $klasor.'/'.$olen->dosya_adi;
        $ozet = hash_file('sha256', $dump);
        rename($klasor, $klasor.'-disk');
        mkdir($klasor, 0750);

        $diskYokken = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $baglamaNoktasi = scandir($klasor);
        $olenDiskYokken = $olen->fresh();
        rmdir($klasor);
        rename($klasor.'-disk', $klasor);
        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);

        $this->assertSame([YedekDurumu::Basarisiz, YedekAlmaHatasi::DEPO], [$diskYokken->durum, $diskYokken->hata_kodu]);
        $this->assertSame(['.', '..'], $baglamaNoktasi);
        $this->assertSame([YedekDurumu::Calisiyor, null, null], [$olenDiskYokken->durum, $olenDiskYokken->hata_kodu, $olenDiskYokken->bitti]);
        $olen->refresh();
        $this->assertSame([YedekDurumu::Tamam, null], [$olen->durum, $olen->hata_kodu]);
        $this->assertTrue($olen->imzali);
        $this->assertSame($ozet, $olen->sha256);
    }

    /**
     * @return array<string, array{\Closure(string): void}>
     */
    public static function dogrulanamayanDepolar(): array
    {
        return [
            // Disk ayrıldı: bağlama noktası alttaki boş, okunabilir klasör
            'diski ayrılmış boş bağlama noktası' => [function (string $klasor): void {
                rename($klasor, $klasor.'-disk');
                mkdir($klasor, 0750);
            }],
            'biçimi tanınmayan işaret' => [function (string $klasor): void {
                file_put_contents($klasor.'/'.YedekDeposu::ISARET, "baska bir sey\n");
            }],
            // BY-011-R5: işaretsiz klasörde katalogdaki yedeğin adını taşıyan dosya kanıt değildir
            'işaretsiz klasörde imzasız manifest' => [function (string $klasor): void {
                rename($klasor, $klasor.'-disk');
                mkdir($klasor, 0750);
                file_put_contents(ManifestDeposu::jsonYolu($klasor.'/'.Yedek::query()->sole()->dosya_adi), '{}');
            }],
            'işaretsiz klasörde kimliği tutmayan imzalı manifest' => [function (string $klasor): void {
                $yedek = Yedek::query()->sole();
                $manifestler = app(ManifestDeposu::class);
                $veri = $manifestler->oku(ManifestDeposu::jsonYolu($klasor.'/'.$yedek->dosya_adi))->manifest->diziye();
                rename($klasor, $klasor.'-disk');
                mkdir($klasor, 0750);
                $manifestler->yaz(YedekManifesti::diziden([...$veri, 'kimlik' => (string) Str::uuid()]), $klasor);
            }],
        ];
    }

    /**
     * BY-011-R3: katalogda yedek varken yedek klasörü depo olarak
     * doğrulanamazsa yedek alınmaz; bağlama noktasına (kök diske) hiçbir şey
     * yazılmaz, kayıt hata koduyla başarısız olur.
     *
     * @param  \Closure(string): void  $boz
     */
    #[DataProvider('dogrulanamayanDepolar')]
    public function test_depo_dogrulanamazsa_yedek_alinmaz_klasore_yazilmaz(\Closure $boz): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $klasor = config('yedekleme.klasor');
        $boz($klasor);
        $once = scandir($klasor);

        $yedek = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);

        $this->assertSame(YedekDurumu::Basarisiz, $yedek->durum);
        $this->assertSame(YedekAlmaHatasi::DEPO, $yedek->hata_kodu);
        $this->assertSame($once, scandir($klasor));
        Process::assertRanTimes(fn (PendingProcess $p): bool => str_ends_with((string) ((array) $p->command)[0], 'pg_dump') && ! in_array('--version', (array) $p->command, true), 1);
    }

    /**
     * @return array<string, array{\Closure(YedekKilidi): void}>
     */
    public static function isaretsizDepolar(): array
    {
        return [
            // İlk kurulum / geçiş kipi: katalog boş, kaybedilecek kayıt yok
            'katalog boş' => [function (YedekKilidi $kilit): void {}],
            // İşaretten önce kurulmuş depo: katalogdaki yedeğin imzalı manifesti burada (BY-011-R5: kimliği tutan)
            'katalogdaki yedeğin imzalı manifesti klasörde' => [function (YedekKilidi $kilit): void {
                app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
            }],
            // İşareti silinmiş doğru disk, ilk yedek yarıda kalmış: kanıt durumdan bağımsız
            'yarıda kalmış ilk yedeğin imzalı manifesti klasörde' => [function (YedekKilidi $kilit): void {
                app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle)->update(['durum' => YedekDurumu::Calisiyor, 'imzali' => false, 'bitti' => null]);
            }],
        ];
    }

    /**
     * BY-011-R3: işaretsiz klasör, depo olduğu anlaşılınca (katalog boş ya da
     * katalogdaki bir yedeğin imzalı manifesti orada) işaretlenir ve yedek alınır;
     * yarıda kalmış kayıt da manifestiyle tamamlanır.
     *
     * @param  \Closure(YedekKilidi): void  $kur
     */
    #[DataProvider('isaretsizDepolar')]
    public function test_isaretsiz_klasor_depo_oldugu_anlasilinca_isaretlenir_yedek_alinir(\Closure $kur): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        $klasor = config('yedekleme.klasor');
        $kur($kilit);
        unlink($klasor.'/'.YedekDeposu::ISARET);

        $yedek = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertSame([], Yedek::query()->where('durum', '!=', YedekDurumu::Tamam->value)->pluck('dosya_adi')->all());
        $this->assertMatchesRegularExpression('/^emor-yedek-deposu [0-9a-f-]{36}\n$/D', (string) file_get_contents($klasor.'/'.YedekDeposu::ISARET));
    }
}
