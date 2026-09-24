<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Yetki\KullaniciGuncelleRequest;
use App\Http\Requests\Yetki\LokalKullaniciOlusturRequest;
use App\Http\Resources\YonetilenKullaniciResource;
use App\Models\User;
use App\Services\KullaniciYonetimServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

/**
 * Ayarlar → Kullanıcılar (EFAT-18). ekranın görüntüle/güncelle izinleriyle korunur.
 */
final class KullaniciController extends Controller
{
    public function __construct(
        private readonly KullaniciYonetimServisi $servis,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return YonetilenKullaniciResource::collection($this->servis->listele());
    }

    public function store(LokalKullaniciOlusturRequest $request): JsonResponse
    {
        /** @var array{kullanici_adi: string, ad: string, email?: string|null, sifre: string, aktif_mi?: bool, rol_idleri?: list<int>} $veri */
        $veri = $request->validated();
        $kullanici = $this->servis->lokalOlustur($veri);

        Log::info('Lokal kullanıcı oluşturuldu', [
            'hedef_kullanici_id' => $kullanici->id,
            'kullanici_id' => $request->user()?->id,
        ]);

        return (new YonetilenKullaniciResource($kullanici->load('roller:id')))->response()->setStatusCode(201);
    }

    public function update(KullaniciGuncelleRequest $request, User $kullanici): YonetilenKullaniciResource
    {
        /** @var array{ad?: string, email?: string|null, sifre?: string|null, aktif_mi?: bool, rol_idleri?: list<int>} $veri */
        $veri = $request->validated();
        /** @var User $yapan */
        $yapan = $request->user();

        $kullanici = $this->servis->guncelle($kullanici, $yapan, $veri);

        // Şifrenin kendisi değil, değişip değişmediği loglanır
        Log::info('Kullanıcı güncellendi', [
            'hedef_kullanici_id' => $kullanici->id,
            'kullanici_id' => $yapan->id,
            'aktif_mi' => $kullanici->aktif_mi,
            'sifre_degisti' => ($veri['sifre'] ?? '') !== '',
            'rol_idleri' => $veri['rol_idleri'] ?? null,
        ]);

        return new YonetilenKullaniciResource($kullanici->load('roller:id'));
    }
}
