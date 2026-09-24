<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Http\Resources\EFaturaResource;
use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaExcelAktarici;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizIstemcisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * e-Fatura listeleri, Excel çıktısı ve PDF görüntüleme (EFAT-11).
 *
 * Kapsam her zaman AKTİF entegratör tanımıdır: başka ortamın faturası
 * listelenmez ve PDF'i açılmaz (404 — varlığı da açık edilmez).
 * Yetkiler rota middleware'inde (`can:efatura.*`).
 */
final class EFaturaController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    public function index(EFaturaListeRequest $request, string $yon): JsonResponse
    {
        $tanim = $this->aktifTanim();
        $faturaYonu = FaturaYonu::from($yon);
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanim, $faturaYonu, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), $faturaYonu, $request->input('sirala'), $request->input('yon'))
            ->paginate($request->sayfaBoyutu());

        return EFaturaResource::collection($sayfa)
            ->additional([
                'ozet' => $this->sorgu->ozet($sorgu),
                'secenekler' => $this->sorgu->secenekler($tanim, $faturaYonu, $filtre['baslangic'], $filtre['bitis']),
                'kapsam' => ['ortam' => $tanim->ortam],
            ])
            ->response();
    }

    public function excel(EFaturaListeRequest $request, string $yon, EFaturaExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        $tanim = $this->aktifTanim();
        $faturaYonu = FaturaYonu::from($yon);
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanim, $faturaYonu, $filtre);
        $adet = $sorgu->count();
        $sinir = (int) config('efatura.excel_azami_satir');

        if ($adet > $sinir) {
            return response()->json([
                'kod' => 'EFATURA_EXCEL_COK_BUYUK',
                'mesaj' => __('hata.efatura_excel_cok_buyuk', ['adet' => $adet, 'sinir' => $sinir]),
            ], 422);
        }

        // Yalnız bu istek için (bkz. config/efatura.php ölçümü)
        $this->bellekSiniriniYukselt((string) config('efatura.excel_bellek_siniri'));

        $yol = $aktarici->olustur(
            $this->sorgu->sirala($sorgu->clone(), $faturaYonu, $request->input('sirala'), $request->input('yon')),
            $faturaYonu,
            $tanim,
            $this->sorgu->ozet($sorgu),
            ['baslangic' => $filtre['baslangic'], 'bitis' => $filtre['bitis']],
        );

        Log::info('e-Fatura Excel çıktısı alındı', [
            'kullanici_id' => $request->user()?->id,
            'yon' => $faturaYonu->value,
            'ortam' => $tanim->ortam,
            'adet' => $adet,
        ]);

        $ad = sprintf('efatura-%s-%s-%s.xlsx', $faturaYonu->value, $filtre['baslangic'], $filtre['bitis']);

        return response()
            ->download($yol, $ad, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Fatura aslı İzibiz'den anlık okunur (saklanmaz). Bearer token ve İzibiz
     * adresi tarayıcıya gitmez; yalnız PDF gövdesi döner.
     */
    public function pdf(int $fatura, IzibizIstemcisi $istemci): Response
    {
        $tanim = $this->aktifTanim();

        // Rota model bağlama bilinçli kullanılmıyor: kayıt yetki denetiminden
        // SONRA ve aktif hesapla sınırlı aranır; başka ortamın faturası 404
        $fatura = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->find($fatura) ?? throw new NotFoundHttpException;

        $kutu = FaturaYonu::from($fatura->yon)->izibizKutusu();
        $pdf = $istemci->getPdf($tanim, "/v1/einvoices/{$kutu}/{$fatura->kaynak_id}/preview/pdf");

        // Dosya adında yalnız güvenli karakterler (başlık enjeksiyonu yok)
        $ad = preg_replace('/[^A-Za-z0-9._-]/', '_', $fatura->belge_no).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$ad.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Sınır yalnız yükseltilir: sunucu daha yüksek/sınırsız verdiyse düşürülmez. */
    private function bellekSiniriniYukselt(string $hedef): void
    {
        $mevcut = (string) ini_get('memory_limit');

        if ($mevcut !== '-1' && $this->bayt($mevcut) < $this->bayt($hedef)) {
            ini_set('memory_limit', $hedef);
        }
    }

    /** php.ini kısaltmalı boyutu (512M, 1G) bayta çevirir. */
    private function bayt(string $deger): int
    {
        $deger = trim($deger);
        $sayi = (int) $deger;

        return match (strtoupper(substr($deger, -1))) {
            'G' => $sayi * 1024 ** 3,
            'M' => $sayi * 1024 ** 2,
            'K' => $sayi * 1024,
            default => $sayi,
        };
    }

    private function aktifTanim(): EntegratorBaglanti
    {
        return $this->baglantilar->aktif() ?? throw EntegratorHatasi::aktifYok();
    }
}
