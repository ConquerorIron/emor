<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use App\Models\MailAyari;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Services\Yedekleme\AyarSecimi;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Üstüne geri yükleme testlerinin ortamı (BY-028…030): sahte PostgreSQL
 * sunucusuna ad değiştirme, bağlantıya kapatma, boyut ve ad sorguları;
 * migration süreci ve `sudo systemctl` sahte. Bakım modu dosyası geçici
 * storage klasöründe (uygulamanın gerçek storage/framework'üne dokunulmaz).
 * YedeklemeOrtami ve SahtePostgres ile birlikte kullanılır.
 */
trait UstuneGeriYuklemeOrtami
{
    /** @var array<string, true> ALLOW_CONNECTIONS false olan veritabanları */
    protected array $baglantiyaKapali = [];

    /** @var list<string> Çalışan dış komutlar (sırasıyla, kısa ad) */
    protected array $komutlar = [];

    protected ?string $kapatmaHatasi = null;

    protected ?string $yerDegistirmeHatasi = null;

    protected ?string $migrationHatasi = null;

    protected ?string $sudoHatasi = null;

    /** Yer değiştirmeden hemen sonra çalışır (8. adımda hata üretmek için) */
    protected ?Closure $yerDegistirmeSonrasi = null;

    /** Kancanın kendisi hata verdiyse (test kurgusu çalışmadı demektir) */
    protected ?\Throwable $kancaHatasi = null;

    /** @var list<bool> Her pg_dump çağrısında bakım modu açık mıydı */
    protected array $pgDumpBakimda = [];

    /** @var array<string, mixed>|null Migration süreci başlarken okunan yapılandırma önbelleği */
    protected ?array $migrationYapilandirmasi = null;

    protected ?string $migrationYapilandirmaDosyasi = null;

    /**
     * Veritabanlarının bağlanma yetkileri (`datacl::text`; null = varsayılan).
     * Bariyer canlıyı servis rolüne daraltır; yetkileriUygula değeri yazar;
     * ad değişince yetki adla birlikte gider.
     *
     * @var array<string, string|null>
     */
    protected array $veritabaniYetkileri = [];

    protected ?string $bariyerHatasi = null;

    protected function ustuneOrtaminiKur(): void
    {
        $this->yedeklemeOrtaminiKur();
        // Yazıcı bariyeri ayrı servis rolü ve onun parola dosyasını ister (BY-028-R1)
        $parolaDosyasi = $this->yedekKoku.'/pgpass';
        file_put_contents($parolaDosyasi, "127.0.0.1:5432:*:emor_yedek:servis-parolasi\n");
        chmod($parolaDosyasi, 0600);
        config([
            'yedekleme.aktif' => true,
            'yedekleme.calistirici' => 'servis',
            'yedekleme.rol' => 'emor_yedek',
            'yedekleme.parola_dosyasi' => $parolaDosyasi,
            'yedekleme.kuyruk_servisi' => 'emor-queue',
            'yedekleme.sudo' => '/usr/bin/sudo',
            'yedekleme.systemctl' => '/usr/bin/systemctl',
            'yedekleme.sure_siniri.kilit_bekleme' => 1,
        ]);
        @mkdir($this->yedekKoku.'/storage/framework', 0755, true);
        $this->app->useStoragePath($this->yedekKoku.'/storage');

        // Yedeğin migration listesi kodunkiyle aynı (şema uyumlu)
        $kod = array_map(fn (string $d): string => basename($d, '.php'), glob(database_path('migrations').'/*.php') ?: []);
        sort($kod);
        $this->kaynakVeritabani->migrationlar = $kod;

        Mail::fake();
        MailAyari::query()->create([
            'anahtar' => MailAyari::ANAHTAR, 'sunucu' => 'smtp.ornek.test', 'port' => 587, 'sifreleme' => MailAyari::SIFRELEME_TLS,
            'kullanici_adi' => 'yedek@ornek.test', 'sifre' => 'smtp-gizli', 'gonderen_adres' => 'erp@ornek.test', 'gonderen_ad' => 'eMOR',
        ]);
        YedekAyari::query()->create(['bildirim_alicilari' => ['bt@ornek.test']]);
        $this->sahteUstune();
    }

    /**
     * @param  array<string, mixed>  $ozel
     */
    protected function sahteUstune(array $ozel = []): void
    {
        $this->sahtePostgres($ozel + [
            '*psql*' => fn (PendingProcess $p) => $this->psqlUstune($p),
            '*artisan*migrate*' => function (PendingProcess $p) {
                $this->komutlar[] = 'migrate';
                $dosya = $p->environment['APP_CONFIG_CACHE'] ?? null;
                $this->migrationYapilandirmaDosyasi = is_string($dosya) ? $dosya : null;
                $this->migrationYapilandirmasi = is_string($dosya) && is_file($dosya) ? require $dosya : null;

                return $this->migrationHatasi === null ? '' : Process::result(errorOutput: $this->migrationHatasi, exitCode: 1);
            },
            '*sudo*' => function (PendingProcess $p) {
                $this->komutlar[] = 'systemctl '.implode(' ', array_slice((array) $p->command, 3));

                return $this->sudoHatasi === null ? '' : Process::result(errorOutput: $this->sudoHatasi, exitCode: 1);
            },
            '*pg_dump*' => function (PendingProcess $p): string {
                if (in_array('--version', (array) $p->command, true)) {
                    return 'pg_dump (PostgreSQL) 18.6';
                }
                $this->pgDumpBakimda[] = is_file(storage_path('framework/down'));
                $this->komutlar[] = 'pg_dump';
                $this->dosyaArgumaninaYaz($p, 'PGDMP-sahte-yedek-'.bin2hex(random_bytes(4)));

                return '';
            },
        ]);
    }

    private function psqlUstune(PendingProcess $p): mixed
    {
        $sql = (string) $p->input;
        $d = $this->psqlDegiskenleri($p);

        if (str_contains($sql, 'datacl IS NULL')) {
            $acl = $this->veritabaniYetkileri[$d['ad']] ?? null;

            return $acl === null ? "t,\n" : 'f,"'.str_replace('"', '""', $acl)."\"\n";
        }

        if (str_contains($sql, 'erp_yedek.rol')) {
            $this->komutlar[] = 'bariyer '.$d['ad'];
            if ($this->bariyerHatasi !== null) {
                return Process::result(errorOutput: $this->bariyerHatasi, exitCode: 3);
            }
            $this->veritabaniYetkileri[$d['ad']] = '{'.$d['rol'].'=c/erp}';

            return "erp|emor_yedek\n0\n";
        }

        if (str_contains($sql, 'erp_yedek.acl')) {
            $acl = $d['varsayilan'] === '1' ? null : $d['acl'];
            $this->komutlar[] = 'yetki '.$d['ad'].' '.($acl ?? 'varsayilan');
            $this->veritabaniYetkileri[$d['ad']] = $acl;

            return '';
        }

        if (str_contains($sql, 'datname IN')) {
            $adlar = array_values(array_filter($d, fn (string $k): bool => preg_match('/^a[0-9]+$/D', $k) === 1, ARRAY_FILTER_USE_KEY));

            return implode("\n", array_intersect($this->veritabanlari, $adlar))."\n";
        }

        if (str_contains($sql, 'ALLOW_CONNECTIONS false')) {
            $this->komutlar[] = 'kapat '.$d['ad'];
            if ($this->kapatmaHatasi !== null) {
                return Process::result(errorOutput: $this->kapatmaHatasi, exitCode: 3);
            }
            $this->baglantiyaKapali[$d['ad']] = true;

            return "0\n";
        }

        if (str_contains($sql, 'FROM pg_stat_activity')) {
            return "0\n";
        }

        if (str_contains($sql, 'ALLOW_CONNECTIONS true')) {
            $this->komutlar[] = 'ac '.$d['ad'];
            unset($this->baglantiyaKapali[$d['ad']]);

            return '';
        }

        if (str_contains($sql, 'RENAME TO')) {
            $this->komutlar[] = "yer {$d['a']}>{$d['b']} {$d['c']}>{$d['d']}";
            if ($this->yerDegistirmeHatasi !== null) {
                return Process::result(errorOutput: $this->yerDegistirmeHatasi, exitCode: 3);
            }

            // Tek işlem: ikisi birden olur ya da hiçbiri (kesif.md §5)
            $vt = $this->veritabanlari;
            $kapali = $this->baglantiyaKapali;
            $yetkiler = $this->veritabaniYetkileri;
            foreach ([[$d['a'], $d['b']], [$d['c'], $d['d']]] as [$eski, $yeni]) {
                if (! in_array($eski, $vt, true) || in_array($yeni, $vt, true)) {
                    return Process::result(errorOutput: 'ERROR:  database does not exist / already exists', exitCode: 3);
                }
                $vt = array_values(array_map(fn (string $ad): string => $ad === $eski ? $yeni : $ad, $vt));
                if (isset($kapali[$eski])) {
                    unset($kapali[$eski]);
                    $kapali[$yeni] = true;
                }
                $yetki = $yetkiler[$eski] ?? null;
                unset($yetkiler[$eski]);
                $yetkiler[$yeni] = $yetki;
            }
            $this->veritabanlari = $vt;
            $this->baglantiyaKapali = $kapali;
            $this->veritabaniYetkileri = $yetkiler;

            // Kancanın kendi hatası "ad değiştirme hatası" gibi koda sızmasın:
            // saklanır, test kancanın çalıştığını ayrıca doğrular (T7)
            if ($this->yerDegistirmeSonrasi !== null) {
                try {
                    ($this->yerDegistirmeSonrasi)();
                } catch (\Throwable $hata) {
                    $this->kancaHatasi = $hata;
                }
            }

            return '';
        }

        if (str_contains($sql, 'CREATE DATABASE')) {
            $this->komutlar[] = 'olustur '.$d['ad'];
        }
        if (str_contains($sql, 'DROP DATABASE')) {
            $this->komutlar[] = 'sil '.$d['ad'];
        }

        return $this->psqlYaniti($p);
    }

    protected function ustuneYedekAl(): Yedek
    {
        $kilit = app(YedeklemeYapilandirmasi::class)->islemDurumu()->kilit();
        $this->assertTrue($kilit->al());
        $yedek = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $kilit->birak();
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->komutlar = [];
        $this->pgDumpBakimda = [];

        return $yedek;
    }

    /**
     * @return array{0: IslemKaydi, 1: string} [son durum, erişim anahtarı]
     */
    protected function ustuneBaslat(Yedek $yedek, int $kullaniciId, string $onayMetni = 'erp'): array
    {
        $anahtar = ErisimAnahtari::uret();
        $kilit = $this->kilitAl();

        try {
            $kayit = app(UstuneGeriYukleyici::class)->baslat($kilit, $yedek, $onayMetni, $kullaniciId, (string) Str::uuid(), ErisimAnahtari::ozet($anahtar), CarbonImmutable::now('UTC')->addHours(8));
        } finally {
            $kilit->birak();
            $this->tutulanKilit = null;
        }

        return [$kayit, $anahtar];
    }

    /**
     * Elle kurulan durum (yarıda kalan işlem, onay bekleyen işlem).
     *
     * @param  array{kod: string, mesaj: string}|null  $hata
     * @param  array{baglanma_yetkileri: string|null}|null  $bariyer  Yazıcı bariyerinin kaydettiği özgün yetkiler
     */
    protected function durumYaz(UstuneGeriYuklemeAdimi|string $adim, bool $acik, ?array $hata = null, ?string $anahtar = null, ?CarbonImmutable $bitis = null, string $kimlik = '7d0c4f3e-2b1a-4c5d-8e9f-0a1b2c3d4e5f', ?string $yedekKimligi = null, ?int $isteyen = 1, ?array $bariyer = null): IslemKaydi
    {
        $kayit = new IslemKaydi(
            kimlik: $kimlik,
            tur: IslemTuru::GeriYuklemeUstune,
            adim: $adim instanceof UstuneGeriYuklemeAdimi ? $adim->value : $adim,
            acik: $acik,
            baslangic: CarbonImmutable::now('UTC')->subMinutes(5),
            sonGuncelleme: CarbonImmutable::now('UTC'),
            hata: $hata,
            erisimAnahtariOzeti: ErisimAnahtari::ozet($anahtar ?? str_repeat('a', 64)),
            erisimAnahtariBitis: $bitis ?? CarbonImmutable::now('UTC')->addHours(8),
            yedekKimligi: $yedekKimligi ?? (string) Str::uuid(),
            hedefVeritabani: 'erp_geri_20261121_103000',
            oncekiVeritabani: 'erp_onceki_20261121_103000',
            isteyenKullaniciId: $isteyen,
            bariyer: $bariyer,
        );
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz($kayit, $kilit);
        $kilit->birak();
        $this->tutulanKilit = null;

        return $kayit;
    }

    /**
     * Web ucunun yazdığı biçimde karar isteği: anahtar değil, anahtarın
     * özetiyle üretilmiş kısa ömürlü kanıt (BY-030-R1). `$kanitAyarSecimleri`
     * verilirse kanıt o seçimlerle, dosya `$ayarSecimleri` ile yazılır (kurcalama).
     *
     * @param  'onayla'|'geri_don'  $tur
     * @param  array<string, string>  $ayarSecimleri
     * @param  array<string, string>|null  $kanitAyarSecimleri
     */
    protected function kararIstegiYaz(string $tur, string $islem, string $anahtar, ?int $kullaniciId, array $ayarSecimleri = [], ?int $son = null, ?string $nonce = null, ?array $kanitAyarSecimleri = null): string
    {
        $son ??= CarbonImmutable::now()->getTimestamp() + ErisimAnahtari::KANIT_OMRU_SN;
        $nonce ??= ErisimAnahtari::nonce();
        $secim = AyarSecimi::metne($ayarSecimleri);

        return app(IstekKutusu::class)->yaz($tur, [
            'islem_kimligi' => $islem,
            'son_gecerlilik' => $son,
            'nonce' => $nonce,
            ...($tur === 'onayla' ? ['ayar_secimleri' => $secim] : []),
            'kanit' => ErisimAnahtari::kanit(ErisimAnahtari::ozet($anahtar), $islem, $tur, $son, $nonce, AyarSecimi::metne($kanitAyarSecimleri ?? $ayarSecimleri)),
        ], $kullaniciId);
    }

    /** Bu işlemin bakım modu dosyası (Laravel biçimi + işaret) */
    protected function bakimDosyasiYaz(string $kimlik): void
    {
        file_put_contents(storage_path('framework/down'), (string) json_encode(['except' => [], 'status' => 503, 'template' => null, 'yedekleme_islemi' => $kimlik]));
    }

    /**
     * Uygulamanın veritabanı erişilemezken çalıştırır: varsayılan bağlantı
     * açılamayan bir dosyayı gösterir. Sonra test veritabanına geri dönülür
     * (testin işlem geri alması varsayılan bağlantıda yapılır).
     */
    protected function veritabaniErisilemezken(Closure $is): void
    {
        $varsayilan = config('database.default');
        config(['database.connections.erisilemez' => ['driver' => 'sqlite', 'database' => '/yok/klasor/erp.sqlite'], 'database.default' => 'erisilemez']);

        try {
            $is();
        } finally {
            config(['database.default' => $varsayilan]);
            DB::purge('erisilemez');
        }
    }

    /** Uygulama bu işlemin bakım modunda mı (dosyadaki işaret) */
    protected function bakimdaBuIslemle(string $kimlik): bool
    {
        $yol = storage_path('framework/down');
        $veri = is_file($yol) ? json_decode((string) file_get_contents($yol), true) : null;

        return is_array($veri) && ($veri['yedekleme_islemi'] ?? null) === $kimlik;
    }

    protected function durumOku(): ?IslemKaydi
    {
        return app(YedeklemeYapilandirmasi::class)->islemDurumu()->oku();
    }
}
