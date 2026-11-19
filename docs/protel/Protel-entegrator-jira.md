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

**PDF ek kuralı (PRT-09):** PDF yalnız Protel'de zaten "yazdırıldı"
(`isPrinted = 1`) görünen faturada istenir. PDF almanın `isPrinted`'ı değiştirip
değiştirmediği bilinmiyor; değişme ihtimali olan çağrı yapılmaz. Protel yazılı
olarak "değiştirmez" derse bu kural kaldırılabilir (kullanıcı kararı).

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
| PRT-01 | Keşif: test ortamı girişi, yanıt ve hata biçimleri | 0 | — | REVIEW_BEKLIYOR |
| PRT-02 | Keşif: PDF çağrısı ve durumun değişmediğinin kanıtı | 0 | PRT-01 | REVIEW_BEKLIYOR |
| PRT-03 | Yapılandırma ve Protel bağlantı tanımı | 1 | PRT-01 | REVIEW_BEKLIYOR |
| PRT-04 | Protel istemcisi ve iki katmanlı kilit | 1 | PRT-03 | REVIEW_BEKLIYOR |
| PRT-05 | Bağlantı sınama (Entegratör Bağlantıları ekranı) | 1 | PRT-04 | REVIEW_BEKLIYOR |
| PRT-06 | Veri modeli: Protel faturalarının saklanması | 1 | PRT-03 | REVIEW_BEKLIYOR |
| PRT-07 | Fatura listesini okuma (`ProtelFaturaKaynagi`) | 1 | PRT-04, PRT-06 | REVIEW_BEKLIYOR |
| PRT-08 | Senkron: ilk tarama, artımlı çekme, gece tazeleme | 1 | PRT-07 | REVIEW_BEKLIYOR |
| PRT-09 | PDF görüntüleme | 1 | PRT-02, PRT-04 | REVIEW_BEKLIYOR |
| PRT-10 | İzinler ve menü | 2 | PRT-03 | REVIEW_BEKLIYOR |
| PRT-11 | Mevcut ekranların adı: "İzibiz Gelen / Giden Faturalar" | 2 | — | REVIEW_BEKLIYOR |
| PRT-12 | Ekran: Protel Gelen Faturalar | 2 | PRT-08, PRT-09, PRT-10 | REVIEW_BEKLIYOR |
| PRT-13 | Ekran: birleşik gelen faturalar (İzibiz + Protel) | 3 | PRT-12 | BEKLIYOR |
| PRT-14 | Test tarafında uçtan uca kabul | 4 | PRT-12 | REVIEW_BEKLIYOR |
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

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** —
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

### PRT-02 — Keşif: PDF çağrısı ve durumun değişmediğinin kanıtı

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-01
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

### PRT-05 — Bağlantı sınama (Entegratör Bağlantıları ekranı)

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-04
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

### PRT-08 — Senkron: ilk tarama, artımlı çekme, gece tazeleme

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-07
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

---

## Faz 2 — Ekranlar

### PRT-10 — İzinler ve menü

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-03
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

### PRT-11 — Mevcut ekranların adı: "İzibiz Gelen / Giden Faturalar"

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** —
- **Kapsam:** Menü ve başlıklar (tr + en); Roller'de ekran adı "İzibiz
  e-Faturaları (Gelen/Giden)". Rota, izin ve davranış değişmedi.
- **Kabul ölçütleri:** [x] Yalnız metin; Roller testleri yeni adla güncel.
- **Canlı:** Evet
- **İnceleme günlüğü:**
  - 2026-11-19 Claude → REVIEW_BEKLIYOR (toplu teslim, §5)

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

---

## Faz 3 — Birleşik ekran

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

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** PRT-12
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
