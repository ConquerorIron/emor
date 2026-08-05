<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
     * İlgili kaydı seçenekleri — Satınalma Talebi "İlgi konusu" cinsine göre
     * farklı ERP arama view'larından okunur (@ILGILI_ID adayları; profiler
     * kayıtları 2026-08-05). Desteklenen cinsler adım adım genişliyor:
     *   7  = Projemiz            → VOHOM_ARAMA_PARTI_YAMASI (TUR=17, miyatsız)
     *   8  = Uygulama Sözleşmesi → VOHOM_ARAMA_SIPARIS (TIP=0, ALT_TUR=3)
     * Güvenlik filtreleri giriş yapan kullanıcının ERP kimliğiyle uygulanır.
     */
    public function ilgili(Request $request, int $cins): JsonResponse
    {
        if (! in_array($cins, [7, 8], true)) {
            throw ValidationException::withMessages([
                'cins' => __('hata.ilgili_cins_tanimsiz'),
            ]);
        }

        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;
        $baglanti = $this->mssql->baglan();

        $satirlar = $cins === 7
            ? $baglanti->select(
                'SELECT PARTI_YAMASI_ID AS kayit_id, RTRIM(KOD) AS kod, AD AS ad, NULL AS ek
                 FROM VOHOM_ARAMA_PARTI_YAMASI
                 WHERE TUR = 17 AND MIYAD_TARIHI IS NULL
                   AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
                 ORDER BY KOD',
                [$erpKullaniciId],
            )
            : $baglanti->select(
                'SELECT SIPARIS_ID AS kayit_id, RTRIM(SIPARIS_NO) COLLATE Turkish_100_CI_AS AS kod,
                        UNVAN AS ad, PROJE_ADI AS ek
                 FROM VOHOM_ARAMA_SIPARIS
                 WHERE TIP = 0 AND ALT_TUR = 3 AND ISNULL(SAHIP_TURU, 255) <> 1
                   AND ((GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
                    AND (PROJE_GUVENLIK_KODU_ID IS NULL OR PROJE_GRUP_KULLANICISI_ID = ?))
                 ORDER BY SIPARIS_NO COLLATE Turkish_100_CI_AS',
                [$erpKullaniciId, $erpKullaniciId],
            );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => (string) $satir->kod,
                    'ad' => (string) ($satir->ad ?? ''),
                    'ek' => (string) ($satir->ek ?? ''),
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
