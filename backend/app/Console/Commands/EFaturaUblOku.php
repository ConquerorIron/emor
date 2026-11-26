<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\IzibizUblServisi;
use App\Services\Entegrator\ProtelUblServisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Gelen faturaların UBL'ini entegratörden okuyup rapor verilerini (tutarlar,
 * kur, vergi dairesi, vergi kalemleri, istisna kodu) yazar: İzibiz toplu
 * indirmeyle (IzibizUblServisi), Protel fatura başına (ProtelUblServisi).
 * Her çalışmada sınırlı sayıda istek (config/efatura.php); her fatura bir kez okunur.
 */
#[Signature('efatura:ubl-oku {--saglayici=izibiz : izibiz | protel}')]
#[Description('Gelen e-Faturaların UBL\'ini entegratörden okuyup vergi kalemlerini ve tutarlarını yazar')]
final class EFaturaUblOku extends Command
{
    public function handle(IzibizUblServisi $izibiz, ProtelUblServisi $protel, EntegratorBaglantiServisi $baglantilar): int
    {
        $saglayici = (string) $this->option('saglayici');
        if (! in_array($saglayici, [EntegratorBaglanti::SAGLAYICI_IZIBIZ, EntegratorBaglanti::SAGLAYICI_PROTEL], true)) {
            $this->error('--saglayici şunlardan biri olmalı: izibiz, protel');

            return self::INVALID;
        }
        $protelMi = $saglayici === EntegratorBaglanti::SAGLAYICI_PROTEL;

        if (! config($protelMi ? 'entegrator.protel.aktif' : 'entegrator.izibiz.senkron_aktif')) {
            $this->warn($protelMi ? 'Protel modülü kapalı (PROTEL_AKTIF=false).' : 'e-Fatura senkronu kapalı (EFATURA_SENKRON_AKTIF=false).');

            return self::SUCCESS;
        }

        $tanim = $baglantilar->aktif($saglayici);

        if ($tanim === null) {
            $this->warn('Aktif entegratör ortamı yok; atlandı.');

            return self::SUCCESS;
        }

        try {
            $sonuc = $protelMi
                ? $protel->tazele($tanim, (int) config('efatura.protel_ubl_azami_istek'))
                : $izibiz->tazele($tanim, (int) config('efatura.ubl_parti_boyutu'), (int) config('efatura.ubl_azami_istek'));
        } catch (EntegratorHatasi $hata) {
            Log::warning('UBL verileri okunamadı', ['saglayici' => $saglayici, 'ortam' => $tanim->ortam, 'kod' => $hata->kod, 'saglayici_kodu' => $hata->saglayiciKodu]);
            $this->error(($protelMi ? 'Protel' : 'İzibiz')." okunamadı ({$hata->kod}); bir sonraki çalışmada yeniden denenecek.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'UBL [%s] %s: %d istek, %d fatura okundu, %d okunamadı',
            $tanim->ortam,
            $protelMi ? 'Protel' : 'İzibiz',
            $sonuc['istek'],
            $sonuc['okunan'],
            $sonuc['hatali'],
        ));

        return self::SUCCESS;
    }
}
