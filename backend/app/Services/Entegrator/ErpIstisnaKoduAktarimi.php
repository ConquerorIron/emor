<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Services\ErpIstisnaKoduYazici;
use Illuminate\Support\Facades\Log;

/**
 * Entegratördeki (UBL) vergi istisna kodu ERP'de boşsa ERP'ye yazılır
 * (kullanıcı kararı 2026-09-24: entegratördeki bilgi esas; ERP'deki bazen eksik).
 *
 * Aday: gelen fatura, entegratörde kod var, ERP'deki kodu boş ve fatura ERP
 * havuzunda (TOHOM_E_FATURA'da satırı var — eMOR havuzda/işlendi). Birden çok
 * kod virgülle olduğu gibi yazılır (kullanıcı kararı; alan nchar(50) — daha uzunu
 * atlanır). Dolu ERP koduna asla dokunulmaz (koşul UPDATE'in içinde). Her yazım loglanır.
 */
final class ErpIstisnaKoduAktarimi
{
    private const ERP_KOD_UZUNLUGU = 50;

    public function __construct(
        private readonly ErpIstisnaKoduYazici $yazici,
    ) {}

    /**
     * @return array{yazilan: int, uzun: int, yazilmayan: int}
     */
    public function aktar(): array
    {
        $sonuc = ['yazilan' => 0, 'uzun' => 0, 'yazilmayan' => 0];

        $adaylar = EFatura::query()
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNotNull('izibiz_istisna_kodu')
            ->whereNull('vergi_istisna_kodu')
            ->whereIn('emor_durumu', [EmorDurumu::Havuzda->value, EmorDurumu::Islendi->value, EmorDurumu::ElleIslendi->value])
            ->get(['id', 'belge_no', 'ettn', 'izibiz_istisna_kodu']);

        foreach ($adaylar as $fatura) {
            $kod = trim((string) $fatura->getAttribute('izibiz_istisna_kodu'));

            // ERP alanı nchar(50): sığmayan kod kesilerek yazılmaz
            if (mb_strlen($kod) > self::ERP_KOD_UZUNLUGU) {
                $sonuc['uzun']++;

                continue;
            }

            if (! $this->yazici->yaz($fatura->ettn, $kod)) {
                // Satır yok ya da ERP'de bu arada kod dolmuş: bir sonraki ERP okuması yansıtır
                $sonuc['yazilmayan']++;

                continue;
            }

            // ERP'deki kod artık bu; bir sonraki okumayı beklemeden yansıt
            EFatura::query()->whereKey($fatura->id)->update(['vergi_istisna_kodu' => $kod]);
            $sonuc['yazilan']++;

            // Denetim kanalı: üretimdeki LOG_LEVEL=warning bu kaydı yutmasın
            Log::channel('denetim')->info('Vergi istisna kodu ERP\'ye yazıldı', [
                'belge_no' => $fatura->belge_no,
                'ettn' => $fatura->ettn,
                'kod' => $kod,
            ]);
        }

        return $sonuc;
    }
}
