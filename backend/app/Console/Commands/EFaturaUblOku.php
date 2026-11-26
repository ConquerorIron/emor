<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\IzibizUblServisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Gelen faturaların UBL'ini entegratörden okuyup rapor verilerini (tutarlar,
 * kur, vergi dairesi, vergi kalemleri, istisna kodu) yazar (IzibizUblServisi).
 * Her çalışmada sınırlı sayıda toplu istek (config/efatura.php); her fatura bir kez okunur.
 */
#[Signature('efatura:ubl-oku')]
#[Description('Gelen e-Faturaların UBL\'ini entegratörden okuyup vergi kalemlerini ve tutarlarını yazar')]
final class EFaturaUblOku extends Command
{
    public function handle(IzibizUblServisi $servis, EntegratorBaglantiServisi $baglantilar): int
    {
        if (! config('entegrator.izibiz.senkron_aktif')) {
            $this->warn('e-Fatura senkronu kapalı (EFATURA_SENKRON_AKTIF=false).');

            return self::SUCCESS;
        }

        $tanim = $baglantilar->aktif();

        if ($tanim === null) {
            $this->warn('Aktif entegratör ortamı yok; atlandı.');

            return self::SUCCESS;
        }

        try {
            $sonuc = $servis->tazele(
                $tanim,
                (int) config('efatura.ubl_parti_boyutu'),
                (int) config('efatura.ubl_azami_istek'),
            );
        } catch (EntegratorHatasi $hata) {
            Log::warning('UBL verileri okunamadı', ['ortam' => $tanim->ortam, 'kod' => $hata->kod, 'saglayici_kodu' => $hata->saglayiciKodu]);
            $this->error("İzibiz okunamadı ({$hata->kod}); bir sonraki çalışmada yeniden denenecek.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'UBL [%s] İzibiz: %d istek, %d fatura okundu, %d okunamadı',
            $tanim->ortam,
            $sonuc['istek'],
            $sonuc['okunan'],
            $sonuc['hatali'],
        ));

        return self::SUCCESS;
    }
}
