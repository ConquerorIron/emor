# Protel Entegratörü — Görev Panosu (PRT)

Otelin e-Fatura posta kutusundaki **gelen** faturaları eMOR'da göstermek.
Otel ile inşaat aynı VKN'yi kullanır ama posta kutuları ayrıdır. İnşaatınki
İzibiz'de, otelinki Protel'dedir. Tedarikçiler faturayı bazen yanlış kutuya
keser; muhasebe şu an Protel portalına elle bakıyor.

Bu dosya, uygulamanın önce test tarafında, sonra canlıda devreye alınması için
izlenecek yolu görev görev tutar. Çalışma biçimi
[Backup-Jira](../yedekleme/Backup-Jira.md) ile aynıdır: **Claude görevi
yazar, Codex inceler.** Bir görev, Codex `PASSED` verene kadar bitmiş sayılmaz.
Keşif bulguları: [api-notlari.md](api-notlari.md).

---

## 1. Değişmez kurallar

### 1.1 Altın kural: Protel'de hiçbir şey değiştirilmez

Kullanıcı kuralı, 2026-11-18. Hiçbir alan güncellenmez: okundu, "ERP aldı",
ERP durumu dahil. Fatura gönderilmez, kabul ya da red cevabı verilmez, taslak
onaylanmaz ya da iptal edilmez. "Mevcut değeri geri göndermek" de yazmadır ve
yasaktır. En küçük hata otelin işleyişini bozar.

Protel'in cevabına göre (2026-11-19, Aykun Turan) web servis kullanıcısına
yetki tanımlanamıyor. Elimizdeki kullanıcı **tam yetkilidir**. Protel tarafında
hiçbir fren olmadığı için tek koruma bizim kodumuzdur. Kilit **iki katmanlıdır**
(kullanıcı onayı 2026-11-19; `App\Services\Entegrator\ProtelIstemcisi`, Protel'e
istek çıkaran TEK sınıf):

1. **Metot izin listesi.** Yalnız aşağıdaki tablodaki yollar çağrılabilir.
   Listede olmayan her yol (bilinmeyen ve ileride eklenecek uçlar dahil) istek
   gönderilmeden kod içinde reddedilir. Kilit, token almadan da ÖNCE çalışır.
2. **Gövde alanı izin listesi.** Her metodun gövdesinde yalnız izinli anahtarlar,
   sabit alanlarda yalnız izinli değerler bulunabilir. `set` ile başlayan
   herhangi bir anahtar (`setErpStatus`, `setErpRecievedStatus`, `setIsRead`…)
   ya da listede olmayan bir anahtar varsa istek gönderilmez.

| Metot | Yol | İzinli gövde anahtarları | Neden güvenli |
|---|---|---|---|
| Giriş | `POST /v1/Auth/AccessToken` (auth sunucusu) | `clientType`, `eMail`, `password`, `customerIdentity` | Yalnız token verir |
| Fatura listesi | `POST /v1/Document/GetInvoiceList` | `startDate`, `endDate`, `filterDateType`, `documentDirection`, `erpRecievedStatus`, `erpStatus` | Süzgeç alanları; önce/sonra ölçümünde durum değişmedi |
| PDF | `POST /v1/Document/GetInvoicePdf` | `uuidType`, `uuid`, `documentDirection`, `outputType` | `set*` alanları gönderilmezse durum değişmez: Protel'in yazılı teyidi + PRT-02 ölçümü; kullanıcı onayı (2026-11-19) |

Sabit değerler: `clientType = WebService`, `documentDirection = Incoming`
(giden kapsam dışı), `erpRecievedStatus` / `erpStatus` = `All`,
`filterDateType` ∈ {`DocumentDate`, `CreateDate`}, `uuidType = DocumentUUID`,
`outputType = Pdf`.

**Asla çağrılmaz** (testte her biri için "istek gitmedi" sınanır):
`SendInvoice`, `SendAppResponse*`, `SendInvoiceResponse`, `SetStatus`,
`SetErpReceived`, `GetUnReceivedIncomingInvoice`,
`GetUnReceivedInvoiceTaxRepresentative`, `GetInvoice`,
`GetInvoiceForTaxRepresentative`, `GetDocumentToken`, `CancelDraftInvoice`,
`ApproveDraftInvoice`, `RePostApplicationResponse`, `/v1` önekisiz bütün
kopyalar (`/Document/...`). Saf okuma olan `GetInvoiceListStatus` de bugün
gerekmediği için izin listesinde değildir (durum gece tazelemesinde listeden gelir).

**PDF ve `isPrinted` (kullanıcı kararı 2026-11-19):** PDF'i görüntülemek ya da
indirmek yazdırma değildir; `isPrinted` portaldaki yazdırma bilgisidir ve
listede yalnız bilgi olarak gösterilir. PDF bütün faturalarda açılır; Protel'e
bu konuda soru sorulmaz (PRT-R2 bu kararla kapatıldı).

### 1.2 Sırlar

- Protel web servis şifresi repoya, bu dosyaya, günlüğe ya da hafızaya yazılmaz.
  Uygulamada **Ayarlar → Entegratör Bağlantıları → Protel** bölümünden girilir
  ve `encrypted` cast ile saklanır.
- Keşif betikleri şifreyi geçici bir dosyadan stdin ile okur. Dosya iş biter
  bitmez silinir. Şifre komut satırına ya da ortam değişkenine girmez (D2).
- Token ve şifre hata mesajına ve günlüğe yazılmaz. **Protel'in hata gövdesi
  isteğin başlıklarını (token dahil) yansıtıyor** (PRT-02): istemci hata
  gövdesini hiçbir yere taşımaz; yalnız HTTP durumu ve kısa `status` kodu.

### 1.3 İstek yükü

İzibiz'deki kural burada da geçerlidir: fatura başına istek atılmaz. Veri
yalnız liste ucundan gelir; senkron ay başına tek istek atar. PDF yalnız
kullanıcı tek bir faturayı açınca istenir (`throttle:efatura-pdf`). Günlük yük:
artımlı her 15 dk'da ≤ 2 istek (≤ 192/gün) + gece 2 istek + token günde ~1.

### 1.4 Ortamlar

| | Protel tarafı | Bizim taraf |
|---|---|---|
| **Test** | `authrestapitest` / `einvoicerestapitest` `.protel.com.tr` — erişilebilir ama **kullanıcımız yok** (PRT-01: `LoginFailed`) | Lokal (WSL) |
| **Canlı** | `authrestapi` / `einvoicerestapi` `.protel.com.tr` | erp.tersane-istanbul.com (sunucudan erişim var) |

