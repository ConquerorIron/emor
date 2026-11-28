<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gelen Protel faturalarının UBL'ini okur, rapor verilerini İzibiz'le aynı
 * tablolara yazar (UblOkuyucu + EFaturaUblKaydi). XML saklanmaz.
 *
 * İstek YALNIZ ProtelIstemcisi'nden: onaylı PDF çağrısı (`GetInvoicePdf`, aynı
 * dört alan), çıktı türü `Ubl` (kullanıcı onayı 2026-11-26; önce/sonra ölçümünde
 * değişen alan 0 — docs/protel/api-notlari.md). Protel'de toplu indirme yok:
 * fatura başına bir istek, çalışma başına `azamiIstek` (zamana yayılır); her
 * fatura bir kez okunur.
 *
 * Hata: yalnız FATURAYA_OZGU_DURUMLAR'daki yanıt ve okunamayan belge faturayı
 * "okunamadı" sayar; giriş, kimlik, erişim, 5xx ve başka her 4xx turu durdurur —
 * her faturada yeniden denenmez (D8). Yeniden deneme ve tur kesme kararları
 * UblOkumaTuru / EFaturaUblKaydi'de (iki sağlayıcıda ortak).
 */
final class ProtelUblServisi
{
    /**
     * Faturaya özgü sayılan HTTP durumları — izin listesi (C-2). Belgede
     * (docs/protel/api-notlari.md) bilinen yalnız 404; Protel'in başka bir
     * durumu faturaya özgü döndürdüğü ölçülürse buraya eklenir.
     */
    private const FATURAYA_OZGU_DURUMLAR = [404];

    public function __construct(
        private readonly ProtelIstemcisi $istemci,
        private readonly UblOkuyucu $okuyucu,
        private readonly EFaturaUblKaydi $kayit,
    ) {}

    /**
     * @return array{istek: int, okunan: int, hatali: int}
     */
    public function tazele(EntegratorBaglanti $tanim, int $azamiIstek): array
    {
        $tur = new UblOkumaTuru($this->kayit);
        $adaylar = $this->kayit->adaylar($tanim, max(1, $azamiIstek))->get(['id', 'ettn']);

        if ($adaylar->isEmpty()) {
            return $tur->bitir();
        }

        // Giriş hatası döngüden önce: hiçbir fatura işaretlenmeden tur kesilir (C-3)
        $this->istemci->token($tanim);

        foreach ($adaylar as $fatura) {
            $tur->istekYapildi();

            try {
                $yanit = $this->istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_PDF, [
                    'uuidType' => 'DocumentUUID',
                    'uuid' => $fatura->ettn,
                    'documentDirection' => 'Incoming',
                    'outputType' => 'Ubl',
                ]);
            } catch (EntegratorHatasi $hata) {
                if (! $this->faturayaOzguMu($hata)) {
                    $tur->durdur($hata, [$fatura->id]);
                }

                Log::warning('Protel UBL okunamadı', ['fatura_id' => $fatura->id, 'saglayici_http_durumu' => $hata->saglayiciHttpDurumu]);
                $tur->okunamadi([$fatura->id]);

                continue;
            }

            $veri = $this->ayikla($yanit);

            // Okunamayan ya da başka faturanın XML'i yazılmaz
            if ($veri === null || $veri->ettn !== mb_strtolower((string) $fatura->ettn)) {
                Log::warning('Protel UBL okunamadı', ['fatura_id' => $fatura->id]);
                $tur->okunamadi([$fatura->id]);

                continue;
            }

            $tur->okundu($fatura->id, $veri);
        }

        return $tur->bitir();
    }

    /**
     * Yanıtın `outputDatas[0]`'ı base64 UBL (ölçüm: zip değil, düz XML; BOM'lu
     * olabilir — libxml BOM'u kendisi tanır). Okunamayan belge null; ayrıştırma
     * hatası (zehirli belge) turu düşürmez (C-4).
     *
     * @param  array<string, mixed>  $yanit
     */
    private function ayikla(array $yanit): ?UblVerisi
    {
        $veriler = $yanit['outputDatas'] ?? null;
        $kodlu = is_array($veriler) && array_is_list($veriler) ? ($veriler[0] ?? null) : null;
        $xml = is_string($kodlu) ? base64_decode($kodlu, true) : false;

        if (! is_string($xml)) {
            return null;
        }

        try {
            return $this->okuyucu->oku($xml);
        } catch (Throwable) {
            return null;
        }
    }

    /** Yalnız izinli durum ve yalnız fatura ucunun yanıtı; giriş adımının hatası değil. */
    private function faturayaOzguMu(EntegratorHatasi $hata): bool
    {
        return ! $hata->girisHatasi
            && $hata->kod === EntegratorHatasi::HATA
            && in_array($hata->saglayiciHttpDurumu, self::FATURAYA_OZGU_DURUMLAR, true);
    }
}
