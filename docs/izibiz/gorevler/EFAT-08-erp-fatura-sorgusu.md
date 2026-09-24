# EFAT-08 — ERP fatura sorgusu

- **Durum:** Devam — kaynak tablo belirlendi, keşif yapıldı; kodlama kullanıcı incelemesinden sonra
- **Bağımlılık:** EFAT-15
- **Skill:** laravel-best-practices, testing-best-practices

## Neden

Mutabakatın ERP tarafı: ERP'deki fatura sorgusuna göre gelen ve giden
faturaların listesi.

## Gereken girdi

- ERP fatura sorgusu / view adı ve kolonları (gelen ve giden için).
- ETTN ve GİB fatura numarasının hangi kolonda tutulduğu (S3).
- Şirket/dönem filtresi, belge tarihi ve kayıt tarihi alanları, durumlar,
  iptal/iade anlamı, belge başına mı satır başına mı sonuç döndüğü.

## Kullanıcı girdisi (2026-09-24)

- Kaynak tablo: **`TOHOM_E_FATURA`**. `SELECT *` yapılmaz: ağır kolonlar
  seçilmez — `PDF_KODU` (varbinary(max)), `XSLT_KODU` (varchar(max); kullanıcı
  "XLST_KODU" dedi, gerçek adı XSLT_KODU) ve keşifte görülen `XML_KODU`
  (varchar(max)).
- Eşleştirme: `UUID` (ETTN). Değilse `FATURA_NO` + `FIRMA_VERGI_KIMLIK_NO`
  birlikte (yalnız fatura no ile eşleştirme yapılmaz — başka firmada aynı no olabilir).
- Mutabakat çalıştırılırken ERP ortamı (test/canlı SQL tanımı) ve entegratör
  ortamı (test/canlı İzibiz tanımı) **ayrı ayrı seçilebilmeli**; aktif
  ortamlardan bağımsız (ör. canlı entegratör + test ERP).

## Keşif (2026-09-24, Claude — test ERP FD_hom, salt okunur)

- 96 kolon; ağır üç kolon yukarıda. Kimlik: `E_FATURA_ID` (int).
- `UUID` uniqueidentifier NOT NULL — 4.503 satırın hepsi dolu ve **tekil**.
  MSSQL büyük harf döndürür; bizde ETTN küçük harf → harfe duyarsız karşılaştırma.
- `FATURA_NO` nvarchar(50) + `FIRMA_VERGI_KIMLIK_NO` nchar(50): birlikte
  tekrar eden grup **0**. nchar sağdan boşluklu → `RTRIM` gerekir.
- `MUSTERI_VERGI_KIMLIK_NO` 4.502/4.503 satırda `4550357065` (firmamız) →
  bu tablo test ERP'de **gelen** faturaları tutuyor; `FIRMA_*` = tedarikçi.
  Giden faturaların yeri belirsiz (soru).
- Yön/tür kolonları bu veride tek değerli: `GIB_TURU`=0, `GIB_TIPI`=12,
  `FATURA_TIPI`=0 (anlamları soru). `EMOR_STATU` 0 (4.365) / 3 (138),
  `GIB_STATU` NULL / 0.
- Tutarlar **float**: `TOPLAM_TUTAR`, `TOPLAM_VERGI`, `VERGI_DAHIL_TOPLAM`,
  `SON_TOPLAM`, `ISKONTO_TOPLAMI` → tutar karşılaştırması toleranslı olmalı.
- `TARIH` aralığı 2026-01-01 … 2026-08-05 (test ERP canlının eski kopyası gibi).
- İzibiz TEST hesabı özetleriyle ortak UUID: **0** — beklenen: İzibiz test
  hesabı örnek veri. Anlamlı mutabakat için canlı İzibiz verisi gerekir.

## Yapılacaklar

- [ ] `ErpFaturaKaynagi`: sorgu `MssqlBaglantiServisi::baglan($tanim)` üzerinden,
      EFAT-15'te sabitlenen tanımla, parametreli (tarih/şirket) ve salt okunur
      çalışır. Kullanıcıdan çalışma anında serbest SQL kabul edilmez.
- [ ] Sonuç EFAT-15'teki DTO'ya çevrilir; `erpKayitId` korunur. Satır/hareket
      birleştirmesi aynı faturayı çoğaltıyorsa belge düzeyinde doğru toplama
      yapılır; `distinct` veya `keyBy` ile veri kaybı gizlenmez.
- [ ] Fatura yönü ve iade işareti sorgu anlamıyla doğrulanır; yalnız SATIS/IADE
      metninden alış/giden yönü tahmin edilmez. ERP dönem ve arşiv tabloları
      varsa aynı aralığın tüm kapsamı kontrol edilir.
- [ ] MSSQL hatası boş başarı değildir. Zaman aşımı, adet/hacim sınırı ve
      tamlık bilgisi EFAT-07 ile aynı sözleşmede döner; kirli okuma ile
      tutarsız fatura durumunun mutabakata girmemesi değerlendirilir.
- [ ] Testler: sahte kaynakla (arayüz bağlanarak) — gerçek MSSQL'e bağlanmaz.

## Kabul kriterleri

- [ ] Test MSSQL'inde (FD_hom) seçilen aralığın listesi ERP ekranıyla tutuyor.
- [ ] Mapper testleri gerçek sözleşmeye göre sentetik kayıtları kapsıyor;
      yalnız kaynak arayüzünün fake olması sorgu/alan eşlemesini doğrulamış sayılmıyor.

## Sonuç

—
