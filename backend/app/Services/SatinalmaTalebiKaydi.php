<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satınalma Talebini ERP'ye kaydeder.
 *
 * Akış (hepsi TEK bağlantıda olmak zorunda — geçici tablolar oturumluktur):
 *   1. ErpIskelesi ile `#TOHOM_ISKELE_*` tabloları kurulur
 *   2. Satırlar kendi ürettiğimiz GUID ile TOHOM_ISKELE_ALIM_SATIM_SATIRI ve
 *      TOHOM_ISKELE_EVRAK_DETAYI'ye yazılır (proc satır parametresi almaz,
 *      satırları bu tablolardan GUID ile toplar)
 *   3. SOHOM_SIPARIS_KAYDET çağrılır; talep numarasını proc üretir
 *
 * Not: Talep numarası, sipariş kimliği ve onay durumu proc'tan OUTPUT olarak
 * döner.
 */
final class SatinalmaTalebiKaydi
{
    /** TOHOM_SIPARIS.TIP — Satınalma Talep Formu (mevcut kayıtlardan doğrulandı) */
    private const TIP = 2;

    /**
     * ERP'nin Kaydet akışı (profiler kaydı 2026-08-07) tek parametrede ayrışır:
     *
     *   0 → DENETLE. Rol kuralları çalışır; kısıtlama varsa proc hiçbir şey
     *       kaydetmeden 2 döner ve kısıtlamalar #TOHOM_ISKELE_SINIRLAMA'da kalır.
     *       ERP bu noktada "Denetim mesajları" penceresini açar.
     *   1 → Pencerede "Onaya sun" İŞARETSİZ Kaydet.
     *   2 → Pencerede "Onaya sun" İŞARETLİ Kaydet.
     */
    public const DENETIM_DENETLE = 0;

    public const DENETIM_ONAYA_SUNMADAN = 1;

    public const DENETIM_ONAYA_SUN = 2;

    /** Proc dönüşü: 0 = kaydedildi, 2 = kısıtlama/onay gerekli (kayıt yok) */
    private const DONUS_ONAY_GEREKLI = 2;

    /** Belge para birimi TL; satır bazlı dövizler satırda taşınır */
    private const YEREL_PARA_ID = 1;

    public function __construct(
        private readonly ErpIskelesi $iskele,
    ) {}

    /**
     * @param  array<string, mixed>  $talep  Form verisi (başlık + satirlar)
     * @return array{durum: string, siparis_id: int, talep_no: string, onay_durumu: int, sinirlamalar: list<array<string, mixed>>}
     */
    public function kaydet(
        array $talep,
        int $erpKullaniciId,
        int $denetimIslemi = self::DENETIM_DENETLE,
    ): array {
        $baglanti = $this->iskele->kur();
        $guid = strtoupper((string) Str::uuid());

        $baglanti->beginTransaction();

        try {
            $this->satirlariYaz($baglanti, $guid, $talep);
            $sonuc = $this->procCagir($baglanti, $guid, $talep, $erpKullaniciId, $denetimIslemi);

            // Kullanıcının rol kuralları kaydı engelliyorsa proc HİÇBİR ŞEY
            // kaydetmeden 2 ile döner; talebin onaya sunulması gerekir
            if ($sonuc['donus_kodu'] === self::DONUS_ONAY_GEREKLI) {
                // Kısıtlamalar geçici tabloda; geri alma onları da sileceği
                // için önce okunur
                $sinirlamalar = $this->sinirlamalar($baglanti);
                $baglanti->rollBack();

                return [
                    'durum' => 'onay_gerekli',
                    'siparis_id' => 0,
                    'talep_no' => '',
                    'onay_durumu' => $sonuc['onay_durumu'],
                    'sinirlamalar' => $sinirlamalar,
                ];
            }

            $baglanti->commit();

            return [
                'durum' => 'kaydedildi',
                'siparis_id' => $sonuc['siparis_id'],
                'talep_no' => $sonuc['talep_no'],
                'onay_durumu' => $sonuc['onay_durumu'],
                'sinirlamalar' => [],
            ];
        } catch (\Throwable $hata) {
            $baglanti->rollBack();
            $this->iskeleyiTemizle($baglanti, $guid);

            throw $hata;
        }
    }

