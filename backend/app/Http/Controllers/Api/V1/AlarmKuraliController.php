<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ayar\AlarmKuraliGuncelleRequest;
use App\Models\AlarmBildirimi;
use App\Models\AlarmKurali;
use App\Services\Alarm\AlarmKuraliServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Ayarlar → Alarm Kuralları (EFAT-13). `can:sistem-yonetimi` ile korunur.
 * Son bildirimler operatör görünürlüğü içindir: mail gitmese de sorun ekranda görünür.
 */
final class AlarmKuraliController extends Controller
{
    public function __construct(
        private readonly AlarmKuraliServisi $servis,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map($this->kural(...), $this->servis->listele()),
            'bildirimler' => $this->servis->sonBildirimler()->map($this->bildirim(...))->all(),
        ]);
    }

    public function update(AlarmKuraliGuncelleRequest $request, string $tur): JsonResponse
    {
        /** @var array{aktif: bool, alicilar?: list<string>|null, parametreler: array<string, int|string>} $veri */
        $veri = $request->validated();
        $kural = $this->servis->guncelle($tur, $veri);

        Log::info('Alarm kuralı güncellendi', [
            'kullanici_id' => $request->user()?->id,
            'tur' => $tur,
            'aktif' => $kural->aktif,
            'alici_sayisi' => count($kural->alicilar),
        ]);

        return response()->json(['data' => $this->kural($kural)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kural(AlarmKurali $kural): array
    {
        return [
            'tur' => $kural->tur,
            'aktif' => $kural->aktif,
            'alicilar' => $kural->alicilar,
            'parametreler' => [...AlarmKurali::varsayilanParametreler($kural->tur), ...$kural->parametreler],
            'updated_at' => $kural->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bildirim(AlarmBildirimi $b): array
    {
        return [
            'id' => $b->id,
            'kural_turu' => $b->kural->tur,
            'ortam' => $b->entegratorBaglanti->ortam,
            'tur' => $b->tur,
            'durum' => $b->durum,
            'ayrinti' => $b->ayrinti,
            'deneme' => $b->deneme,
            'hata_kodu' => $b->hata_kodu,
            'hata_mesaji' => $b->hata_mesaji,
            'gonderildi' => $b->gonderildi?->toIso8601String(),
            'olusturuldu' => $b->created_at->toIso8601String(),
        ];
    }
}
