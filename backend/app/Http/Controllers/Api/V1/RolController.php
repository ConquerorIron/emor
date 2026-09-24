<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Yetki\RolKaydetRequest;
use App\Models\Rol;
use App\Services\RolServisi;
use App\Yetki\Izin;
use App\Yetki\YetkiSiniri;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Ayarlar → Roller (EFAT-18). `roller.goruntule` / `roller.guncelle` izinleriyle
 * korunur (routes/api.php).
 */
final class RolController extends Controller
{
    public function __construct(
        private readonly RolServisi $servis,
    ) {}

    /**
     * İzin kataloğu, ekran başına görüntüle/güncelle/ek izinler (Roller
     * ekranındaki matris). Etiketler frontend i18n'inde (`yetki.ekran.<ekran>`).
     */
    public function izinler(): JsonResponse
    {
        return response()->json(['data' => Izin::ekranlar()]);
    }

    public function index(): JsonResponse
    {
        [$roller, $izinler] = $this->servis->listele();

        return response()->json(['data' => $roller->map(fn (Rol $rol): array => $this->rolVerisi($rol, $izinler[$rol->id] ?? []))->all()]);
    }

    public function store(RolKaydetRequest $request): JsonResponse
    {
        /** @var array{ad: string, aciklama?: string|null, izinler: list<string>} $veri */
        $veri = $request->validated();
        $rol = $this->servis->kaydet(null, $veri);
        $this->kaydiYaz('Rol oluşturuldu', $request, $rol);

        return response()->json(['data' => $this->rolVerisi($rol->loadCount('kullanicilar'), $rol->izinler())], 201);
    }

    public function update(RolKaydetRequest $request, Rol $rol): JsonResponse
    {
        /** @var array{ad: string, aciklama?: string|null, izinler: list<string>} $veri */
        $veri = $request->validated();
        $rol = $this->servis->kaydet($rol, $veri);
        $this->kaydiYaz('Rol güncellendi', $request, $rol);

        return response()->json(['data' => $this->rolVerisi($rol->loadCount('kullanicilar'), $rol->izinler())]);
    }

    public function destroy(Request $request, Rol $rol): Response
    {
        // Yönetici olmayan, kendinden yetkili bir rolü silemez (YetkiSiniri)
        if (YetkiSiniri::asanRolIzinleri($request->user(), [$rol->id]) !== []) {
            throw new AuthorizationException;
        }

        $this->servis->sil($rol);
        $this->kaydiYaz('Rol silindi', $request, $rol);

        return response()->noContent();
    }

    /**
     * @param  list<string>  $izinler
     * @return array<string, mixed>
     */
    private function rolVerisi(Rol $rol, array $izinler): array
    {
        return [
            'id' => $rol->id,
            'ad' => $rol->ad,
            'aciklama' => $rol->aciklama,
            'izinler' => $izinler,
            'kullanici_sayisi' => (int) ($rol->kullanicilar_count ?? 0),
        ];
    }

    private function kaydiYaz(string $olay, Request $request, Rol $rol): void
    {
        Log::info($olay, ['rol_id' => $rol->id, 'rol' => $rol->ad, 'kullanici_id' => $request->user()?->id]);
    }
}
