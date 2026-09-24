<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\ErpBelgeArsivi;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Fatura aslı (PDF / UBL XML): önce ERP havuzundan (TOHOM_E_FATURA), orada
 * yoksa entegratörden anlık okunur (kullanıcı kararı 2026-09-24). Hiçbir
 * yerde saklanmaz. ERP'ye ulaşılamazsa ekran bozulmaz, entegratöre düşülür.
 *
 * Kaynak: 'erp' | 'entegrator' — yanıt başlığıyla ekrana taşınır.
 */
final class FaturaBelgeServisi
{
    private const XML_BILDIRIMI = '<?xml version="1.0" encoding="UTF-8"?>';

    public function __construct(
        private readonly ErpBelgeArsivi $erp,
        private readonly IzibizIstemcisi $izibiz,
    ) {}

    /**
     * @return array{icerik: string, kaynak: 'erp'|'entegrator'}
     */
    public function pdf(EntegratorBaglanti $tanim, EFatura $fatura): array
    {
        $erpdeki = $this->erpden($fatura, fn (string $ettn): ?string => $this->erp->pdf($ettn));

        if ($erpdeki !== null && str_starts_with($erpdeki, '%PDF-')) {
            return ['icerik' => $erpdeki, 'kaynak' => 'erp'];
        }

        $kutu = FaturaYonu::from($fatura->yon)->izibizKutusu();

        return [
            'icerik' => $this->izibiz->getPdf($tanim, "/v1/einvoices/{$kutu}/{$fatura->kaynak_id}/preview/pdf"),
            'kaynak' => 'entegrator',
        ];
    }

    /**
     * Entegratörde tek faturalık toplu UBL indirme kullanılır (okundu
     * işaretine dokunmaz — api-notlari.md).
     *
     * @return array{icerik: string, kaynak: 'erp'|'entegrator'}
     */
    public function xml(EntegratorBaglanti $tanim, EFatura $fatura): array
    {
        $erpdeki = $this->erpden($fatura, fn (string $ettn): ?string => $this->erp->xml($ettn));

        if ($erpdeki !== null && trim($erpdeki) !== '') {
            return ['icerik' => $this->bildirimli($erpdeki), 'kaynak' => 'erp'];
        }

        $zip = $this->izibiz->ublIndir($tanim, FaturaYonu::from($fatura->yon), [(int) $fatura->kaynak_id]);

        return ['icerik' => $this->bildirimli($this->zipIcindekiXml($zip)), 'kaynak' => 'entegrator'];
    }

    /**
     * @param  Closure(string): ?string  $oku
     */
    private function erpden(EFatura $fatura, Closure $oku): ?string
    {
        // ERP havuzu yalnız gelen faturaları tutar: giden için sorgu atılmaz
        if ($fatura->yon !== FaturaYonu::Gelen->value) {
            return null;
        }

        try {
            return $oku($fatura->ettn);
        } catch (Throwable $e) {
            Log::warning('ERP fatura arşivi okunamadı; entegratörden okunuyor', [
                'fatura_id' => $fatura->id,
                'hata' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** ERP'deki XML bildirimsiz saklanır; indirilen dosya UTF-8 olduğunu söylesin. */
    private function bildirimli(string $xml): string
    {
        $govde = str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;

        return str_starts_with(ltrim($govde), '<?xml') ? $govde : self::XML_BILDIRIMI."\n".$govde;
    }

    private function zipIcindekiXml(string $zipIcerigi): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'izibiz-ubl-');
        if ($yol === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        try {
            file_put_contents($yol, $zipIcerigi);
            $zip = new ZipArchive;
            if ($zip->open($yol) !== true) {
                throw EntegratorHatasi::yanitGecersiz();
            }

            $xml = $zip->numFiles > 0 ? (string) $zip->getFromIndex(0) : '';
            $zip->close();

            if (trim($xml) === '') {
                throw EntegratorHatasi::yanitGecersiz();
            }

            return $xml;
        } finally {
            @unlink($yol);
        }
    }
}
