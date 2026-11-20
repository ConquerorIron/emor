<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * e-Fatura ekranlarının ortak çıktıları: Excel ve fatura aslı (PDF / UBL XML)
 * yanıtı. Sağlayıcı ekranları (EFaturaController) ve Tüm Faturalar aynı akışı
 * kullanır (Fable incelemesi PRT-F9: kopyalar ayrı ayrı düzeltiliyordu).
 */
final class EFaturaCiktisi
{
    public function __construct(
        private readonly EFaturaSorgusu $sorgu,
        private readonly EFaturaExcelAktarici $aktarici,
    ) {}

    /**
     * Ekrandaki listenin .xlsx'i: aynı filtre ve sıralama; `page` doluysa
     * yalnız o sayfanın satırları, ekrandaki görünür kolonlar.
     *
     * @param  EntegratorBaglanti|list<EntegratorBaglanti>  $tanimlar
     * @param  string  $kapsam  sunucudaki aktif kapsam (`canli` ya da `izibiz:canli,protel:canli`)
     */
    public function excel(
        EFaturaListeRequest $request,
        EntegratorBaglanti|array $tanimlar,
        FaturaYonu $yon,
        string $kapsam,
        string $dosyaOneki,
        ?string $baslik = null,
    ): BinaryFileResponse|JsonResponse {
        // Ekrandaki liste başka kapsamın verisiyse (aktif ortam değişti, liste
        // henüz tazelenmedi) görülenden farklı faturalar inmesin (PRT-R3, F1)
        if ($request->filled('ortam') && $request->input('ortam') !== $kapsam) {
            return response()->json([
                'kod' => 'ENTEGRATOR_ORTAM_DEGISTI',
                'mesaj' => __('hata.entegrator_ortam_degisti'),
            ], 409);
        }

        $filtre = $request->filtre();
        $sirala = $request->input('sirala');
        $siraYonu = $request->input('yon');
        $sorgu = $this->sorgu->filtrele($tanimlar, $yon, $filtre);

        // Ekrandaki sayfa (kullanıcı isteği 2026-09-24): tablonun o an gösterdiği satırlar
        if ($request->filled('page')) {
            $idler = $this->sorgu->sirala($sorgu->clone(), $yon, $sirala, $siraYonu)
                ->forPage($request->integer('page'), $request->sayfaBoyutu())
                ->pluck('efatura_faturalari.id');
            $sorgu = EFatura::query()->whereKey($idler);
        }

        $adet = $sorgu->count();
        $sinir = (int) config('efatura.excel_azami_satir');

        if ($adet > $sinir) {
            return response()->json([
                'kod' => 'EFATURA_EXCEL_COK_BUYUK',
                'mesaj' => __('hata.efatura_excel_cok_buyuk', ['adet' => $adet, 'sinir' => $sinir]),
            ], 422);
        }

        $yol = $this->aktarici->olustur(
            $this->sorgu->sirala($sorgu->clone(), $yon, $sirala, $siraYonu),
            $yon,
            $tanimlar,
            $this->sorgu->ozet($sorgu),
            ['baslangic' => $filtre['baslangic'], 'bitis' => $filtre['bitis']],
            $request->excelKolonlari(),
            $baslik,
        );

        Log::info('e-Fatura Excel çıktısı alındı', [
            'kullanici_id' => $request->user()?->id,
            'yon' => $yon->value,
            'kapsam' => $kapsam,
            'adet' => $adet,
        ]);

        return response()
            ->download($yol, sprintf('%s-%s-%s.xlsx', $dosyaOneki, $filtre['baslangic'], $filtre['bitis']), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Fatura aslı yanıtı. PDF tarayıcıda açılır; XML her zaman ek (attachment)
     * olarak ve betik çalıştırmayan CSP ile döner. Bearer token ve entegratör
     * adresi tarayıcıya gitmez; yalnız gövde ve kaynağı (X-Belge-Kaynagi).
     *
     * @param  array{icerik: string, kaynak: 'erp'|'entegrator'}  $belge
     * @param  'pdf'|'xml'  $tur
     */
    public function belge(EFatura $fatura, array $belge, string $tur): Response
    {
        $basliklar = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Belge-Kaynagi' => $belge['kaynak'],
        ];
        // Dosya adında yalnız güvenli karakterler (başlık enjeksiyonu yok)
        $dosyaAdi = preg_replace('/[^A-Za-z0-9._-]/', '_', $fatura->belge_no).'.'.$tur;

        return response($belge['icerik'], 200, $tur === 'pdf' ? [
            ...$basliklar,
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$dosyaAdi.'"',
        ] : [
            ...$basliklar,
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$dosyaAdi.'"',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