    /**
     * Rol denetiminin bıraktığı kısıtlamalar — "onaya sun" penceresinin
     * içeriği buradan gelecek (onaylayacak kişi, mesaj…).
     *
     * @return list<array<string, mixed>>
     */
    private function sinirlamalar(Connection $baglanti): array
    {
        $satirlar = $baglanti->select(
            'SELECT ROL_SINIRLAMASI_ID, SINIRLAMA_TURU, MESAJ, ONAYLAYAN_ID, ACIKLAMA,
                    VEKIL_ID, ONERILEN_FIRMA_ID
             FROM #TOHOM_ISKELE_SINIRLAMA',
        );

        return array_map(
            static fn (object $satir): array => [
                'sinirlama_turu' => (int) ($satir->SINIRLAMA_TURU ?? 0),
                'mesaj' => trim((string) ($satir->MESAJ ?? '')),
                'onaylayan_id' => $satir->ONAYLAYAN_ID === null ? null : (int) $satir->ONAYLAYAN_ID,
                'aciklama' => trim((string) ($satir->ACIKLAMA ?? '')),
            ],
            $satirlar,
        );
    }

    /**
     * Satırları iskele tablolarına yazar. Proc bunları GUID ile bulup
     * TOHOM_SIPARIS_SATIRI'na taşır.
     *
     * @param  array<string, mixed>  $talep
     */
    private function satirlariYaz(Connection $baglanti, string $guid, array $talep): void
    {
        $projemizId = $this->projemizId($talep);
        $depomuzId = $this->kimlik($talep['depomuz_id'] ?? null);

        foreach (array_values((array) ($talep['satirlar'] ?? [])) as $indeks => $ham) {
            $satir = (array) $ham;
            $miktar = $this->sayi($satir['miktar'] ?? null);

            $baglanti->table('TOHOM_ISKELE_ALIM_SATIM_SATIRI')->insert([
                'GUID' => $guid,
                // ERP satır numarası sıfırdan başlar (mevcut kayıtlardan)
                'SATIR_NO' => $indeks,
                'SATIR_TURU' => 0,
                'KALEM_NO' => 0,

                'URUN_YAMASI_ID' => $this->kimlik($satir['urun_yamasi_id'] ?? null),
                'MIKTAR' => $miktar,
                'BIRIM_ID' => $this->kimlik($satir['urun_birim_id'] ?? null),
                'DEPOMUZ_ID' => $depomuzId,

                'PROJEMIZ_ID' => $projemizId,
                'AKTIVITE_ID' => $this->kimlik($satir['aktivite_id'] ?? null),
                'MASRAF_MERKEZI_ID' => $this->kimlik($satir['masraf_merkezi_id'] ?? null),
                'EKIPMAN_ID' => $this->kimlik($satir['ekipman_id'] ?? null),
                'BUTCE_KALEMI_ID' => $this->kimlik($satir['butce_kalemi_id'] ?? null),
                'BUTCE_BOLUMU_ID' => $this->kimlik($satir['butce_bolumu_id'] ?? null),
                'DURAN_VARLIK_ID' => $this->kimlik($satir['duran_varlik_id'] ?? null),
                'PERSONEL_ID' => $this->kimlik($satir['personel_id'] ?? null),

                'DARA' => $this->sayi($satir['dara'] ?? null),
                'KAPANDI' => ($satir['kapandi'] ?? '0') === '1' ? 1 : 0,

                // TOHOM_SIPARIS_SATIRI'nda NOT NULL olup bu ekranda karşılığı
                // olmayan kolonlar: proc iskeleden birebir kopyaladığı için
                // boş bırakılamaz
                'KDVSIZ_TUTAR' => 0.0,
                'ISKONTO' => 0.0,
                'ISKONTO_PAYI' => 0.0,
                'RUSUM' => 0.0,
                'TAKIM_STATUSU' => 0,
                'SOZLESME_SATIR_TURU' => 0,
                'KAP_KULLANIM_SEKLI' => 0,

                // Üç fiyat grubu: tutarlar burada hesaplanır (ekranda da öyle)
                ...$this->fiyatGrubu($satir, $miktar, 'birim_fiyati', 'birim_fiyati_para_id', 'birim_fiyati_kuru', ''),
                ...$this->fiyatGrubu($satir, $miktar, 'teklif_birim_fiyati', 'teklif_para_id', 'teklif_kuru', '2'),
                ...$this->fiyatGrubu($satir, $miktar, 'butce_birim_fiyati', 'butce_para_id', 'butce_birim_fiyati_kuru', '3'),
            ]);

            // Satırın teslim süresi ayrı iskele tablosunda (proc EVRAK_TURU=3
            // olarak TOHOM_EVRAK_DETAYI'ye kopyalar)
            $sure = $this->sayi($satir['teslim_suresi'] ?? null);
            if ($sure !== null) {
                $baglanti->table('TOHOM_ISKELE_EVRAK_DETAYI')->insert([
                    'GUID' => $guid,
                    'SATIR_NO' => $indeks,
                    'TESLIMAT_SURESI' => $sure,
                    'TESLIMAT_SURESI_BIRIMI' => $this->kimlik($satir['teslim_suresi_birimi'] ?? null),
                ]);
            }
        }
    }

