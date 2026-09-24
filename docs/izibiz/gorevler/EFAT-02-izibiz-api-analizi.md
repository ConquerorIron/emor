# EFAT-02 — İzibiz fatura API dokümanı ve analizi

- **Durum:** Bitti (faz 1 — e-Fatura listeleme ve görüntüleme)
- **Bağımlılık:** —
- **Skill:** —

## Neden

Elimizde yalnız Authentication collection'ı var. Gelen ve giden fatura
listelerini çekmek için uç adreslerini, parametreleri, sayfalamayı ve
dönen alanları bilmemiz gerekiyor.

İzibiz'in açık Postman dokümanında incelemeye başlanabilir; export gelmesi
bütün analizin ön koşulu değil. Doğrulanan ilk bilgiler [API notlarında](../api-notlari.md).

## Gereken girdi

- Postman `iziapi` workspace'inden e-Fatura (gelen/giden) ve (S2'ye göre)
  e-Arşiv collection'larının export'u → bu klasöre.
- Test ortamında örnek fatura olup olmadığı (liste boş dönerse test nasıl yapılacak).

## Yapılacaklar

- [x] Gelen ve giden e-Fatura listeleme uçlarını çıkar: yöntem, URL,
      tarih filtresi, sayfalama, sıralama, azami sayfa boyutu.
- [x] Dönen alanları listele: ETTN/UUID, fatura no, tarih, gönderici ve
      alıcı VKN/unvan, tutarlar, para birimi, durum (kabul/red/yanıt bekliyor),
      senaryo (TEMEL/TICARI), tip (SATIS/IADE...).
- [ ] Hız sınırı, zaman aşımı ve hata gövdesi biçimini belirle.
- [x] Token uzatma (`PUT /v1/auth/token`) gerekli mi, yoksa yeniden token
      almak yeterli mi, karar ver.
- [x] `validity` saat dilimini, hata gövdesindeki kod alanını ve 100–104
      anlamlarını doğrula. Token başarısı ile fatura okuma yetkisini ayrı sınayacak sözleşme çıkar.
- [x] `DOCUMENT`/`DELIVERY` tarih farkını, sınırların dahil/hariç olmasını,
      kararlı sayfalamayı, toplam sayıyı ve geç gelen güncellemeleri incele.
      API'deki azami sayfa boyutu ile uygulamanın toplam iş sınırını karıştırma.
- [x] Listelemede okundu/indirildi/aktarılmış filtrelerinin varsayılanını ve
      yan etkileri teyit et. Mevcut ERP entegrasyonunun tükettiği kayıtları
      saklayan veya okundu işaretleyen yöntemler kullanılmasın.
- [x] Durum sözlüğünü ayrı boyutlarda çıkar: entegratörde mevcut, GİB iletim
      sonucu, alıcı yanıtı, iptal/red. Bilinmeyen kod normal başarı sayılmasın.
- [x] UUID/belge no ile tekil sorgu, kapsam dışına düşen kayıtları doğrulama
      ve S10 istenirse güvenli PDF/HTML/XML görüntüleme uçlarını araştır.
- [x] Test hesabıyla her uçtan birer gerçek yanıt al ve alan adlarını doğrula
      (token ve kişisel veri dosyaya yazılmaz).
- [x] Bulguları `docs/izibiz/api-notlari.md` dosyasına yaz.

## Kabul kriterleri

- [x] EFAT-07 için gereken her uç, parametre ve alan belgelenmiş.
- [x] Eşleştirme anahtarı adayları (ETTN, fatura no) API yanıtında doğrulanmış.

## Sonuç

2026-09-23: Açık eFatura dokümanı ve listeleme bölümüne ulaşıldı; API
notları eklendi. Hesap üstünde fatura uçları ve yanıt şemaları henüz sınanmadı.

2026-09-23 (Claude): kimlik doğrulama kısmı test hesabında doğrulandı ve
`api-notlari.md`'ye yazıldı — `validity` İstanbul saati, token ömrü 12 saat,
kimlik hatası HTTP 401 + `"10004"` (100–104 değil), geçersiz token 403 boş gövde,
uzatma çağrısı süreyi uzatmıyor (kullanılmayacak). EFAT-04 bu bulgularla yazıldı.
Listeleme uçları, alanlar ve durum sözlüğü (EFAT-07 öncesi) açık.

2026-09-23 (Claude): listeleme keşfi. Postman dokümanı oturumsuz okunamıyor
(JS ile çiziliyor, iç API kapalı); test API'deki Swagger'ın tanım dosyaları kapalı;
GitHub deposu yalnız SOAP servislerini anlatıyor. GET yoklamasıyla `/v1/einvoices`
ve `/v1/einvoices/incoming` uçlarının var olduğu ama GET kabul etmediği görüldü.
POST durum değiştirebileceği için parametreler görülmeden denenmedi. Ayrıntı:
`api-notlari.md` → "e-Fatura uçları keşfi". Kapsam kararı: yalnız e-Fatura (S2);
PDF görüntüleme uçları da gerekli (S10).

2026-09-23 (Claude) — tamamlandı (faz 1 kapsamı):

- Kaynak: kullanıcının eklediği `eFatura API.postman_collection.json`
  (içinde bizim kimlik bilgimiz yok; örnek token İzibiz'in, süresi dolmuş).
- Test hesabında yalnız GET ile doğrulandı: `GET /v1/einvoices/inbox` ve
  `/outbox` (tarih/durum/uuid filtresi, sayfalama, sıralama), `lookup-statuses`,
  `preview/pdf|html` (ve `preview/ubl` dokümanda). Ayrıntı: `api-notlari.md` →
  "e-Fatura listeleme ve görüntüleme — doğrulandı".
- Kritik bulgular: tarih aralığının iki ucu dahil; `DOCUMENT`/`DELIVERY` farklı;
  `amount`/`taxAmount` Türkçe biçimli metin; PDF/HTML görüntüleme `readStatus` ve
  `erpReadFlag`'i **değiştirmiyor**; `erp-read-flag`/`portal-read-flag` uçları
  bu modülde kullanılmayacak (mevcut ERP entegrasyonunu etkileyebilir).
- Açık kalan: hız sınırı (dokümanda yok; gözlenmedi), eşzamanlı yeni kayıt
  eklenirken sayfalamanın kararlılığı (senkronda `sortProperty=id asc` ve
  tekrar çalıştırmada upsert ile telafi edilecek — EFAT-07/10).
