# Protel (Süper Entegratör) API notları

PRT-01 ve PRT-02 keşiflerinin kanıtlı sonuçları (2026-11-19). Bütün istekler
salt okumadır ve iki katmanlı kilitle yapıldı (scratchpad betikleri; şifre
stdin'den okundu, hiçbir yere yazılmadı). Fatura değerleri örnekte
sentetiktir; alan adları ve biçimler gerçektir.

## Adresler ve giriş

| | Giriş (token) | Fatura |
|---|---|---|
| Canlı | `https://authrestapi.protel.com.tr` | `https://einvoicerestapi.protel.com.tr` |
| Test | `https://authrestapitest.protel.com.tr` | `https://einvoicerestapitest.protel.com.tr` |

- Lokalden ve canlı sunucudan (10.2.30.67) dört adrese de erişim var (HTTP 200,
  kimliksiz `About` / `swagger.json`).
- **Test ortamında kullanıcımız yok:** tek deneme → HTTP 401,
  `result = LoginFailed`, `resultMessageCode = MSG.Auth.LoginFailed`. İkinci
  deneme yapılmadı (hesap kilitlenmesin). Kabul (PRT-14) lokal uygulama ↔
  canlı Protel ile yapıldı.
- `POST /v1/Auth/AccessToken`, gövde
  `{clientType: "WebService", eMail, password, customerIdentity}`.
  `customerIdentity` olarak VKN gönderilince de giriş başarılı (uygulama
  bunu gönderir ve yanıttaki firma VKN'sini tanımla karşılaştırır).
- Başarılı yanıt: HTTP 200, `isSuccessed = true`, `accessToken` (JWT, ~370
  karakter), `userInfo.loggedCustomerIdentity` / `loggedCustomerTitle`.
  Yanıtta **bitiş alanı yok**; bitiş JWT'nin `exp` alanında: alındıktan
  24 saat sonra. JWT yükünde `ClientType=WebService`, `UserType=ServiceUser`,
  `MasterUser=False`.
- Kimlik başlığı: `Authorization: Bearer <token>`.

## Fatura listesi — `POST /v1/Document/GetInvoiceList`

Gövde (uygulamanın gönderdiği, birebir):

```json
{
  "startDate": "2026-05-01T00:00:00",
  "endDate": "2026-05-31T23:59:59",
  "filterDateType": "DocumentDate",
  "documentDirection": "Incoming",
  "erpRecievedStatus": "All",
  "erpStatus": "All"
}
```

- `filterDateType`: `DocumentDate` (belge tarihi) ya da `CreateDate` (Protel'e
  ulaşma — İzibiz'deki `DELIVERY` karşılığı; artımlı senkron bunu kullanır).
- Sayfalama yok; 180 günde ~1600 fatura ~2,3 sn. Aralık 180 günü aşarsa:
  HTTP 400, `status = InputValidationFailed`, `resultMessage = "Filter date
  range can be up to 180 days"`.
- Durum süzgeci gönderilmezse bütün durumlar gelir. (`IncomingInvoiceStatus`
  enum'unda "All" yok — yani sunucu gönderilmeyen enum'u "verilmedi" sayıyor.
  Swagger şeması bunu gösteremiyor: Swashbuckle `$ref` alanlarına `nullable`
  koyamaz. Şemaya bakıp "zorunlu" sonucu çıkarılmaz.)
- **Tuhaflık:** başarılı yanıtta `isSuccess = false`, `status = "Success"`,
  `resultMessage = "Success"`. Başarı ölçütü HTTP 200 + `invoiceList` dizisi.
- Hatalı / eksik token: HTTP 401, gövde boş.

### Kayıt alanları ve eşleme

| Protel | Biçim (örnek) | eMOR (`efatura_faturalari`) |
|---|---|---|
| `id` | int (`12236857`) | `kaynak_id` — benzersizlik (bağlantı, yön, kaynak_id) |
| `invoiceUUID` | 36 karakter | `ettn` (küçük harf) — PDF bununla alınır |
| `documentId` | 16 karakter | `belge_no` |
| `issueDate` | `2026-05-25T00:00:00` | `belge_tarihi` (tarih kısmı) |
| `issueTime` | `2026-05-25T14:05:09` | `belge_saati` (saat kısmı) |
| `createAt` | `2026-05-29T18:48:37.402647` (ekisiz İstanbul saati) | `olusturma_zamani` (UTC'ye çevrilir) |
| `documentType` | `SATIS` | `fatura_tipi` |
| `profileId` | `TEMELFATURA` | `senaryo` |
| `documentCurrencyCode` | `TRY` | `para_birimi` |
| `payableAmount` | JSON sayı (`1786.0`) | `tutar` (ondalık metin) |
| `taxTotal` | JSON sayı (`401.98`) | `vergi_tutari` |
| `lineCount` | int | `satir_sayisi` |
| `supplierId` / `supplierTitle` / `supplierAlias` | VKN·TCKN / en uzun 111 kr / etiket | `gonderici_vkn` / `gonderici_unvan` / `gonderici_etiketi` |
| `customerId` / `customerTitle` / `customerAlias` | | `alici_*` |
| `status` / `statusDescription` | `Received` / `Alındı` | `durum` / `durum_aciklamasi` |
| `gibStatus` / `gibStatusMessage` | `"1200"` / `ZARF_BASARIYLA_ISLENDI` | `gib_durum_kodu` / `gib_durum_aciklamasi` |
| `isErpRecieved` | 0/1 | `erp_okundu` (otel ERP'si aldı mı) |
| `isRead` | 0/1 | `okundu` |
| `erpStatus` | `New` … `Booked` | `erp_durumu` (yeni kolon) |
| `isPrinted` | 0/1 | `yazdirildi` (yeni kolon) |
| `appRespMessage` | metin | `yanit_aciklamasi` |
| `despatchId` | metin | `irsaliye_no` |

`status` değerleri (180 gün): Received 759, AutomaticallyAccepted 407,
AcceptAnswerCompleted 325, RejectAnswerCompleted 84, PendingAnswer 35,
AcceptAnswered 4.

## PDF — `POST /v1/Document/GetInvoicePdf` (PRT-02)

- Çalışan gövde **yalnız dört alan**:
  `{uuidType: "DocumentUUID", uuid, documentDirection: "Incoming", outputType: "Pdf"}`.
  `set*` alanları gönderilmez (Protel'in yazılı teyidi 2026-11-19: gönderilmezse
  durum değişmez).
- Yanıt: HTTP 200, `outputDatas[0]` = base64 PDF (`%PDF-1.4`, ~50–80 KB),
  `invoices[0]` faturanın durumu. ~1,2 sn.
- `includeInvoiceBinaryData: true, compressedBinaryData: false,
  pdfContentType: "Normal"` eklenince HTTP 500 "Zip file cannot read…" döndü;
  uygulama bu alanları göndermez (izin listesinde de yoklar).
- **Hata gövdesi isteğin başlıklarını yansıtıyor** (`Headers` alanında
  `Authorization-Bearer eyJ…`): hata gövdesi hiçbir yere yazılmaz/gösterilmez
  (ProtelIstemcisi yalnız HTTP durumu ve kısa `status` kodunu taşır).
- Önce/sonra ölçümü (bütün gelen faturalar, `status`, `erpStatus`,
  `isErpRecieved`, `isRead`, `isPrinted`): 500 hatalı çağrı ve başarılı çağrı
  sonrasında **değişen fatura: 0**.
- **`isPrinted`:** portaldaki yazdırma bilgisidir (son 180 günde 441 fatura `0`).
  PDF görüntüleme/indirme yazdırma değildir (kullanıcı kararı 2026-11-19);
  uygulama bu alanı yalnız gösterir, PDF bütün faturalarda açılır.

## Uçtan uca kabul ölçümü (PRT-14, lokal uygulama ↔ canlı Protel)

| Adım | İstek | Sonuç |
|---|---|---|
| Önceki durum görüntüsü | 1 giriş + 2 liste | 2640 fatura (01.01.2026 – 19.11.2026) |
| Giriş (uygulama) | 1 | firma VKN 4550357065 doğrulandı |
| İlk tarama (`--baslangic=2026-01-01`) | 11 liste (ay başına 1) | 11/11 ay `tam`, 2640 yeni — görüntüyle birebir |
| Artımlı (`--gun=10 --tarih-turu=DELIVERY`) | 1 | `tam`, 111 güncellenen |
| Gece (`--gun=45`) | 2 | `tam`, 388 güncellenen |
| PDF (3 yazdırılmış fatura) | 3 | 3 × `%PDF-1.4` |
| Sonraki durum görüntüsü | 1 giriş + 2 liste | 2640 fatura, **değişen alan: 0**, yeni/kaybolan: 0 |

Lokal veritabanına girilen şifre kabul bitince boşaltıldı, token önbelleği
silindi, lokal `PROTEL_AKTIF` yeniden kapatıldı.
