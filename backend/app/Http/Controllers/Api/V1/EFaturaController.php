<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaGizlemeRequest;
use App\Http\Requests\EFatura\EFaturaIslendiRequest;
use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Http\Requests\EFatura\EFaturaRedRequest;
use App\Http\Resources\EFaturaIslemiResource;
use App\Http\Resources\EFaturaResource;
use App\Models\EFatura;
use App\Models\EFaturaIslemi;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\EFaturaCiktisi;
use App\Services\Entegrator\EFaturaElleIslemleri;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaBelgeServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tek kaynaklı e-Fatura ekranlarının uçları: listeler, Excel çıktısı, PDF
 * görüntüleme, UBL XML indirme (EFAT-11) ve elle işlemler.
 *
 * Kapsam her zaman rotanın sağlayıcısının AKTİF tanımıdır: İzibiz; Protel
 * rotalarında Protel (PRT-12); Logo / e-Arşiv rotalarında statik tanım (EK-07,
 * EK-08). Başka sağlayıcının ya da ortamın faturası listelenmez, açılmaz (404 —
 * varlığı da açık edilmez). Yetkiler rota middleware'inde (`can:efatura.*`,
 * `can:protel_faturalari.*`, `can:logo_faturalari.*`, `can:earsiv_faturalari.*`).
 * XML ve gizleme yalnız İzibiz, "İşlendi" işareti Protel ve statik kaynak
 * rotalarındadır; RED bütün gelen fatura rotalarında.
 */
final class EFaturaController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaSorgusu $sorgu,
        private readonly EFaturaElleIslemleri $islemler,
    ) {}

    public function index(EFaturaListeRequest $request, string $yon): JsonResponse
    {
        $tanim = $this->aktifTanim($request);
        $faturaYonu = FaturaYonu::from($yon);
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanim, $faturaYonu, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), $faturaYonu, $request->input('sirala'), $request->input('yon'))
            ->with(EFaturaResource::ILISKILER)
            ->paginate($request->sayfaBoyutu());
        $this->emorKurumuHesaplansin($tanim);

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
        $onEk = $tanim->saglayici === EntegratorBaglanti::SAGLAYICI_IZIBIZ ? 'efatura' : $tanim->saglayici;

        return $cikti->excel($request, $tanim, FaturaYonu::from($yon), $tanim->ortam, "{$onEk}-{$yon}");
    }

    /**
     * Fatura aslı önce ERP havuzundan, yoksa entegratörden anlık okunur
     * (saklanmaz; Protel'de yalnız entegratörden, statik kaynakta yalnız
     * ERP'den — FaturaBelgeServisi).
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
        $kayit = $this->gelenFatura($tanim, $fatura);

        return $this->yanit($tanim, $this->islemler->gizlemeDegistir($kayit, $request->boolean('gizli'), $this->kullanici($request)));
    }

    /**
     * "SAP (Manuel İşle)": faturayı işlendi işaretler ya da işareti kaldırır
     * (Protel: 2026-11-23; statik kaynaklar: EK-07; İzibiz: 2026-11-26). Yalnız gelen fatura. Yalnız eMOR'da tutulur:
     * `sap_islendi` true, kaldırılınca NULL. Dış sisteme istek gitmez. RED
     * faturası işaretlenemez (409 FATURA_REDDEDILDI; ekranda "Red" yazar).
     */
    public function islendi(EFaturaIslendiRequest $request, int $fatura): EFaturaResource
    {
        $tanim = $this->aktifTanim($request);
        $kayit = $this->gelenFatura($tanim, $fatura);

        return $this->yanit($tanim, $this->islemler->islendiDegistir($kayit, $request->boolean('islendi'), $this->kullanici($request)));
    }

    /** Yetkili kullanıcının gelen faturayı RED'e çekmesi / RED'i geri alması (EK-04). */
    public function red(EFaturaRedRequest $request, int $fatura): EFaturaResource
    {
        $tanim = $this->aktifTanim($request);
        $kayit = $this->gelenFatura($tanim, $fatura);

        return $this->yanit($tanim, $this->islemler->redDegistir($kayit, $request->boolean('red'), $request->neden(), $this->kullanici($request)));
    }

    /** Satırdaki "Geçmiş" penceresi (EK-03): faturanın elle işlemleri, en yeni üstte. */
    public function gecmis(Request $request, int $fatura): AnonymousResourceCollection
    {
        $kayit = $this->aktifHesabinFaturasi($this->aktifTanim($request), $fatura);

        return EFaturaIslemiResource::collection(
            EFaturaIslemi::query()->where('fatura_id', $kayit->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get(),
        );
    }

    private function yanit(EntegratorBaglanti $tanim, EFatura $kayit): EFaturaResource
    {
        // Ekran satırı bu yanıtla yerinde günceller: liste yanıtıyla aynı alanlar
        $this->emorKurumuHesaplansin($tanim);

        return new EFaturaResource($kayit->load(EFaturaResource::ILISKILER));
    }

    /** "eMOR VKN Var" kolonu: Protel ve statik kaynak ekranları (ERP'deki kurum kartları) */
    private function emorKurumuHesaplansin(EntegratorBaglanti $tanim): void
    {
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, $tanim->saglayici !== EntegratorBaglanti::SAGLAYICI_IZIBIZ);
    }

    /** Gizleme ve RED yalnız gelen faturada (yanlış posta kutusu, karşı tarafın iptali) */
    private function gelenFatura(EntegratorBaglanti $tanim, int $fatura): EFatura
    {
        $kayit = $this->aktifHesabinFaturasi($tanim, $fatura);

        return $kayit->yon === FaturaYonu::Gelen->value ? $kayit : throw new NotFoundHttpException;
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

    private function kullanici(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
