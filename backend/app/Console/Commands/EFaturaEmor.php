<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entegrator\EmorIslenmeServisi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * eMOR kolonunu tazeler: gelen faturalar aktif ERP ortamının TOHOM_FATURA
 * tablosunda işlenmiş mi. ERP'den yalnız SELECT yapılır.
 */
#[Signature('efatura:emor')]
#[Description('Gelen e-Faturaların ERP\'ye işlenip işlenmediğini (eMOR) tazeler')]
final class EFaturaEmor extends Command
{
    public function handle(EmorIslenmeServisi $servis): int
    {
        try {
            $sonuc = $servis->tazele();
        } catch (Throwable $hata) {
            // Bayraklar değişmedi; bir sonraki çalışma yeniden dener
            Log::warning('eMOR tazelenemedi: ERP okunamadı', ['hata' => $hata->getMessage()]);
            $this->error('ERP okunamadı; eMOR bayrakları değiştirilmedi.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'eMOR: işlendi %d, işlenmedi %d, değişen %d',
            $sonuc['islendi'],
            $sonuc['islenmedi'],
            $sonuc['degisen'],
        ));

        return self::SUCCESS;
    }
}
