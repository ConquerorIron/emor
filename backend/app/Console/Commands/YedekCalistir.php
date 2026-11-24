<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\AcikGeriYuklemeKorumasi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
use App\Services\Yedekleme\KaliciKlasor;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Yedekleme çalıştırıcısı (BY-025; README §5.2): istek klasörünü işler.
 *
 * - Servis (`emor-yedek.service`, servis hesabı): uzun süren döngü; turlar
 *   arası 2 sn. SIGTERM / SIGINT gelince (pcntl varsa) o anki iş bitince
 *   çıkar. `--en-cok-tur` turdan sonra kendiliğinden çıkar; systemd yeniden
 *   başlatır, yeni kod ve yapılandırma yüklenir. Yayından sonra beklemez:
 *   `deploy.sh` başarılı yayının sonunda yayın damgasını yazar
 *   (`yedekleme.yayin_damgasi`); döngü her turun başında (iş yokken) damgayı
 *   açılıştakiyle karşılaştırır, değiştiyse çıkar (YF-24). Bellekteki eski
 *   sınıflar yeni şemaya, diskteki yeni sınıflarla karışarak çalışmaz. Damga
 *   yoksa ya da okunamıyorsa etkisizdir; içeriği yalnız karşılaştırılır.
 *   Servis kipinde
 *   (`calistirici = servis`) planlı yedek (`yedek:zamanla`, dakikada bir) ve
 *   günlük temizlik (`yedek:temizle`) de bu döngüde yürür (BY-009-R1).
 * - `--tek-tur`: kuyruğu bir kez işler. Geçiş döneminde (`calistirici =
 *   dogrudan`) zamanlayıcı dakikada bir çağırır (routes/console.php).
 *
 * Modül kapalıyken hiçbir istek işlenmez. Servis döngüsü kapalıyken de
 * çıkmaz, boşta bekler: systemd'yi yeniden başlatma döngüsüne sokmasın.
 */
#[Signature('yedek:calistir
    {--tek-tur : Kuyruğu bir kez işleyip çık}
    {--en-cok-tur=1800 : Servis döngüsü bu kadar turdan sonra çıkar (systemd yeniden başlatır)}')]
#[Description('Yedekleme istek klasörünü işler (servis hesabının çalıştırıcısı)')]
final class YedekCalistir extends Command
{
    /** Turlar arası bekleme (sn) */
    private const BEKLEME = 2;

    /** Servis kipinde günlük temizliğin saati (routes/console.php'deki zamanlayıcıyla aynı) */
    private const TEMIZLIK_SAATI = '04:10';

    private const TEMIZLIK_SAAT_DILIMI = 'Europe/Istanbul';

    /** Durum klasöründe günlük temizliğin son deneme anı (UTC) */
    private const TEMIZLIK_DOSYASI = 'temizlik-son-calisma';

    private const ZAMAN_BICIMI = 'Y-m-d\TH:i:sP';

    private bool $durdur = false;

    /** Ayar kopyasının son denetlendiği an (hrtime, ns) */
    private ?int $sonAyarDenetimi = null;

    /** Planlı yedek tikinin son çalıştığı an (hrtime, ns) */
    private ?int $sonPlanTiki = null;

    /** Bu süreçte temizliğin denendiği (ya da yapılmış bulunduğu) vade */
    private ?CarbonImmutable $sonTemizlikVadesi = null;

