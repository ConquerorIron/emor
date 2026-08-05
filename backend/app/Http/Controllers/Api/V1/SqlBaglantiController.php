<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ayar\SqlBaglantiGuncelleRequest;
use App\Http\Resources\SqlBaglantiResource;
use App\Models\SqlBaglanti;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ayarlar → SQL Bağlantıları: Test/Canlı MSSQL tanımları + global aktif ortam.
 */
final class SqlBaglantiController extends Controller
{
    public function __construct(
        private readonly MssqlBaglantiServisi $servis,
    ) {}

    public function index(): JsonResponse
    {
        $tanimlar = $this->servis->listele();

        return response()->json([
            'data' => [
                'test' => isset($tanimlar[SqlBaglanti::ORTAM_TEST])
                    ? new SqlBaglantiResource($tanimlar[SqlBaglanti::ORTAM_TEST])
                    : null,
                'canli' => isset($tanimlar[SqlBaglanti::ORTAM_CANLI])
                    ? new SqlBaglantiResource($tanimlar[SqlBaglanti::ORTAM_CANLI])
                    : null,
                'aktif_ortam' => $this->servis->aktif()?->ortam,
            ],
        ]);
    }

    public function guncelle(SqlBaglantiGuncelleRequest $request, string $ortam): JsonResponse
    {
        /** @var array{sunucu: string, port?: int|null, veritabani: string, kullanici_adi: string, sifre?: string|null} $veri */
        $veri = $request->validated();

        // PUT idempotent güncelleme: ilk kayıtta da 200 döner (MailAyarController deseni)
        return (new SqlBaglantiResource($this->servis->guncelle($ortam, $veri)))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Bağlantıyı sına: gövde verilirse formdaki (henüz kaydedilmemiş) değerlerle,
     * verilmezse kayıtlı tanımla denenir. Boş şifre kayıtlı şifreye düşer.
     */
    public function sina(Request $request, string $ortam): JsonResponse
    {
        /** @var array{sunucu?: string|null, port?: int|null, veritabani?: string|null, kullanici_adi?: string|null, sifre?: string|null} $veri */
        $veri = $request->validate([
            'sunucu' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'veritabani' => ['nullable', 'string', 'max:128'],
            'kullanici_adi' => ['nullable', 'string', 'max:128'],
            'sifre' => ['nullable', 'string', 'max:255'],
        ]);

        $kayitli = SqlBaglanti::query()->where('ortam', $ortam)->first();

        $tanim = $kayitli ?? new SqlBaglanti(['ortam' => $ortam]);
        $tanim->sunucu = $veri['sunucu'] ?? $tanim->sunucu ?? '';
        $tanim->port = $veri['port'] ?? $kayitli?->port;
        $tanim->veritabani = $veri['veritabani'] ?? $tanim->veritabani ?? '';
        $tanim->kullanici_adi = $veri['kullanici_adi'] ?? $tanim->kullanici_adi ?? '';

        $sifre = $veri['sifre'] ?? null;
        if ($sifre !== null && $sifre !== '') {
            $tanim->sifre = $sifre;
        } elseif ($kayitli === null) {
            throw ValidationException::withMessages([
                'sifre' => __('hata.sql_sifre_zorunlu'),
            ]);
        }

        if ($tanim->sunucu === '' || $tanim->veritabani === '' || $tanim->kullanici_adi === '') {
            throw ValidationException::withMessages([
                'sunucu' => __('hata.sql_baglanti_eksik'),
            ]);
        }

        return response()->json(['data' => $this->servis->sina($tanim)]);
    }

    public function aktifYap(Request $request): JsonResponse
    {
        /** @var array{ortam: string} $veri */
        $veri = $request->validate([
            'ortam' => ['required', Rule::in(SqlBaglanti::ORTAMLAR)],
        ]);

        $tanim = $this->servis->aktifYap($veri['ortam']);

        return response()->json(['data' => new SqlBaglantiResource($tanim)]);
    }
}
