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
     * Depo seçenekleri — depolar da parti yaması ağacındadır (TUR=12, miyatsız;
     * profiler kaydı 2026-08-05). kayit_id, sipariş SATIRLARININ DEPOMUZ_ID
     * alanına yazılır (TOHOM_SIPARIS_SATIRI — başlık tablosunda depo yoktur;
     * proc, satırları TOHOM_ISKELE_ALIM_SATIM_SATIRI'ndan kopyalarken taşır).
     */
    public function depolar(Request $request): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT PARTI_YAMASI_ID AS kayit_id, RTRIM(KOD) AS kod, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_ARAMA_PARTI_YAMASI
             WHERE TUR = 12 AND MIYAD_TARIHI IS NULL
               AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY AD COLLATE Turkish_100_CI_AS',
            [$erpKullaniciId],
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => (string) $satir->kod,
                    'ad' => (string) $satir->ad,
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * Firmamızın adresleri (VOHOM_ARAMA_FIRMAMIZ_ADRESI; profiler kaydı
     * 2026-08-06). Seçimde İKİ değer birden kullanılır: kayit_id proc'un
     * TESLIMAT_ADRESI_ID parametresine, adres metni ise TESLIMAT_ADRESI
     * (ACIKLAMA200) parametresine gider.
     */
    public function firmamizAdresleri(): JsonResponse
    {
        $satirlar = $this->mssql->baglan()->select(
            'SELECT ADRES_ID AS kayit_id, ADRES_TIPI_ID AS adres_tipi_id,
                    AD COLLATE Turkish_100_CI_AS AS ad, ADRES AS adres,
                    SEMT AS semt, SEHIR AS sehir
             FROM VOHOM_ARAMA_FIRMAMIZ_ADRESI
             ORDER BY AD COLLATE Turkish_100_CI_AS',
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'ad' => (string) ($satir->ad ?? ''),
                    'adres' => trim((string) ($satir->adres ?? '')),
                    'semt' => trim((string) ($satir->semt ?? '')),
                    'sehir' => trim((string) ($satir->sehir ?? '')),
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * İlgili kaydı seçenekleri — Satınalma Talebi "İlgi konusu" cinsine göre
     * farklı ERP arama view'larından okunur (@ILGILI_ID adayları; profiler
     * kayıtları 2026-08-05):
     *   7  = Projemiz             → VOHOM_ARAMA_PARTI_YAMASI (TUR=17, miyatsız)
     *   8  = Uygulama Sözleşmesi  → VOHOM_ARAMA_SIPARIS (TIP=0, ALT_TUR=3)
     *   11 = Arızalı Yedek Parça  → VOHOM_DEPO_ARIZALI_URUNLER
     *   12 = İş Paketi            → VOHOM_ARAMA_SIPARIS (TIP=2, ALT_TUR=6; sözleşmesi bugün geçerli)
     *   13 = Satınalma Sözleşmesi → VOHOM_ARAMA_SIPARIS (TIP=0, ALT_TUR=2)
     * Güvenlik filtreleri giriş yapan kullanıcının ERP kimliğiyle uygulanır.
     */
    public function ilgili(Request $request, int $cins): JsonResponse
    {
        if (! in_array($cins, [7, 8, 11, 12, 13], true)) {
            throw ValidationException::withMessages([
                'cins' => __('hata.ilgili_cins_tanimsiz'),
            ]);
        }

        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;
        $baglanti = $this->mssql->baglan();

        $siparisSorgusu = static fn (string $kosul): string => "SELECT SIPARIS_ID AS kayit_id, RTRIM(SIPARIS_NO) COLLATE Turkish_100_CI_AS AS kod,
                    UNVAN AS ad, PROJE_ADI AS ek
             FROM VOHOM_ARAMA_SIPARIS
             WHERE {$kosul}
               AND ((GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
                AND (PROJE_GUVENLIK_KODU_ID IS NULL OR PROJE_GRUP_KULLANICISI_ID = ?))
             ORDER BY SIPARIS_NO COLLATE Turkish_100_CI_AS";

        $satirlar = match ($cins) {
            7 => $baglanti->select(
                'SELECT PARTI_YAMASI_ID AS kayit_id, RTRIM(KOD) AS kod, AD AS ad, NULL AS ek
                 FROM VOHOM_ARAMA_PARTI_YAMASI
                 WHERE TUR = 17 AND MIYAD_TARIHI IS NULL
                   AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
                 ORDER BY KOD',
                [$erpKullaniciId],
            ),
            8 => $baglanti->select(
                $siparisSorgusu('TIP = 0 AND ALT_TUR = 3 AND ISNULL(SAHIP_TURU, 255) <> 1'),
                [$erpKullaniciId, $erpKullaniciId],
            ),
            11 => $baglanti->select(
                'SELECT DEPO_FISI_SATIRI_ID AS kayit_id, RTRIM(URUN_KODU) AS kod,
                        URUN_ADI COLLATE Turkish_100_CI_AS AS ad, DEPO_ADI AS ek
                 FROM VOHOM_DEPO_ARIZALI_URUNLER
                 ORDER BY URUN_ADI COLLATE Turkish_100_CI_AS',
            ),
            // Sözleşme aralığı bugünü kapsayan iş paketleri (ERP 'YYYYMMDD' karşılaştırır)
            12 => $baglanti->select(
                'SELECT SIPARIS_ID AS kayit_id, RTRIM(SIPARIS_NO) COLLATE Turkish_100_CI_AS AS kod,
                        UNVAN AS ad, PROJE_ADI AS ek
                 FROM VOHOM_ARAMA_SIPARIS
                 WHERE TIP = 2 AND ALT_TUR = 6
                   AND ((GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
                    AND (PROJE_GUVENLIK_KODU_ID IS NULL OR PROJE_GRUP_KULLANICISI_ID = ?))
                   AND ? BETWEEN SOZLESME_BASLANGIC_TARIHI AND SOZLESME_BITIS_TARIHI
                 ORDER BY SIPARIS_NO COLLATE Turkish_100_CI_AS',
                [$erpKullaniciId, $erpKullaniciId, now()->format('Ymd')],
            ),
            default => $baglanti->select(
                $siparisSorgusu('TIP = 0 AND ALT_TUR = 2 AND ISNULL(SAHIP_TURU, 255) <> 1'),
                [$erpKullaniciId, $erpKullaniciId],
            ),
        };

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
