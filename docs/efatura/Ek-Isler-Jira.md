# e-Fatura Ek İşler — Görev Panosu (EK)

Muhasebenin (Egemen Olta, 2026-11-24 / 25 mailleri) e-Fatura ekranları için
istediği ek işler. Kapanan Logo portalının faturaları ile GİB'den alınan
e-Arşiv faturaları ve e-SMM makbuzları da ekranlara girecek; yetkili kullanıcı
faturayı RED'e çekebilecek; elle yapılan her işlem kullanıcı bazında
kaydedilecek; portal bazında bir pano ve en sonda hatırlatma maili gelecek.

**Çalışma biçimi:** Claude Opus 5.5 kodlar, bütün görevler bitince Claude
Fable 5.2 toplu inceler (§2). Durum ve inceleme kayıtları bu dosyada tutulur.

---

## 1. Kaynak ve kararlar

### 1.1 Mail maddeleri → görevler

| Mail | İstek | Görev |
|---|---|---|
| a | Logo 2026 gelen fatura listesini içeri aktarma, "LOGO → Gelen Faturalar" | EK-05, EK-06, EK-07 |
| b | Yetkili kullanıcının faturayı RED'e çekmesi | EK-04 |
| c | SAP entegrasyonu | EK-11 (BEKLIYOR) |
| d, e | Kullanıcı açılması, entegratör RED'inin otomatik görünmesi | Tamamlandı (03c6b0f) |
| f | e-Arşiv faturaları / e-SMM makbuzları, aylık statik liste | EK-05, EK-06, EK-08 |
| g | Elle işlemlerin kullanıcı bazlı kaydı | EK-03 |
| h | "Şifremi unuttum" | **Yapılmayacak** (§1.2) |
| i | İşlenmemiş faturalar için hatırlatma maili | EK-10 (en son) |
| j | Hızlı filtrelerde RED faturalar gelmesin | EK-01 |
| k | Protel Gelen Faturalar'a hızlı filtreler | EK-02 |
| l | Protel Gelen Faturalar'a SAP ve eMOR kolonları | EK-02 |
| m | Portal bazında pano | EK-09 |

### 1.2 Kullanıcı kararları (2026-11-25)

- **h yapılmaz.** Şifreler eMOR'a aittir; uygulama şifre tutmaz ve ERP'ye yazmaz.
- **c beklemede.** SAP'den "işlendi mi" bilgisi gelmedi; gelirse yapılır (EK-11).
- **i en sona kalır** ama SAP beklenmez. Kuralların ekrandan yazılabildiği bir
  yapı istenir (mevcut alarm kuralları altyapısı).
- **Canlıya alma:** bu panodaki maddelerin **hepsi bitince** canlıya alınabilir
  (kullanıcı izni, 2026-11-25). Ara teslimlerde yalnız `./publish.sh`
  (commit + push); `deploy.sh` yok. Canlıya alırken yedekleme modülünün
  yayın sırası uygulanır: `.env` `YEDEKLEME_AKTIF=false` + `config:clear`,
  deploy, sonra `true` + `config:cache`.
- **Protel hızlı filtre çubuğunun yeri:** Tüm Faturalar'daki hizaya alınır
  (EK-02).

### 1.3 Değişmeyen kurallar

- Protel'de ve İzibiz'de hiçbir alan değiştirilmez; ERP (MSSQL) yalnız okunur.
  Bu panodaki bütün yazmalar uygulamanın PostgreSQL'ine yapılır.