    /**
     * ERP satırında fiyat üç kez tekrar eder: FIYAT/PARA_ID/KUR/TUTAR,
     * FIYAT2/PARA2_ID/KUR2/TUTAR2, FIYAT3/PARA3_ID/KUR3/TUTAR3.
     *
     * @param  array<string, mixed>  $satir
     * @return array<string, float|int|null>
     */
    private function fiyatGrubu(
        array $satir,
        ?float $miktar,
        string $fiyatAnahtari,
        string $paraAnahtari,
        string $kurAnahtari,
        string $sonEk,
    ): array {
        $fiyat = $this->sayi($satir[$fiyatAnahtari] ?? null) ?? 0.0;
        $kur = $this->sayi($satir[$kurAnahtari] ?? null) ?? 1.0;

        // Birinci grubun kolonları eksizdir: BIRIM_FIYATI / PARA_ID / KUR / TUTAR
        return [
            ($sonEk === '' ? 'BIRIM_FIYATI' : 'FIYAT'.$sonEk) => $fiyat,
            ($sonEk === '' ? 'PARA_ID' : 'PARA'.$sonEk.'_ID') => $this->kimlik(
                $satir[$paraAnahtari] ?? null,
            ) ?? self::YEREL_PARA_ID,
            'KUR'.$sonEk => $kur,
            // Ekranda gösterilen tutarla aynı formül
            'TUTAR'.$sonEk => ($miktar ?? 0.0) * $fiyat * $kur,
        ];
    }

    /**
     * @param  array<string, mixed>  $talep
     * @return array{donus_kodu: int, siparis_id: int, talep_no: string, onay_durumu: int}
     */
    private function procCagir(
        Connection $baglanti,
        string $guid,
        array $talep,
        int $erpKullaniciId,
        int $denetimIslemi,
    ): array {
        $evrakKonusuId = $this->evrakKonusuId($baglanti);
        $tarih = (string) ($talep['tarih'] ?? '');

        // Zorunlu ama bu ekranda kullanılmayan parametreler açıkça NULL geçilir;
        // proc adlandırılmış parametre beklediği için atlanamazlar.
        $parametreler = [
            'SIPARIS_ID' => null,
            'PARTI_YAMASI_ID' => $this->kimlik($talep['personel_id'] ?? null),
            'TIP' => self::TIP,
            'EVRAK_KONUSU_ID' => $evrakKonusuId,
            'SIPARIS_NO' => null,
            'TARIH' => $tarih,
            'OPSIYON_TARIHI' => $this->tarih($talep['termin'] ?? null),
            'KUR_TARIHI' => $tarih,
            'PARA_ID' => self::YEREL_PARA_ID,
            'KUR' => 1.0,
            'ONCELIK_ID' => $this->kimlik($talep['oncelik_id'] ?? null),
            'ACIKLAMA' => (string) ($talep['aciklama'] ?? ''),
            'ILGI_CINSI' => $this->kimlik($talep['ilgi_cinsi'] ?? null),
            'ILGILI_ID' => $this->kimlik($talep['ilgili_id'] ?? null),
            'TESLIMAT_YETKILISI_ID' => null,
            'TESLIMAT_ADRESI' => (string) ($talep['teslimat_adresi'] ?? ''),
            'TESLIMAT_ADRESI_ID' => $this->kimlik($talep['teslimat_adresi_id'] ?? null),
            'TESLIMAT_BICIMI' => $this->kimlik($talep['teslimat_bicimi'] ?? null) ?? 0,
            'TESLIMAT_SEKLI_ID' => $this->kimlik($talep['teslimat_sekli_id'] ?? null),
            'TESLIMAT_SURESI' => $this->sayi($talep['teslimat_suresi'] ?? null),
            'TESLIMAT_SURESI_BIRIMI' => $this->kimlik($talep['teslimat_suresi_birimi'] ?? null),
            'YOLA_CIKIS_TARIHI' => null,
            'YUKLEME_YERI' => null,
            'YUKLEME_TARIHI' => null,
            'VARACAGI_YER' => null,
            'TAHMINI_VARIS_TARIHI' => null,
            'NAKLIYATCI_ID' => null,
            'NAKLIYATCI_ACENTA_ID' => null,
            'NAKLIYAT_SIGORTASINI_USTLENEN' => null,
            'NAKLIYAT_SIGORTACISI_ID' => null,
            'NAKLIYAT_SIGORTACISI_ACENTA_ID' => null,
            'GUMRUKCU_ID' => null,
            'ULASIM_SEKLI_ID' => null,
            'ULASIM_DETAYI' => null,
            'HAKKINDA' => (string) ($talep['hakkinda'] ?? ''),
            'NOT1' => null,
            'NOT2' => null,
            'NOT3' => null,
            'ALIM_YERI' => $this->kimlik($talep['alim_yeri'] ?? null),
            'KULLANICI_ID' => $erpKullaniciId,
            'GUID' => $guid,
            'DENETIM_ISLEMI' => $denetimIslemi,
        ];

        // OUTPUT parametreleri PDO ile bağlamak yerine SQL yerel değişkenlerine
        // yazdırıp SELECT ile okuyoruz — sürücüden bağımsız ve tek gidiş dönüş
        $bagimsizlar = [];
        $degerler = [];
        foreach ($parametreler as $ad => $deger) {
            if (in_array($ad, ['SIPARIS_ID', 'SIPARIS_NO'], true)) {
                $bagimsizlar[] = "@{$ad} = @{$ad} OUTPUT";

                continue;
            }
            $bagimsizlar[] = "@{$ad} = ?";
            $degerler[] = $deger;
        }
        $bagimsizlar[] = '@ONAY_DURUMU = @ONAY_DURUMU OUTPUT';

        // SET NOCOUNT ON şart: proc içindeki INSERT/UPDATE'lerin "N rows
        // affected" sayaçları sürücüye boş sonuç kümesi gibi görünüyor ve
        // asıl SELECT'e sıra gelmiyor
        // Proc'un DÖNÜŞ KODU da okunur: 2 = kısıtlama var, kayıt yapılmadı.
        // Bu okunmazsa "onaya sunulmalı" durumu başarı gibi görünür.
        $sonuc = $baglanti->select(
            'SET NOCOUNT ON;
             DECLARE @SIPARIS_ID INT, @SIPARIS_NO VARCHAR(50),
                     @ONAY_DURUMU TINYINT = 0, @DONUS INT;
             EXEC @DONUS = SOHOM_SIPARIS_KAYDET '.implode(', ', $bagimsizlar).';
             SELECT @DONUS AS donus_kodu, @SIPARIS_ID AS siparis_id,
                    @SIPARIS_NO AS talep_no, @ONAY_DURUMU AS onay_durumu;',
            $degerler,
        );

        $ilk = (array) ($sonuc[0] ?? []);

        return [
            'donus_kodu' => (int) ($ilk['donus_kodu'] ?? 0),
            'siparis_id' => (int) ($ilk['siparis_id'] ?? 0),
            'talep_no' => trim((string) ($ilk['talep_no'] ?? '')),
            'onay_durumu' => (int) ($ilk['onay_durumu'] ?? 0),
        ];
    }

