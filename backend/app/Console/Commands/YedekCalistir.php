<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
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
 *   başlatır, yeni kod ve yapılandırma yüklenir.
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

    private bool $durdur = false;

    /** Ayar kopyasının son denetlendiği an (hrtime, ns) */
    private ?int $sonAyarDenetimi = null;

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

        for ($tur = 0; $tur < (int) $enCok && ! $this->durdur; $tur++) {
            if ($yapilandirma->aktif()) {
                $this->tur($calistirici);
            }

            if (! $this->durdur) {
                Sleep::for(self::BEKLEME)->seconds();
            }
        }

        return self::SUCCESS;
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
