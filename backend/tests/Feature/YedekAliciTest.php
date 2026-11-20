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
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
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

        $yedek = $this->al();

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertSame(DogrulamaDurumu::OzetTamam, $yedek->dogrulama_durumu);
        $this->assertTrue($yedek->imzali);
        $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        $this->assertMatchesRegularExpression('/^erp_elle_\d{4}-\d{2}-\d{2}_\d{6}\+0300\.dump$/D', $yedek->dosya_adi);
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
}
