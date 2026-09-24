# İzibiz API — ön inceleme notları

İnceleme: 2026-09-23. EFAT-02 (faz 1 — e-Fatura) bu dosyadaki doğrulamalarla
tamamlandı: kimlik doğrulama ve e-Fatura listeleme/görüntüleme uçları test
hesabında yalnız GET ile denendi. Aşağıdaki "keşif — export öncesi" bölümü
tarihsel kayıttır.

## Kaynaklar ve doğrulananlar

- Yerel `Authentication API.postman_collection.json`: müşteri token alma
  `POST /v1/auth/token`, uzatma `PUT /v1/auth/token`. Başarı örneği
  `data.accessToken`, `data.validity`, `data.customerType` ve `error` içerir.
  `validity` saat dilimsiz tarih/saat metnidir; süre birimi değildir.
- `mail.md`: test/canlı adresleri ve hesap bilgileri mevcut. Her işlemde
  token isteniyor. Gönderim örnekleri kullanılırsa `seriePrefix` kullanılmaması
  istenmiş; gönderim bu modülün ilk kapsamına dahil değildir.
- [İzibiz eFatura REST dokümanı](https://www.postman.com/iziapi/izibiz-api-v1/documentation/x8hmnip/efatura-api):
  `dateType=DOCUMENT` belge tarihi, `DELIVERY` entegratöre ulaşma/yüklenme tarihi;
  `startDate`/`endDate` gün biçiminde. Sayfa numarası 0'dan başlıyor;
  `pageSize` varsayılan 20, azami 100. `sort` ve `sortProperty` belirtilmiş.
  Sayfa sonunu belirleyen yanıt alanları ve istikrarlı sıralama ayrıca teyit edilecek.
- [Listeleme ve Sorgulama bölümü](https://www.postman.com/iziapi/izibiz-api-v1/folder/xquesuq/listeleme-ve-sorgulama)
  ve [e-Arşiv listeleme isteği](https://www.postman.com/iziapi/izibiz-api-v1/request/2goyvtn/e-ariv-fatura-listeleme)
  açık kaynak olarak bulundu. Gerekli ayrıntılar görünmezse export istenecek.

## Kimlik doğrulama — test hesabında doğrulandı (2026-09-23, Claude)

`https://apitest.izibiz.com.tr/v1/auth/token`, test hesabıyla (token ve şifre
dosyaya yazılmadı):

| Durum | HTTP | Gövde |
|---|---|---|
| Doğru bilgiler | 200 | `{data:{accessToken, validity, customerType, privileges}, error:null}` — `customerType` = `C` |
| Hatalı şifre / boş bilgiler | **401** | `{data:null, error:{code:"10004", message:"Kullanıcı adı veya şifre hatalı (hata kodu: 10004)", detail:"Bad credentials", group:"AUTHENTICATION"}, warning:null}` |
| Geçersiz Bearer token (`PUT`) | **403** | boş gövde |
| Geçerli token ile uzatma (`PUT`) | 200 | **aynı** token ve **aynı** `validity` döndü — süre uzamadı |

- **`validity` İstanbul saatidir (UTC+03:00), saat dilimi eki yok.** JWT `exp`
  23:24:41 UTC iken `validity` `2026-09-24 02:24:41`. Ayrıştırma
  `Europe/Istanbul` ile yapılır; güvenlik payı düşülür.
- **Token ömrü 12 saat** (JWT `iat`→`exp`). Koleksiyondaki 2021 örneği de ~12 saat.
- Hata kodları dokümandaki 100–104 değil; kimlik hatası **`10004`** (metin).
  Kod alanı metin olarak ele alınır; eşleme yapılamayan kod genel hata sayılır.
- Hata yanıtı HTTP 4xx ile gelir; ayrıca 200 içinde `error` dolu gelme
  ihtimaline karşı başarı yalnız `error === null` ve dolu `accessToken` ile kabul edilir.
- Uzatma çağrısı süreyi uzatmadığı için kullanılmaz; token bitince yeniden alınır.

## e-Fatura listeleme ve görüntüleme — doğrulandı (2026-09-23, Claude)

Kaynak: kullanıcının eklediği `eFatura API.postman_collection.json` (Postman
IZIBIZ API v1 export'u) + test hesabında **yalnız GET** çağrılarıyla doğrulama.
Collection'daki örnek token İzibiz'in `izibiz-entegrasyon` hesabına ait ve
süresi 2026-01-01'de dolmuş; bizim kimlik bilgimiz yok.

### Uçlar (faz 1 için kullanılacaklar)

| İş | Yöntem ve yol | Not |
|---|---|---|
| Gelen liste | `GET /v1/einvoices/inbox` | Filtre + sayfalama sorgu parametreleriyle |
| Giden liste | `GET /v1/einvoices/outbox` | Aynı parametreler |
| ETTN ile tekil | `GET /v1/einvoices/{inbox\|outbox}?uuid={ettn}` | Doğrulandı (1 kayıt) |
| Durum sözlüğü | `GET /v1/einvoices/{inbox\|outbox}/lookup-statuses` | Aşağıda |
| PDF | `GET /v1/einvoices/{inbox\|outbox}/{id}/preview/pdf` | Gövde `%PDF-1.4…`; Content-Type başlığı boş geliyor |
| HTML | `GET /v1/einvoices/{inbox\|outbox}/{id}/preview/html` | |
| UBL XML | `GET /v1/einvoices/{inbox\|outbox}/{id}/preview/ubl` | |

`{id}` İzibiz'in iç kayıt kimliğidir (listede `id`), ETTN değil.

### Parametreler

- `dateType` = `DOCUMENT` (belge tarihi) | `DELIVERY` (İzibiz'e ulaşma tarihi);
  `startDate`/`endDate` `YYYY-MM-DD`. **İki uç da dahil** (tek gün sorgusu o günü
  getiriyor). DELIVERY ile DOCUMENT farklı sonuç verir (20'sinde düzenlenen
  fatura 21'inde ulaşmış).
- `status` (isteğe bağlı), `uuid`.
- `page` (0'dan), `pageSize` (doküman: varsayılan 20, azami 100 — test hesabı
  500'ü de kabul etti; belgelenmemiş olduğu için **100 kullanılır**),
  `sort` = `asc|desc`, `sortProperty` (ör. `id`, `createDate`).
- Yanıt: `data.contents[]` + `data.pageable {page, size, totalElements, totalPages}`.
- `Accept-Language: tr-TR` → Türkçe hata/durum metinleri.

### Liste kaydı alanları (gelen ve giden aynı biçim)

`id`, `uuid` (ETTN), `documentNo`, `issueDate` (`YYYY-MM-DD`), `issueTime`,
`createDate` (yerel zaman, ekisiz), `documentType`/`invoiceType` (SATIS, IADE,
TEVKIFAT, ISTISNA…), `profile` (TEMELFATURA, TICARIFATURA, IHRACAT…),
`direction` (IN/OUT), `currency`, **`amount` ve `taxAmount` Türkçe biçimli
metin** (`"5.155.262,20"` — binlik `.`, ondalık `,`; float'a çevrilmez),
`lineCount`, `accountingSupplier{identifier,name,alias}`,
`accountingCustomer{…}`, `supplierSSN`/`customerSSN` (VKN/TCKN metin),
`documentStatus{value,label}`, `invoiceStatus`, `statusCode`/`statusCodeDesc`,
`statusDesc`, `envelope{identifier,gibStatusCode,gibStatusDescription}`,
`readStatus`, `erpReadFlag`, `responseDescription`, `note`, `orderReference`.

### Durum sözlüğü (`lookup-statuses`)

- Gelen: Accepted, Rejected, Cancel, Received, WaitingForResponse,
  ResponseTimeExpired, ResponseUnDelivered.
- Giden: Draft, InProcessing, Uploaded, WaitIdAssign, IdAssigned,
  IdAssignedFailed, AutoQueue, NotUploaded, Cancel, CancelDraft, Signed,
  Delivered, UnDelivered, SendToGib, SendToReceiver, ResponseTimeExpired,
  WaitingForResponse, Accepted, Rejected.
- Ayrı boyut: `envelope.gibStatusCode` (ör. 1300 "BAŞARIYLA TAMAMLANDI").

### Yan etki — ölçüldü

- PDF ve HTML görüntüleme (`preview/*`) sonrasında aynı faturanın `readStatus`
  ve `erpReadFlag` değerleri **değişmedi** (gelen ve giden için ayrı ölçüldü).
- **ASLA kullanılmayacak uçlar (kullanıcı teyidi 2026-09-23: `erpReadFlag`'i ERP kullanıyor; bayrak yalnız gösterilir ve raporlanır):** `POST /v1/einvoices/inbox/erp-read-flag/{READ|UNREAD}`
  ve `portal-read-flag` — mevcut ERP entegrasyonu yeni gelen faturaları
  `erpReadFlag` ile izliyor (teyit edildi); bu modül bu bayraklara dokunmaz. İstemci veri için yalnız GET yapar (`IzibizIstemcisi::getJson`); tek POST token alımıdır. Kabul/red,
  gönderme ve `POST .../download/*` (toplu indirme) da kullanılmaz.

## e-Fatura uçları keşfi — export öncesi (2026-09-23, Claude)

Postman dokümanı oturum açmadan okunamıyor (sayfa JS ile çiziliyor; iç API
anonim erişime kapalı). Test API'de Swagger arayüzü (`/swagger-ui.html`,
springfox 2.8) var ama `swagger-resources` ve `api-docs` kapalı (404).
GitHub `izibiz/api-documentation` deposu SOAP servislerini anlatıyor, REST v1'i değil.

Test hesabında yalnız GET ile yoklama:

| Yol | Sonuç | Yorum |
|---|---|---|
| `/v1/einvoices` | 500 "Request method 'GET' not supported" | Uç var, GET değil (muhtemelen POST) |
| `/v1/einvoices/incoming` | 500 "Request method 'GET' not supported" | Uç var, GET değil (listeleme değil; doğrusu `/inbox`) |
| `/v1/invoices/*`, `/v1/einvoice/*`, `/v1/incoming-invoices` | 404 "Servis kullanılmamaktadır" | Yok |

- Tanımsız yol HTTP 404 + `{"error":{"code":"404","group":"SYSTEM"}}`; yanlış
  yöntem HTTP **500** + `code:"-1"` döner (istemci 500'ü geçici hata sayıp
  yeniden denediği için yanlış yöntemle çağrı yapılmamalı).
- Listeleme uçları POST ile çalışıyor olabilir. POST durum değiştirebileceği
  (ör. okundu işaretleme) ve mevcut ERP entegrasyonunu etkileyebileceği için
  parametreleri dokümandan görülmeden **denenmedi**.
- **Gereken:** Postman `IZIBIZ API v1` çalışma alanındaki e-Fatura collection'ının
  (Gelen/Giden E-Fatura klasörleri, PDF/HTML indirme) export'u (S1).

## Henüz doğrulanmayanlar

- Gelen/giden liste ve kimlikle tekil arama uçlarının tam sözleşmesi,
  durum alanları, iptal/red görünürlüğü, okundu/indirildi filtrelerinin etkisi.
- Yeniden token almanın aynı hesabın diğer oturumlarını (ör. mevcut ERP
  entegrasyonu) düşürüp düşürmediği. İlk gözlem: yeni token alındığında önceki
  token'ın geçerliliği test edilmedi.
- Hesap kilitlenmesi: art arda hatalı şifre denemesinin hesabı kilitleyip
  kilitlemediği bilinmiyor; sınama ucu bu yüzden sıkı sınırlandırılır.
- Örnek token alma başarısı hesabın bütün fatura yetkilerini kanıtlamaz.
  `customerType` hesap tipidir; fatura erişimi ayrıca sınanacak.
- Tarih sınırlarının dahil/hariç oluşu, maksimum aralık, hız sınırı,
  toplam sayıları ve sayfalama sırasında eklenen kayıtların davranışı.
- PDF/HTML/XML erişimi ve yan etkileri, yalnız S10'da istenirse incelenecek.

## Uygulamaya etkisi

Belge tarihi ile ERP kayıt/işlenme tarihinin aynı olduğu varsayılmamalı.
Liste dışında kalan bir kayıt, karşı kaynakta kesinlikle yok sayılmaz;
aynı kapsam veya destekleniyorsa kimlikle ek sorgu gerekir. Başarısız/eksik
okuma boş listeye çevrilmez. Okundu işaretleme gibi mevcut entegrasyonun
işleyişini değiştirebilecek çağrılar bu modüle eklenmez.
