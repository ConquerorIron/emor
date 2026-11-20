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
use App\Services\Entegrator\FaturaBelgeServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tüm Faturalar (kullanıcı isteği 2026-11-20): İzibiz ve Protel'e GELEN
 * faturaların birleşik listesi — aynı VKN'nin iki posta kutusu. Kapsam her
 * sağlayıcının AKTİF tanımıdır (biri tanımsızsa yalnız diğeri); başka ortamın
 * faturası listelenmez, PDF'i açılmaz (404).
 *
 * Liste, filtre, sıralama ve Excel sağlayıcı ekranlarıyla aynı sorgudan
 * (EFaturaSorgusu). Senkron ve gizleme sağlayıcı ekranlarındadır.
 * Yetkiler rota middleware'inde (`can:tum_faturalar.*`).
 */
final class TumFaturalarController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    public function index(EFaturaListeRequest $request): JsonResponse
    {
        $tanimlar = $this->aktifTanimlar();
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanimlar, FaturaYonu::Gelen, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), FaturaYonu::Gelen, $request->input('sirala'), $request->input('yon'))
            ->with(['gizleyen:id,ad', 'entegratorBaglanti:id,saglayici'])
            ->paginate($request->sayfaBoyutu());

        return EFaturaResource::collection($sayfa)
            ->additional([
                'ozet' => $this->sorgu->ozet($sorgu),
                'secenekler' => $this->sorgu->secenekler($tanimlar, FaturaYonu::Gelen, $filtre['baslangic'], $filtre['bitis']),
                'kapsam' => ['ortam' => $this->kapsamAnahtari($tanimlar)],
            ])
            ->response();
    }

    public function excel(EFaturaListeRequest $request, EFaturaExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        $tanimlar = $this->aktifTanimlar();

        // Ekrandaki liste başka tanımların verisiyse (aktif ortam değişti, liste
        // henüz tazelenmedi) görülenden farklı faturalar inmesin (PRT-R3, F1)
        if ($request->filled('ortam') && $request->input('ortam') !== $this->kapsamAnahtari($tanimlar)) {
            return response()->json([
                'kod' => 'ENTEGRATOR_ORTAM_DEGISTI',
                'mesaj' => __('hata.entegrator_ortam_degisti'),
            ], 409);
        }
        $filtre = $request->filtre();
        $sorgu = $this->sorgu->filtrele($tanimlar, FaturaYonu::Gelen, $filtre);

        // Ekrandaki sayfa: tablonun o an gösterdiği satırlar
        if ($request->filled('page')) {
            $idler = $this->sorgu->sirala($sorgu->clone(), FaturaYonu::Gelen, $request->input('sirala'), $request->input('yon'))
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

        $yol = $aktarici->olustur(
            $this->sorgu->sirala($sorgu->clone(), FaturaYonu::Gelen, $request->input('sirala'), $request->input('yon')),
            FaturaYonu::Gelen,
            $tanimlar,
            $this->sorgu->ozet($sorgu),
            ['baslangic' => $filtre['baslangic'], 'bitis' => $filtre['bitis']],
            $request->excelKolonlari(),
            __('efatura.excel.baslik_tum'),
        );

        Log::info('Tüm Faturalar Excel çıktısı alındı', [
            'kullanici_id' => $request->user()?->id,
            'kapsam' => $this->kapsamAnahtari($tanimlar),
            'adet' => $adet,
        ]);

        return response()
            ->download($yol, sprintf('tum-faturalar-%s-%s.xlsx', $filtre['baslangic'], $filtre['bitis']), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    /** Faturanın kendi sağlayıcısından (İzibiz ya da Protel) — sağlayıcı ekranıyla aynı akış. */
    public function pdf(int $fatura, FaturaBelgeServisi $belgeler): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);
        ['icerik' => $pdf, 'kaynak' => $kaynak] = $belgeler->pdf($kayit->entegratorBaglanti, $kayit);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->dosyaAdi($kayit, 'pdf').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Belge-Kaynagi' => $kaynak,
        ]);
    }

    /** UBL XML — yalnız İzibiz faturasında (Protel'de 404, FaturaBelgeServisi). */
    public function xml(int $fatura, FaturaBelgeServisi $belgeler): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);
        ['icerik' => $xml, 'kaynak' => $kaynak] = $belgeler->xml($kayit->entegratorBaglanti, $kayit);

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->dosyaAdi($kayit, 'xml').'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'X-Belge-Kaynagi' => $kaynak,
        ]);
    }

    /**
     * Sağlayıcı başına aktif tanım; hiçbiri yoksa hata (boş liste gibi görünmez).
     *
     * @return list<EntegratorBaglanti>
     */
    private function aktifTanimlar(): array
    {
        $tanimlar = array_values(array_filter([
            $this->baglantilar->aktif(EntegratorBaglanti::SAGLAYICI_IZIBIZ),
            $this->baglantilar->aktif(EntegratorBaglanti::SAGLAYICI_PROTEL),
        ]));

        return $tanimlar !== [] ? $tanimlar : throw EntegratorHatasi::aktifYok();
    }

    /**
     * Ekrandaki listenin kapsamı (`izibiz:canli,protel:canli`): Excel isteği
     * bununla gelir, sunucuda değiştiyse reddedilir.
     *
     * @param  list<EntegratorBaglanti>  $tanimlar
     */
    private function kapsamAnahtari(array $tanimlar): string
    {
        return implode(',', array_map(fn (EntegratorBaglanti $t): string => $t->saglayici.':'.$t->ortam, $tanimlar));
    }

    /**
     * Rota model bağlama bilinçli kullanılmıyor: kayıt yetki denetiminden
     * SONRA ve aktif tanımlarla sınırlı aranır; başka ortamın faturası 404.
     */
    private function kapsamdakiFatura(int $fatura): EFatura
    {
        $idler = array_map(fn (EntegratorBaglanti $t): int => $t->id, $this->aktifTanimlar());

        return EFatura::query()
            ->with('entegratorBaglanti')
            ->whereIn('entegrator_baglanti_id', $idler)
            ->find($fatura) ?? throw new NotFoundHttpException;
    }

    /** Dosya adında yalnız güvenli karakterler (başlık enjeksiyonu yok). */
    private function dosyaAdi(EFatura $fatura, string $uzanti): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $fatura->belge_no).'.'.$uzanti;
    }
}
