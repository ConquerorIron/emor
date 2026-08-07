<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Ekranlar\EkranKataloglari;
use App\Http\Controllers\Controller;
use App\Models\EkranTasarimi;
use App\Services\EkranTasarimServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Ekran tasarım motoru uçları. Okuma (form çizimi) herkese açıktır; tasarım
 * düzenleme yalnızca ERP sistem yöneticilerine.
 */
final class EkranTasarimController extends Controller
{
    public function __construct(
        private readonly EkranTasarimServisi $servis,
    ) {}

    /** Formu çizmek için yayındaki düzen + alan kataloğu. */
    public function goster(string $ekran): JsonResponse
    {
        $katalog = EkranKataloglari::bul($ekran);

        return response()->json([
            'data' => [
                'ekran' => $katalog->anahtar(),
                'baslik_anahtari' => $katalog->baslikAnahtari(),
                'bolumler' => $katalog->bolumler(),
                'katalog' => array_map(
                    static fn ($alan): array => $alan->diziye(),
                    $katalog->alanlar(),
                ),
                'duzen' => $this->servis->yayindakiDuzen($ekran),
            ],
        ]);
    }

    /** Tasarım editörü: üzerinde çalışılan taslak (yoksa yayındakinden açılır). */
    public function taslak(Request $request, string $ekran): JsonResponse
    {
        $this->yoneticiOlmali($request);
        $katalog = EkranKataloglari::bul($ekran);
        $taslak = $this->servis->taslakGetirVeyaAc($ekran, (int) $request->user()->id);

        return response()->json([
            'data' => [
                'ekran' => $katalog->anahtar(),
                'baslik_anahtari' => $katalog->baslikAnahtari(),
                'bolumler' => $katalog->bolumler(),
                'katalog' => array_map(
                    static fn ($alan): array => $alan->diziye(),
                    $katalog->alanlar(),
                ),
                'duzen' => $taslak->duzen,
                'surum' => $taslak->surum,
                'yayinda_surum' => EkranTasarimi::query()
                    ->where('ekran_anahtari', $ekran)
                    ->where('durum', EkranTasarimi::DURUM_YAYINDA)
                    ->value('surum'),
            ],
        ]);
    }

    public function taslagiKaydet(Request $request, string $ekran): JsonResponse
    {
        $this->yoneticiOlmali($request);

        /** @var array{duzen: array<string, mixed>} $veri */
        $veri = $request->validate([
            'duzen' => ['required', 'array'],
            'duzen.bolumler' => ['required', 'array', 'min:1'],
            // Ekranın onay rolü (ERP ROL_ID) — alan değil, ekran ayarı
            'duzen.onay_rol_id' => ['nullable', 'integer'],
        ]);

        $taslak = $this->servis->taslagiKaydet($ekran, $veri['duzen'], (int) $request->user()->id);

        return response()->json(['data' => ['duzen' => $taslak->duzen, 'surum' => $taslak->surum]]);
    }

    public function yayinla(Request $request, string $ekran): JsonResponse
    {
        $this->yoneticiOlmali($request);

        $tasarim = $this->servis->yayinla($ekran, (int) $request->user()->id);

        return response()->json(['data' => ['duzen' => $tasarim->duzen, 'surum' => $tasarim->surum]]);
    }

    /** Sürüm geçmişi — geri almak için. */
    public function surumler(Request $request, string $ekran): JsonResponse
    {
        $this->yoneticiOlmali($request);

        $surumler = EkranTasarimi::query()
            ->where('ekran_anahtari', $ekran)
            ->orderByDesc('surum')
            ->limit(50)
            ->get(['surum', 'durum', 'notlar', 'yayin_zamani', 'updated_at']);

        return response()->json(['data' => $surumler]);
    }

    public function geriAl(Request $request, string $ekran, int $surum): JsonResponse
    {
        $this->yoneticiOlmali($request);

        $taslak = $this->servis->surumuGeriAl($ekran, $surum, (int) $request->user()->id);

        return response()->json(['data' => ['duzen' => $taslak->duzen, 'surum' => $taslak->surum]]);
    }

    private function yoneticiOlmali(Request $request): void
    {
        if ($request->user()?->sistem_yoneticisi !== true) {
            throw new AccessDeniedHttpException;
        }
    }
}