Test ortamında kullanıcımız olmadığı için lokal uygulama da **canlı Protel'i**
okur (`ortam = canli` Protel tanımı). Bu, iki katmanlı kilit sayesinde
güvenlidir; PRT-14'te önce/sonra ölçümüyle doğrulandı.

### 1.5 Bilinen API tuhaflıkları

Ayrıntı ve kanıt: [api-notlari.md](api-notlari.md).

- Başarılı yanıtta `isSuccess = false` (başarı ölçütü HTTP 200 + `invoiceList`).
- `GetInvoiceList` sayfalama yapmaz; aralık en çok 180 gün (fazlası 400).
- Giriş yanıtında bitiş alanı yok; token 24 saatlik JWT (`exp`).
- Hata gövdesi istek başlıklarını yansıtır (§1.2).
- Swagger şeması enum alanlarının boş bırakılabildiğini gösteremiyor (T9).

---

## 2. Çalışma kuralları

### 2.1 Durumlar ve akış

`YAPILACAK` → `DEVAM` → `REVIEW_BEKLIYOR` → (`DUZELTME` ⇄ `REVIEW_BEKLIYOR`) →
`PASSED` → `CANLIDA`. `BEKLIYOR` dış bir ön koşul bekler; nedeni yazılır.

Akış, inceleme günlüğü biçimi ve önem dereceleri (P1 / P2 / P3) için
[Backup-Jira §1.3–§1.5](../yedekleme/Backup-Jira.md) geçerlidir. Farklar:

1. **Sıra.** İlk karar kodun BY-032'den sonra yazılmasıydı (2026-11-18). Kullanıcı
   2026-11-19'da "tüm maddeleri bitir, en son review yaptırırız" dedi: PRT-01…14
   tek seferde yazıldı ve toplu olarak incelemeye teslim edildi (§5).
