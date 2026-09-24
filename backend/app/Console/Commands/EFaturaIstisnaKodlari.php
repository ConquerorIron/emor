<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\IzibizIstisnaKoduServisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Gelen faturaların vergi istisna kodunu önce ERP havuzundaki, yoksa İzibiz'deki
 * UBL'lerinden okur (IzibizIstisnaKoduServisi). Her çalışmada sınırlı sayıda toplu istek
 * (config/efatura.php); her fatura bir kez okunur.
 */
#[Signature('efatura:istisna-kodlari')]
#[Description('Gelen e-Faturaların vergi istisna kodunu ERP havuzundaki, yoksa İzibiz\'deki UBL\'den okur')]
final class EFaturaIstisnaKodlari extends Command
{
    public function handle(IzibizIstisnaKoduServisi $servis, EntegratorBaglantiServisi $baglantilar): int
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
                (int) config('efatura.istisna_parti_boyutu'),
                (int) config('efatura.istisna_azami_istek'),
            );
        } catch (EntegratorHatasi $hata) {
            Log::warning('İstisna kodları okunamadı', ['ortam' => $tanim->ortam, 'kod' => $hata->kod, 'saglayici_kodu' => $hata->saglayiciKodu]);
            $this->error("İzibiz okunamadı ({$hata->kod}); bir sonraki çalışmada yeniden denenecek.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'İstisna kodu [%s]: ERP arşivinden %d fatura (%d kodlu); İzibiz: %d istek, %d fatura okundu (%d kodlu), %d okunamadı',
            $tanim->ortam,
            $sonuc['erp_okunan'],
            $sonuc['erp_kodlu'],
            $sonuc['istek'],
            $sonuc['okunan'],
            $sonuc['kodlu'],
            $sonuc['hatali'],
        ));

        return self::SUCCESS;
    }
}