- `docs/excel/` altındaki dosyalar gerçek fatura verisi ve şahıs TC kimlik
  numarası içerir. Repoya girmez (`.gitignore`'da). **Testlerde bu dosyalar ve
  içlerindeki gerçek ad / VKN / TCKN kullanılmaz;** test dosyası kod içinde
  uydurma veriyle üretilir (PhpSpreadsheet).
- VKN / TCKN, unvan ve fatura satırı günlüğe ve maile yazılmaz (D2).
- Yeni bağımlılık eklenmez (grafik kütüphanesi dahil; EK-09).

---

## 2. Çalışma kuralları

Durumlar: `YAPILACAK` → `DEVAM` → `REVIEW_BEKLIYOR` → (`DUZELTME` ⇄
`REVIEW_BEKLIYOR`) → `PASSED` → `CANLIDA`. `BEKLIYOR` dış bir ön koşul bekler.

Her kod görevi için ortak teslim ölçütleri:

- [AGENTS.md](../../AGENTS.md), [CLAUDE.md](../../CLAUDE.md) ve
  [Dikkat_Edilecekler.md](../../Dikkat_Edilecekler.md) uygulanır; ilgili
  skill'ler okunur.
- Mevcut e-Fatura altyapısı yeniden kullanılır (`App\Services\Entegrator\…`,
  `efatura_faturalari`, `features/efatura`). İkinci bir paralel modül ya da
  kopya sayfa yazılmaz (K10).
- Backend: `php artisan test` ve `vendor/bin/pint --test` temiz. Şema ya da
  sorgu değişikliğinde PostgreSQL ile de koşulur
  (`DB_CONNECTION=pgsql DB_DATABASE=erp_test php artisan test …`, T6).
- Frontend: `npm run typecheck`, `npm run lint`, `npm run test`,
  `npm run build`; metinler `tr.json` ve `en.json`'da.
- Regresyon testi düzeltmeden / özellikten önce kırmızı görülür (T5).
- Her görevin sonunda bu dosyada durum güncellenir, "Teslim notu" yazılır:
  ne yapıldı, hangi kontroller koşuldu, ne doğrulanamadı.

Sıra panodaki sıradır (§5). Bir görev, bağımlı olduğu görev bitmeden başlamaz.

---

## 3. Dosya bulguları (2026-11-25 incelemesi)

Dosyalar `docs/excel/` altında. Sayılar bu dosyalara aittir; içe aktarımın
kabul testinde aynı sayılar beklenir.

### 3.1 `HalicAB_GelenEFatura_Logo_2026.xlsx` — sayfa `LogoFt`

- Başlık 1. satırda, 60 kolon. **581 fatura**; 2…5381 arası 5.380 satır
  görünür ama 582. satırdan sonrası yalnız formül dolgusudur (`Fatura No` boş).
- Fatura tarihi 2026-01-01 … 2026-02-11 (Ocak 486, Şubat 95).
- `ETTN` (AO) 581 satırda dolu ve tekil; dosyada büyük harfli. `Fatura No`
  tek başına tekil değil (2 numara ikişer kez, farklı göndericilerde).
- `Alıcı VKN` (F) hep şirket VKN'si.
- `Uygulama Yanıtı` (AP) = `RED` olan **266** satır; kırmızı yazılı satırlarla
  birebir aynı, hepsi `TICARIFATURA`. Açıklama `Uygulama yanıt açıklaması` (AQ).
- Para birimi TRY 483, USD 60, EUR 38. `Kur` (AW) dövizlilerde dolu.
- AY…BG "TL …" kolonları ve `Boş` (AX) formüldür; alınmaz.
- `Oluşturma Tarihi` (A) metindir: `04.01.2026 18:49:46` (İstanbul saati).

| Excel kolonu | Hedef alan |
|---|---|
| ETTN (AO) | `ettn` (küçük harfe çevrilir) + anahtar |
| Fatura No (B) | `belge_no` |
| Fatura Tarihi (C) | `belge_tarihi` |
| Oluşturma Tarihi (A) | `olusturma_zamani` (İstanbul → UTC, G17) |
| Gönderici VKN / Adı (D, E) | `gonderici_vkn`, `gonderici_unvan` |
| Alıcı VKN / Adı (F, G) | `alici_vkn`, `alici_unvan` |
| Senaryo (H), Tür (I) | `senaryo`, `fatura_tipi` |
| Toplam Tutar (O) | `tutar` (ödenecek tutar; §4.2) |
| Vergi Toplamı (L) | `vergi_tutari` |
| Para Birimi (P) | `para_birimi` |
| Uygulama Yanıtı (AP), açıklaması (AQ) | `durum`, `yanit_aciklamasi` |
| Zarf yanıt kodu / açıklaması (AM, AN) | `gib_durum_kodu`, `gib_durum_aciklamasi` |

### 3.2 `HalicAB_GelenEArsivFTList_2026.xlsx` — sayfa `FtList`

- Başlık 1. satırda. **245 fatura**, 2026-01-05 … 2026-10-31.
- **ETTN yok.** `Fatura No` (D) tek başına tekil değil (80 ayrı değer; aynı
  numara 18 kez). `Vergi Kimlik/T.C. Kimlik Numarası` (C) + `Fatura No`
  birlikte tekil (245 / 245).
- C kolonunda 169 satır 11 haneli (TCKN), 76 satır 10 haneli (VKN).
- `İptal İtiraz Durum` (L) 9 satırda dolu: 5'i "İptal Edilmiştir" (tarihi M
  kolonunda, `21/01/2026` metin), 4'ü "Burada yok ama İPTAL/İtiraz Talep
  Yönetiminde İptal Gözüküyor" (tarihsiz). Kırmızı yazılı satırlar bunlardır.
- R…X kolonları formüldür, V kolonu `#REF!` hatası verir; alınmaz. `Sıra` (A),
  `Tesisat Numarası`, `Belge Türü`, iki `Boş` kolon ve `Kur` alınmaz.

| Excel kolonu | Hedef alan |
|---|---|
| Vergi Kimlik/T.C. Kimlik No (C) + Fatura No (D) | anahtar |
| Fatura No (D) | `belge_no` |
| Düzenleme Tarihi (E) | `belge_tarihi` |
| Ünvanı/Adı Soyadı (B), kimlik no (C) | `gonderici_unvan`, `gonderici_vkn` |
| Ödenecek Tutar (G) | `tutar` |
| Vergiler Toplamı (H) | `vergi_tutari` |
| Toplam Tutar (F) | `brut_tutar` (EK-05) |
| Para Birimi (I) | `para_birimi` |
| İptal İtiraz Durum (L), Tarihi (M) | `durum` = iptal, `yanit_aciklamasi` (metin + tarih) |

### 3.3 `HalicAB_GelenESMMMkList_2026.xlsx` — sayfa `Veriler`

- 1. satır birleşik başlık ("E-Serbest Meslek Makbuzlarım"), kolon adları
  **2. satırda**. **21 makbuz**, 2026-08-20 … 2026-10-30.
- **ETTN yok.** `Makbuz No` (D) 21 satırda tekil; anahtar yine kimlik no +
  makbuz no (aynı kural, e-Arşiv ile ortak).
- `Makbuz Tarihi` (E) metindir: `20/08/2026`.
- Unvan ve `Gönderim Şekli` sağdan boşlukla doldurulmuş; kırpılır.
- Tutar hücrelerinde formül var (`=F17*0.2`, `=50000+12500`): hesaplanmış
  değer okunur.
- Tutarlar kendi içinde tutarlı değil (bir satırda tahsilat = brüt + KDV −
  stopaj, başka satırda stopaj düşülmemiş). **Hiçbir tutar yeniden
  hesaplanmaz; dosyadaki değer yazılır.**
- 4 makbuz `KAGIT`; onlar da alınır.

| Excel kolonu | Hedef alan |
|---|---|
| Düzenleyen T.C./VKN (B) + Makbuz No (D) | anahtar |
| Makbuz No (D), Makbuz Tarihi (E) | `belge_no`, `belge_tarihi` |
| Düzenleyen Unvanı/Adı Soyadı (C), kimlik no (B) | `gonderici_unvan`, `gonderici_vkn` |
| Tahsilat Tutarı (J) | `tutar` |
| KDV Tutarı (H) | `vergi_tutari` |
| Brüt Ücret Tutarı (F) | `brut_tutar` |
| G.V. Stopaj Tutarı (G) | `stopaj_tutari` |
| Para Birimi (K) | `para_birimi` |
| İptal/itiraz Durumu, Tarihi (M, N) | e-Arşiv ile aynı kural (bugün hepsi boş) |

