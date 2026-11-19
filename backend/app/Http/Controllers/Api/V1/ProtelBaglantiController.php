<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ayar\ProtelBaglantiGuncelleRequest;
use App\Http\Resources\EntegratorBaglantiResource;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\ProtelIstemcisi;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ayarlar → Entegratör Bağlantıları → Protel (PRT-03, PRT-05): Test/Canlı
 * Protel tanımları ve aktif ortam. Liste İzibiz'le birlikte
 * EntegratorBaglantiController::index'ten döner. Uçlar ekranın güncelle
 * izniyle korunur (routes/api.php); sınama `throttle:entegrator-sina` ile sınırlı.
 */
final class ProtelBaglantiController extends Controller
{
    private const SAGLAYICI = EntegratorBaglanti::SAGLAYICI_PROTEL;

    public function __construct(
        private readonly EntegratorBaglantiServisi $servis,
    ) {}

    public function guncelle(ProtelBaglantiGuncelleRequest $request, string $ortam): JsonResponse
    {
        /** @var array{kullanici_adi: string, sifre?: string|null, vkn: string} $veri */
        $veri = $request->validated();

        $tanim = $this->servis->guncelle($ortam, $veri, self::SAGLAYICI);

        // Şifrenin kendisi değil, yalnız değişip değişmediği yazılır
        Log::info('Protel bağlantı tanımı güncellendi', [
            'ortam' => $ortam,
            'kullanici_id' => $request->user()?->id,
            'sifre_degisti' => ($veri['sifre'] ?? '') !== '',
        ]);

        return (new EntegratorBaglantiResource($tanim))->response()->setStatusCode(200);
    }

    /**
     * Bağlantıyı sına: yalnız giriş (AccessToken) yapılır; önbellek ve kayıtlı
     * tanım değişmez. Boş şifre yalnız kullanıcı kayıtlıyla aynıysa kayıtlı
     * şifreye düşer. PROTEL_AKTIF kapalıyken istek gitmez (409 PROTEL_KAPALI).
     */
    public function sina(Request $request, string $ortam, ProtelIstemcisi $istemci): JsonResponse
    {
        /** @var array{kullanici_adi?: string|null, sifre?: string|null, vkn?: string|null} $veri */
        $veri = $request->validate([
            'kullanici_adi' => ['nullable', 'string', 'email', 'max:128'],
            'sifre' => ['nullable', 'string', 'max:255'],
            'vkn' => ['nullable', 'string', 'regex:/^\d{10,11}$/D'],
        ], ['vkn.regex' => __('hata.entegrator_vkn_gecersiz')]);

        $kayitli = $this->servis->tanim($ortam, self::SAGLAYICI);
        $kullaniciAdi = $veri['kullanici_adi'] ?? $kayitli?->kullanici_adi ?? '';
        $vkn = $veri['vkn'] ?? $kayitli?->vkn ?? '';
        $sifre = $veri['sifre'] ?? '';

        if ($kullaniciAdi === '') {
            throw ValidationException::withMessages(['kullanici_adi' => __('hata.entegrator_kullanici_zorunlu')]);
        }

        if ($vkn === '') {
            throw ValidationException::withMessages(['vkn' => __('hata.entegrator_vkn_gecersiz')]);
        }

        if ($sifre === '') {
            if ($kayitli === null) {
                throw ValidationException::withMessages(['sifre' => __('hata.entegrator_sifre_zorunlu')]);
            }

            if ($kayitli->kullanici_adi !== $kullaniciAdi) {
                throw ValidationException::withMessages(['sifre' => __('hata.entegrator_sifre_kullanici_degisti')]);
            }

            $sifre = $kayitli->sifre;
        }

        // Kaydedilmeyen geçici tanım: kayıtlı modele dokunulmaz
        $tanim = new EntegratorBaglanti([
            'saglayici' => self::SAGLAYICI,
            'ortam' => $ortam,
            'kullanici_adi' => $kullaniciAdi,
            'vkn' => $vkn,
        ]);
        $tanim->sifre = $sifre;

        $token = $istemci->tokenAl($tanim);

        return response()->json([
            'data' => [
                'firma_unvani' => $token->firmaUnvani,
                'gecerlilik_bitis' => $token->bitis->toIso8601String(),
            ],
        ]);
    }

    public function aktifYap(Request $request): JsonResponse
    {
        /** @var array{ortam: string} $veri */
        $veri = $request->validate([
            'ortam' => ['required', Rule::in(EntegratorBaglanti::ORTAMLAR)],
        ]);

        $tanim = $this->servis->aktifYap($veri['ortam'], self::SAGLAYICI);

        Log::info('Aktif Protel ortamı değiştirildi', [
            'ortam' => $tanim->ortam,
            'kullanici_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => new EntegratorBaglantiResource($tanim)]);
    }
}
