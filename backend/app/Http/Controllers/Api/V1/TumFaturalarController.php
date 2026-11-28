<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EFaturaElleIslemleri;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaBelgeServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tüm Faturalar (kullanıcı isteği 2026-11-20): bütün kaynaklara GELEN
 * faturaların birleşik listesi — İzibiz ve Protel (aynı VKN'nin iki posta
 * kutusu) ile statik kaynaklar Logo ve e-Arşiv / e-SMM (EK-07, EK-08). Kapsam
 * entegratörlerde sağlayıcının AKTİF tanımıdır (biri tanımsızsa yalnız diğeri),
 * statik kaynaklarda her zaman statik tanım (`gelenTanimlari`); başka ortamın
 * ya da giden yönün faturası listelenmez, açılmaz (404).
 *
 * Liste, filtre, sıralama ve Excel sağlayıcı ekranlarıyla aynı sorgudan
 * (EFaturaSorgusu). RED ve "SAP (Manuel İşle)" bu ekranda da (kendi
 * izinleriyle); senkron ve gizleme sağlayıcı ekranlarındadır.
 * Yetkiler rota middleware'inde (`can:tum_faturalar.*`).
 */
final class TumFaturalarController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    public function index(EFaturaListeRequest $request, EFaturaDurumServisi $durum): JsonResponse
    {
        $tanimlar = $this->aktifTanimlar();
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanimlar, FaturaYonu::Gelen, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), FaturaYonu::Gelen, $request->input('sirala'), $request->input('yon'))
            ->with([...EFaturaResource::ILISKILER, 'entegratorBaglanti:id,saglayici'])
            ->paginate($request->sayfaBoyutu());
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, true);

        return EFaturaResource::collection($sayfa)
            ->additional([
                'ozet' => $this->sorgu->ozet($sorgu),
                'secenekler' => $this->sorgu->secenekler($tanimlar, FaturaYonu::Gelen, $filtre['baslangic'], $filtre['bitis']),
                'kapsam' => ['ortam' => $this->kapsamAnahtari($tanimlar)],
                'tazelik' => $this->tazelik($tanimlar, $durum),
            ])
            ->response();
    }

    /**
     * Sağlayıcı başına verinin güncelliği (Fable incelemesi PRT-F3): bu
     * ekranda senkron paneli yok; bir posta kutusunun senkronu durursa (ör.
     * şifre değişti) liste eski veriyle normal görünmesin.
     *
     * @param  list<EntegratorBaglanti>  $tanimlar
     * @return list<array{saglayici: string, ortam: string, veri_zamani: string|null, guncel: bool}>
     */
    private function tazelik(array $tanimlar, EFaturaDurumServisi $durum): array
    {
        // Statik kaynağın (Logo, e-Arşiv) senkronu yok: verisi içe aktarımla gelir
        $senkronlu = array_values(array_filter($tanimlar, fn (EntegratorBaglanti $t): bool => ! $t->statikMi()));

        return array_map(function (EntegratorBaglanti $tanim) use ($durum): array {
            $d = $durum->yonDurumu($tanim, FaturaYonu::Gelen);

            return [
                'saglayici' => $tanim->saglayici,
                'ortam' => $tanim->ortam,
                'veri_zamani' => $d['veri_zamani']?->toIso8601String(),
                'guncel' => $d['guncel'],
            ];
        }, $senkronlu);
    }

    public function excel(EFaturaListeRequest $request, EFaturaCiktisi $cikti): BinaryFileResponse|JsonResponse
    {
        $tanimlar = $this->aktifTanimlar();

        return $cikti->excel(
            $request,
            $tanimlar,
            FaturaYonu::Gelen,
            $this->kapsamAnahtari($tanimlar),
            'tum-faturalar',
            __('efatura.excel.baslik_tum'),
        );
    }

    /**
     * Faturanın kendi sağlayıcısından — sağlayıcı ekranıyla aynı akış (statik
     * kaynakta belge yok: 404 FATURA_BELGESI_YOK, FaturaBelgeServisi).
     */
    public function pdf(int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);

        return $cikti->belge($kayit, $belgeler->pdf($kayit->entegratorBaglanti, $kayit), 'pdf');
    }

    /** UBL XML — yalnız İzibiz faturasında (Protel'de ve statik kaynakta 404, FaturaBelgeServisi). */
    public function xml(int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);

        return $cikti->belge($kayit, $belgeler->xml($kayit->entegratorBaglanti, $kayit), 'xml');
    }

    /**
     * Yetkili kullanıcının gelen faturayı RED'e çekmesi / RED'i geri alması
     * (EK-04) — sağlayıcı ekranlarıyla aynı kurallar.
     */
    public function red(EFaturaRedRequest $request, int $fatura, EFaturaElleIslemleri $islemler): EFaturaResource
    {
        /** @var User $kullanici */
        $kullanici = $request->user();
        $kayit = $islemler->redDegistir($this->kapsamdakiFatura($fatura), $request->boolean('red'), $request->neden(), $kullanici);
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, true);

        return new EFaturaResource($kayit->load([...EFaturaResource::ILISKILER, 'entegratorBaglanti:id,saglayici']));
    }

    /** "SAP (Manuel İşle)" (kullanıcı isteği 2026-11-26) — sağlayıcı ekranlarıyla aynı kurallar. */
    public function islendi(EFaturaIslendiRequest $request, int $fatura, EFaturaElleIslemleri $islemler): EFaturaResource
    {
        /** @var User $kullanici */
        $kullanici = $request->user();
        $kayit = $islemler->islendiDegistir($this->kapsamdakiFatura($fatura), $request->boolean('islendi'), $kullanici);
        request()->attributes->set(EFaturaResource::EMOR_KURUMU, true);

        return new EFaturaResource($kayit->load([...EFaturaResource::ILISKILER, 'entegratorBaglanti:id,saglayici']));
    }

    /** Satırdaki "Geçmiş" penceresi (EK-03). */
    public function gecmis(int $fatura): AnonymousResourceCollection
    {
        $kayit = $this->kapsamdakiFatura($fatura);

        return EFaturaIslemiResource::collection(
            EFaturaIslemi::query()->where('fatura_id', $kayit->id)->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get(),
        );
    }

    /**
     * Sağlayıcı başına aktif tanım; statik kaynaklar (Logo, e-Arşiv) her zaman
     * kapsamdadır (EK-07, EK-08). Hiçbiri yoksa hata (boş liste gibi görünmez).
     *
     * @return list<EntegratorBaglanti>
     */
    private function aktifTanimlar(): array
    {
        $tanimlar = $this->baglantilar->gelenTanimlari();

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
     * Ekran yalnız GELEN faturalarındır: İzibiz tanımının giden faturası da 404
     * (RED / SAP / PDF / XML / geçmiş — tek kaynaklı ekranların `gelenFatura`'sı gibi).
     */
    private function kapsamdakiFatura(int $fatura): EFatura
    {
        $idler = array_map(fn (EntegratorBaglanti $t): int => $t->id, $this->aktifTanimlar());

        return EFatura::query()
            ->with('entegratorBaglanti')
            ->whereIn('entegrator_baglanti_id', $idler)
            ->where('yon', FaturaYonu::Gelen->value)
            ->find($fatura) ?? throw new NotFoundHttpException;
    }
}
