<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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
 * Hata: yalnız o faturaya özgü 4xx fatura "okunamadı" işaretlenir (bir gün sonra
 * yeniden, AZAMI_HATA denemeden sonra bırakılır); erişim, kimlik ve 5xx hataları
 * bu çalışmayı durdurur — her faturada yeniden denenmez (D8).
 */
final class ProtelUblServisi
{
    private const AZAMI_HATA = 3;

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
        $sonuc = ['istek' => 0, 'okunan' => 0, 'hatali' => 0];

        $adaylar = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('ubl_okundu')
            ->where('ubl_hata', '<', self::AZAMI_HATA)
            ->where(fn ($q) => $q->whereNull('ubl_son_deneme')
                ->orWhere('ubl_son_deneme', '<', CarbonImmutable::now()->subDay()))
            ->orderByDesc('id')
            ->limit(max(1, $azamiIstek))
            ->get(['id', 'ettn']);

        foreach ($adaylar as $fatura) {
            $sonuc['istek']++;

            try {
                $yanit = $this->istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_PDF, [
                    'uuidType' => 'DocumentUUID',
                    'uuid' => $fatura->ettn,
                    'documentDirection' => 'Incoming',
                    'outputType' => 'Ubl',
                ]);
            } catch (EntegratorHatasi $hata) {
                $faturayaOzgu = $hata->kod === EntegratorHatasi::HATA
                    && $hata->saglayiciHttpDurumu !== null
                    && $hata->saglayiciHttpDurumu >= 400 && $hata->saglayiciHttpDurumu < 500;
                if (! $faturayaOzgu) {
                    throw $hata;
                }

                $this->kayit->hataliIsaretle([$fatura->id]);
                $sonuc['hatali']++;

                continue;
            }

            $veri = $this->ayikla($yanit);

            // Okunamayan ya da başka faturanın XML'i yazılmaz
            if ($veri === null || $veri->ettn !== mb_strtolower((string) $fatura->ettn)) {
                Log::warning('Protel UBL okunamadı', ['fatura_id' => $fatura->id]);
                $this->kayit->hataliIsaretle([$fatura->id]);
                $sonuc['hatali']++;

                continue;
            }

            $this->kayit->yaz($fatura->id, $veri);
            $sonuc['okunan']++;
        }

        return $sonuc;
    }

    /**
     * Yanıtın `outputDatas[0]`'ı base64 UBL (ölçüm: zip değil, düz XML).
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

        // UTF-8 BOM'u DOMDocument'e takılmasın
        return $this->okuyucu->oku(str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml);
    }
}