---

## 4. Ortak tasarım

### 4.1 Tek tablo, statik kaynak

Logo, e-Arşiv ve e-SMM belgeleri ayrı tablolara değil **`efatura_faturalari`**
tablosuna yazılır. Böylece Tüm Faturalar, eMOR eşleştirmesi, RED, İşlendi,
işlem geçmişi, pano ve hatırlatma tek koddan çalışır.

- Üç yeni sağlayıcı: `logo`, `earsiv`, `esmm`. Bunlar **statik**tir: senkronu,
  bağlantı sınaması, şifresi, token'ı yoktur; veri yalnız içe aktarımla gelir.
- Tanım kaydı `entegrator_baglantilari`'nda durur (faturaların yabancı anahtarı
  ve `SAGLAYICI_IFADESI` aynen çalışsın). Statik sağlayıcıları sayan tek yer
  olur (`EntegratorBaglanti::STATIK_SAGLAYICILAR`). Statik tanım ortamdan
  bağımsızdır, her zaman kapsamdadır.
- **G1 taraması zorunlu:** bağlantı tanımlarını ya da sağlayıcıları dolaşan her
  yer için "statik tanım burada ne yapmalı" kararı yazılır: Entegratör
  Bağlantıları ekranı (görünmez), senkron zamanlayıcısı ve komutları (atlar),
  bağlantı sınama, token önbelleği, alarm kuralları, tazelik paneli, Tüm
  Faturalar kapsam anahtarı (dahil), PDF / XML uçları (§4.5).

### 4.2 Anahtar ve zorunlu alanlar

Bugün `ettn` ve `kaynak_id` zorunlu, upsert anahtarı
`(entegrator_baglanti_id, yon, kaynak_id)`. Statik belgelerde entegratör kimliği
yok, e-Arşiv ve e-SMM'de ETTN de yok.

- `kaynak_id` ve `ettn` boş bırakılabilir olur. Alanlara başka anlam yüklenmez
  (uydurma kimlik, boş metin ETTN yazılmaz — K9).
- Yeni `belge_anahtari` (metin): Logo'da küçük harfli ETTN; e-Arşiv ve e-SMM'de
  normalleştirilmiş `kimlik no|belge no` (boşluksuz, büyük harf).
- Statik satırlar için koşullu benzersiz indeks:
  `(entegrator_baglanti_id, belge_anahtari) WHERE belge_anahtari IS NOT NULL`.
  Entegratör satırlarının mevcut benzersizliği korunur; NULL'ların benzersizliği
  bozmadığı iki veritabanında sınanır (G7). Her satırda ikisinden biri dolu
  olmalı (CHECK kısıtı).
- `tutar` bugün **ödenecek tutar**dır (`ProtelFaturaKaynagi`: `payableAmount`).
  Yeni kaynaklarda aynı anlamdaki kolon yazılır (§3 eşlemeleri). Yeni kolonlar:
  `brut_tutar`, `stopaj_tutari` (ikisi de boş bırakılabilir, `decimal(20,4)`).
- `ettn`'yi metin varsayan her yer taranır (`EmorIslenmeServisi::ettn()`,
  resource, Excel, arama, XML eşleştirme).
- Migration canlıdaki dolu tabloya uygulanacak: eski şema + örnek satırlarla
  yükseltme testi yazılır (K6). SQLite'ta `->change()` tabloyu yeniden kurar.

### 4.3 Bir faturanın sınıfı (tek tanım)

Hızlı filtreler, kolonlar, pano ve hatırlatma **aynı** tanımı kullanır. Tanım
tek yerde durur (`EFatura` sorgu kapsamları + PHP tarafında aynı kuralı veren
tek yöntem); ekranlar ve pano onu çağırır, kendi koşulunu yazmaz.

| Sınıf | Kural |
|---|---|
| **Red** | Kaynak RED'i **ya da** harici RED (EK-04). Kaynak RED'i: Protel `PROTEL_RED_DURUMLARI`; İzibiz gelen `REJECTED`; Logo `RED`; e-Arşiv / e-SMM iptal. |
| **İşlendi** | Red değil **ve** (eMOR `islendi` / `elle_islendi` **ya da** `sap_islendi = true`). |
| **İşlenecek** | Red değil, İşlendi değil. |

- Gizlenen (bize ait olmayan) faturalar bugünkü gibi varsayılan listede yoktur;
  sınıf sayılarına da girmez, panoda ayrı "Gizlenen" sayısı olarak görünür
  (§6 soru 4'ün varsayılanı).
- İzibiz'de RED sayılacak değerler koddan önce veriden doğrulanır (EK-01).

### 4.4 İçe aktarım kuralları

Kesinti modülündeki akış kullanılır: dosya → "Kontrol Et" (önizleme, hatalar
nedenine göre gruplu) → hatasızsa "Kaydet" → tek parti. Okuyucu mevcut
`Kesinti\IceAktarimTablosu`'dur (başlığı adından tanır, TR sayı / tarih);
kopyalanmaz. Yalnız `.xlsx` dosyası; yapıştırma yok.

- **Yeniden yükleme güvenlidir.** Anahtar eşleşirse dosyadan gelen alanlar
  güncellenir; elle verilmiş bilgiler (harici RED, `sap_islendi`, gizleme)
  **korunur**. Dosyada artık bulunmayan satır silinmez (aylık liste yalnız o
  ayı içerebilir).
- Önizleme sayıları: yeni / güncellenecek / değişmeyen / hatalı.
- Dosyadaki RED / iptal bilgisi `durum`'a yazılır (kaynak RED'i). Harici RED
  alanlarına içe aktarım dokunmaz. Renk okunmaz.
