<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Http\JsonResponse;

/**
 * Satınalma formu seçim listeleri — ERP view'larından (aktif MSSQL ortamı)
 * okunur. Alan bazında endpoint eklenerek genişler (depo, proje, ürün…).
 */
final class SatinalmaSecenekController extends Controller
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    /**
     * Personel seçenekleri — ERP'nin kendi arama ekranıyla aynı kaynak
     * (VOHOM_ARAMA_PERSONEL, profiler kaydı 2026-08-05). ~120 kayıt;
     * tamamı döner, arama react-select'te client tarafında yapılır.
     */
    public function personeller(): JsonResponse
    {
        $satirlar = $this->mssql->baglan()->select(
            'SELECT PERSONEL_ID AS kayit_id, RTRIM(KOD) AS kod, UNVAN COLLATE Turkish_100_CI_AS AS unvan
             FROM VOHOM_ARAMA_PERSONEL
             ORDER BY UNVAN COLLATE Turkish_100_CI_AS',
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => (string) $satir->kod,
                    'unvan' => (string) $satir->unvan,
                ],
                $satirlar,
            ),
        ]);
    }
}
