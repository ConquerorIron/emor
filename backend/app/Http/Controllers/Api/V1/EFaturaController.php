<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaGizlemeRequest;
use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Http\Requests\EFatura\ProtelIslendiRequest;
use App\Http\Resources\EFaturaResource;
use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaCiktisi;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaBelgeServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * e-Fatura listeleri, Excel çıktısı, PDF görüntüleme ve UBL XML indirme (EFAT-11).
 *
 * Kapsam her zaman rotanın sağlayıcısının (İzibiz; Protel rotalarında Protel —
 * PRT-12) AKTİF tanımıdır: başka sağlayıcının ya da ortamın faturası
 * listelenmez ve PDF'i açılmaz (404 — varlığı da açık edilmez).
 * Yetkiler rota middleware'inde (`can:efatura.*`, `can:protel_faturalari.*`).
 * XML ve gizleme yalnız İzibiz, "İşlendi" işareti yalnız Protel rotalarındadır.
 */
final class EFaturaController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    public function index(EFaturaListeRequest $request, string $yon): JsonResponse
    {
        $tanim = $this->aktifTanim($request);
        $faturaYonu = FaturaYonu::from($yon);
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanim, $faturaYonu, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), $faturaYonu, $request->input('sirala'), $request->input('yon'))
            ->with('gizleyen:id,ad')
            ->paginate($request->sayfaBoyutu());
        // Protel Gelen Faturalar'da "eMOR VKN Var" kolonu (ERP'deki kurum kartları)
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, $tanim->saglayici === EntegratorBaglanti::SAGLAYICI_PROTEL);

        return EFaturaResource::collection($sayfa)
            ->additional([
                'ozet' => $this->sorgu->ozet($sorgu),
                'secenekler' => $this->sorgu->secenekler($tanim, $faturaYonu, $filtre['baslangic'], $filtre['bitis']),
                'kapsam' => ['ortam' => $tanim->ortam],
            ])
            ->response();
    }

    public function excel(EFaturaListeRequest $request, string $yon, EFaturaCiktisi $cikti): BinaryFileResponse|JsonResponse
    {
        $tanim = $this->aktifTanim($request);
        $onEk = $tanim->saglayici === EntegratorBaglanti::SAGLAYICI_PROTEL ? 'protel' : 'efatura';

        return $cikti->excel($request, $tanim, FaturaYonu::from($yon), $tanim->ortam, "{$onEk}-{$yon}");
    }

    /**
     * Fatura aslı önce ERP havuzundan, yoksa entegratörden anlık okunur
     * (saklanmaz; Protel'de yalnız entegratörden — FaturaBelgeServisi).
     */
    public function pdf(Request $request, int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $tanim = $this->aktifTanim($request);
        $kayit = $this->aktifHesabinFaturasi($tanim, $fatura);

        return $cikti->belge($kayit, $belgeler->pdf($tanim, $kayit), 'pdf');
    }

    /** UBL XML — PDF ile aynı kaynak sırası (Protel'de yok). */
    public function xml(Request $request, int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $tanim = $this->aktifTanim($request);
        $kayit = $this->aktifHesabinFaturasi($tanim, $fatura);

        return $cikti->belge($kayit, $belgeler->xml($tanim, $kayit), 'xml');
    }

    /**
     * Faturayı listede gizler ya da listeye geri alır (kullanıcı isteği
     * 2026-09-24): aynı VKN'yi paylaşan şirketlerden birine kesilip bizim posta
     * kutumuza düşen fatura silinmez. Zaten gizliyse ilk gizleme bilgisi korunur.
     */
    public function gizle(EFaturaGizlemeRequest $request, int $fatura): EFaturaResource
    {
        $tanim = $this->aktifTanim($request);
        $kayit = $this->aktifHesabinFaturasi($tanim, $fatura);
        // Ekran satırı bu yanıtla yerinde günceller: liste yanıtıyla aynı alanlar
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, $tanim->saglayici === EntegratorBaglanti::SAGLAYICI_PROTEL);

        // Yanlış posta kutusu yalnız gelen faturada olur; giden gizlenmez
        if ($kayit->yon !== FaturaYonu::Gelen->value) {
            throw new NotFoundHttpException;
        }

        $gizli = $request->boolean('gizli');
        if (! $gizli) {
            $kayit->update(['gizlenme_zamani' => null, 'gizleyen_id' => null]);
        } elseif ($kayit->gizlenme_zamani === null) {
            $kayit->update(['gizlenme_zamani' => now(), 'gizleyen_id' => $request->user()?->id]);
        }

        // Liste, özet, Excel ve alarmı etkiler: kim ne zaman yaptı izi kalır
        Log::channel('denetim')->info($gizli ? 'e-Fatura gizlendi' : 'e-Fatura listeye geri alındı', [
            'kullanici_id' => $request->user()?->id,
            'fatura_id' => $kayit->id,
            'belge_no' => $kayit->belge_no,
        ]);

        return new EFaturaResource($kayit->load('gizleyen:id,ad'));
    }

    /**
     * Protel faturasını "İşlendi" işaretler ya da işareti kaldırır (kullanıcı
     * isteği 2026-11-23). Yalnız eMOR'da tutulur: `sap_islendi` true, kaldırılınca
     * NULL (işaretlenmemiş). Tüm Faturalar'ın SAP kolonunda görünür. Protel'e
     * istek gitmez; senkron bu kolona yazmaz. Portalda reddedilmiş fatura
     * işaretlenemez (409 PROTEL_FATURA_REDDEDILDI; ekranda "Red" yazar).
     */
    public function islendi(ProtelIslendiRequest $request, int $fatura): EFaturaResource
    {
        $kayit = $this->aktifHesabinFaturasi($this->aktifTanim($request), $fatura);
        // Ekran satırı bu yanıtla yerinde günceller: liste yanıtıyla aynı alanlar
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, true);
        $islendi = $request->boolean('islendi');

        if ($kayit->protelReddedildi()) {
            throw new HttpResponseException(response()->json([
                'kod' => 'PROTEL_FATURA_REDDEDILDI',
                'mesaj' => __('hata.protel_fatura_reddedildi'),
            ], 409));
        }

        $kayit->update(['sap_islendi' => $islendi ?: null]);

        Log::channel('denetim')->info($islendi ? 'Protel faturası işlendi işaretlendi' : 'Protel faturasının işlendi işareti kaldırıldı', [
            'kullanici_id' => $request->user()?->id,
            'fatura_id' => $kayit->id,
            'belge_no' => $kayit->belge_no,
        ]);

        return new EFaturaResource($kayit->load('gizleyen:id,ad'));
    }

    /**
     * Rota model bağlama bilinçli kullanılmıyor: kayıt yetki denetiminden
     * SONRA ve aktif hesapla sınırlı aranır; başka ortamın faturası 404.
     */
    private function aktifHesabinFaturasi(EntegratorBaglanti $tanim, int $fatura): EFatura
    {
        return EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->find($fatura) ?? throw new NotFoundHttpException;
    }

    private function aktifTanim(Request $request): EntegratorBaglanti
    {
        return $this->baglantilar->aktif(EntegratorBaglantiServisi::rotaninSaglayicisi($request))
            ?? throw EntegratorHatasi::aktifYok();
    }
}