- Boş anahtar satırı (formül dolgusu) hata değildir, atlanır. Hata değerli
  hücre (`#REF!`) alınan bir kolondaysa satır hatasıdır.
- Tarih ve sayı tam biçim denetiminden geçer (G11); tutar float'tan geçmez (G14).
- Parti geri alma: partinin **eklediği** satırları siler; güncellediği
  satırları eski hâline döndürmez (ekranda yazılır). Elle işaret taşıyan satır
  varsa geri alma 409 döner ve kaç satırın engellediğini söyler (§6 soru 5).
- Dosya boyutu ve satır sınırı Kesinti içe aktarımıyla aynı kalıpla konur;
  5.380 dolgu satırlı Logo dosyası bellek sınırına takılmadan okunmalı.

### 4.5 Statik kaynaklarda olmayanlar

Senkron paneli, elle senkron ve XML düğmesi yok. PDF yalnız fatura eMOR
arşivinde bulunuyorsa açılır (`FaturaBelgeServisi::erpden`, ETTN'li belgeler);
bulunmuyorsa düğme "PDF yok" bilgisi verir, entegratöre istek gitmez.

---

## 5. Pano

| No | Görev | Mail | Bağımlı | Durum |
|---|---|---|---|---|
| EK-01 | Ortak RED tanımı; hızlı filtrelerde RED gelmesin | j | — | REVIEW_BEKLIYOR |
| EK-02 | Protel Gelen Faturalar: hızlı filtreler, SAP ve eMOR kolonları, çubuğun yeri | k, l | EK-01 | REVIEW_BEKLIYOR |
| EK-03 | İşlem geçmişi (kullanıcı bazlı kayıt) | g | — | REVIEW_BEKLIYOR |
| EK-04 | Yetkili kullanıcının RED'e çekmesi / geri alması | b | EK-01, EK-03 | REVIEW_BEKLIYOR |
| EK-05 | Veri modeli: statik kaynaklar | a, f | EK-03 | REVIEW_BEKLIYOR |
| EK-06 | İçe aktarım altyapısı (önizleme, parti, geri alma) | a, f | EK-05 | REVIEW_BEKLIYOR |
| EK-07 | Logo Gelen Faturalar | a | EK-04, EK-06 | REVIEW_BEKLIYOR |
| EK-08 | e-Arşiv / e-SMM Gelen Faturalar / Makbuzlar | f | EK-07 | REVIEW_BEKLIYOR |
| EK-09 | Pano (portal bazında) | m | EK-08 | REVIEW_BEKLIYOR |
| EK-10 | Hatırlatma maili (kural yapısı) | i | EK-09 | REVIEW_BEKLIYOR |
| EK-11 | SAP "işlendi" bilgisi | c | SAP ekibi | BEKLIYOR |
| — | "Şifremi unuttum" | h | — | YAPILMAYACAK |

---

## 6. Açık sorular

Egemen Bey'e sorulacak. **Cevap beklenmez:** her soru için aşağıdaki
varsayılanla kodlanır; cevap farklı gelirse ilgili görev güncellenir.

| # | Soru | Varsayılan |
|---|---|---|
| 1 | Pano örneğinde Logo "İptal 342", dosyada RED 266. Kalan 76 harici iptalin listesi var mı? | Liste gelmezse yetkili kullanıcı EK-04 ile elle RED'e çeker. |
| 2 | Pano örneğinde e-Arşiv "Gelen 97", dosyada 245 fatura + 21 makbuz. 97 nasıl sayıldı? | Pano dosyadaki bütün belgeleri sayar. |
| 3 | Pano örneğinde Portal Plus "Gelen 566"; Protel'de 2.600'den fazla fatura var. Hangi küme sayılıyor? | Seçilen tarih aralığındaki, gizlenmemiş bütün Protel faturaları. |
| 4 | Gizlenen (bize ait olmayan) faturalar panoda nasıl sayılsın? | Sınıflara girmez; ayrı "Gizlenen" sayısı. |
| 5 | e-SMM için "sil / düzelt" ne kadar gerekli? | Parti geri alma + yeniden yükleme ile düzeltme; tek satır silme yok. |
| 6 | RED olduğu hâlde eMOR'da işlenmiş fatura hızlı filtrelerde görünsün mü? | Maildeki istek uygulanır: RED hiçbir "işlenen / işlenmeyen" filtresinde görünmez. Pano bunları ayrı bir uyarı sayısı olarak gösterir (EK-09). |
| 7 | Hatırlatma mailinde fatura listesi mi, özet mi? | Özet + ekrana bağlantı (mevcut alarm maili kuralı: mailde fatura satırı, unvan, VKN yok). |

---

## 7. Görevler

### EK-01 — Ortak RED tanımı; hızlı filtrelerde RED gelmesin

- **Durum:** YAPILACAK · **Bağımlı:** —
- **Kapsam:**
  - §4.3'teki sınıf tanımı tek yerde: Red / İşlendi / İşlenecek sorgu kapsamları
    ve aynı kuralı satır için veren yöntem. `protelReddedildi()` bu tanıma taşınır.
  - Önce veri: lokal veritabanında `saglayici, yon, durum` dağılımı çıkarılır;
    İzibiz gelen faturada RED sayılacak değerler buna göre yazılır
    (`docs/izibiz/api-notlari.md`: Accepted, Rejected, Cancel, …; veride büyük
    harfli `REJECTED`). `Cancel` benzeri değerlerin RED sayılıp sayılmayacağı
    teslim notunda gerekçesiyle belirtilir.
  - "ERP İşlenmeyenler" ve "ERP İşlenenler" hızlı filtreleri (`emor=islenmemis`
    / `islenmis`) RED faturaları getirmez. Aynı filtreyle alınan Excel ve özet
    kartları da aynı sonucu verir.
  - Resource'ta tek `red` alanı: `{ var: bool, kaynak: 'entegrator' | 'harici' | null }`.
    `protel_red` kaldırılır, kullanan yerler taşınır (G1). Tüm Faturalar SAP
    kolonu ve Excel'de "Red" artık bütün sağlayıcılar için bu alandan gelir.
- **Kabul ölçütleri:**
  - [ ] RED tanımı tek yerde; ekran / Excel / özet onu çağırıyor (grep ile kanıt).
  - [ ] İzibiz ve Protel için: RED fatura iki hızlı filtrede de yok; filtresiz
        listede var.
  - [ ] Liste toplamı, özet kartı ve Excel satır sayısı aynı filtrede eşit.
  - [ ] PostgreSQL'de de geçer.
- **Dikkat:** G1, K10, T6.

### EK-02 — Protel Gelen Faturalar: hızlı filtreler, SAP ve eMOR kolonları

- **Durum:** YAPILACAK · **Bağımlı:** EK-01
- **Kapsam:**
  - **Çubuğun yeri (kullanıcı, 2026-11-25):** hızlı filtre çubuğu bugün filtre
    alanlarının altında; Tüm Faturalar'da başlığın hemen altında, senkron
    panelinin ve filtre alanlarının üstünde. Protel'de de aynı yere alınır
    (`ProtelFaturalariPage.tsx`; karşılaştırma `EFaturalarPage.tsx`).
  - Hızlı filtreler: "İşlenmeyenler" (sınıf İşlenecek), "İşlenenler" (sınıf
    İşlendi), mevcut "eMOR'da Kayıtlı Firmalar". İlk ikisi aynı alanı paylaşır,
    birlikte basılı olamaz.
  - **eMOR kolonu:** `emor_durumu` Protel faturaları için zaten hesaplanıyor
    (`EmorIslenmeServisi`, gelen yönde Protel dahil; Protel'de "havuzda" yok).
    Tüm Faturalar'daki rozetin aynısı gösterilir, sıralanır, Excel'e girer.
  - **SAP kolonu:** mevcut "İşlendi" anahtarı SAP işaretidir. Kolon başlığı
    "SAP" olur, anahtar aynı kalır; RED faturada anahtar yerine "Red".
  - 2026-11-18 kararı ("Protel ekranında eMOR karşılaştırması yok") bu istekle
    değişti; sayfa yorumu ve `docs/protel` içindeki ilgili cümle güncellenir (K11).
- **Kabul ölçütleri:**
  - [ ] Çubuk başlığın altında; iki ekranda aynı sırada.
  - [ ] Her hızlı filtre doğru parametreyi gönderir; tekrar basınca kalkar.
  - [ ] eMOR ve SAP kolonları listede ve Excel'de; eMOR kolonuna göre sıralama.
- **Dikkat:** F1 (yenilenirken Excel kapalı), K10 (Tüm Faturalar'daki rozet /
  düğme bileşenleri ortak kullanılır).

### EK-03 — İşlem geçmişi (kullanıcı bazlı kayıt)

- **Durum:** YAPILACAK · **Bağımlı:** —
- **Kapsam:**
  - Yeni tablo `efatura_islem_gecmisi`: fatura (silinince boşalan yabancı
    anahtar), belgenin kimlik kopyası (sağlayıcı, belge no, ETTN / anahtar —
    fatura silinse de kayıt okunur kalsın), işlem türü, önceki ve yeni değer,
    neden, kullanıcı (kimlik + o andaki ad), zaman.
  - İşlem türleri: RED'e çekme / geri alma (EK-04), İşlendi işaretleme /
    kaldırma, gizleme / geri alma, içe aktarım partisi ve geri alınması (EK-06).
  - **Salt eklemeli:** modelde güncelleme ve silme engellenir, ihlali sınanır (K3).
  - Kayıt, durum değişikliğiyle **aynı veritabanı işleminde** yazılır; biri
    olmadan diğeri kalmaz. Mevcut `denetim` günlük satırları yerinde kalır.
  - Mevcut gizleme ve İşlendi uçları da bu tabloya yazar.
  - Ekran: satırda "Geçmiş" penceresi (o faturanın kayıtları) ve ayrı
    "İşlem Geçmişi" ekranı (kullanıcı, tarih aralığı, işlem türü, belge no
    filtresi; sayfalı). İzin `efatura_islem_gecmisi.goruntule`.
  - Geçmiş günlük dosyalarından geriye dönük doldurma yapılmaz.
- **Kabul ölçütleri:**
  - [ ] Her elle işlem tek kayıt üretir; işlem geri alınırsa (hata) kayıt da yok.
  - [ ] Güncelleme / silme denemesi reddedilir.
  - [ ] Fatura silinince geçmiş kaydı kimlik kopyasıyla okunur.
  - [ ] Liste ucu sayfalı, N+1 yok; izinsiz kullanıcı 403.
- **Dikkat:** K3, T6 (yutulan yazım yok), G17 (zaman UTC).

### EK-04 — Yetkili kullanıcının RED'e çekmesi / geri alması

- **Durum:** YAPILACAK · **Bağımlı:** EK-01, EK-03
- **Kapsam:**
  - Yeni alanlar: `harici_red_zamani`, `harici_red_eden_id`, `harici_red_nedeni`.
    Senkron ve içe aktarım bu alanlara dokunmaz (gizleme alanları gibi).
  - Bütün **gelen** fatura ekranlarında (İzibiz gelen, Protel, Tüm Faturalar,
    Logo, e-Arşiv / e-SMM) satır işlemi: "RED'e Çek" (neden zorunlu, onay
    penceresi) ve "RED'i Geri Al". Giden faturalarda yok.
  - İzin ekran başına, mevcut kalıpla: `efatura.red`, `protel_faturalari.red`,
    `tum_faturalar.red` ve statik ekranların kendi izni.
  - Kurallar:
    - Kaynak zaten RED ise harici RED verilemez (409).
    - `sap_islendi` işaretli fatura RED'e çekilemez (409, "önce İşlendi'yi
      kaldırın"); işaret sessizce silinmez.
    - Harici RED'li faturada İşlendi anahtarı yerine "Red" görünür; İşlendi
      ucu 409 döner (bugünkü Protel davranışı bütün RED'lere genişler).
    - eMOR'da işlenmiş fatura RED'e çekilebilir (eMOR bilgisi ERP'nindir);
      pencere bunu uyarı olarak gösterir.
  - "Red" rozeti kaynağı ayırt eder (Entegratör / Harici); üzerine gelince
    harici RED'de kim, ne zaman, neden.
- **Kabul ölçütleri:**
  - [ ] Denetim ile yazım tek atomik adım (satır kilidi); art arda iki tıklama
        tek istek, tek geçmiş kaydı.
  - [ ] Senkron ve yeniden içe aktarım harici RED'i silmez.
  - [ ] Yukarıdaki her 409 kuralı için test.
  - [ ] RED'e çekilen fatura hızlı filtrelerden düşer, geri alınınca döner.
- **Dikkat:** G18, F5, K5 (ortak hata metni yeni ekranda doğru mu).

### EK-05 — Veri modeli: statik kaynaklar

- **Durum:** YAPILACAK · **Bağımlı:** EK-03
- **Kapsam:** §4.1 ve §4.2. Üç statik sağlayıcı tanımı, `belge_anahtari`,
  boş bırakılabilen `kaynak_id` / `ettn`, `brut_tutar`, `stopaj_tutari`,
  kısıtlar ve indeksler; G1 taraması ve her yer için karar.
- **Kabul ölçütleri:**
  - [ ] Eski şema + örnek satırlarla yükseltme testi: satırlar ve çocuk
        kayıtlar (işlem geçmişi) korunur.
  - [ ] Aynı statik anahtar iki kez yazılamaz; ETTN'siz iki satır yazılabilir;
        İzibiz / Protel upsert'i eskisi gibi çalışır — SQLite ve PostgreSQL.
  - [ ] Statik tanım Entegratör Bağlantıları ekranında yok, senkron komutları
        onu atlıyor, hiçbir dış istek çıkmıyor (`Http::assertNothingSent`).
  - [ ] G1 taramasının sonucu teslim notunda (yer → karar).
- **Dikkat:** K6, G7, K9, G14, supabase-postgres-best-practices (kısmi indeks, kısıt).

### EK-06 — İçe aktarım altyapısı

- **Durum:** YAPILACAK · **Bağımlı:** EK-05
- **Kapsam:** §4.4. Parti tablosu (`efatura_yuklemeleri`: kaynak, dosya adı,
  kullanıcı, zaman, yeni / güncellenen / değişmeyen sayıları), satırda partiyi
  gösteren bağ, önizleme ve kaydetme uçları, geri alma ucu, "Yüklemeler"
  penceresi. Kaynağa özgü kısım yalnız kolon eşlemesi ve satır kuralları olan
  ince bir tanımdır (Kesinti'deki `Icmal*IceAktarimi` kalıbı); Logo, e-Arşiv
  ve e-SMM aynı tabanı kullanır.
- **Kabul ölçütleri:**
  - [ ] Aynı dosya iki kez yüklenince ikincide yeni 0, güncellenen 0.
  - [ ] Yeniden yüklemede harici RED, `sap_islendi`, gizleme korunur.
  - [ ] Hatalı dosyada hiçbir satır yazılmaz; hata nedenleri gruplu.
  - [ ] Geri alma yalnız partinin eklediklerini siler; elle işaretli satır
        varsa 409.
  - [ ] Parti ve geri alma işlem geçmişine yazılır.
  - [ ] Kaydet yalnız hatasız "Kontrol Et"ten sonra açılır; kayıt sürerken
        düğme kilitli.
- **Dikkat:** G10, G11, G14, F5, D9, K10.

### EK-07 — Logo Gelen Faturalar

- **Durum:** YAPILACAK · **Bağımlı:** EK-04, EK-06
- **Kapsam:**
  - §3.1 eşlemesiyle Logo içe aktarımı. Alıcı VKN'si şirket VKN'si olmayan
    satır hatadır.
  - Menü: yeni "Logo" grubu → "Gelen Faturalar". İzinler
    `logo_faturalari.goruntule / ice_aktar / disari_aktar / isle / red / pdf`.
  - Ekran mevcut liste parçalarından kurulur; **statik kaynak ekranı tek
    bileşen + kaynak tanımıdır** (EK-08 aynısını kullanır). Protel sayfası
    kopyalanmaz (K10).
  - Kolonlar: eMOR, SAP (İşlendi anahtarı — uç statik kaynaklara genişler),
    Red, eMOR VKN Var; hızlı filtreler Protel ile aynı.
  - eMOR eşleştirmesi statik tanımları da kapsar: Logo'da ETTN ile, yoksa
    fatura no + gönderen VKN ile (mevcut kural).
  - Tüm Faturalar: Entegratör kolonu ve filtresi "Logo"yu da içerir.
- **Kabul ölçütleri:**
  - [ ] Gerçek dosya lokalde yüklenince 581 fatura, 266 RED, tarih aralığı
        2026-01-01 … 2026-02-11 (teslim notuna yazılır; dosya repoya girmez).
  - [ ] Dövizli faturada tutar ve para birimi dosyadaki gibi.
  - [ ] RED faturada İşlendi anahtarı yok; hızlı filtrelerde görünmez.
  - [ ] Testler uydurma veriyle üretilen küçük dosyayla.
- **Dikkat:** G17, T3 (dosya içeriği sınanır), T10.

### EK-08 — e-Arşiv / e-SMM Gelen Faturalar / Makbuzlar

- **Durum:** YAPILACAK · **Bağımlı:** EK-07
- **Kapsam:**
  - §3.2 ve §3.3 eşlemeleriyle iki içe aktarım. Dosya türü başlıktan tanınır;
    önizleme hangi tür olarak okunduğunu gösterir.
  - Menü: "E-Arşiv / E-SMM" grubu → "Gelen Faturalar / Makbuzlar". Tek ekran,
    "Belge Türü" kolonu ve filtresi (Fatura / Makbuz). Makbuzda brüt ve stopaj
    kolonları.
  - **Keşif (koddan önce, salt okuma):** birkaç gerçek fatura ve makbuz
    numarasıyla eMOR'da fatura no + VKN eşleşmesinin çalıştığı doğrulanır
    (`ErpFaturaSorgusu`'nun okuduğu kaynaklar). e-SMM makbuzları eMOR'da bu
    kaynaklarda görünmüyorsa eMOR kolonu makbuzda "—" kalır ve durum kullanıcıya
    bildirilir; gereken yeni `WEB_ERP_*` view'ı kullanıcıdan istenir.
  - Pano ve Tüm Faturalar'da bu iki sağlayıcı tek "E-Arşiv" satırı olarak görünür.
- **Kabul ölçütleri:**
  - [ ] Gerçek dosyalarla lokalde 245 fatura (9 iptal) ve 21 makbuz.
  - [ ] Aynı fatura numarası farklı gönderende ayrı satır.
  - [ ] Formüllü hücre hesaplanmış değeriyle; `#REF!` kolonu alınmıyor.
  - [ ] Keşif sonucu teslim notunda.
- **Dikkat:** T9 (eşleşme iddiası denemeyle doğrulanır), G11, K9.

### EK-09 — Pano (portal bazında)

- **Durum:** YAPILACAK · **Bağımlı:** EK-08
- **Kapsam:**
  - Menü: e-Fatura grubunda "Pano". İzin `efatura_panosu.goruntule`.
  - Yıl / tarih aralığı seçimi (belge tarihi). Portal satırları: Logo, Portal
    Plus (Protel), İzibiz, E-Arşiv, Toplam. Kolonlar: Gelen, İşlenen, İptal
    (Red), İşlenecek; ayrıca Gizlenen ve "RED ama eMOR'da işlenmiş" uyarı sayısı.
  - İşlenecek faturaların aylara göre dağılımı (portal × 12 ay) ve iki grafik
    (kayıt durumu dağılımı, aylara göre işlenecek). **Grafik kütüphanesi
    eklenmez;** SVG / CSS ile çizilir (`dataviz` skill'i okunur). Kütüphane
    gerekirse önce kullanıcı onayı.
  - Her sayı tıklanabilir: ilgili liste ekranı aynı filtreyle açılır (filtre
    adres çubuğundan okunur). Sayılar §4.3 kapsamlarından gelir; **pano sayısı
    ile açılan listenin toplamı eşit olmalı.**
  - İşlenecekler için gönderen firma bazlı döküm: firma, adet, para birimi
    başına tutar; Excel.
- **Kabul ölçütleri:**
  - [ ] Her hücre için: pano sayısı = aynı filtreyle liste `meta.total` (test).
  - [ ] Sayılar tek gruplu sorgudan; portal ya da ay başına ayrı sorgu yok.
  - [ ] Farklı para birimleri toplanmaz.
  - [ ] Açık / koyu tema ve dar ekranda okunur.
- **Dikkat:** F1, F2, T4, T12, vercel-react-best-practices.

### EK-10 — Hatırlatma maili (kural yapısı)

- **Durum:** YAPILACAK · **Bağımlı:** EK-09
- **Kapsam:**
  - Mevcut alarm kuralları altyapısına (`AlarmKurali`, Alarm Kuralları ekranı,
    `AlarmBildirimiGonder`) yeni kural türü: "İşlenmemiş fatura hatırlatması".
  - Parametreler ekrandan: bekleme günü (belge N günden eski ve hâlâ
    İşlenecek), gönderim günleri ve saati, kapsanan portallar, alıcılar.
    Birden çok kural tanımlanabilmeli (bugün tür başına tek satır; bu kısıt bu
    tür için kaldırılır ya da kurala ad verilir — karar teslim notunda).
  - İçerik: portal bazında adet, en eski belge tarihi, panoya / filtreli listeye
    bağlantı. Fatura satırı, unvan, VKN yok (§6 soru 7).
  - Koşul §4.3 "İşlenecek" kapsamını kullanır.
- **Kabul ölçütleri:**
  - [ ] Kural kapalıyken mail gitmez; açıkken belirlenen gün ve saatte bir kez.
  - [ ] İşlenecek fatura yoksa mail gitmez.
  - [ ] Mail gövdesinde VKN / unvan / fatura no yok (test).
  - [ ] Gönderim hatası kuralı durdurmaz, günlüğe yazılır, aynı gün ikinci
        kez gönderilmez.
- **Dikkat:** D8, G9 (ayar aralıkları), G17 (İstanbul saati).

### EK-11 — SAP "işlendi" bilgisi

- **Durum:** BEKLIYOR — SAP ekibinden dönüş bekleniyor (kullanıcı, 2026-11-25).
- **Kapsam (dönüş gelince):** ETTN (ETTN'sizlerde belge no + VKN) ile "işlendi
  mi" dönen salt okunur bir uç ya da view; `sap_islendi` bu kaynaktan dolar,
  elle "İşlendi" anahtarı o sağlayıcıda salt okunur olur.

---

## 8. Teslim ve inceleme günlüğü

Her görev bitince buraya eklenir (en yeni en altta): tarih, commit, yapılan,
koşulan kontroller, doğrulanamayanlar. İnceleme bulguları `EK-R1`, `EK-R2`…
olarak numaralanır.

- 2026-11-25 — Pano açıldı (Fable). Kod yok. `docs/excel/` `.gitignore`'a eklendi.
- 2026-11-25 — **EK-01…EK-10 toplu teslim (Opus), REVIEW_BEKLIYOR.** §6 soruları
  varsayılanlarıyla kodlandı.

  **Tasarımdan sapmalar (gerekçeli):**
  - *e-Arşiv / e-SMM tek sağlayıcı* (`earsiv`) + `belge_turu` ('fatura' | 'makbuz')
    kolonu; §4.1'deki iki sağlayıcı (`earsiv`, `esmm`) yerine. Ekran, pano ve Tüm
    Faturalar onları zaten tek "E-Arşiv" satırı olarak gösteriyor; tek tanım ekranı
    sıradan tek kaynaklı ekran yapar (çok tanımlı ikinci bir denetleyici gerekmez).
    Anahtar türü de içerir: `fatura|VKN|NO`, `makbuz|VKN|NO`.
  - *Sınıf süzgeci yeni parametre* (`sinif` = islendi | islenecek | red | red_islenmis);
    eski `emor=islenmemis/islenmis` kaldırıldı (K1). Hızlı filtre etiketleri aynı
    ("ERP İşlenmeyenler / İşlenenler"); RED faturayı getirmezler. "Havuzda Olmayanlar"
    (`emor=yok`) artık ayrı alanda: sınıfla birlikte basılabilir (AND). İzibiz ve Tüm
    Faturalar'a "RED Faturalar" hızlı filtresi eklendi (panonun İptal sayısının listesi).
  - *İşlendi işaretini kaldırmak RED'de serbest*; işaretlemek 409 (`FATURA_REDDEDILDI`).
    Portal sonradan red verirse eski işaret silinebilsin diye. Protel'in eski kodu
    `PROTEL_FATURA_REDDEDILDI` genelleşti (K5).
  - *Pano grafikleri*: örnekteki pasta yerine satır başına ince bölümlü çubuk +
    aylık tek serili sütun grafik (dataviz skill: pasta / halka önerilmez). Renkler
    skill'in doğrulanmış paletinin ilk üç dilimi; grafik kütüphanesi eklenmedi.
  - *Hatırlatma tek kural* (alarm kuralı türü `islenmemis_hatirlatma`; tür başına tek
    satır kısıtı korundu). Parametreler ekrandan: bekleme günü (fatura tarihinden),
    kaç günde bir, saat, portallar, alıcılar. Bildirim değerlendiricinin hesabına
    (aktif İzibiz tanımı) bağlanır; sayılar bütün kaynaklardan.
  - *Tüm Faturalar statik kaynakları her zaman kapsar*: entegratör tanımı hiç yokken
    de liste açılır (eski "ENTEGRATOR_AKTIF_YOK" artık çıkmaz; test güncellendi).

  **Yapılanlar (özet):** `EFatura` kapsamları (reddedilen / reddedilmeyen / islenen /
  islenecek / redAmaIslenen) tek tanım; `EFaturaElleIslemleri` (RED / İşlendi /
  gizleme, satır kilidi + geçmiş aynı işlemde); `efatura_islem_gecmisi` (salt
  eklemeli) + İşlem Geçmişi ekranı + satır "Geçmiş" penceresi; `efatura_yuklemeleri`
  + `EFaturaIceAktarimi` (önizleme, kilitli kayıt, geri alma 409 kuralı) + üç dosya
  türü sınıfı; statik tanım `EntegratorBaglantiServisi::statikTanim` (ilk istenişte);
  `EFaturaPanosu` (sayılar sınıf kapsamlarından, ay dağılımı, firma dökümü + Excel);
  izinler (RED her gelen ekranda ayrı; Logo / e-Arşiv ekran izinleri; pano; geçmiş);
  ön yüzde Protel sayfası genel `GelenFaturaEkrani`'na dönüştü, Logo ve e-Arşiv aynı
  bileşeni kullanır (K10).

  **G1 taraması (bağlantıları dolaşan yerler → karar):** senkron komutu / işi /
  denetleyicisi, bağlantı sınama, token önbelleği, Entegratör Bağlantıları ekranı,
  istisna kodu aktarımı, ERP okumadı / günlük özet alarmları — hepsi sağlayıcıyı
  adıyla (İzibiz / Protel) seçiyor: statik tanıma dokunmaz. Tüm Faturalar ve pano:
  statik tanımları kapsar (tek yardımcı `gelenTanimlari`). eMOR eşleştirmesi: statik
  tanımları kapsar, oluşturmaz; ETTN'siz belge yalnız no + VKN. PDF: statikte yalnız
  eMOR arşivi (`FATURA_BELGESI_YOK`), XML yok. Tüm Faturalar tazelik paneli: statik
  tanımı göstermez (senkronu yok).

  **Kontroller:** backend tam takım (SQLite) 1771 geçti; e-Fatura / alarm / içe
  aktarım / pano / geçmiş / migration testleri PostgreSQL'de de geçti. Yükseltme
  migration'ı yerel PostgreSQL'deki dolu fatura tablosunda çalıştı. Frontend tip,
  lint, vitest geçti. Gerçek dosyaların önizlemesi (yerel, yazılmadan): Logo 581
  fatura / 266 RED (7,5 sn), e-Arşiv 245 fatura / 9 iptal, e-SMM 21 makbuz — hatasız.

  **Doğrulanamayan:** EK-08 keşfi (e-Arşiv / e-SMM belgelerinin eMOR'da no + VKN ile
  bulunması) yerelde canlı ERP tanımı olmadığı için yapılamadı; canlıda liste
  yüklenince eMOR kolonu sonucu gösterir. Mailin gerçek SMTP ile gönderimi denenmedi
  (testlerde içerik doğrulandı).
