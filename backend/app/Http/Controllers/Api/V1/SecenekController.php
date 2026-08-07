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

        // ERP sorgusu türe göre farklı sıralar (ör. Öncelik AD, Teslimat şekli
        // KAYIT_ID). Beyaz liste — sıralama ifadesi istekten gelmez.
        $siralama = $request->string('sirala')->value() === 'kayit_id'
            ? 'TABLO_MADDESI_ID'
            : 'AD COLLATE Turkish_100_CI_AS';

        $satirlar = $this->mssql->baglan()->select(
            'SELECT TABLO_MADDESI_ID AS kayit_id, UST_ID AS ust_id, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_TABLO_MADDESI
             WHERE TUR = ? AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY '.$siralama,
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

    /**
     * Talep SATIRLARININ aktivite seçenekleri — proje bazlıdır (profiler kaydı
     * 2026-08-07). ERP iki adımda çözer, istemciyi bununla uğraştırmıyoruz:
     * seçili projenin iş programı (VOHOM_PROJEMIZ.IS_PROGRAMI_ID) bulunur,
     * aktiviteler o iş emrine göre süzülür. kayit_id = AKTIVITE_ID.
     *
     * Aktivite kodu ve açıklaması aynı kaydın iki yüzüdür (kullanıcı hangisinden
     * seçerse diğeri dolar), bu yüzden tek listede ikisi de döner.
     */
    public function aktiviteler(int $projemizId): JsonResponse
    {
        $baglanti = $this->mssql->baglan();

        // PROJEMIZ_ID = PARTI_YAMASI_ID (projenin arama view'ındaki KAYIT_ID)
        $isProgramiId = $baglanti->scalar(
            'SELECT IS_PROGRAMI_ID FROM VOHOM_PROJEMIZ WHERE PROJEMIZ_ID = ?',
            [$projemizId],
        );

        // İş programı tanımsız projede aktivite yoktur — hata değil, boş liste
        $satirlar = $isProgramiId === null ? [] : $baglanti->select(
            'SELECT AKTIVITE_ID AS kayit_id, KOD COLLATE Turkish_100_CI_AS AS kod,
                    ACIKLAMA AS aciklama, POZ_NO AS poz_no
             FROM VOHOM_ARAMA_AKTIVITE
             WHERE IS_EMRI_ID = ? AND TIP IN (0) AND ISNULL(KAPANDI, 0) = 0
             ORDER BY KOD COLLATE Turkish_100_CI_AS',
            [$isProgramiId],
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => rtrim((string) $satir->kod),
                    'aciklama' => (string) ($satir->aciklama ?? ''),
                    'poz_no' => (string) ($satir->poz_no ?? ''),
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * Ekipman seçenekleri — VOHOM_ARAMA_EKIPMAN (profiler kaydı 2026-08-07).
     * Kiralama hizmetine bağlı kayıtlar listelenmez (KIRA_HIZMETI_ID dolu
     * olanlar ekipman değil, kiralama kalemidir). kayit_id = EKIPMAN_ID.
     */
    public function ekipmanlar(Request $request): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT EKIPMAN_ID AS kayit_id, KOD AS kod, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_ARAMA_EKIPMAN
             WHERE KIRA_HIZMETI_ID IS NULL
               AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY AD COLLATE Turkish_100_CI_AS',
            [$erpKullaniciId],
        );

        return response()->json(['data' => $this->kodAdListesi($satirlar)]);
    }

    /**
     * Bütçe kalemi seçenekleri — VOHOM_ARAMA_PROJEMIZ_BUTCE_KALEMI (profiler
     * kaydı 2026-08-07). Proje bazlıdır; yalnız bütçede ya da nakit akışında
     * kullanılan kalemler listelenir. kayit_id = HARCAMA_KALEMI_ID.
     */
    public function butceKalemleri(Request $request, int $projemizId): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT HARCAMA_KALEMI_ID AS kayit_id, KOD AS kod, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_ARAMA_PROJEMIZ_BUTCE_KALEMI
             WHERE (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
               AND TUR = 2 AND TIP = 0 AND PROJEMIZ_ID = ?
               AND (BUTCEDE_KULLANILIR = 1 OR NAKIT_AKISTA_KULLANILIR = 1)
             ORDER BY AD COLLATE Turkish_100_CI_AS',
            [$erpKullaniciId, $projemizId],
        );

        return response()->json(['data' => $this->kodAdListesi($satirlar)]);
    }

    /**
     * Duran varlık seçenekleri — VOHOM_PERSONEL_UZERINDEKI_ZIMMETLER (profiler
     * kaydı 2026-08-07). Liste personele zimmetli varlıklardan oluşur; ekranda
     * varlığın ADI görünür. kayit_id = DURAN_VARLIK_ID.
     */
    public function duranVarliklar(): JsonResponse
    {
        $satirlar = $this->mssql->baglan()->select(
            'SELECT DURAN_VARLIK_ID AS kayit_id, DURAN_VARLIK_KODU AS kod,
                    DURAN_VARLIK_ADI COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_PERSONEL_UZERINDEKI_ZIMMETLER
             ORDER BY DURAN_VARLIK_ADI COLLATE Turkish_100_CI_AS',
        );

        return response()->json(['data' => $this->kodAdListesi($satirlar)]);
    }

    /**
     * Talep SATIRLARININ personel seçenekleri (profiler kaydı 2026-08-07).
     * DİKKAT: başlıktaki "Personel adı" ile AYNI KAYNAK DEĞİLDİR — başlık İK
     * kartlarını (VOHOM_ARAMA_PERSONEL, PERSONEL_ID) kullanır, satır ise parti
     * yaması ağacındaki personel tarafını (TUR=2, PARTI_YAMASI_ID).
     */
    public function partiYamasiPersonelleri(Request $request): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT PARTI_YAMASI_ID AS kayit_id, KOD AS kod, AD COLLATE Turkish_100_CI_AS AS ad
             FROM VOHOM_ARAMA_PARTI_YAMASI_PERSONEL
             WHERE TUR = 2 AND MIYAD_TARIHI IS NULL
               AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY AD COLLATE Turkish_100_CI_AS',
            [$erpKullaniciId],
        );

        return response()->json(['data' => $this->kodAdListesi($satirlar)]);
    }

    /**
     * Para birimleri — TOHOM_PARA. Basamak sayıları ERP'de para başına tanımlı:
     * fiyat alanları FIYAT_KURUS_BASAMAK_SAYISI (6), tutar alanları
     * KURUS_BASAMAK_SAYISI (2) kadar ondalık gösterir.
     */
    public function paralar(): JsonResponse
    {
        $satirlar = $this->mssql->baglan()->select(
            'SELECT PARA_ID AS kayit_id, KOD AS kod, AD COLLATE Turkish_100_CI_AS AS ad,
                    FIYAT_KURUS_BASAMAK_SAYISI AS fiyat_basamak,
                    KURUS_BASAMAK_SAYISI AS tutar_basamak
             FROM TOHOM_PARA
             ORDER BY PARA_ID',
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => rtrim((string) $satir->kod),
                    'ad' => (string) ($satir->ad ?? ''),
                    'fiyat_basamak' => (int) ($satir->fiyat_basamak ?? self::VARSAYILAN_BASAMAK),
                    'tutar_basamak' => (int) ($satir->tutar_basamak ?? self::VARSAYILAN_BASAMAK),
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * Bir para biriminin belirli tarihteki kuru — VOHOMR_KUR.RAPOR_KURU
     * (kullanıcı kararı 2026-08-07: alış değil RAPOR kuru, belge tarihi baz).
     * Kaynak TOHOM_KAPANIS_KURU'dur.
     *
     * TAM TARİH aranır: o güne kur girilmemişse önceki günün kuru KULLANILMAZ
     * (kullanıcı bildirimi 2026-08-07 — eski kur sessizce kullanılınca yanlış
     * tutar hesaplanıyordu). Kur yoksa null döner, alan sıfır gösterir.
     */
    public function kur(int $paraId, string $tarih): JsonResponse
    {
        $kur = $this->mssql->baglan()->scalar(
            'SELECT TOP 1 RAPOR_KURU FROM VOHOMR_KUR WHERE PARA_ID = ? AND TARIH = ?',
            [$paraId, str_replace('-', '', $tarih)],
        );

        return response()->json([
            'data' => ['kur' => $kur === null ? null : (string) round((float) $kur, 6)],
        ]);
    }

    /** kayit_id + kod + ad döndüren listeler için ortak dönüşüm */
    private function kodAdListesi(array $satirlar): array
    {
        return array_map(
            static fn (object $satir): array => [
                'kayit_id' => (int) $satir->kayit_id,
                'kod' => rtrim((string) ($satir->kod ?? '')),
                'ad' => (string) ($satir->ad ?? ''),
            ],
            $satirlar,
        );
    }

    /** Ürün listesi büyük olduğu için tek istekte dönen azami kayıt */
    private const URUN_AZAMI = 50;

    /** Ölçü sistemi tanımsızsa miktar alanının ondalık basamak sayısı */
    private const VARSAYILAN_BASAMAK = 2;

    /**
     * Ürün seçenekleri — VOHOM_ARAMA_URUN_YAMASI (TUR=6, profiler kaydı
     * 2026-08-07). kayit_id = URUN_YAMASI_ID →
     * TOHOM_SIPARIS_SATIRI.URUN_YAMASI_ID.
     *
     * Diğer listelerden farklı olarak TAMAMI dönmez (test ortamında 12.000+
     * kayıt): arama sunucuda yapılır, ilk 50 kayıt döner. Arama kod, ad ve
     * barkodun üçünde birden çalışır — kullanıcı hangi sütunda olursa olsun
     * bildiği değeri yazabilsin.
     */
    public function urunler(Request $request): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;
        $ara = trim($request->string('ara')->value());

        $parametreler = [$erpKullaniciId];
        $aramaKosulu = '';

        if ($ara !== '') {
            // LIKE joker karakterleri düz metin sayılır ('%' arayan kullanıcı
            // tüm listeyi getirmesin)
            $desen = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $ara).'%';
            $aramaKosulu = 'AND (U.KOD COLLATE Turkish_100_CI_AS LIKE ?
                             OR U.AD COLLATE Turkish_100_CI_AS LIKE ?
                             OR U.BARKOD COLLATE Turkish_100_CI_AS LIKE ?)';
            array_push($parametreler, $desen, $desen, $desen);
        }

        // Miktar alanının ondalık hassasiyeti ürünün ölçü sisteminden gelir
        // (ör. "Ad" → 6 basamak, "m3" → 4); satır seçimle birlikte taşır
        $satirlar = $this->mssql->baglan()->select(
            'SELECT TOP '.self::URUN_AZAMI.' U.URUN_YAMASI_ID AS kayit_id,
                    U.KOD COLLATE Turkish_100_CI_AS AS kod, U.AD AS ad, U.BARKOD AS barkod,
                    U.OLCU_SISTEMI AS birim, U.OLCU_SISTEMI_ID AS birim_id,
                    O.MIKTAR_BASAMAK_SAYISI AS basamak
             FROM VOHOM_ARAMA_URUN_YAMASI U
                LEFT OUTER JOIN TOHOM_OLCU_SISTEMI O ON O.OLCU_SISTEMI_ID = U.OLCU_SISTEMI_ID
             WHERE U.TUR = 6 AND (U.GUVENLIK_KODU_ID IS NULL OR U.GRUP_KULLANICISI_ID = ?)
             '.$aramaKosulu.'
             ORDER BY U.KOD COLLATE Turkish_100_CI_AS',
            $parametreler,
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => rtrim((string) $satir->kod),
                    'ad' => (string) ($satir->ad ?? ''),
                    'barkod' => rtrim((string) ($satir->barkod ?? '')),
                    'birim' => (string) ($satir->birim ?? ''),
                    // Satırın BIRIM_ID'si — kayıtta ERP'ye bu gider
                    'birim_id' => (string) ($satir->birim_id ?? ''),
                    'basamak' => (int) ($satir->basamak ?? self::VARSAYILAN_BASAMAK),
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * Ambalaj (kap) seçenekleri — VOHOM_ARAMA_KAP (profiler kaydı 2026-08-07).
     * Ekranda kabın ADI görünür; kayit_id = KAP_ID. Ambalaj miktarının ondalık
     * hassasiyeti kabın kapasite ölçü sisteminden gelir.
     */
    public function ambalajlar(): JsonResponse
    {
        $satirlar = $this->mssql->baglan()->select(
            'SELECT K.KAP_ID AS kayit_id, K.KOD AS kod, K.AD COLLATE Turkish_100_CI_AS AS ad,
                    K.BARKOD AS barkod, O.MIKTAR_BASAMAK_SAYISI AS basamak
             FROM VOHOM_ARAMA_KAP K
                LEFT OUTER JOIN TOHOM_OLCU_SISTEMI O
                    ON O.OLCU_SISTEMI_ID = K.KAPASITE_OLCU_SISTEMI_ID
             WHERE ISNULL(K.KAPASITE_OLCU_SISTEMI_ID, 1) = 1
             ORDER BY K.AD COLLATE Turkish_100_CI_AS',
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => rtrim((string) ($satir->kod ?? '')),
                    'ad' => (string) ($satir->ad ?? ''),
                    'barkod' => rtrim((string) ($satir->barkod ?? '')),
                    'basamak' => (int) ($satir->basamak ?? self::VARSAYILAN_BASAMAK),
                ],
                $satirlar,
            ),
        ]);
    }

    /**
     * Talep SATIRLARININ masraf merkezi seçenekleri (profiler kaydı 2026-08-07).
     * Liste hem projeye hem kullanıcıya göre süzülür: projesiz (genel) merkezler
     * her projede görünür. kayit_id = MASRAF_MERKEZI_ID —
     * TOHOM_SIPARIS_SATIRI.MASRAF_MERKEZI_ID alanına yazılır.
     * Kod ve ad aynı kaydın iki yüzüdür (biri seçilince diğeri dolar).
     */
    public function masrafMerkezleri(Request $request, int $projemizId): JsonResponse
    {
        $erpKullaniciId = $request->user()?->erp_kullanici_id ?? -1;

        $satirlar = $this->mssql->baglan()->select(
            'SELECT MASRAF_MERKEZI_ID AS kayit_id, KOD COLLATE Turkish_100_CI_AS AS kod, AD AS ad
             FROM VOHOM_ARAMA_MASRAF_MERKEZI
             WHERE TIP = 0
               AND (PROJEMIZ_ID IS NULL OR PROJEMIZ_ID = ?)
               AND (GUVENLIK_KODU_ID IS NULL OR GRUP_KULLANICISI_ID = ?)
             ORDER BY KOD COLLATE Turkish_100_CI_AS',
            [$projemizId, $erpKullaniciId],
        );

        return response()->json([
            'data' => array_map(
                static fn (object $satir): array => [
                    'kayit_id' => (int) $satir->kayit_id,
                    'kod' => rtrim((string) $satir->kod),
                    'ad' => (string) ($satir->ad ?? ''),
                ],
                $satirlar,
            ),
        ]);
    }
}
