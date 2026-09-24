<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entegrator\EmorIslenmeServisi;
use App\Services\Entegrator\FaturaYonu;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * eMOR kolonunu tazeler: gelen ve giden e-faturaların aktif ERP ortamında
 * karşılığı var mı (EmorIslenmeServisi). ERP'den yalnız okuma yapılır. Bir
 * yön okunamazsa diğeri yine tazelenir; komut başarısız döner.
 */
#[Signature('efatura:emor')]
#[Description('e-Faturaların ERP\'de karşılığı olup olmadığını (eMOR) tazeler')]
final class EFaturaEmor extends Command
{
    public function handle(EmorIslenmeServisi $servis): int
    {
        $basarisiz = false;

        foreach (FaturaYonu::cases() as $yon) {
            try {
                $sonuc = $servis->tazele($yon);
            } catch (Throwable $hata) {
                // Bayraklar değişmedi; bir sonraki çalışma yeniden dener
                Log::warning('eMOR tazelenemedi: ERP okunamadı', ['yon' => $yon->value, 'hata' => $hata->getMessage()]);
                $this->error("{$yon->value}: ERP okunamadı; eMOR bayrakları değiştirilmedi.");
                $basarisiz = true;

                continue;
            }

            $this->line(sprintf(
                'eMOR %s: işlendi %d, havuzda %d, yok %d, değişen %d',
                $yon->value,
                $sonuc['islendi'],
                $sonuc['havuzda'],
                $sonuc['yok'],
                $sonuc['degisen'],
            ));
        }

        return $basarisiz ? self::FAILURE : self::SUCCESS;
    }
}
