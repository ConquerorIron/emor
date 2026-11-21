<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * "Onay bekleniyor" korumaları (README §6.4 "Yeniden başlatmaya
 * dayanıklılık", §7.6; BY-029). Açık bir üstüne geri yükleme varken:
 *
 * - Kuyruk işçisi iş ALMAZ: `Looping` olayı false döner, işçi bekler
 *   (Laravel Worker::daemonShouldRun). Sunucu yeniden başlayıp servis
 *   kendiliğinden açılsa da durum dosyası bakım modundan bağımsız korur.
 * - Zamanlayıcı hiçbir görevi başlatmaz: `schedule:run` başlarken her göreve
 *   aynı denetimle `skip` eklenir (görev hangi dosyada tanımlanmış olursa
 *   olsun). e-Fatura senkronu ve alarm mailleri de böylece onaydan önce
 *   çalışmaz (eski veriyle — §7.6).
 *
 * Durum dosyası okunamıyorsa da çalışılmaz (bilinmeyen durum izin değildir —
 * D6). Modül kapalıyken (YEDEKLEME_AKTIF) etkisizdir; bakım modu ayrıca korur.
 * Yayın (`deploy.sh` → `yedek:yayin-korumasi denetle`, yayın kilidi altında),
 * planlı yedek (`yedek:zamanla`) ve servis döngüsünün günlük temizliği
 * (`yedek:calistir`) aynı denetimi kendileri yapar.
 */
final class AcikGeriYuklemeKorumasi
{
    private static bool $bozukBildirildi = false;

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    public static function kaydet(Application $uygulama): void
    {
        Queue::looping(fn (): bool => ! $uygulama->make(self::class)->engelliMi());

        Event::listen(CommandStarting::class, function (CommandStarting $olay) use ($uygulama): void {
            if ($olay->command !== 'schedule:run') {
                return;
            }

            foreach ($uygulama->make(Schedule::class)->events() as $gorev) {
                $gorev->skip(fn (): bool => $uygulama->make(self::class)->engelliMi());
            }
        });
    }

    /** Açık üstüne geri yükleme var (ya da durum bilinmiyor): iş çalıştırılmaz */
    public function engelliMi(): bool
    {
        if (! $this->yapilandirma->aktif()) {
            return false;
        }

        try {
            return $this->yapilandirma->islemDurumu()->acikGeriYuklemeVarMi();
        } catch (Throwable $hata) {
            // İşçi her döngüde sorar: günlüğe süreç başına bir kez
            if (! self::$bozukBildirildi) {
                self::$bozukBildirildi = true;
                Log::channel('yedekleme')->error('İşlem durumu okunamadı; kuyruk ve zamanlayıcı iş çalıştırmıyor', ['hata' => $hata->getMessage()]);
            }

            return true;
        }
    }
}