    /** Evrak konusu id'si ortama göre değişebilir; sabit yazılmaz */
    private function evrakKonusuId(Connection $baglanti): int
    {
        $id = $baglanti->scalar(
            'SELECT TOP 1 EVRAK_KONUSU_ID FROM TOHOM_EVRAK_KONUSU
             WHERE EVRAK_TURU = 2 AND ALT_TUR = 8 AND TEKLIFLERI_VAR IS NULL
             ORDER BY EVRAK_KONUSU_ID',
        );

        if ($id === null) {
            throw new RuntimeException('ERP\'de "Satınalma Talep Formu" evrak konusu bulunamadı.');
        }

        return (int) $id;
    }

    /** Hata durumunda iskelede satır bırakmayalım */
    private function iskeleyiTemizle(Connection $baglanti, string $guid): void
    {
        foreach (['TOHOM_ISKELE_ALIM_SATIM_SATIRI', 'TOHOM_ISKELE_EVRAK_DETAYI'] as $tablo) {
            try {
                $baglanti->table($tablo)->where('GUID', $guid)->delete();
            } catch (\Throwable) {
                // Temizlik başarısız olsa da asıl hatayı gölgelemesin
            }
        }
    }

    /** @param array<string, mixed> $talep */
    private function projemizId(array $talep): ?int
    {
        // Satırların projesi başlıktaki ilgili kayıttır — yalnız cins "Projemiz"
        // (7) iken proje anlamı taşır
        return ($talep['ilgi_cinsi'] ?? '') === '7'
            ? $this->kimlik($talep['ilgili_id'] ?? null)
            : null;
    }

    private function kimlik(mixed $deger): ?int
    {
        return is_numeric($deger) ? (int) $deger : null;
    }

    private function sayi(mixed $deger): ?float
    {
        return is_numeric($deger) ? (float) $deger : null;
    }

    private function tarih(mixed $deger): ?string
    {
        $metin = is_string($deger) ? trim($deger) : '';

        return $metin === '' ? null : $metin;
    }
}
