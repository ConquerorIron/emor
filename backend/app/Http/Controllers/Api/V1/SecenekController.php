<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ERP seçim listeleri — aktif MSSQL ortamının arama view'larından okunur.
 * Program genelinde ortak kullanılır (personel, depo, proje, ürün…);
 * alan bazında metot eklenerek genişler.
 */
final class SecenekController extends Controller
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    /**
     * Personel seçenekleri — ERP'nin kendi arama ekranıyla aynı kaynak
     * (VOHOM_ARAMA_PERSONEL, profiler kaydı 2026-08-05). kayit_id,
     * SOHOM_SIPARIS_KAYDET'te @PARTI_YAMASI_ID olarak kullanılır.
     * ~120 kayıt; tamamı döner, arama react-select'te client tarafında yapılır.
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

    /**
     * Tablo maddesi seçenekleri — ERP'nin genel amaçlı madde tablosu
     * (VOHOM_TABLO_MADDESI); TUR'a göre farklı listeler döner
     * (ör. TUR=36 → Öncelik, SOHOM_SIPARIS_KAYDET @ONCELIK_ID).
     * Güvenlik filtresi giriş yapan kullanıcının ERP kimliğiyle uygulanır
     * (profiler kaydı 2026-08-05). kayit_id = TABLO_MADDESI_ID.
     */
    public function tabloMaddesi(Request $request, int $tur): JsonResponse
    {
        // Lokal (ERP'siz) kullanıcıda -1: yalnız güvenlik kodu olmayan maddeler
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT TABLO_MADDESI_ID AS kayit_id, UST_ID AS ust_id, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_TABLO_MADDESI
             WHERE TUR = ? AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY AD COLLATE Turkish_100_CI_AS',
            [$tur, $erpKullaniciId],
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'ust_id' => $satir->ust_id === null ? null : (int) $satir->ust_id,
                    'ad' => (string) $satir->ad,
                ],
                $satirlar,
            ),
        ]);
    }
}
