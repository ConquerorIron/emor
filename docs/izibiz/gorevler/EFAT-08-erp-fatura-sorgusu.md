# EFAT-08 — ERP fatura sorgusu

- **Durum:** Bekliyor (sorgu kullanıcıdan gelecek — S3)
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
