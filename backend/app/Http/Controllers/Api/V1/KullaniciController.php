<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Yetki\ErpKullaniciTanimlaRequest;
use App\Http\Requests\Yetki\KullaniciGuncelleRequest;
use App\Models\User;
use App\Services\KullaniciYonetimServisi;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ayarlar → Kullanıcılar (EFAT-18): ERP kullanıcılarından hangilerinin
 * uygulamaya girebileceği ve rolleri. Ekranın görüntüle/güncelle izinleriyle
 * korunur.
 */
final class KullaniciController extends Controller
{
    public function __construct(
        private readonly KullaniciYonetimServisi $servis,
    ) {}

    public function index(Request $request): JsonResponse
    {
        ['kullanicilar' => $kullanicilar, 'erp_okunamadi' => $erpOkunamadi] = $this->servis->listele();

        return response()->json([
            'data' => array_map(fn (array $satir): array => $this->satir($satir, $request), $kullanicilar),
            'meta' => ['erp_okunamadi' => $erpOkunamadi],
        ]);
    }

    /** ERP kullanıcısını uygulamaya tanımlar (giriş izni + roller). */
    public function store(ErpKullaniciTanimlaRequest $request): JsonResponse
    {
        /** @var array{erp_kullanici_id: int, aktif_mi?: bool, rol_idleri?: list<int>} $veri */
        $veri = $request->validated();
        /** @var User $yapan */
        $yapan = $request->user();
        $erpKullanici = $this->servis->erpKullanicisi((int) $veri['erp_kullanici_id']);

        // Sistem yöneticisi hesabını yalnız sistem yöneticisi tanımlar (YetkiSiniri)
        if ($erpKullanici['sistem_yoneticisi'] && ! $yapan->sistem_yoneticisi) {
            throw new AuthorizationException;
        }

        $kullanici = $this->servis->tanimla($erpKullanici, $veri);

        Log::info('ERP kullanıcısı uygulamaya tanımlandı', [
            'hedef_kullanici_id' => $kullanici->id,
            'erp_kullanici_id' => $kullanici->erp_kullanici_id,
            'kullanici_id' => $yapan->id,
            'aktif_mi' => $kullanici->aktif_mi,
            'rol_idleri' => $veri['rol_idleri'] ?? [],
        ]);

        return response()->json(['data' => $this->modelSatiri($kullanici, $request)], 201);
    }

    public function update(KullaniciGuncelleRequest $request, User $kullanici): JsonResponse
    {
        /** @var array{aktif_mi?: bool, rol_idleri?: list<int>} $veri */
        $veri = $request->validated();
        /** @var User $yapan */
        $yapan = $request->user();

        $kullanici = $this->servis->guncelle($kullanici, $yapan, $veri);

        Log::info('Kullanıcı güncellendi', [
            'hedef_kullanici_id' => $kullanici->id,
            'kullanici_id' => $yapan->id,
            'aktif_mi' => $kullanici->aktif_mi,
            'rol_idleri' => $veri['rol_idleri'] ?? null,
        ]);

        return response()->json(['data' => $this->modelSatiri($kullanici, $request)]);
    }

    /**
     * @param  array{id: int|null, erp_kullanici_id: int|null, kullanici_adi: string, ad: string, kaynak: string, sistem_yoneticisi: bool, aktif_mi: bool, rol_idleri: list<int>, erpde_yok: bool}  $satir
     * @return array<string, mixed>
     */
    private function satir(array $satir, Request $request): array
    {
        $kendisi = $satir['id'] !== null && $satir['id'] === $request->user()?->id;
        $yedekAdmin = $satir['kaynak'] === User::KAYNAK_LOKAL
            && $satir['kullanici_adi'] === (string) config('erp.admin_kullanici');

        return [
            ...$satir,
            // Giriş izni kaldırılamaz: yedek admin ya da isteği yapan kişinin kendisi
            'pasif_yapilamaz' => $yedekAdmin || $kendisi,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelSatiri(User $kullanici, Request $request): array
    {
        return $this->satir([
            'id' => $kullanici->id,
            'erp_kullanici_id' => $kullanici->erp_kullanici_id,
            'kullanici_adi' => $kullanici->kullanici_adi,
            'ad' => $kullanici->ad,
            'kaynak' => $kullanici->kaynak,
            'sistem_yoneticisi' => $kullanici->sistem_yoneticisi,
            'aktif_mi' => $kullanici->aktif_mi,
            'rol_idleri' => $kullanici->roller()->pluck('roller.id')->all(),
            'erpde_yok' => false,
        ], $request);
    }
}
