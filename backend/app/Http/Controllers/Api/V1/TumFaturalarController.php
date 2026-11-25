<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Http\Resources\EFaturaResource;
use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaCiktisi;
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaBelgeServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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

    public function index(EFaturaListeRequest $request, EFaturaDurumServisi $durum): JsonResponse
    {
        $tanimlar = $this->aktifTanimlar();
        $filtre = $request->filtre();

        $sorgu = $this->sorgu->filtrele($tanimlar, FaturaYonu::Gelen, $filtre);
        $sayfa = $this->sorgu->sirala($sorgu->clone(), FaturaYonu::Gelen, $request->input('sirala'), $request->input('yon'))
            ->with(['gizleyen:id,ad', 'entegratorBaglanti:id,saglayici'])
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
        return array_map(function (EntegratorBaglanti $tanim) use ($durum): array {
            $d = $durum->yonDurumu($tanim, FaturaYonu::Gelen);

            return [
                'saglayici' => $tanim->saglayici,
                'ortam' => $tanim->ortam,
                'veri_zamani' => $d['veri_zamani']?->toIso8601String(),
                'guncel' => $d['guncel'],
            ];
        }, $tanimlar);
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

    /** Faturanın kendi sağlayıcısından (İzibiz ya da Protel) — sağlayıcı ekranıyla aynı akış. */
    public function pdf(int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);

        return $cikti->belge($kayit, $belgeler->pdf($kayit->entegratorBaglanti, $kayit), 'pdf');
    }

    /** UBL XML — yalnız İzibiz faturasında (Protel'de 404, FaturaBelgeServisi). */
    public function xml(int $fatura, FaturaBelgeServisi $belgeler, EFaturaCiktisi $cikti): Response
    {
        $kayit = $this->kapsamdakiFatura($fatura);

        return $cikti->belge($kayit, $belgeler->xml($kayit->entegratorBaglanti, $kayit), 'xml');
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
}
