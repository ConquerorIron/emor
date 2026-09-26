<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entegrator\EmorIslenmeServisi;
use App\Services\Entegrator\ErpIstisnaKoduAktarimi;
use App\Services\Entegrator\FaturaYonu;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * eMOR kolonunu tazeler: gelen ve giden e-faturaların aktif ERP ortamında
 * karşılığı var mı (EmorIslenmeServisi). Bir yön okunamazsa diğeri yine
 * tazelenir; komut başarısız döner. ERP'ye tek yazım: boş vergi istisna kodu
 * entegratördekiyle doldurulur (ErpIstisnaKoduAktarimi, kullanıcı kararı 2026-09-24).
 */
#[Signature('efatura:emor')]
#[Description('e-Faturaların ERP\'de karşılığı olup olmadığını (eMOR) tazeler')]
final class EFaturaEmor extends Command
{
    public function handle(EmorIslenmeServisi $servis, ErpIstisnaKoduAktarimi $aktarim): int
    {
        $basarisiz = false;
        $gelenOkundu = false;

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

            $gelenOkundu = $gelenOkundu || $yon === FaturaYonu::Gelen;
            $this->line(sprintf(
                'eMOR %s: işlendi %d, elle işlendi %d, havuzda %d, yok %d, değişen %d',
                $yon->value,
                $sonuc['islendi'],
                $sonuc['elle_islendi'],
                $sonuc['havuzda'],
                $sonuc['yok'],
                $sonuc['degisen'],
            ));
        }

        // ERP'de boş olan istisna kodu entegratördekiyle doldurulur — yalnız
        // ERP taze okunduysa (havuz bilgisi güncel) ve anahtar açıksa
        if ($gelenOkundu && config('efatura.erp_istisna_kodu_yaz')) {
            try {
                $aktarilan = $aktarim->aktar($servis->sonHavuzEttnleri() ?? []);
                $this->line(sprintf(
                    'İstisna kodu ERP\'ye: %d yazıldı, %d uzun olduğu için atlandı, %d yazılmadı',
                    $aktarilan['yazilan'],
                    $aktarilan['uzun'],
                    $aktarilan['yazilmayan'],
                ));
            } catch (Throwable $hata) {
                // Yetki/erişim sorunu eMOR'u etkilemez; bir sonraki çalışma yeniden dener
                Log::warning('İstisna kodu ERP\'ye yazılamadı', ['hata' => $hata->getMessage()]);
                $this->error('İstisna kodu ERP\'ye yazılamadı (yetki/erişim); eMOR etkilenmedi.');
                $basarisiz = true;
            }
        }

        return $basarisiz ? self::FAILURE : self::SUCCESS;
    }
}
