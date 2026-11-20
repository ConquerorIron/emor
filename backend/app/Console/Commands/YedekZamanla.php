<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\YedekPlani;
use App\Services\Yedekleme\PlanliYedek;
use App\Services\Yedekleme\PlanTakvimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
 */
#[Signature('yedek:zamanla')]
#[Description('Vadesi gelen yedek planlarını çalıştırır')]
final class YedekZamanla extends Command
{
    public function handle(YedeklemeYapilandirmasi $yapilandirma, PlanTakvimi $takvim, PlanliYedek $planli): int
    {
        if (! $yapilandirma->aktif()) {
            return self::SUCCESS;
        }

        $simdi = CarbonImmutable::now();
        $vadesiGelenler = YedekPlani::query()->where('aktif', true)->orderBy('id')->get()
            ->filter(function (YedekPlani $plan) use ($takvim, $simdi): bool {
                if ($plan->sonraki_calisma === null) {
                    try {
                        // İlk görülen plan geriye dönük çalışmaz: sıradaki an kurulur
                        $plan->forceFill(['sonraki_calisma' => $takvim->sonraki($plan, $simdi)])->save();
                    } catch (Throwable $hata) {
                        Log::channel('yedekleme')->warning('Plan tanımı geçersiz; atlandı', ['plan_id' => $plan->id, 'hata' => $hata->getMessage()]);
                    }

                    return false;
                }

                return $plan->sonraki_calisma->lessThanOrEqualTo($simdi);
            })
            ->values();

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

            if ($durum->acikGeriYuklemeVarMi()) {
                $this->warn('Açık bir geri yükleme var; planlı yedek alınmadı.');

                return self::SUCCESS;
            }

            $yedek = $planli->calistir($vadesiGelenler, $kilit);
            $this->line("Planlı yedek ({$vadesiGelenler->pluck('ad')->implode(', ')}): {$yedek->durum->value}");
        } catch (Throwable $hata) {
            // Bozuk durum dosyası "başlama" demektir (BY-005)
            Log::channel('yedekleme')->error('Planlı yedek çalışmadı', ['hata' => $hata->getMessage()]);
            $this->error($hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        return self::SUCCESS;
    }
}
