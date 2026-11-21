<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * BY-016-R2, R4: "Bağlantıyı Sına" tek toplam süre bütçesiyle çalışır (D9);
 * süre monoton saatle ölçülür (D1) — testte sahte saat. Bütçe bağlayıcının
 * içindeki her isteğe, yeniden denemeye, beklemeye ve sürece taşınır.
 */
final class HedefSinayiciTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** ssh-keygen ile üretilmiş deneme anahtarı (SftpBaglayicisiTest ile aynı) */
    private const SFTP_ANAHTARI = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMe0NgRXiRgIHEKi16ZxwBWlZnAgh5lzMpXqkOF+T8Aq';

    public function test_yavas_adimlar_toplam_butceyi_asmaz_bitmeyen_adim_sure_doldu_olur_temizlik_yine_denenir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 900, 'yedekleme.sure_siniri.baglanti' => 10]);
        $saat = new class
        {
            public int $ns = 1_000_000_000;
        };

        // Her çağrı kendisine verilen sınırın en kötü hâlini tüketir: 3 deneme × sınır
        $baglayici = new class($saat) implements HedefBaglayicisi
        {
            /** @var array<string, array{kopya: int, baglanti: int, kalan: float}> */
            public array $gorulen = [];

            /** @var array<string, string> */
            public array $dosyalar = [];

            public ?SureButcesi $butce = null;

            public function __construct(private readonly object $saat) {}

            private function yavas(string $adim): void
            {
                $yapilandirma = app(YedeklemeYapilandirmasi::class);
                $kopya = $yapilandirma->sureSiniri('kopya');
                $this->gorulen[$adim] = ['kopya' => $kopya, 'baglanti' => $yapilandirma->sureSiniri('baglanti'), 'kalan' => $this->butce?->kalanSaniye() ?? 0.0];
                $this->saat->ns += 3 * max($kopya, $yapilandirma->sureSiniri('baglanti')) * 1_000_000_000;
            }

            public function baglan(): void
            {
                $this->yavas('baglan');
            }

            public function yaz(string $yerelYol, string $ad): string
            {
                $this->yavas('yaz');
                $this->dosyalar[$ad] = (string) file_get_contents($yerelYol);

                return $ad;
            }

            public function oku(string $uzakKimlik, string $yerelYol): void
            {
                $this->yavas('oku');
                file_put_contents($yerelYol, $this->dosyalar[$uzakKimlik]);
            }

            public function sil(string $uzakKimlik): void
            {
                $this->yavas('sil');
                unset($this->dosyalar[$uzakKimlik]);
            }

            public function listele(): array
            {
                return array_keys($this->dosyalar);
            }

            public function kalanAlan(): ?int
            {
                $this->yavas('kalanAlan');

                return 1;
            }
        };
        $baslangic = $saat->ns;
        $butce = new SureButcesi(HedefSinayici::TOPLAM_SURE, fn (): int => $saat->ns);
        $baglayici->butce = $butce;

        $sonuc = app(HedefSinayici::class)->sonuc($baglayici, $butce);

        // Toplam süre bütçeyi aşmadı; sonuç döndü
        $this->assertLessThanOrEqual(HedefSinayici::TOPLAM_SURE * 1_000_000_000, $saat->ns - $baslangic);
        $adimlar = collect($sonuc['adimlar'])->keyBy('adim');
        $this->assertSame(['baglan' => true, 'yaz' => true, 'oku' => false, 'dogrula' => null, 'sil' => true], $adimlar->map(fn (array $a) => $a['basarili'])->all());
        $this->assertSame(HedefSinayici::SURE_DOLDU, $adimlar['oku']['kod']);
        $this->assertFalse($sonuc['basarili']);
        // Sınama dosyası silindi; her adımın sınırı kalan sürenin üçte birini geçmedi
        $this->assertSame([], $baglayici->dosyalar);
        foreach ($baglayici->gorulen as $adim => $gorulen) {
            $this->assertLessThanOrEqual(max(1, $gorulen['kalan'] / 3), max($gorulen['kopya'], $gorulen['baglanti']), $adim);
        }
        $this->assertArrayNotHasKey('oku', $baglayici->gorulen);
        // Bağlayıcının süre ayarları sınamadan sonra eski değerinde
        $this->assertSame([900, 10], [config('yedekleme.sure_siniri.kopya'), config('yedekleme.sure_siniri.baglanti')]);
    }

    /**
     * BY-016-R4: Drive tek çağrıda token + istek + 401 sonrası token yenileme
     * yapar; yenileme 5xx ile yeniden denenir. Her istek kendisine verilen süre
     * sınırının sonunda yanıt verir (yavaş sunucu), beklemeler sahte saati
     * ilerletir: sınama yine bütçe içinde izlenebilir sonuçla döner.
     */
    public function test_drive_yavas_baglanti_401_yenileme_ve_yeniden_denemede_toplam_butceyi_asmaz(): void
    {
        config([
            'yedekleme.sure_siniri.kopya' => 900, 'yedekleme.sure_siniri.baglanti' => 10,
            'yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir',
        ]);
        $saat = new class
        {
            public int $ns = 1_000_000_000;
        };
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $sure) use ($saat): void {
            $saat->ns += (int) ($sure->totalMicroseconds * 1000);
        });
        $tokenYanitlari = [
            Http::response(['access_token' => 'ya29.ilk', 'expires_in' => 3599]),
            Http::response(['error' => ['code' => 503]], 503),
            Http::response(['error' => ['code' => 503]], 503),
            Http::response(['error' => ['code' => 503]], 503),
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $istek, array $secenekler) use ($saat, &$tokenYanitlari) {
            // İlk token hızlı (1 sn); sonrakiler süre sınırının sonunda
            $ilkToken = count($tokenYanitlari) === 4 && str_starts_with($istek->url(), 'https://oauth2.googleapis.com/token');
            $saat->ns += (int) (($ilkToken ? 1 : (float) $secenekler['timeout']) * 1_000_000_000);

            return str_starts_with($istek->url(), 'https://oauth2.googleapis.com/token')
                ? array_shift($tokenYanitlari)
                : Http::response(['error' => ['code' => 401]], 401);
        });
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'yenileme']]);
        $baslangic = $saat->ns;

        $sonuc = app(HedefSinayici::class)->sonuc(app(HedefKatalogu::class)->olustur($hedef), new SureButcesi(HedefSinayici::TOPLAM_SURE, fn (): int => $saat->ns));

        $this->assertLessThanOrEqual(HedefSinayici::TOPLAM_SURE * 1_000_000_000, $saat->ns - $baslangic);
        $this->assertFalse($sonuc['basarili']);
        $this->assertSame(['baglan', false, HedefHatasi::ERISILEMEDI], [$sonuc['adimlar'][0]['adim'], $sonuc['adimlar'][0]['basarili'], $sonuc['adimlar'][0]['kod']]);
        // İlk token + klasör isteği (401) + yenileme iki kez denendi; üçüncü deneme bütçeye sığmadığı için yapılmadı
        $this->assertSame(3, Http::recorded(fn (Request $istek): bool => str_starts_with($istek->url(), 'https://oauth2.googleapis.com/token'))->count());
        $this->assertSame(1, Http::recorded(fn (Request $istek): bool => str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/files/klasor-1'))->count());
    }

    /** BY-016-R4: SFTP sunucu anahtarı alma (dış süreç) ve ardından bağlantı aynı son zamanı paylaşır */
    public function test_sftp_anahtar_alma_ve_baglanti_sureci_ortak_son_zamani_asmaz(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 900, 'yedekleme.sure_siniri.baglanti' => 10]);
        $saat = new class
        {
            public int $ns = 1_000_000_000;
        };
        $sureler = [];
        // Her süreç kendisine verilen süre sınırının sonunda biter (asılı sunucu)
        Process::fake(function (PendingProcess $surec) use ($saat, &$sureler) {
            $sureler[] = $surec->timeout;
            $saat->ns += (int) ($surec->timeout * 1_000_000_000);

            return str_contains((string) $surec->command[0], 'ssh-keyscan')
                ? Process::result('sftp.ornek.test '.self::SFTP_ANAHTARI."\n")
                : Process::result('', 'Connection timed out', 255);
        });
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::Sftp, 'baglanti' => ['sunucu' => 'sftp.ornek.test', 'port' => 22, 'kullanici_adi' => 'yedekci', 'klasor' => 'yedekler'], 'gizli_bilgiler' => ['sifre' => 'Gizli-1']]);
        $baslangic = $saat->ns;

        $sonuc = app(HedefSinayici::class)->sonuc(app(HedefKatalogu::class)->olustur($hedef), new SureButcesi(HedefSinayici::TOPLAM_SURE, fn (): int => $saat->ns));

        $this->assertLessThanOrEqual(HedefSinayici::TOPLAM_SURE * 1_000_000_000, $saat->ns - $baslangic);
        $this->assertSame([false, HedefHatasi::ERISILEMEDI], [$sonuc['adimlar'][0]['basarili'], $sonuc['adimlar'][0]['kod']]);
        // Anahtar alma bağlantı adımının payından; bağlantı kalan süreyle sınırlı
        $this->assertCount(2, $sureler);
        $this->assertLessThanOrEqual(HedefSinayici::TOPLAM_SURE - HedefSinayici::TEMIZLIK_PAYI, array_sum($sureler));
    }

    public function test_hizli_hedefte_butce_kalan_alani_da_kapsar(): void
    {
        $sonuc = app(HedefSinayici::class)->sonuc(new class implements HedefBaglayicisi
        {
            /** @var array<string, string> */
            private array $dosyalar = [];

            public function baglan(): void {}

            public function yaz(string $yerelYol, string $ad): string
            {
                $this->dosyalar[$ad] = (string) file_get_contents($yerelYol);

                return $ad;
            }

            public function oku(string $uzakKimlik, string $yerelYol): void
            {
                file_put_contents($yerelYol, $this->dosyalar[$uzakKimlik]);
            }

            public function sil(string $uzakKimlik): void
            {
                unset($this->dosyalar[$uzakKimlik]);
            }

            public function listele(): array
            {
                return [];
            }

            public function kalanAlan(): ?int
            {
                return 123_456;
            }
        });

        $this->assertSame([true, 123_456, null], [$sonuc['basarili'], $sonuc['kalan_alan'], $sonuc['silinme_korumasi']]);
    }
}
