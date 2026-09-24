# EFAT-07 — Entegratörden gelen/giden fatura listeleri

- **Durum:** Bitti (lokal) — faz 1, yalnız e-Fatura
- **Bağımlılık:** EFAT-02 (bitti), EFAT-04 (bitti)
- **Skill:** laravel-best-practices, testing-best-practices

## Neden

Mutabakatın entegratör tarafı: belirli bir tarih aralığında İzibiz'deki
gelen ve giden faturaların listesi.

## Yapılacaklar

- [x] `EntegratorFaturaKaynagi` arayüzü + `IzibizFaturaKaynagi` uygulaması:
      `gelenler(baslangic, bitis)` ve `gidenler(baslangic, bitis)`.
- [x] Sayfalama sonuna kadar izlenir; azami aralık ve iş sınırı konur.
      Sınıra ulaşmak başarılı tamamlanma sayılmaz: aralık bölünür veya
      çalışma eksik işaretlenir. İlk sayfa/tek çağrı yeterli kabul edilmez.
- [x] EFAT-15'in ortak sözleşmesine çevir; kaynak ID'sini koru. Beklenen
      alan/tür eksikse veri kalitesi hatası üret; kaydı sessizce düşürme.
- [x] Okuma sonucu kapsamı (şirket, ortam, belge türü/yön, tarih türü/aralığı),
      alınan sayfa/adet, başlama/bitme zamanı ve tamlık durumunu taşısın.
- [x] Okundu ve okunmadı dahil gereken bütün kayıtlar kapsansın; okundu,
      aktarıldı veya indirildi işaretleme çağrısı yapılmasın. Portal/ERP'deki
      mevcut aktarım süreciyle yan etki oluşturulmadığı teyit edilsin.
- [x] Kararlı sıralama ve kaynak ID'siyle tekrarları ele al; sayfalar arasında
      kayıt değişmesinin veri atlamasına etkisini EFAT-02 sözleşmesine göre yönet.
      Sayım tutmuyorsa kesin yokluk sonucu üretme.
- [x] Boş ama başarılı okuma ile hata/eksik yanıtı ayır; başarısızlıkta
      `[]` dönüp karşı kaynağı tek başına mutabakata sokma.
- [ ] (S2'ye göre) e-Arşiv giden faturalar da aynı DTO'ya çevrilir. — **S2 kararıyla faz 1 dışı** (yalnız e-Fatura).
- [x] Testler: kaydedilmiş gerçek yanıt örnekleriyle (kişisel veri
      maskelenmiş) `Http::fake()`.

## Kabul kriterleri

- [x] Test hesabında seçilen tarih aralığının listesi, portaldaki sayılarla tutuyor.
- [x] Çok sayfa, sayfa ortasında hata, iş sınırı, tekrar eden kaynak ID'si,
      bozuk alan ve boş başarı senaryoları kapsanmış; tamlık doğru raporlanıyor.

## Sonuç

2026-09-23 (Claude) — uygulandı (faz 1, e-Fatura):

- `App\Services\Entegrator\IzibizFaturaKaynagi::oku(tanim, yon, baslangic, bitis, tarihTuru)`
  → `FaturaOkumaSonucu`; kayıtlar `EntegratorFatura` (ortak biçim), yön `FaturaYonu`
  (Gelen=inbox, Giden=outbox). **Sapma:** ayrı `EntegratorFaturaKaynagi` arayüzü
  açılmadı — tek sağlayıcı var; ikinci entegratör gelince çıkarılır.
- Okuma: `GET /v1/einvoices/{inbox|outbox}`, `sort=asc`, `sortProperty=id`,
  `pageSize=100`, sayfalar `totalPages`e kadar; `dateType` DOCUMENT (varsayılan) |
  DELIVERY; aralık en çok 366 gün (config `azami_gun`), en çok 500 sayfa (`azami_sayfa`).
- Tamlık: HTTP/bağlantı hatası (sayfa ortasında dahil) **istisna** — boş liste
  dönmez; sayfa sınırı, sayım uyuşmazlığı (sayfalar arası kayma) ve bozuk kayıt
  sonucu `eksikNedeni` ile **eksik** işaretler. Bozuk kayıt düşürülmez,
  `hataliKayitlar` (kaynak ID + alan adları) olarak döner. Aynı kaynak ID tekil tutulur.
- Dönüşüm: tutar Türkçe metinden ondalık metne (`"5.155.262,20"` → `"5155262.20"`,
  float yok); ETTN küçük harfe; VKN metin; bilinmeyen değer null. `erpOkundu` ve
  `okundu` yalnız okunur.
- Yan etki güvencesi: istemci veri için yalnız GET yapar; test tüm isteklerin GET
  (veya token POST) olduğunu ve `read-flag` içeren istek gitmediğini doğrular.
- Testler: `IzibizFaturaKaynagiTest` (22) — tek/çok sayfa, giden kutusu, boş başarı,
  sayfa ortasında hata, bozuk sayfa, sayfa sınırı, kayma ile tekrar, bozuk kayıt,
  tutar biçimleri (9 durum), yalnız-GET güvencesi, geçersiz aralıklar. Backend 173
  test yeşil, Pint temiz.
- Canlı doğrulama (test hesabı, yalnız GET): gelen 20.03.2026 → 50/50; gelen
  16–22.03 → 151/151 (2 sayfa); giden 21–23.09 → 138/138; **Ocak 2026 gelen
  524/524 (6 sayfa, 1,4 sn), giden 811/811 (9 sayfa, 1,9 sn)** — hepsi tam, bozuk
  kayıt 0. Karşılaştırma API'nin `totalElements` değeriyle; portal ekranıyla elle
  karşılaştırılmadı.
- Açık: saklama ve zamanlanmış çekme EFAT-10; 01.01.2026'dan ilk tarama orada
  ay ay bölünerek yapılacak.

## Karar — "ERP okundu" bayrağı (2026-09-23, kullanıcı)

Modül İzibiz'deki `erpReadFlag` / `readStatus` bayraklarını **hiç değiştirmez** — kullanıcı teyidi: ERP bu bayrağı kullanıyor, tetiklenirse ERP faturayı almaz
(`erp-read-flag`, `portal-read-flag` uçları çağrılmaz). Bayrak listede
"ERP'ye aktarıldı mı" sütunu olarak **gösterilir ve raporlanır**; ERP'ye geçmemiş
gelen faturalar filtrelenebilir ve ileride alarm kuralı olabilir (EFAT-13).
