<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\YedekPlani;
use App\Services\Yedekleme\PlanliYedek;
use App\Services\Yedekleme\PlanTakvimi;
use App\Services\Yedekleme\YedekBildirimcisi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zamanlayıcı (BY-009; README §4.2, §5.2): dakikada bir çağrılır, vadesi
 * gelen planları bulur, aynı ana düşenler için TEK yedek alır.
 *
 * - `YEDEKLEME_AKTIF` kapalıysa hiçbir şey yapmaz, hiçbir süreç başlatmaz.
 * - Kilit başka işlemdeyse beklemez (bir sonraki dakika dener); açık bir
 *   üstüne geri yükleme varsa başlamaz.
 * - Kaçırılan çalışma bir kez telafi edilir: sıradaki an şimdiden hesaplanır.
 * - Bakım modunda da çalışır (routes/console.php `evenInMaintenanceMode`).
 * - Planlar okunamazsa (veritabanı erişilemiyor) FAILURE ve bildirim (G2-3).
 *   Bilinen sınır: geçiş kipinde zamanlayıcının `withoutOverlapping` mutex'i
 *   veritabanı önbelleğinde olduğu için veritabanı yokken `schedule:run` bu
 *   komutu hiç başlatmaz; o durumu dış sessizlik izleyicisi (yaşam sinyali)
 *   yakalar. Servis kipinde döngü komutu doğrudan çağırır, bildirim gider.
 */
#[Signature('yedek:zamanla')]
#[Description('Vadesi gelen yedek planlarını çalıştırır')]
final class YedekZamanla extends Command
{
    public function handle(YedeklemeYapilandirmasi $yapilandirma, PlanTakvimi $takvim, PlanliYedek $planli, YedekBildirimcisi $bildirimci): int
    {
        if (! $yapilandirma->aktif()) {
            return self::SUCCESS;
        }

        // Planlar okunamıyorsa (PostgreSQL erişilemiyor) bu "vadesi gelen yok"
        // değildir (D6): hata ile çıkılır ve bildirilir (G2-3). Mail ayarı o zaman
        // dosya kopyasından okunur; yineleme sınırı dosyada (saatte bir mail)
        try {
            $vadesiGelenler = $this->vadesiGelenler($takvim, CarbonImmutable::now());
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->error('Yedek planları okunamadı; planlı yedek alınmadı', ['hata' => $hata::class]);
            $this->error('Yedek planları okunamadı: '.$hata::class);
            $bildirimci->bildir('yedek_basarisiz', [__('yedekleme.mail.planlar_okunamadi').': '.$hata::class], 'zamanlayici:veritabani');

            return self::FAILURE;
        }

        if ($vadesiGelenler->isEmpty()) {
            return self::SUCCESS;
        }

        $durum = $yapilandirma->islemDurumu();
        $kilit = $durum->kilit();

        try {
            if (! $kilit->al()) {
                $this->line('Başka bir yedekleme işlemi sürüyor; bir sonraki dakika denenecek.');

                return self::SUCCESS;
            }

            // Kilit beklenirken başka bir süreç aynı planları çalıştırmış olabilir
            // (zamanlayıcı mutex'i erken dolabilir): vade kilit ALTINDA yeniden
            // okunur, çalıştırma kararı ondan verilir (G18, YF-37)
            $vadesiGelenler = $this->vadesiGelenler($takvim, CarbonImmutable::now());
            if ($vadesiGelenler->isEmpty()) {
                return self::SUCCESS;
            }

            if ($durum->acikGeriYuklemeVarMi()) {
                $this->warn('Açık bir geri yükleme var; planlı yedek alınmadı.');

                return self::SUCCESS;
            }

            $yedek = $planli->calistir($vadesiGelenler, $kilit);
            $this->line("Planlı yedek ({$vadesiGelenler->pluck('ad')->implode(', ')}): {$yedek->durum->value}");
        } catch (Throwable $hata) {
            // Bozuk durum dosyası "başlama" demektir (BY-005). Yedek kaydı hiç
            // açılamadıysa (veritabanı yok) YedekAlici bildiremez: burada
            // bildirilir; yineleme sınırı saatte bir maille sınırlar (YF-39)
            Log::channel('yedekleme')->error('Planlı yedek çalışmadı', ['hata' => $hata->getMessage()]);
            $this->error($hata->getMessage());
            $bildirimci->bildir('yedek_basarisiz', [__('yedekleme.mail.planli_yedek_calismadi').': '.$hata::class]);

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        return self::SUCCESS;
    }

    /**
     * Etkin ve vadesi gelmiş planlar. İlk görülen plan geriye dönük çalışmaz:
     * sıradaki anı kurulur, bu turda çalışmaz.
     *
     * @return Collection<int, YedekPlani>
     */
    private function vadesiGelenler(PlanTakvimi $takvim, CarbonImmutable $simdi): Collection
    {
        return YedekPlani::query()->where('aktif', true)->orderBy('id')->get()
            ->filter(function (YedekPlani $plan) use ($takvim, $simdi): bool {
                if ($plan->sonraki_calisma === null) {
                    try {
                        $plan->forceFill(['sonraki_calisma' => $takvim->sonraki($plan, $simdi)])->save();
                    } catch (Throwable $hata) {
                        Log::channel('yedekleme')->warning('Plan tanımı geçersiz; atlandı', ['plan_id' => $plan->id, 'hata' => $hata->getMessage()]);
                    }

                    return false;
                }

                return $plan->sonraki_calisma->lessThanOrEqualTo($simdi);
            })
            ->values();
    }
}