    public function handle(YedeklemeYapilandirmasi $yapilandirma, IstekCalistiricisi $calistirici): int
    {
        if ($this->option('tek-tur')) {
            return $yapilandirma->aktif() ? $this->tur($calistirici) : self::SUCCESS;
        }

        $enCok = (string) $this->option('en-cok-tur');
        if (preg_match('/^[1-9][0-9]{0,6}$/D', $enCok) !== 1) {
            $this->error('--en-cok-tur pozitif bir tam sayı olmalı.');

            return self::INVALID;
        }

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->durdur = true;
            });
        }

        $damgaYolu = (string) config('yedekleme.yayin_damgasi');
        $ilkDamga = $this->yayinDamgasi($damgaYolu);

        for ($tur = 0; $tur < (int) $enCok && ! $this->durdur; $tur++) {
            $damga = $this->yayinDamgasi($damgaYolu);
            if ($damga !== null && $damga !== $ilkDamga) {
                $this->line('Yeni yayın görüldü; çalıştırıcı yeni kodla başlamak için çıkıyor.');

                break;
            }

            if ($yapilandirma->aktif()) {
                $this->tur($calistirici);

                if ($yapilandirma->servisCalistiricisi()) {
                    $this->zamanlanmisIsler($yapilandirma);
                }
            }

            if (! $this->durdur) {
                Sleep::for(self::BEKLEME)->seconds();
            }
        }

        return self::SUCCESS;
    }

    /** Yayın damgasının içeriği (en çok 256 bayt); yol boşsa, dosya yoksa ya da okunamıyorsa null */
    private function yayinDamgasi(string $yol): ?string
    {
        if ($yol === '') {
            return null;
        }

        $icerik = @file_get_contents($yol, false, null, 0, 256);

        return is_string($icerik) ? $icerik : null;
    }

    /**
     * Servis kipinde planlı yedek ve günlük temizlik bu döngüde yürür (BY-009-R1):
     * yedek klasörüne yazma ve imza anahtarı servis hesabınındır; Laravel
     * zamanlayıcısı (web hesabı) servis kipinde bu iki işi çalıştırmaz
     * (routes/console.php). Plan tiki dakikada bir (monoton saat — D1). Hata
     * döngüyü bozmaz.
     */
    private function zamanlanmisIsler(YedeklemeYapilandirmasi $yapilandirma): void
    {
        try {
            $simdi = hrtime(true);
            if ($this->sonPlanTiki === null || $simdi - $this->sonPlanTiki >= 60_000_000_000) {
                $this->sonPlanTiki = $simdi;
                $this->call('yedek:zamanla');
            }

            $this->gunlukTemizlik($yapilandirma);
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->error('Planlı yedek / günlük temizlik çalışmadı', ['hata' => $hata::class]);
        }
    }

    /**
     * Günlük temizlik: en son TEMIZLIK_SAATI (İstanbul) vadesinden sonra
     * çalışılmadıysa bir kez. Son deneme anı (UTC) servisin durum klasöründe
     * tutulur: servis o saatte kapalıysa açılınca bir kez yapar, yeniden
     * başlayınca tekrarlamaz. Deneme SONUÇTAN bağımsız kaydedilir: başarısız
     * temizlik (erişilemeyen hedef) her turda yeniden denenmez (D8). Açık
     * üstüne geri yüklemede (ya da durum okunamazsa) çalışmaz — zamanlayıcının
     * AcikGeriYuklemeKorumasi'nın karşılığı; işlem kapanınca yapılır.
     */
    private function gunlukTemizlik(YedeklemeYapilandirmasi $yapilandirma): void
    {
        [$saat, $dakika] = array_map('intval', explode(':', self::TEMIZLIK_SAATI));
        $simdi = CarbonImmutable::now(self::TEMIZLIK_SAAT_DILIMI);
        $vade = $simdi->setTime($saat, $dakika);
        if ($vade->greaterThan($simdi)) {
            $vade = $vade->subDay();
        }

        if ($this->sonTemizlikVadesi?->equalTo($vade)) {
            return;
        }

        $dosya = $yapilandirma->durumKlasoru().'/'.self::TEMIZLIK_DOSYASI;
        $son = $this->sonTemizlikAni($dosya);
        if ($son !== null && $son->greaterThanOrEqualTo($vade)) {
            $this->sonTemizlikVadesi = $vade;

            return;
        }

        if (app(AcikGeriYuklemeKorumasi::class)->engelliMi()) {
            return;
        }

        $this->sonTemizlikVadesi = $vade;

        try {
            KaliciKlasor::ac($yapilandirma->durumKlasoru(), $yapilandirma->kaliciKok());
            AtomikDosya::yaz($dosya, CarbonImmutable::now('UTC')->format(self::ZAMAN_BICIMI), 0640);
        } catch (Throwable $hata) {
            // Bu süreçte tekrarlanmaz (sonTemizlikVadesi); yeniden başlarsa bir kez daha denenir
            Log::channel('yedekleme')->warning('Günlük temizliğin son çalışma anı yazılamadı', ['hata' => $hata::class]);
        }

        $this->call('yedek:temizle');
    }

    /** Dosyadaki son temizlik anı; yoksa ya da biçimsizse null (G11: geri biçimlenip karşılaştırılır) */
    private function sonTemizlikAni(string $dosya): ?CarbonImmutable
    {
        $metin = @file_get_contents($dosya);
        if (! is_string($metin)) {
            return null;
        }

        try {
            $an = CarbonImmutable::createFromFormat(self::ZAMAN_BICIMI, $metin, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $an instanceof CarbonImmutable && $an->format(self::ZAMAN_BICIMI) === $metin ? $an : null;
    }

    /**
     * Ayar dosya kopyası veritabanındakinden farklıysa yazılır (en çok dakikada
     * bir; monoton saat — D1). Ayar ekranları web sürecindedir ve servis
     * kipinde kopyayı yazamaz (README §5.5). Açık üstüne geri yüklemede
     * yazılmaz; durum okunamazsa da (istisna) yazılmaz. Hata turu bozmaz.
     */
    private function ayarKopyasiniTazele(): void
    {
        $simdi = hrtime(true);
        if ($this->sonAyarDenetimi !== null && $simdi - $this->sonAyarDenetimi < 60_000_000_000) {
            return;
        }
        $this->sonAyarDenetimi = $simdi;

        try {
            // Açık üstüne geri yüklemede veritabanı yedeğin anındadır: kopya o
            // eski ayarlarla ezilmesin (onay beklerken bildirimler ve onay
            // bilgisi güncel kopyadan okunur — BY-029)
            if (app(YedeklemeYapilandirmasi::class)->islemDurumu()->acikGeriYuklemeVarMi()) {
                return;
            }

            app(AyarKopyasi::class)->guncelDegilseYaz();
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->warning('Ayar kopyası tazelenemedi', ['hata' => $hata::class]);
        }
    }

    /**
     * Turun hatası döngüyü öldürmez (bir sonraki tur yeniden dener); tek
     * turda çıkış kodu hatadır.
     */
    private function tur(IstekCalistiricisi $calistirici): int
    {
        try {
            $sayi = $calistirici->tur();
            if ($sayi > 0) {
                $this->line("{$sayi} yedekleme isteği işlendi.");
            }

            $this->ayarKopyasiniTazele();

            return self::SUCCESS;
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->error('Çalıştırıcı turu çalışmadı', ['hata' => $hata::class]);
            $this->error('Çalıştırıcı turu çalışmadı: '.$hata::class);

            return self::FAILURE;
        }
    }
}
