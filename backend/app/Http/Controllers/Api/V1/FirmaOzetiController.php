<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\KesintiEkstresiRequest;
use App\Http\Requests\Kesinti\KesintiRaporuRequest;
use App\Services\Kesinti\FirmaOzeti;
use App\Services\Kesinti\FirmaOzetiExcelAktarici;
use App\Services\Kesinti\KesintiEkstresi;
use App\Services\Kesinti\KesintiEkstresiPdf;
use App\Services\Kesinti\TumKesintilerSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Kesinti Yönetimi → Pivot (eski adı Firma Özeti; kullanıcı isteği 2026-11-16):
 * kesinti türlerinin kalanı yan yana — taşeron + proje, taşeron ve proje
 * başına üç sekme (Tüm Kesintiler'den; ERP okunmaz). Yalnız rapor;
 * `firma_ozeti.goruntule` izniyle korunur (izin anahtarı ve API adresi aynı
 * kaldı: rol atamaları bozulmasın).
 */
final class FirmaOzetiController extends Controller
{
    public function index(KesintiRaporuRequest $request, FirmaOzeti $ozet): JsonResponse
    {
        return response()->json(['data' => $ozet->hesapla($request->parametreler())]);
    }

    /** Ekrandaki üç sekme, sekme başına sayfa (tablo + toplam satırı) */
    public function excel(KesintiRaporuRequest $request, FirmaOzeti $ozet, FirmaOzetiExcelAktarici $aktarici): BinaryFileResponse
    {
        return response()
            ->download($aktarici->olustur($ozet->hesapla($request->parametreler())), 'pivot.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    public function secenekler(TumKesintilerSorgusu $kesintiler): JsonResponse
    {
        return response()->json(['data' => $kesintiler->raporSecenekleri()]);
    }

    /** Tıklanan hücrenin kesinti ekstresi (PDF, A3 yatay) */
    public function ekstre(KesintiEkstresiRequest $request, KesintiEkstresi $ekstre, KesintiEkstresiPdf $pdf): Response
    {
        $veri = $ekstre->veri($request->parametreler());
        $dosyaAdi = 'kesinti-ekstresi-'.Str::slug(implode(' ', array_filter([$veri['firma']['kod'], $veri['proje'], $veri['kesinti_turu']]))).'.pdf';

        return response($pdf->olustur($veri), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $dosyaAdi),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
