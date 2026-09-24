<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AlarmBildirimiGonder;
use App\Models\AlarmBildirimi;
use App\Services\Alarm\AlarmDegerlendirici;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * e-Fatura alarm kurallarını değerlendirir ve bekleyen bildirimleri kuyruğa
 * verir (EFAT-13). Zamanlanmış senkrondan hemen sonra çalışır.
 *
 * Kuyruğa verilemeden kalmış (ör. süreç çöktü) eski `bekliyor` kayıtlar da
 * yeniden kuyruğa alınır; iş tekil olduğu için aynı bildirim iki kez sıraya girmez.
 */
#[Signature('efatura:alarmlar')]
#[Description('e-Fatura alarm kurallarını değerlendirir, bildirimleri kuyruğa verir')]
final class EFaturaAlarmlar extends Command
{
    private const YETIM_DAKIKA = 15;

    public function handle(AlarmDegerlendirici $degerlendirici): int
    {
        $yeniler = $degerlendirici->calistir();

        foreach ($yeniler as $bildirim) {
            AlarmBildirimiGonder::dispatch($bildirim->id);
        }

        $yetimler = AlarmBildirimi::query()
            ->where('durum', AlarmBildirimi::BEKLIYOR)
            ->where('updated_at', '<', CarbonImmutable::now()->subMinutes(self::YETIM_DAKIKA))
            ->pluck('id');

        foreach ($yetimler as $id) {
            AlarmBildirimiGonder::dispatch($id);
        }

        $this->line(sprintf('Yeni bildirim: %d, yeniden kuyruğa: %d', count($yeniler), $yetimler->count()));

        return self::SUCCESS;
    }
}