2. **Canlıya alma yalnız kullanıcının "canlıya al" talimatıyla** (PRT-15).
   Canlıda Protel tanımını girmek ve `PROTEL_AKTIF`'i açmak da buna dahildir.
   `./publish.sh` (commit + GitHub'a gönderme) incelemeye teslimdir,
   canlıya alma değildir.
3. **Kapalıyken etkisiz.** `PROTEL_AKTIF` (modül anahtarı) varsayılan olarak
   **kapalıdır**. Kapalıyken Protel'e hiçbir istek gitmez — sınama, senkron ve
   PDF dahil; denetim tek giriş noktasında (ProtelIstemcisi). Zamanlayıcı
   satırları komutu çağırır ama komut istek göndermeden çıkar. Menü öğesi
   `protel_faturalari.goruntule` iznine bağlıdır; izin canlıda yalnız devreye
   almada verilir (sistem yöneticisi her izne sahiptir, ama kapalıyken liste boş
   ve panel "kapalı" der).

### 2.2 Ortak teslim ölçütleri (her kod görevi)

- [AGENTS.md](../../AGENTS.md), [CLAUDE.md](../../CLAUDE.md) ve
  [Dikkat_Edilecekler.md](../../Dikkat_Edilecekler.md) kuralları uygulanır;
  ilgili skill'ler okunur.
- Mevcut eFatura altyapısı yeniden kullanılır (`App\Services\Entegrator\…`,
  `efatura_faturalari`, `EntegratorBaglanti`, eFatura denetleyicileri,
  `features/efatura`). Paralel bir ikinci modül yazılmaz.
- Testlerde Protel'e gerçek istek gitmez (`Http::fake` + `Http::preventStrayRequests`).
- Backend: `php artisan test` geçer, `vendor/bin/pint --test` temiz. Şema
  değişikliğinde PostgreSQL ile de çalıştırılır:
  `DB_CONNECTION=pgsql DB_DATABASE=erp_test php artisan test`.
- Frontend (dokunulduysa): `npm run typecheck`, `npm run lint`,
  `npm run test`, `npm run build` geçer; metinler `tr.json` ve `en.json`'da.
- Yeni bağımlılık eklenmez.
- Hata gövdeleri API sözleşmesine uyar (`kod` alanı; `EntegratorHatasi`).

---

## 3. Pano

| No | Görev | Faz | Bağımlı | Durum |
|---|---|---|---|---|
| PRT-01 | Keşif: test ortamı girişi, yanıt ve hata biçimleri | 0 | — | PASSED |
| PRT-02 | Keşif: PDF çağrısı ve durumun değişmediğinin kanıtı | 0 | PRT-01 | PASSED |
| PRT-03 | Yapılandırma ve Protel bağlantı tanımı | 1 | PRT-01 | REVIEW_BEKLIYOR |
| PRT-04 | Protel istemcisi ve iki katmanlı kilit | 1 | PRT-03 | REVIEW_BEKLIYOR |
| PRT-05 | Bağlantı sınama (Entegratör Bağlantıları ekranı) | 1 | PRT-04 | PASSED |
| PRT-06 | Veri modeli: Protel faturalarının saklanması | 1 | PRT-03 | REVIEW_BEKLIYOR |
| PRT-07 | Fatura listesini okuma (`ProtelFaturaKaynagi`) | 1 | PRT-04, PRT-06 | REVIEW_BEKLIYOR |
| PRT-08 | Senkron: ilk tarama, artımlı çekme, gece tazeleme | 1 | PRT-07 | PASSED |
| PRT-09 | PDF görüntüleme | 1 | PRT-02, PRT-04 | REVIEW_BEKLIYOR |
| PRT-10 | İzinler ve menü | 2 | PRT-03 | PASSED |
| PRT-11 | Mevcut ekranların adı: "İzibiz Gelen / Giden Faturalar" | 2 | — | PASSED |
| PRT-12 | Ekran: Protel Gelen Faturalar | 2 | PRT-08, PRT-09, PRT-10 | REVIEW_BEKLIYOR |
| PRT-13 | Ekran: birleşik gelen faturalar (İzibiz + Protel) | 3 | PRT-12 | BEKLIYOR |
| PRT-14 | Test tarafında uçtan uca kabul | 4 | PRT-12 | BEKLIYOR |
| PRT-15 | Canlıya alma | 4 | PRT-14 | YAPILACAK |

**Kilometre taşları**

- ✅ PRT-02: PDF'in durum değiştirmeden alınabildiği kendi ölçümümüzle de kanıtlandı.
- ✅ PRT-14: lokal uygulama canlı Protel'den 2640 faturayı eksiksiz çekti,
  önce/sonra karşılaştırmasında değişen alan 0.
- ⏳ Codex incelemesi → kullanıcının "canlıya al" talimatı → PRT-15.

**Sonraya kalanlar (bu panonun kapsamı dışında):** mail alarmları (kullanıcı
kararı: "şimdilik yalnız ekran"); SAP ekibinden "otelde işlendi" bilgisinin
alınması (faz 2); Protel giden faturaları (kapsam dışı).

---

## 4. Görevler

Şablon: **Kapsam** (yapılan) · **Kabul ölçütleri** · **Canlı** · **İnceleme günlüğü**.

---

## Faz 0 — Keşif (kod yok)

### PRT-01 — Keşif: test ortamı girişi, yanıt ve hata biçimleri

- **Durum:** PASSED · **Bağımlı:** —
- **Kapsam:** Test ortamına tek giriş denemesi; canlıda giriş, 179 günlük liste,
  180 gün aşımı, geçersiz ve eksik token yanıtları; kilidin kendi kendini sınaması.
- **Kabul ölçütleri:**
  - [x] `docs/protel/api-notlari.md`: adresler, giriş, token ömrü, alan
        listesi ve eşlemesi, hata gövdeleri, tuhaflıklar.
  - [x] Test ortamı sonucu §1.4'e yazıldı (kullanıcı yok).
  - [x] Yapılan istekler: test girişi 1, canlı giriş 1, liste 4 (+ `isPrinted`
        dağılımı için giriş 1, liste 2).
- **Canlı:** — (yalnız okuma)
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    Keşif kaydı test hesabının bulunmadığını ve Protel yanıt sözleşmesini belgeliyor. Dış ölçümler yeniden çalıştırılmadı.

### PRT-02 — Keşif: PDF çağrısı ve durumun değişmediğinin kanıtı

- **Durum:** PASSED · **Bağımlı:** PRT-01
- **Kapsam:** Test ortamında kullanıcı olmadığı için doğrudan canlıda, en
  zararsız durumdaki tek faturayla (`Received` / `New` / `isRead=0` /
  `isErpRecieved=1`). İlk gövde (7 alan) 500 verdi; dört alanlı gövde PDF döndü.
  Her denemenin önce/sonra görüntüsü bütün faturalar için alındı.
- **Kabul ölçütleri:**
  - [x] Önce/sonra durum karşılaştırması api-notlari'nda: değişen 0.
  - [x] Çıkarılan PDF açılıyor (`%PDF-1.4`, 78 KB).
  - [x] Durum değişseydi iş dururdu — değişmedi.
  - [x] Açık kalan: `isPrinted`'ın PDF ile değişip değişmediği (§1.1 PDF ek
        kuralı; Protel'e soru taslağı kullanıcıya verildi).
- **Canlı:** — (yalnız okuma)
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    Önce/sonra ölçümü, denenen çağrıların kapsamı için incelendi. Yazdırılmamış faturadaki davranış açık ve PRT-R2 kapsamında.

---

## Faz 1 — Altyapı

### PRT-03 — Yapılandırma ve Protel bağlantı tanımı

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-01
- **Kapsam:**
  - `config/entegrator.php` → `protel`: test/canlı `auth_url` + `api_url`,
    `izinli_alan_adlari = ['protel.com.tr']`, `aktif =
    AcKapa::cevir(env('PROTEL_AKTIF'), false)`, saat dilimi, token payı, zaman
    aşımları, `azami_gun = 180`, `ilk_tarama_tarihi`.
  - `EntegratorBaglanti::SAGLAYICI_PROTEL`, `authUrl()`; Protel'de `apiUrl()`
    kayıtlı adresi hiç kullanmaz.
  - `EntegratorBaglantiServisi` sağlayıcı parametresi alır (varsayılan İzibiz —
    mevcut bütün çağıranlar değişmeden İzibiz'i görür); Protel'de adres ve
    İzibiz alanları saklanmaz.
  - Uçlar: `PUT /ayarlar/entegrator-baglantilari/protel/{ortam}`,
    `POST …/protel/{ortam}/sina`, `POST …/protel/aktif`
    (`ProtelBaglantiController`, `ProtelBaglantiGuncelleRequest` — kullanıcı
    e-posta, şifre, VKN); liste mevcut index'in `protel` bloğunda (`modul_aktif` dahil).
  - Ekran: Entegratör Bağlantıları'nda "Protel — otelin posta kutusu" bölümü
    (`ProtelBolumu.tsx`): aktif ortam seçimi (canlı onaylı), Test/Canlı formları.
- **Kabul ölçütleri:**
  - [x] `PROTEL_AKTIF` tanımsız → kapalı (index testi); kapalıyken sınama 409,
        istek yok. (`AcKapa` davranışı mevcut testlerde.)
  - [x] Protel tanımı kaydedilir; şifre yanıtta yok; gönderilen adres ve posta
        kutusu saklanmaz; İzibiz'in aktif tanımı değişmez.
  - [x] E-posta olmayan kullanıcı reddedilir (backend + frontend).
  - [x] İzibiz tanımlarının mevcut testleri geçiyor.
- **Canlı:** Evet (kapalıyken etkisiz)
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    Firma kimliği ile token ve kayıt kapsamı PRT-R1, başarılı kayıtta şifre alanının temizlenmesi PRT-R6 (§5).
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R1 (VKN), PRT-R6 (şifre alanı) düzeltildi (§5).

### PRT-04 — Protel istemcisi ve iki katmanlı kilit

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-03
- **Kapsam:** `ProtelIstemcisi`: kilit (yol + gövde alanı + sabit değer), modül
  anahtarı, adres alan adı denetimi, yönlendirme kapalı, token önbelleği (JWT
  `exp`, şifreli, kilitli yenileme), 401'de bir kez yenileme, giriş yanıtında
  firma VKN'si = tanım VKN'si denetimi, hata gövdesi taşınmaz.
- **Kabul ölçütleri:**
  - [x] "Asla çağrılmaz" listesinin her metodu, `/v1` önekisiz kopyalar,
        `GetInvoiceListStatus`, sondaki `/` ve uydurma yollar: istisna + hiç istek yok.
  - [x] `set*` (büyük/küçük harf), bilinmeyen alan, `Outgoing`, `erpStatus=New`,
        `Unreceived`, `outputType=Ubl`: istisna + hiç istek yok.
  - [x] Modül kapalı, İzibiz tanımı, izinsiz alan adı: hiç istek yok.
  - [x] İzinli çağrıda giden gövde ve `Bearer` birebir doğrulanır.
  - [x] 307 izlenmez (tek istek); 401'de bir kez yenileme; ikinci 401 kimlik hatası.
  - [x] Yansıtılan token hata mesajına/bağlamına girmez.
  - [x] Kırmızı görüldü (T5): ilk çalıştırmada 32 test düştü — kilit token
        almadan sonra çalışıyordu, yasak çağrıda giriş isteği gidiyordu (düzeltildi).
- **Not (G1):** 3xx yanıtta Laravel'in yeniden deneme koşuluna `null` geçtiği
  görüldü; aynı `fn (Throwable …)` kalıbı `IzibizIstemcisi`'nde de düzeltildi.
- **Canlı:** Evet (kapalıyken etkisiz)
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    Yasak yol ve alanlar girişten önce reddediliyor. VKN doğrulamasının token önbelleğiyle uyumu PRT-R1 (§5).
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R1: VKN değişince token sürümü artar, yeni girişte firma doğrulanır (§5).

### PRT-05 — Bağlantı sınama (Entegratör Bağlantıları ekranı)

- **Durum:** PASSED · **Bağımlı:** PRT-04
- **Kapsam:** Sına yalnız `AccessToken` çağırır (kaydedilmemiş form değerleri,
  önbelleksiz); boş şifre yalnız kullanıcı kayıtlıyla aynıysa kayıtlı şifreye
  düşer. Sonuç: firma unvanı + token bitişi.
- **Kabul ölçütleri:**
  - [x] Kapalıyken 409 ve istek yok; kayıtlı şifreyle başarı; kullanıcı
        değişince kayıtlı şifre gönderilmez (istek yok).
  - [x] Frontend: formdaki değerlerle sınanır, firma gösterilir.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    Bağlantı sınaması girişle sınırlı. Kapalı mod ve kullanıcı değişimi testleri geçti.

### PRT-06 — Veri modeli: Protel faturalarının saklanması

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-03
- **Kapsam (tasarım değişikliği):** Protel'in iç kimliği (`id`, tam sayı) var →
  `kaynak_id`'ye yazılır; mevcut `unique(bağlantı, yön, kaynak_id)` geçerli,
  ETTN için kısmi indeks GEREKMEDİ. Yeni migration yalnız iki boş olabilir kolon
  ekler: `erp_durumu` (string 32, Protel `erpStatus`) ve `yazdirildi` (boolean,
  `isPrinted`). Factory: `EntegratorBaglanti::protel()`, `EFatura::protel()`.
- **Kabul ölçütleri:**
  - [x] Mükerrer engeli: ikinci senkron yeni satır üretmez (test).
  - [x] K6: lokal `erp` veritabanında (38.994 İzibiz faturası) normal `migrate`
        → kolonlar geldi, satır sayısı aynı. (Aynı çalıştırmada lokalde bekleyen
        iki eski migration da uygulandı: pivot izni yeniden adlandırma ve
        yedekleme tabloları — yalnız lokal.)
  - [x] İzibiz ekranı Protel faturasını, Protel ekranı İzibiz faturasını
        göstermez (test). İzibiz alarm / eMOR / istisna işleri yalnız aktif
        İzibiz tanımıyla çalışır (kod: `aktif()` varsayılanı İzibiz).
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    Var olan şema, aynı tanımda firma değişikliğinde kayıtları ayırmıyor. PRT-R1 (§5).
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R1: geçmişi olan tanımda VKN değişikliği reddedilir (§5).

### PRT-07 — Fatura listesini okuma (`ProtelFaturaKaynagi`)

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-04, PRT-06
- **Kapsam:** Ortak sözleşme `FaturaKaynagi` arayüzüne çıkarıldı (İzibiz ve
  Protel uygular). Tek istek; aralık ≤ 180 gün (senkron zaten aya böldüğü için
  kaynakta parçalama gerekmedi — tasarım değişikliği). `DOCUMENT → DocumentDate`,
  `DELIVERY → CreateDate`. Tutar JSON sayısından ondalık metne (4 basamak).
- **Kabul ölçütleri:**
  - [x] Gerçek yanıt biçimiyle eşleme testi (111 karakterlik Türkçe unvan, G4).
  - [x] Giden gövde birebir; bozuk kayıt düşürülmez, okuma eksik sayılır.
  - [x] `invoiceList` yoksa yanıt geçersiz; 180 gün aşımı ve giden yön istek
        gönderilmeden reddedilir.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    PostgreSQL sayısal sınırlarında bozuk kayıt ayrımı için PRT-R5 (§5).
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R5: sayısal sınırlar PostgreSQL kolonlarına uyduruldu (§5).

### PRT-08 — Senkron: ilk tarama, artımlı çekme, gece tazeleme

- **Durum:** PASSED · **Bağımlı:** PRT-07
- **Kapsam:** `efatura:senkron --saglayici=protel` (yalnız gelen);
  `EFaturaSenkronServisi` kaynağı tanımın sağlayıcısına göre seçer; elle senkron
  işi Protel'de yalnız gelen. Zamanlayıcı: artımlı `--gun=10
  --tarih-turu=DELIVERY --aralik-denetimi` (dakikada bir çağrılır, aralık
  tanımdan, en az 15 dk) ve gece 03:30 `--gun=45`.
- **Kabul ölçütleri:**
  - [x] Modül kapalıyken komut istek göndermez, çalışma kaydı açmaz.
  - [x] İkinci senkron mükerrer üretmez, değişen durumu günceller.
  - [x] İzibiz senkronu Protel tanımını kullanmaz.
  - [x] Günlük istek sayısı §1.3'te.
- **Canlı:** Evet (kapalıyken etkisiz)
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    Zamanlayıcı, ilk tarama ve senkron akışının sağlayıcı seçimi incelendi. İlgili kaynak ve kayıt kapsamı düzeltmeleri PRT-06/07'de açık.

### PRT-09 — PDF görüntüleme

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-02, PRT-04
- **Kapsam:** `FaturaBelgeServisi`: Protel faturasında ERP arşivine bakılmaz;
  `yazdirildi !== true` ise Protel'e istek gitmeden 409
  `PROTEL_PDF_YAZDIRILMAMIS`; aksi hâlde dört alanlı `GetInvoicePdf`,
  `outputDatas[0]` base64 → `%PDF-` denetimi. Protel'de XML 404.
- **Kabul ölçütleri:**
  - [x] Giden gövde birebir; ERP arşivi çağrılmaz.
  - [x] Yazdırılmamış faturada istek yok; PDF olmayan yanıt 502 yanıt geçersiz.
  - [x] Protel'de giden liste ve XML uçları yok.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    PDF için son eşitlemedeki yazdırıldı bayrağının sınırı PRT-R2 (§5).

---

## Faz 2 — Ekranlar
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R2 kullanıcı kararıyla kapandı: "yazdırıldı" kısıtı kaldırıldı (§5).

### PRT-10 — İzinler ve menü

- **Durum:** PASSED · **Bağımlı:** PRT-03
- **Kapsam:** `protel_faturalari.goruntule / senkron / pdf / disari_aktar`
  (`App\Yetki\Izin`, Roller matrisinde e-Fatura'nın altında); rotalar
  `/api/v1/protel/*` aynı eFatura denetleyicilerine `saglayici=protel`
  varsayılanıyla bağlı; menü "Protel Gelen Faturalar" (`/efatura/protel`).
- **Kabul ölçütleri:**
  - [x] e-Fatura izni Protel'i, Protel izni e-Fatura'yı açmaz; PDF / Excel /
        senkron kendi iznini ister (403 testleri).
  - [x] Rol kataloğu testi güncellendi (33 ekran).
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    Fatura izinlerinin sağlayıcı ve işlem bazında ayrıldığı uygulama testleri geçti.

### PRT-11 — Mevcut ekranların adı: "İzibiz Gelen / Giden Faturalar"

- **Durum:** PASSED · **Bağımlı:** —
- **Kapsam:** Menü ve başlıklar (tr + en); Roller'de ekran adı "İzibiz
  e-Faturaları (Gelen/Giden)". Rota, izin ve davranış değişmedi.
- **Kabul ölçütleri:** [x] Yalnız metin; Roller testleri yeni adla güncel.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → PASSED · commit `4c92ccf`
    İzibiz menü ve başlık adları ile mevcut rotalar incelendi.

### PRT-12 — Ekran: Protel Gelen Faturalar

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-08, PRT-09, PRT-10
- **Kapsam:** `pages/ProtelFaturalari/ProtelFaturalariPage.tsx`. Ortak parçalar
  `features/efatura/`'ya taşındı (sayfalar birbirinden import etmez):
  `PdfGoruntuleyici` (PDF/XML uçları prop), `SenkronDurumuPaneli` (uçlar, izin,
  kapalı mesajı prop; "ERP Senkronla" İzibiz sayfasında `children`),
  `ListeParcalari` (özet kartları, metin hücresi, evet/hayır rozeti), `bicim`,
  `durumRenkleri` (Protel durumları eklendi). Kolonlar: fatura no, tarih, VKN,
  unvan, tip, tutar, para, KDV, alınma zamanı, durum, zarf durumu, Otel ERP
  Aldı, Otel ERP Durumu, Yazdırıldı, irsaliye, yanıt, senaryo, ETTN. eMOR,
  gizleme, XML yok. PDF düğmesi yazdırılmamış faturada kapalı ve açıklamalı.
- **Kabul ölçütleri:**
  - [x] Excel içeriği sınandı (backend, T3); Excel isteği filtre/sıralama/
        sayfa/kolonlarla (frontend, T1); PDF doğru id'yle (T1).
  - [x] İzinsiz kullanıcı PDF / Excel / senkron düğmelerini görmez.
  - [x] Yenilenirken Excel kapalı (F1).
  - [x] İzibiz ekranının mevcut testleri taşımadan sonra değişmeden geçiyor.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → CHANGES_REQUESTED · commit `4c92ccf`
    Ortam değişiminde Excel, varsayılan tarih ve kullanılmayan sorgular için PRT-R3, PRT-R4 ve PRT-R7 (§5).

---

## Faz 3 — Birleşik ekran
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · PRT-R3 (Excel ortamı), PRT-R4 (açılış tarihi), PRT-R7 (istisna sorgusu) düzeltildi (§5).

### PRT-13 — Ekran: birleşik gelen faturalar (İzibiz + Protel)

- **Durum:** BEKLIYOR — muhasebe kararı: "ERP'de olup iki kutuda da olmayan
  faturalar" gösterilecek mi; Protel satırında ERP durumu ne anlama gelir
  (2026-11-18 kararı: Protel faturaları eMOR'da yok).
- **Bağımlı:** PRT-12
- **Kapsam (taslak):** İki kaynağın gelen faturaları tek listede; kaynak
  kolonları (İzibiz / Protel); İzibiz satırlarında mevcut eMOR durumu.
  Karar gelince kapsam ve ölçütler yazılır. PRT-15 bu göreve bağlı değildir.
- **Canlı:** —
- **İnceleme günlüğü:**

---

## Faz 4 — Kabul ve devreye alma

### PRT-14 — Test tarafında uçtan uca kabul

- **Durum:** BEKLIYOR · **Bağımlı:** PRT-12
- **Ortam:** Lokal uygulama ↔ canlı Protel (test ortamında kullanıcı yok, §1.4).
- **Yapılan:** önceki durum görüntüsü (2640 fatura) → lokal `PROTEL_AKTIF=true`,
  canlı Protel tanımı uygulamanın servisiyle (şifre geçici dosyadan, şifreli
  kolona) → giriş (VKN doğrulandı) → ilk tarama → artımlı → gece → 3 PDF +
  yazdırılmamış faturada PDF reddi → sonraki durum görüntüsü. Ayrıntılı tablo:
  [api-notlari.md](api-notlari.md#uçtan-uca-kabul-ölçümü-prt-14-lokal-uygulama--canlı-protel).
- **Kabul ölçütleri:**
  - [x] eMOR'daki Protel fatura sayısı (2640) = Protel'in aynı aralık için döndürdüğü sayı.
  - [x] Durum görüntüsünde hiçbir mevcut faturanın alanı değişmedi (0 / 2640 × 5 alan).
  - [x] Bütün kontroller (§2.2) PostgreSQL dahil geçiyor.
  - [x] Temizlik: geçici şifre dosyası silindi, lokal şifre boşaltıldı, token
        önbelleği silindi, lokal `PROTEL_AKTIF=false`.
  - [ ] Kullanıcı ekranı gördü ve onayladı.
- **Canlı:** —
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)
  - 2026-11-19 Codex → BEKLIYOR · commit `4c92ccf`
    Lokal kabul ölçümü dokümanda. PRT-12 düzeltmesi ve kullanıcının ekran onayı bekleniyor.

### PRT-15 — Canlıya alma

- **Durum:** YAPILACAK · **Bağımlı:** PRT-14 · **Ön koşul:** Codex `PASSED` ve
  kullanıcının "canlıya al" talimatı (§2.1 madde 2).
- **Kapsam:**
  1. **Önce:** sunucudaki commit ile gidecek commit arası listelenir;
     birlikte gidecek başka işler (yedekleme, eFatura 86d6844 kilidi)
     kullanıcıya bildirilir. Yedekleme kapalıyken etkisizdir (Backup-Jira §2).
  2. `./publish.sh` → sunucuda `./deploy.sh`; "Deploy tamamlandı" satırı ve
     migrate çıktısı (yeni migration: `add_protel_alanlari…`) doğrulanır.
  3. Canlıdaki bütün gelen faturaların durum görüntüsü alınır (scratchpad betiği).
  4. Sunucu `.env`'ye `PROTEL_AKTIF=true`, `php artisan config:cache`.
  5. Kullanıcı **Entegratör Bağlantıları → Protel → Canlı** formuna kullanıcı,
     şifre ve VKN'yi girer (şifreyi Claude girmez); "Bağlantıyı Sına" başarılı;
     Protel aktif ortamı Canlı yapılır.
  6. İlk tarama elle: `php artisan efatura:senkron --saglayici=protel
     --baslangic=2026-01-01 --tetikleyen=ilk_tarama`; sayı Protel'le karşılaştırılır.
  7. Zamanlayıcının ilk artımlı çalışması başarılı görülür (`efatura_senkron_calismalari`).
  8. Roller ekranında muhasebe rollerine `protel_faturalari.*` izinleri verilir.
  9. 24 saat sonra (gece tazelemesinden sonra) durum görüntüsü yeniden alınır
     ve 3. adımdakiyle karşılaştırılır.
- **Geri dönüş:** sunucu `.env`'de `PROTEL_AKTIF=false` + `config:cache` →
  Protel'e istek anında kesilir. Saklanan satırlar zarar vermez.
- **Kabul ölçütleri:**
  - [ ] 2, 6, 7 ve 9. adımların çıktıları günlükte.
  - [ ] Durum görüntüsünde bizim kaynaklı değişiklik yok.
  - [ ] Muhasebe ekranı kullandı.
- **İnceleme günlüğü:**

---

## 5. Teslim günlüğü (toplu teslim, 2026-11-19)

- 2026-11-19 Claude → PRT-01…12 ve PRT-14 REVIEW_BEKLIYOR · commit: bu dosyayı
  içeren commit (`feat(protel): …`)
  - Backend: `php artisan test` 872/872 (SQLite); Protel + eFatura testleri
    PostgreSQL'de (`erp_test`) 161/161; `pint --test` temiz. (Projede phpstan yok.)
  - Frontend: typecheck temiz, lint temiz (4 uyarı önceden var, başka dosyalarda),
    vitest 317/317, build başarılı.
  - Gerçek ortam: PRT-14 tablosu (api-notlari.md).
  - Doğrulanamayan: canlı sunucuda çalışma (PRT-15'e kadar alınmaz); `isPrinted`'ın
    PDF ile değişip değişmediği (Protel'e sorulacak; uygulama güvenli tarafta).
  - Yeni dersler Dikkat_Edilecekler'e işlendi: G8 (kilit yan istekten önce),
    D2 (karşı tarafın hata gövdesi başlıkları yansıtabilir), A3 (Laravel
    retry koşulu 3xx'te null), T9 (Swagger nullable), T6 (SQLite uzunluk).

- 2026-11-19 Codex → CHANGES_REQUESTED · incelenen commit `4c92ccf`
  - **PRT-R1 [P1] — VKN değişikliği, token ve fatura kapsamına yansımıyor (PRT-03/04/06).**
    [Bağlantı servisi](../../backend/app/Services/EntegratorBaglantiServisi.php#L173)
    aynı satıra yeni VKN'yi kaydediyor. Kimlik sürümü hesabında VKN yok
    ([satır 192](../../backend/app/Services/EntegratorBaglantiServisi.php#L192)).
    [İstemci](../../backend/app/Services/Entegrator/ProtelIstemcisi.php#L129)
    önbellekteki token'ı döndürüyor. Firma VKN denetimi yalnız yeni girişte
    çalışıyor. Liste ve Excel [bağlantı kimliğine](../../backend/app/Services/Entegrator/EFaturaSorgusu.php#L34),
    PDF de [aynı kimliğe](../../backend/app/Http/Controllers/Api/V1/EFaturaController.php#L193)
    bağlı. VKN değişince önceki hesabın faturaları yeni tanımda görünür.
    Kaynak kimlikleri çakışırsa [upsert](../../backend/app/Services/Entegrator/EFaturaSenkronServisi.php#L182)
    eski satırı da güncelleyebilir.
    **Düzeltme.** VKN değişince token sürümünü değiştir ve yeni girişte VKN'yi
    doğrula. Fatura veya çalışma geçmişi olan tanımda VKN değişikliğini
    reddetmek en küçük veri koruma adımı. Hesap değişimi gerekiyorsa geçmişi
    ayrı bir kapsamda tutan tanım kullan, devam eden senkronu da hesaba kat.
    **Doğrulama.** Sentetik iki firma ve sahte HTTP ile eski token'ın tekrar
    kullanılmadığını ve eski hesabın fatura, PDF ve çalışma kayıtlarının
    yeni kapsamla karışmadığını doğrula.
    **Dayanak.** PRT-04 firma doğrulaması ve testing-best-practices kapsam testi.

  - **PRT-R2 [P2] — PDF koruması son eşitlemede kaydedilen bayrağa bağlı (PRT-09).**
    [PDF servisi](../../backend/app/Services/Entegrator/FaturaBelgeServisi.php#L92)
    yereldeki `yazdirildi` üzerinden karar veriyor. [Zamanlayıcı](../../backend/routes/console.php#L45)
    artımlı olarak 10, gece 45 günü tarıyor. Fatura bu aralıklardan çıktıysa
    bayrak kendiliğinden tazelenmez. Protel'de `1` sonradan `0` olabiliyorsa
    mevcut kontrol §1.1'deki koşulu artık karşılamaz. Denemede yalnız zaten
    yazdırılmış faturalar kullanıldı. Ölçüm, bayrağın geri alınabilirliğini
    veya PDF'nin yazdırılmamış faturadaki etkisini belirlemiyor.
    **Düzeltme.** Yazılı “PDF bayrağı değiştirmez” teyidiyle konuyu kapat.
    Yanıt gelene kadar PDF’yi kapalı tutmak en küçük kesin koruma. Protel
    bayrağın kalıcı biçimde `1` kaldığını teyit ederse yerel kontrolün
    dayanağını kaydet. Bayrak geri alınabiliyorsa ayrı bir güncel okuma bile
    kontrol ile PDF isteği arasındaki değişikliği tek başına önleyemez.
    **Doğrulama.** Sahte istemcide yerel bayrak `true`, güncel bayrak
    `false`/bilinmiyor iken PDF çağrısı olmamalı. §1.1 tablosunda
    `set*` için bilinen davranışla açık `isPrinted` sorusunu netleştir.

  - **PRT-R3 [P2] — Ortam değişiminde Excel ekrandakinden farklı faturalar içerebilir (PRT-12).**
    [Liste](../../frontend/src/pages/ProtelFaturalari/ProtelFaturalariPage.tsx#L111)
    filtre, sıralama ve sayfa ile anahtarlanıyor. Ortam değişince [aynı sorgu
    yenileniyor](../../frontend/src/pages/ProtelFaturalari/ProtelFaturalariPage.tsx#L125).
    Eski veri `isPlaceholderData` sayılmadığı için [Excel düğmesi](../../frontend/src/pages/ProtelFaturalari/ProtelFaturalariPage.tsx#L359)
    açık kalabiliyor. Excel sunucuda [istek anındaki aktif ortamı](../../backend/app/Http/Controllers/Api/V1/EFaturaController.php#L62)
    okuyor. Muhasebe, ekranda Test satırları dururken Canlı satırları indirebilir.
    **Düzeltme.** Görünen listenin `kapsam.ortam` değeriyle Excel isteğini bağla.
    Sunucuda aktif ortam uyuşmazsa 409 dön ve liste yenilenirken düğmeyi kapat.
    **Doğrulama.** İki ortam ve geciken liste yanıtıyla senkronize geçişi sına.
    Eski liste açıkken yeni ortamdan Excel dönmemeli. Backend testi, ortam
    değişiminin istemciye henüz ulaşmadığı durumu da kapsamalı.
    **Dayanak.** Dikkat_Edilecekler F1 ve React sorgu anahtarı/veri kapsamı.

  - **PRT-R4 [P2] — Varsayılan tarih aralığı 2 Ocak 2027'de listeyi durduruyor (PRT-12).**
    [Sayfa](../../frontend/src/pages/ProtelFaturalari/ProtelFaturalariPage.tsx#L69)
    başlangıcı 1 Ocak 2026, bitişi bugün seçiyor. 366 günlük [sınır](../../frontend/src/pages/ProtelFaturalari/ProtelFaturalariPage.tsx#L42)
    aşılınca sorgu devre dışı kalıyor. Kullanıcı tarihleri düzeltmeden
    varsayılan ekranda fatura göremez. Aynı açılış kalıbı mevcut İzibiz
    ekranında da var.
    **Düzeltme.** Açılış başlangıcını ilk tarama tarihi ile bugünden
    `LISTE_AZAMI_GUN - 1` gün öncesinin geç olanına ayarla. Ortak tarih
    hesabını iki ekranda kullan.
    **Doğrulama.** 1 ve 2 Ocak 2027'ye sabitlenen saatle ilk yüklemede
    geçerli aralıkla liste isteği gönderildiğini doğrula.

  - **PRT-R5 [P2] — Bozuk satır sayısı PostgreSQL'de sağlam faturaların yazımını
    da engelliyor (PRT-07).** [Eşleyici](../../backend/app/Services/Entegrator/ProtelFaturaKaynagi.php#L127)
    `lineCount` için 4.294.967.295'e kadar izin veriyor.
    [Migration](../../backend/database/migrations/2026_09_23_145110_create_efatura_faturalari_table.php#L41)
    PostgreSQL'de `integer` üretiyor. Yerel PostgreSQL 16'da 2.147.483.648
    değeriyle `integer out of range` doğrulandı. Bu satır, kabul ölçütündeki
    eksik kayıt yoluna girmeden toplu `upsert` aşamasına ulaşır. Aynı
    [eşleyicideki tutar sınırı](../../backend/app/Services/Entegrator/ProtelFaturaKaynagi.php#L210)
    da `numeric(20,4)` ile uyumsuz. PostgreSQL 10¹⁵ değerini saklayabildiği
    hâlde kod reddediyor.
    **Düzeltme.** Eşleyicinin sayısal sınırlarını saklama sözleşmesine uydur.
    Geçersiz alanı toplu yazımdan önce hatalı kayıt olarak işaretle.
    **Doğrulama.** PostgreSQL testinde geçerli ve taşan satır sayısı bulunan
    iki faturayı birlikte eşitle. Geçerli fatura kaydedilmeli, çalışma
    `eksik` olmalı. Tutar için en küçük, en büyük ve yuvarlamayla sınırı
    aşan değerleri ayrı sınayıp aynı sözleşmeyi denetle.
    **Dayanak.** PRT-07 bozuk kayıt ölçütü ve PostgreSQL kolon türü.

  - **PRT-R6 [P3] — Yalnız şifre değişince formdaki değer temizlenmiyor (PRT-03).**
    [Form anahtarı](../../frontend/src/pages/EntegratorBaglantilari/ProtelBolumu.tsx#L50)
    kayıtlı şifre varsa aynı kalıyor. [Başarı akışı](../../frontend/src/pages/EntegratorBaglantilari/ProtelBolumu.tsx#L80)
    listeyi tazeliyor. Girilen şifre formda kalıyor ve sonraki kayıtta
    tekrar gönderilebiliyor.
    **Düzeltme ve doğrulama.** Başarılı kayıtta mevcut form API'siyle
    şifre alanını temizle. Yalnız şifre değiştiğinde alanın boşaldığını,
    kayıt hatasında kullanıcının yeniden deneyebildiğini sına.

  - **PRT-R7 [P3] — Protel listesi kullanılmayan iki istisna kodu sorgusunu
    çalıştırıyor (PRT-12).** [Ortak seçenek sorgusu](../../backend/app/Services/Entegrator/EFaturaSorgusu.php#L215)
    İzibiz'in iki istisna kolonunu da okuyor. Protel ekranı bu seçenekleri
    kullanmıyor. Tarih aralığındaki satırlar üzerinde fazladan iki sorgu
    çalıştığı kodda görülüyor. Gerçek veriyle gecikme ölçülmedi.
    **Düzeltme ve doğrulama.** Sağlayıcı kapsamı Protel ise istisna kodlarını
    boş döndür. Liste yanıtının mevcut şeklini koru ve İzibiz seçenek
    testlerini yeniden çalıştır.

  - **Olumlu kontroller.** Bilinen yasak yol ve üst seviye gövde
    alanları giriş isteğinden önce reddediliyor. Protel kapalıyken dış
    istek çıkmıyor. Ortamlar ayrı bağlantı kayıtlarına bağlı, sağlayıcıya
    özel rota izinleri sınanmış. Liste tek ayda tek Protel isteğiyle okunuyor.
  - **Yerel doğrulama.** WSL, PHP 8.5, Laravel 13.24 ve React/Vite.
    SQLite tam backend **872/872**. Ayrı PostgreSQL 16 kümesinde seçili
    Protel, kilit ve durum testleri **142/142**. Pint geçti. Frontend
    **317/317**, tür denetimi, lint ve build geçti. Lint aynı dört uyarıyı
    verdi. Build ana paketin 500 kB eşiğini geçtiğini bildirdi.
    Uygulanan skill'ler `code-review`, `laravel-best-practices`,
    `testing-best-practices`, `vercel-react-best-practices` ve
    `supabase-postgres-best-practices`. Canlı Protel'e istek gönderilmedi.
    PDF'nin `isPrinted` etkisi ve PRT-14 kullanıcı kabulü açık.

- 2026-11-19 Claude → REVIEW_BEKLIYOR · commit: bu düzeltmeyi içeren commit
  (`fix(protel): Codex 4c92ccf incelemesi …`)
  - **PRT-R1 kabul, düzeltildi.** `EntegratorBaglantiServisi`: Protel'de VKN
    değişince `kimlik_surumu` artar ve eski token önbellekten silinir → sonraki
    istek yeni giriş yapar, yanıttaki firma VKN'si tanımla karşılaştırılır.
    Fatura ya da (süren dahil) senkron çalışması olan Protel tanımında VKN
    değişikliği 422 ile reddedilir (`hata.protel_vkn_gecmisli`); başka firmanın
    kutusu ayrı tanım ister. İzibiz'de token VKN'ye bağlı olmadığı için mevcut
    davranış korundu (mevcut test). Testler: geçmişli tanımda red + VKN aynı;
    VKN değişince önbellekteki token kullanılmadı (2. giriş), yeni girişte
    `VKN_UYUSMUYOR`, liste isteği gitmedi. İkisi de eski kodda kırmızı.
  - **PRT-R2 kullanıcı kararıyla kapandı.** Kullanıcı (2026-11-19): "Biz
    yazdırmıyoruz ki, PDF download ediyoruz ya da görüntülüyoruz." `isPrinted`
    yazdırma bilgisidir; PDF görüntüleme onu kapsamaz. "Yazdırıldı" kısıtı ve
    `PROTEL_PDF_YAZDIRILMAMIS` kaldırıldı, PDF bütün faturalarda açılır (§1.1).
    `set*` alanlarının gönderilmemesi (yazılı teyit + PRT-02 ölçümü) ve iki
    katmanlı kilit aynen duruyor. Test: yazdırılmamış faturada PDF dört alanlı
    gövdeyle alınır (backend), düğme açık ve doğru id'yle çağırır (frontend).
  - **PRT-R3 kabul, düzeltildi (İzibiz ekranı dahil — G1).** Excel isteği
    ekrandaki listenin `kapsam.ortam`'ını gönderir; sunucuda aktif ortam farklıysa
    409 `ENTEGRATOR_ORTAM_DEGISTI` (dosya üretilmez). Düğme `isFetching` iken
    kapalı (yalnız yer tutucu veride değil — aynı anahtarla tazelenen ortam
    değişimi de kapsanır). `ortam` isteğe bağlıdır: yayın anında açık eski
    sekmeler bozulmasın. Testler: backend Test listesiyle Canlı aktifken 409,
    Canlı ile indirme; frontend iki ekranda Excel çağrısında ortam.
  - **PRT-R4 kabul, düzeltildi (İzibiz ekranı dahil).** Ortak
    `varsayilanBaslangic(bugun)` = ilk tarama tarihi ile `bugün − 365 gün`ün geç
    olanı (`features/efatura/bicim.ts`), iki ekranda kullanılır. Testler: yardımcı
    (2026-11-19, 2027-01-01, 2027-01-02, artık yıl) ve 2 Ocak 2027'ye sabitlenmiş
    saatle ilk liste isteği `2026-01-02..2027-01-02`.
  - **PRT-R5 kabul, düzeltildi (İzibiz okuyucusu dahil — G1).** `lineCount` üst
    sınırı PostgreSQL `integer` (2.147.483.647; Laravel `unsignedInteger` PG'de
    işaretli). Protel tutarı yuvarlamadan SONRA en çok 16 tam basamak
    (`numeric(20,4)`); 10¹⁵ ile 10¹⁶ arası artık kabul. Test (SQLite + PostgreSQL):
    sınırdaki geçerli fatura + taşan satır sayısı + taşan tamsayı tutar + yuvarlamayla
    taşan float tutar birlikte → geçerli fatura yazıldı, çalışma `eksik`, 3 hatalı kayıt.
  - **PRT-R6 kabul, düzeltildi.** Başarılı kayıtta `resetField('sifre')`. Test:
    yalnız şifre değişip kaydedilince alan boşalır.
  - **PRT-R7 kabul, düzeltildi.** Protel tanımında istisna kodu sorguları hiç
    çalışmaz, `istisna_kodlari: []` (yanıt şekli aynı). Test: istisna kodlu Protel
    faturasında boş liste. İzibiz seçenek testleri geçiyor.
  - **T5:** yeni backend testlerinin 5'i, frontend testlerinin 6'sı düzeltme
    öncesi kodla kırmızı görüldü.
  - **Kontroller:** backend 877/877 (SQLite), PostgreSQL seçili 306/306
    (Protel, eFatura, İzibiz okuyucu, durum/kilit, bağlantı), Pint temiz;
    frontend typecheck, lint (aynı 4 uyarı), vitest 320/320, build.
  - Dikkat_Edilecekler: G13 (önbellek anahtarı kimliği belirleyen her alanı
    kapsar), G14 (doğrulama sınırı = saklama kolonu sınırı), F1 genişletildi,
    F2 (sabit başlangıç + azami aralık), D6 genişletildi (BY-005-R12).
