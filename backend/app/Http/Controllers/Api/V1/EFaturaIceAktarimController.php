<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaIceAktarRequest;
use App\Models\EFaturaYuklemesi;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\IceAktarim\EFaturaIceAktarimi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Statik kaynakların (Logo, e-Arşiv / e-SMM) Excel içe aktarımı ve partileri
 * (EK-06). Kapsam rotanın statik tanımıdır; başka tanımın partisi 404.
 * Yetkiler rota middleware'inde (`can:logo_faturalari.ice_aktar`, …).
 */
final class EFaturaIceAktarimController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaIceAktarimi $aktarim,
    ) {}

    /** Yapıştırma tablosunun kolonları, belge türüne göre (Kesinti'deki kolon ucuyla aynı rol). */
    public function kolonlar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->aktarim->kolonlar($this->tanim($request))]);
    }

    public function kontrol(EFaturaIceAktarRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->aktarim->onizle($this->tanim($request), $request->girdi())]);
    }

    /** Hatalı girdi hiçbir satır yazmaz: 422 + önizleme (hangi satır, hangi neden). */
    public function kaydet(EFaturaIceAktarRequest $request): JsonResponse
    {
        /** @var User $kullanici */
        $kullanici = $request->user();
        $sonuc = $this->aktarim->kaydet($this->tanim($request), $request->girdi(), $request->dosyaAdi(), $kullanici);

        if (is_array($sonuc)) {
            return response()->json(['kod' => 'ICE_AKTARIM_HATALI', 'mesaj' => __('hata.ice_aktarim_hatali'), 'data' => $sonuc], 422);
        }

        return response()->json(['data' => $this->parti($sonuc)], 201);
    }

    /** "Yüklemeler" penceresi: tanımın partileri, en yeni üstte. */
    public function index(Request $request): JsonResponse
    {
        $partiler = EFaturaYuklemesi::query()
            ->where('entegrator_baglanti_id', $this->tanim($request)->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $partiler->map($this->parti(...))->all()]);
    }

    public function geriAl(Request $request, int $yukleme): Response
    {
        $parti = EFaturaYuklemesi::query()
            ->where('entegrator_baglanti_id', $this->tanim($request)->id)
            ->find($yukleme) ?? throw new NotFoundHttpException;
        /** @var User $kullanici */
        $kullanici = $request->user();

        $this->aktarim->geriAl($parti, $kullanici);

        return response()->noContent();
    }

    /**
     * @return array{id: int, belge_turu: string|null, dosya_adi: string, kullanici_adi: string, yeni: int, guncellenen: int, degismeyen: int, zaman: string}
     */
    private function parti(EFaturaYuklemesi $parti): array
    {
        return [
            'id' => $parti->id,
            'belge_turu' => $parti->belge_turu,
            'dosya_adi' => $parti->dosya_adi,
            'kullanici_adi' => $parti->kullanici_adi,
            'yeni' => $parti->yeni,
            'guncellenen' => $parti->guncellenen,
            'degismeyen' => $parti->degismeyen,
            'zaman' => $parti->created_at->toIso8601String(),
        ];
    }

    private function tanim(Request $request): EntegratorBaglanti
    {
        $saglayici = EntegratorBaglantiServisi::rotaninSaglayicisi($request);

        // Yalnız statik kaynak rotaları: entegratör tanımı burada oluşturulamaz
        return in_array($saglayici, EntegratorBaglanti::STATIK_SAGLAYICILAR, true)
            ? $this->baglantilar->statikTanim($saglayici)
            : throw new NotFoundHttpException;
    }
}
