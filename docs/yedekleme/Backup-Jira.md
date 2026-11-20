# Backup Yönetimi — Görev Panosu (BY)

Yedekleme ve Geri Yükleme modülünün uygulama görevleri. **Tasarım:**
[README.md](README.md) — bu dosya tasarımı tekrar etmez, ona atıf yapar (§ ile).
Bir görevde tasarımla çelişen bir şey görülürse önce README düzeltilir.

Bu dosyayı iki yapay zekâ okur ve günceller: **Claude görevi yazar, Codex
inceler.** Bir görev, Codex `PASSED` verene kadar bitmiş sayılmaz.

---

## 1. Çalışma kuralları

### 1.1 Roller

| Rol | Yapar | Yapmaz |
|---|---|---|
| **Claude (geliştirici)** | Görevi kodlar, testini yazar, bu dosyada durumu günceller, inceleme notlarını değerlendirir ve düzeltir | Kendi görevine `PASSED` vermez |
| **Codex (inceleyici)** | Görevi kabul ölçütlerine, README'ye ve proje kurallarına göre inceler; bulgularını bu dosyaya yazar; `PASSED` ya da `CHANGES_REQUESTED` verir | Uygulama kodunu değiştirmez; geçici doğrulama testlerini bırakmaz |
| **Kullanıcı (Fatih)** | Görev sırasını ve açık kararları belirler; anlaşmazlıkta son sözü söyler; canlıda açma kararını verir | — |

### 1.2 Durumlar

`YAPILACAK` → `DEVAM` → `REVIEW_BEKLIYOR` → (`DUZELTME` ⇄ `REVIEW_BEKLIYOR`) → `PASSED` → `CANLIDA`

- `BEKLIYOR`: dış bir ön koşul bekleniyor (kullanıcı kararı, sunucu kurulumu). Nedeni yazılır.
- `IPTAL`: yapılmayacak; nedeni yazılır.

### 1.3 Akış

1. **Sıra.** Görevler numara sırasıyla yapılır. Bir görev, "Bağımlı" alanındaki
   görevlerin hepsi `PASSED` olmadan başlamaz. Aynı anda tek görev `DEVAM` olur.
2. **Başlarken (Claude).** Görevi ve atıf yaptığı README bölümlerini okur;
   "Ön koşul" varsa karşılandığını doğrular (karşılanmadıysa durumu `BEKLIYOR`
   yapar ve kullanıcıya sorar); durumu `DEVAM` yapar.
3. **Kapsam.** Yalnız görevin "Kapsam"ı yapılır. Sonraki görevin işi öne
   çekilmez; görev dışı düzeltme / refaktör yapılmaz. Kapsam yetersizse ya da
   tasarımla çelişiyorsa kodlamadan önce bu dosya ve README güncellenir.
4. **Teslim (Claude).** §2'deki ortak ölçütler ve görevin kabul ölçütleri
   sağlanınca kutucuklar işaretlenir, durum `REVIEW_BEKLIYOR` yapılır ve
   inceleme günlüğüne teslim satırı yazılır (commit, çalıştırılan kontroller,
   doğrulanamayan noktalar).
5. **İnceleme (Codex).** Teslim edilen commit aralığını inceler. Her bulgu için:
   önem (P1 / P2 / P3), dosya ve satır, sorun ve etkisi, önerilen en küçük
   düzeltme, nasıl doğrulanacağı. Sonuç: `PASSED` ya da `CHANGES_REQUESTED`.
   - **P1:** veri kaybı, güvenlik, yanlış davranış. **P2:** kabul ölçütü
     karşılanmıyor ya da önemli hata durumu sınanmamış. **P3:** öneri.
   - `PASSED` için açık P1 ve P2 kalmamalı ve kabul ölçütlerinin hepsi
     karşılanmış olmalı. P3'ler `PASSED`'ı engellemez.
6. **Düzeltme (Claude).** Her bulguyu **kodda doğrular**, körü körüne uygulamaz:
   - haklıysa düzeltir ve hangi commit'te düzelttiğini yazar,
   - haklı değilse gerekçesi ve kanıtıyla (dosya, satır, test çıktısı) reddeder.
   - bulgu başka yerde de tekrar edebilecek türdense dersi aynı commit'te
     [Dikkat_Edilecekler.md](../../Dikkat_Edilecekler.md)'ye ekler ve aynı
     kalıbı kullanan diğer yerleri tarar (o dosyadaki G1 kuralı). Kapsam
     dışında kalan yerleri düzeltmez; ilgili göreve not düşer ya da kullanıcıya bildirir.
   Durum `DUZELTME` → yeniden `REVIEW_BEKLIYOR`.
7. **Anlaşmazlık.** Aynı bulgu iki turda çözülmezse kullanıcıya sorulur; kararı
   bu dosyaya yazılır.
8. **Canlıya alma — yalnız kullanıcı "canlıya al" dediğinde** (kullanıcı kararı
   2026-11-18). Bu modülün hiçbir görevi kendiliğinden canlıya alınmaz: `PASSED`
   olmak ve görevin "Canlı" alanının "Evet" olması yetmez, kullanıcının açık
   talimatı gerekir. Kullanıcı görevleri biriktirip toplu almayı düşünüyor
   (BY-018'den ya da BY-032'den sonra). Projenin genel "biten işi sormadan
   canlıya al" kuralı bu modüle **uygulanmaz**.
   - Görev `PASSED` olunca orada kalır; `CANLIDA` yalnız kullanıcı talimatıyla
     canlıya alındıktan sonra yazılır.
   - Bu kapsama canlı sunucudaki her değişiklik girer: `./deploy.sh`,
     zamanlayıcı satırı, sunucuda betik çalıştırma, `YEDEKLEME_AKTIF`'i açma.
   - `./publish.sh` (commit + GitHub'a gönderme) canlıya alma değildir;
     incelemeye teslim için kullanılır.
   - Görevdeki "Canlı" alanı izin değil, **uygunluk** bilgisidir: "Evet" =
     talimat gelince alınabilir; "Hayır" = talimat gelse de önce yazılı koşul
     sağlanmalı.
   - Başka bir iş için yapılan yayın bu modülün kodunu da sunucuya taşır; bu
     yüzden "kapalıyken etkisiz" kuralı (§2) her görevde zorunludur.

### 1.4 İnceleme günlüğü biçimi

Her görevin altındaki "İnceleme günlüğü"ne, en yeni satır en altta olacak
şekilde eklenir; eski satırlar silinmez. Yeni kayıt **günlüğün en sonuna**
(bir sonraki görev başlığından hemen önce) eklenir; başkasının kaydının içine
ya da ortasına yazılmaz. Eklemeden önce son kaydın bittiği yer okunarak doğrulanır.

```
- 2026-11-20 Claude → REVIEW_BEKLIYOR · commit abc1234
  Kontroller: backend 512 test geçti, pint temiz; frontend lint + tür temiz.
  Doğrulanamayan: gerçek pg_dump ile uçtan uca (lokalde PostgreSQL yoktu).
- 2026-11-20 Codex → CHANGES_REQUESTED
  1. [P1] app/Services/Yedekleme/YedekAlici.php:84 — geçici dosya hata yolunda
     silinmiyor; disk dolar. Öneri: finally bloğu. Doğrulama: pg_dump hata
     döndüren test, klasörde .tmp kalmamalı.
  2. [P3] ...
- 2026-11-21 Claude → REVIEW_BEKLIYOR · commit def5678
  1 kabul, düzeltildi (def5678) + test eklendi. 2 uygulanmadı: gerekçe …
- 2026-11-21 Codex → PASSED
```

### 1.5 Yarım kalan oturum

Görev tek oturumda bitmeyebilir. Oturum biterken Claude, görevin altına kısa
bir **"Kaldığı yer"** notu yazar: ne bitti, ne kaldı, bilinen sorun. Yeni
oturum bu dosyayı ve o notu okuyarak devam eder.

---

## 2. Ortak teslim ölçütleri (her görev için)

Görevin kendi kabul ölçütlerine **ek olarak**:

- [AGENTS.md](../../AGENTS.md) ve [CLAUDE.md](../../CLAUDE.md) kuralları
  uygulanır; ilgili skill'ler okunur (Laravel, test, PostgreSQL, React, Tailwind).
- Adlandırma Türkçe ve mevcut kalıpta (`App\Services\Yedekleme\…`, komutlar
  `yedek:…`, tablolar `yedek_…`).
- Backend: `php artisan test` geçer; `vendor/bin/pint --test` temiz. Yeniden
  adlandırılan / yeni dosyalarda `pint --dirty` yetmeyebilir; dosya adıyla çalıştır.
- Frontend (dokunulduysa): `npm run typecheck`, `npm run lint`, `npm run test`,
  `npm run build` geçer; metinler `tr.json` ve `en.json`'da.
- Davranış değişikliği ve önemli hata durumları için test vardır. Dış komutlar
  (`pg_dump`, `pg_restore`, `gpg`) testte sahtelenir (`Process::fake`); testler
  SQLite ile çalışır.
- **Yeni bağımlılık (composer / npm) kullanıcı onayı olmadan eklenmez.** Görev
  bağımlılık gerektiriyorsa "Ön koşul"da yazılıdır.
- Parola, anahtar, gerçek sunucu adresi koda ve bu dosyaya yazılmaz.
- Dış komutlar argüman dizisiyle çalıştırılır; kullanıcı girdisi kabuk metnine
  ya da SQL'e doğrudan girmez (README §5.2, §8).
- Hata gövdeleri API sözleşmesine uyar (`kod` alanı; frontend `api/errors.ts`).
- **Kapalıyken etkisiz:** modül `YEDEKLEME_AKTIF` ayarı kapalıyken hiçbir şey
  yapmaz (zamanlayıcı çalışmaz, menü görünmez). Böylece incelenmemiş ya da yarım
  kod canlıya gitse de etkisizdir. Canlıda açma kararı kullanıcınındır.
- Tasarım değiştiyse README aynı commit'te güncellenir.

---

## 3. Pano

| No | Görev | Faz | Bağımlı | Durum |
|---|---|---|---|---|
| BY-001 | Geçici önlem: günlük yedek ve elle sunucu dışı kopya | 0 | — | PASSED |
| BY-002 | Keşif: mevcut yedeği geri yükleme, PostgreSQL davranışları | 0 | — | PASSED |
| BY-003 | Sunucu kurulum betiği | 0 | BY-002 | PASSED |
| BY-004 | Veri modeli, yapılandırma, izinler | 1 | BY-002 | PASSED |
| BY-005 | Dosya kilidi ve işlem durumu dosyası | 1 | BY-004 | PASSED |
| BY-006 | Manifest, imza, olay günlüğü | 1 | BY-004 | YAPILACAK |
| BY-007 | Yedek alma (`yedek:al`) | 1 | BY-005, BY-006 | YAPILACAK |
| BY-008 | Hedef soyutlaması, Yerel disk hedefi, kopyalama | 1 | BY-007 | YAPILACAK |
| BY-009 | Planlar ve zamanlayıcı | 1 | BY-008 | YAPILACAK |
| BY-010 | Saklama ve temizlik | 1 | BY-009 | YAPILACAK |
| BY-011 | Klasör tarama, dizini yeniden kurma | 1 | BY-006, BY-008 | YAPILACAK |
| BY-012 | Bildirimler ve yaşam sinyali | 1 | BY-009 | YAPILACAK |
| BY-013 | `deploy.sh` entegrasyonu, eski yedeklerin içeri alınması | 1 | BY-007, BY-011 | YAPILACAK |
| BY-014 | API: Yedekler | 2 | BY-010, BY-011 | YAPILACAK |
| BY-015 | Ekran: Yedekler | 2 | BY-014 | YAPILACAK |
| BY-016 | API + Ekran: Yedek Hedefleri | 2 | BY-008, BY-015 | YAPILACAK |
| BY-017 | API + Ekran: Yedek Planları | 2 | BY-010, BY-016 | YAPILACAK |
| BY-018 | Ekran: İşlem Günlüğü ve Yedekleme Ayarları | 2 | BY-012, BY-015 | YAPILACAK |
| BY-019 | Şifreleme (sunucu dışı kopyalar) | 3 | BY-008 | YAPILACAK |
| BY-020 | Hedef: FTP ve FTPS | 3 | BY-016, BY-019 | YAPILACAK |
| BY-021 | Hedef: Google Drive | 3 | BY-016, BY-019 | YAPILACAK |
| BY-022 | Hedef: SFTP | 3 | BY-020 | YAPILACAK |
| BY-023 | Hedef: Ağ paylaşımı (NAS) ve S3 | 3 | BY-020 | YAPILACAK |
| BY-024 | Kurtarma paketi ve kurulum dokümanı | 3 | BY-019 | YAPILACAK |
| BY-025 | Çalıştırıcı servis ve istek klasörü (servis hesabı) | 4 | BY-003, BY-013 | YAPILACAK |
| BY-026 | Geri yükleme çekirdeği: farklı ada + sınama geri yüklemesi | 4 | BY-025 | YAPILACAK |
| BY-027 | Ekran: Geri Yüklemeler (kopya) | 4 | BY-026, BY-015 | YAPILACAK |
| BY-028 | Üstüne geri yükleme çekirdeği | 4 | BY-026 | YAPILACAK |
| BY-029 | "Onay bekleniyor" korumaları ve acil durum komutu | 4 | BY-028 | YAPILACAK |
| BY-030 | Ekran: Üstüne geri yükleme | 4 | BY-029, BY-027 | YAPILACAK |
| BY-031 | Arıza senaryoları, tatbikat, işletim dokümanı | 4 | BY-030 | YAPILACAK |
| BY-032 | Dosya yedeği (`storage/app`) | 5 | BY-010 | YAPILACAK |

**Kilometre taşları**

- BY-001 canlıya alınınca: yayın yapılmayan günlerde de yedek var (geçici).
  Canlıya alınana kadar bu açık sürer (§1.3 madde 8).
- BY-013 bitince: asıl sistem yerel diske planlı yedek alıyor; BY-001 kaldırılır.
- BY-018 bitince: her şey ekrandan yönetiliyor.
- BY-021 bitince: FTP ve Google Drive'a planlı kopya gidiyor (kullanıcının tarifi).
- BY-031 bitince: geri yükleme uçtan uca çalışıyor ve denenmiş.

---

## 4. Görevler

Şablon: **Tasarım** (README bölümleri) · **Ön koşul** · **Kapsam** · **Kapsam
dışı** · **Kabul ölçütleri** · **Canlı** · **İnceleme günlüğü**.

---

## Faz 0 — Hazırlık

### BY-001 — Geçici önlem: günlük yedek ve elle sunucu dışı kopya

- **Durum:** PASSED · **Bağımlı:** —
- **Tasarım:** README §2, §11 (YDK-00)
- **Ön koşul:** Kullanıcı onayı (canlı sunucuda zamanlayıcı değişikliği); `.env`
  ve yedeğin kopyalanacağı yer kullanıcıdan.
- **Dosyalar:** `scripts/gecici-gunluk-yedek.sh`, `scripts/gecici-env-oku.php`,
  `scripts/tests/gecici-gunluk-yedek.test.sh`, `docs/yedekleme/gecici-onlem.md`
- **Kapsam:**
  - Repoya `scripts/gecici-gunluk-yedek.sh`: veritabanı bilgilerini
    `backend/.env`'den okur (parolayı komut satırına koymaz), `pg_dump
    --format=custom` ile `/var/backups/emor/erp_gunluk_<tarih>.dump` yazar, önce
    geçici ada yazıp sonra taşır, 30 günden eski `erp_gunluk_*` dosyalarını siler
    (en yeni 7'yi her durumda tutar), sonucu bir günlük dosyasına yazar.
  - Canlıda yayın kullanıcısının (`admin`) zamanlayıcısına günde bir çalışan satır.
  - En yeni yedeğin ve `.env`'in sunucu dışına bir kez elle kopyalanması için
    adımlar (kopyayı kullanıcı yapar ya da açıkça onaylar).
- **Kapsam dışı:** Asıl yedekleme sistemi; `deploy.sh` değişikliği.
- **Kabul ölçütleri:**
  - [x] Betik iki kez arka arkaya çalıştırılınca ikisi de başarılı; yarım dosya kalmıyor.
  - [x] `pg_dump` başarısız olursa betik sıfır olmayan kodla çıkar ve geçici dosyayı siler.
  - [x] Temizlik `erp_predeploy_*` dosyalarına dokunmuyor.
  - [ ] Canlıda zamanlayıcı satırı kurulu; ertesi gün dosyanın oluştuğu görüldü.
        (`PASSED` sonrası, canlıya alınırken tamamlanır — §1.3 madde 8.)
  - [x] `pg_restore --list` üretilen dosyayı okuyabiliyor.
- **Canlı:** Evet (kullanıcı onayıyla). BY-013 `CANLIDA` olunca bu önlem kaldırılır.
- **İnceleme günlüğü:**
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · commit başlığı
    `feat(yedekleme): BY-001 gecici gunluk yedek betigi` (bu satırı içeren commit)
    - Yapılan: betik, testi ve kurulum / elle kopya / kaldırma adımları
      (`gecici-onlem.md`). Kapsamdaki "canlıda zamanlayıcı satırı" ve "elle
      kopya" `PASSED` sonrasına bırakıldı; elle kopyanın yeri kullanıcıdan bekleniyor.
    - Kontroller: `scripts/tests/gecici-gunluk-yedek.test.sh` → 32 denetim geçti
      (sahte `pg_dump` / `pg_restore` ile: başarı, `pg_dump` hatası, doğrulama
      hatası, temizlik, kilit, parola gizliliği, hatalı ayar). `bash -n` temiz.
    - Gerçek araçlarla (lokal PostgreSQL 16, geçici klasör): iki yedek alındı
      (2,1 MB, `pg_restore --list` 28 tablo verisi gösterdi); yanlış portla
      gerçek `pg_dump` hatasında çıkış kodu 1, geride dosya kalmadı.
    - Kapsam dışına taşan: `pg_restore --file=/dev/null` ile bütün verinin
      okunması (README §4.4, 1. düzey) ve `flock` ile tek kopya da eklendi.
    - Doğrulanamayan: `shellcheck` lokalde kurulu değil, çalıştırılmadı. Canlı
      sunucuda (araçlar 18.6, `admin` kullanıcısı, zamanlayıcı ortamı) denenmedi.
    - Not: repo kökünde yeni `scripts/` klasörü açıldı (görev tanımındaki yol).
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `2084d41`
    - **BY-001-R1 [P2] — geçerli `.env` değerleri değiştirilerek okunuyor.**
      `scripts/gecici-gunluk-yedek.sh:64-66`: `tr -d` bütün tek/çift tırnakları
      siliyor; satır sonu yorumunu ise değere ekliyor. Projenin mevcut Dotenv
      ayrıştırıcısının kabul ettiği `DB_PASSWORD='fake"password'` ve
      `DB_PASSWORD="fake-password" # credential comment` örnekleriyle bağımsız
      test yapıldı: beklenen parolayı isteyen sahte `pg_dump` iki durumda da
      reddetti, betik 1 ile çıktı. Etki: uygulamanın kullanabildiği geçerli bir
      yapılandırmayla günlük yedek alınamıyor. `deploy.sh` ile aynı eski
      yaklaşımın kullanılması bu doğruluk hatasını ortadan kaldırmıyor;
      `deploy.sh` bu görevin kapsamı dışında bırakıldı.
      **En küçük düzeltme:** `.env` değerlerini projenin zaten kurulu Dotenv
      ayrıştırıcısıyla doğru çöz; parola içindeki karakterleri koru,
      yorum/tırnak/kaçış kurallarını uygula. Dosyayı `source`/`eval` etme;
      parola argümana ya da ortam değişkenine aktarılmadan `PGPASSFILE`
      yaklaşımını koru. **Doğrulama:** iki örnek için kalıcı regresyon testi
      ekle; `pg_dump`'a ulaşan parola doğru, çıkış 0 ve geçici dosyalar
      temizlenmiş olmalı. Mevcut 32 denetim de geçmeli.
    - **BY-001-R2 [P3] — asılı dış komut sonraki günlük yedekleri engelleyebilir.**
      `scripts/gecici-gunluk-yedek.sh:120-130`: bağlantı ve toplam çalışma
      süresi sınırı yok. Asılı bırakılan sahte `pg_dump` denemesinde ilk
      çalışma kilidi tutarken ikinci çalışma 75 ile çıktı; ilk süreç yerel
      denemenin sonunda sonlandırıldı. **Öneri:** makul bir bağlantı ve toplam
      komut süresi sınırı koy; süre aşımını günlüğe hata olarak yaz ve alt
      süreçlerin sonlandığından emin ol. **Doğrulama:** kısa süre sınırıyla
      bekletilen `pg_dump`/`pg_restore` hata vermeli, geçici dosyalar
      temizlenmeli ve sonraki çalışma kilidi alabilmeli. Bu öneri `PASSED`'ı
      engellemez. Teknik dayanak:
      [libpq connect_timeout](https://www.postgresql.org/docs/18/libpq-connect.html#LIBPQ-CONNECT-CONNECT-TIMEOUT).
    - Kontroller: mevcut betik testi **32/32 geçti**; iki betikte `bash -n`
      temiz; `git diff --check 2084d41^ 2084d41` temiz. Dört ek geçici testten
      `.env` okuma testlerinin ikisi R1'i doğrulayarak başarısız oldu;
      `SIGTERM` sırasında geçici dosya temizliği ve `--list` başarılıyken tam
      veri okumasının hata vermesi senaryoları geçti. Ek testlerde yalnız
      yapay kimlik bilgileri ve geçici klasörler kullanıldı; dosya bırakılmadı.
    - Kullanılan skill'ler: `code-review`, `supabase-postgres-best-practices`.
      Değerlendirme: doğruluk, kimlik bilgilerinin korunması ve hata yolları.
    - Doğrulanamayan: `shellcheck` kurulu değil; gerçek PostgreSQL ile yedek
      alma bu tur tekrarlanmadı; canlı araç sürümü, kullanıcı/zamanlayıcı
      ortamı ve ertesi gün yedek oluşması sınanmadı. Backend/frontend kodu
      değişmediği için bu tur tam uygulama testleri yeniden çalıştırılmadı.
    - Sonuç: açık P2 nedeniyle görev `DUZELTME`. `scripts/` yolu görevde
      açıkça tanımlı; eklenen tam veri okuması ve kilit bu geçici önlemle
      uyumlu. Uygulama/betik/test kodu değiştirilmedi; yalnız bu inceleme ve
      BY-001 durumları yazıldı. Canlı işlemler ve elle sunucu dışı kopya,
      §1.3 madde 8 uyarınca ayrıca kullanıcı talimatını bekler.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · commit `2e984a3`
    `fix(yedekleme): BY-001 .env ayristirici ile okunur, dis komutlara sure siniri`
    - **R1 kabul, düzeltildi.** Bulgu kodda doğrulandı: `tr -d` bütün
      tırnakları siliyor, satır sonu yorumu değere katılıyordu. `.env` artık
      uygulamanın ayrıştırıcısıyla okunuyor: yeni `scripts/gecici-env-oku.php`
      (`Dotenv\Dotenv::parse` + Laravel `env()` dönüşümünün metin değerleri
      etkileyen kısmı: `empty` / `null` → boş, saran artık tırnaklar atılır).
      Değerler kabuğa NUL ile ayrılmış gelir; dosya `source` / `eval` edilmez,
      `PGPASSFILE` yaklaşımı korundu. Ayrıştırıcının hata metni hatalı satırı
      içerebildiği için günlüğe yalnız istisna türü yazılır. Satır sonu içeren
      değer (parola dosyasına yazılamaz) açık hatayla reddedilir.
      Regresyon: test bölümü 10–11 — Codex'in iki örneği, kaçışlı çift tırnak,
      `null`, ayrıştırılamayan satır (sızmadığı da denetlenir), çok satırlı parola.
    - **R2 kabul, düzeltildi** (P3 olmasına rağmen: asılı yedek sonraki günleri
      sessizce atlatır). Her dış komut `timeout --kill-after=10` ile çalışıyor
      (`EMOR_GUNLUK_SURE_SINIRI`, varsayılan 1800 sn); bağlantı için
      `PGCONNECT_TIMEOUT` (`EMOR_GUNLUK_BAGLANTI_SURESI`, varsayılan 15 sn).
      Süre aşımı günlüğe ayrı mesajla yazılır. Regresyon: test bölümü 12 —
      30 sn bekleyen sahte `pg_dump` 1 sn sınırla sonlanıyor, geçici dosya
      kalmıyor, sonraki çalışma kilidi alıp başarıyla bitiyor.
    - Kapsam dışı bırakılan: `deploy.sh` aynı `.env` okumasını ve `PGPASSWORD`
      kullanımını taşıyor; BY-013'ün kapsamına yazıldı.
    - Kontroller: betik testi **57/57**; `bash -n` ve `php -l` temiz; `pint`
      temiz; `git diff --check` temiz. Lokal PostgreSQL 16'da gerçek araçlarla
      yeniden denendi (gerçek `.env` yeni okuyucuyla okundu, iki yedek alındı,
      yanlış portta çıkış 1, artık dosya yok).
    - Doğrulanamayan: `shellcheck` yok. Canlı sunucu denenmedi (kullanıcı
      kararı: talimat gelene kadar canlıya alınmayacak).
    - Not: test yazarken kendi varsayımım yanlış çıktı — kapanmayan tırnağı
      ayrıştırıcı reddetmiyor, değişkeni tanımsız bırakıyor (uygulama da öyle
      görür). Test, ayrıştırıcının gerçekten reddettiği satırla yazıldı.
    - Dersler `Dikkat_Edilecekler.md`'ye işlendi: G2, G3, D1, D2, T7.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `2e984a3`
    - **Önceki bulgular:** BY-001-R1'in tırnak ve satır sonu yorumuna ilişkin
      iki örneği düzeldi; kalıcı testler ve bağımsız deneme geçti. BY-001-R2
      düzeldi: `pg_dump`, `pg_restore --list` ve `pg_restore --file=/dev/null`
      süre sınırıyla çalışıyor. İki geri okuma adımı ayrıca bekletilerek
      sınandı; hata kaydı, geçici dosya temizliği ve sonraki çalışmanın kilidi
      alarak başarılı olması doğrulandı. Bu iki eski bulgu kapatıldı.
    - **BY-001-R3 [P2] — Laravel'in ayrılmış değer dönüşümü eksik.**
      `scripts/gecici-env-oku.php:53-56`: `empty` / `null` dönüştürülürken
      `true`, `(true)`, `false`, `(false)` olduğu gibi bırakılıyor. Kurulu
      Laravel'in `Illuminate\Support\Env::getOption()` uygulaması
      (`backend/vendor/laravel/framework/src/Illuminate/Support/Env.php:255`)
      bunları büyük/küçük harften bağımsız boolean'a çevirir; bağlantıya metin
      olarak geçen karşılıkları `true` için `1`, `false` için boş metindir.
      İzole `.env` dosyaları gerçek Laravel okuyucusuyla karşılaştırıldı:
      `DB_PASSWORD=true` için uygulama `1`, yardımcı `true`; `false` için
      uygulama boş metin, yardımcı `false` görüyor. Uygulamanın gördüğü
      parolayı bekleyen sahte `pg_dump` iki durumda da reddetti, betik 1 ile
      çıktı. Etki: bu ayrılmış değerler kullanıldığında uygulama ve günlük
      yedek farklı kimlik bilgileri kullanıyor; yedek alınamayabilir.
      **Teknik gerekçe:** `code-review` doğruluk/uyumluluk ölçütü; G3'teki
      uygulamanın ayrıştırma davranışını koruma amacı ve T7. Dotenv kullanımı
      doğru; eksik kalan, üstüne elle eklenen Laravel dönüşümüdür.
      **En küçük düzeltme:** mevcut izole `Dotenv::parse` yaklaşımında boolean
      dönüşümlerini de tamamla; parantezli ve büyük/küçük harfli biçimleri
      kapsa. Parolayı ortam değişkenine yazmadan NUL / `PGPASSFILE` aktarımını
      koru (D2). **Doğrulama:** bu değerleri kullanan uçtan uca betik
      testlerinde parola dosyasının beklenen içeriğini ve başarılı çıkışı
      denetle; mevcut 57 denetim de geçsin. Sıradan metin parolalarında ve
      önceki R1 örneklerinde bu yeni uyumsuzluk görülmedi.
    - **BY-001-R4 [P3] — önceki incelemenin son paragrafları yeni teslimin altında.**
      `docs/yedekleme/Backup-Jira.md:300-310` (`2e984a3` satırları): önceki
      Codex kaydının skill, doğrulanamayan ve `DUZELTME` sonucu paragrafları
      Claude'un yeni `REVIEW_BEKLIYOR` kaydının altına düşmüş. Böylece aynı
      teslimde hem gerçek PostgreSQL ile yeniden denendiği hem de
      denenmediği yazıyor; eski sonuç yeni teslimin sonucu gibi okunuyor.
      **Dayanak:** P1 doküman tutarlılığı ve §1.4 inceleme günlüğü biçimi.
      **En küçük düzeltme:** bu üç eski paragrafı metinlerini koruyarak
      önceki Codex kaydına, Claude'un yeni kaydından önce taşı.
      **Doğrulama:** her kaydın kontrolleri ve sonucu kendi commit'i/yazarı
      altında kalsın; tarihçe kaybolmasın. Bu not `PASSED`'ı engellemez.
      Açıklık için: söz konusu eski `DUZELTME` sonucu `2084d41` incelemesine
      aittir; bu turun kararı aşağıdadır. Eski kayıtlar bu tur taşınmadı.
    - Kontroller: betik testi **57/57 geçti**; iki betikte `bash -n`, yardımcıda
      `php -l` temiz. Beş ek geçici testin üçü geçti (eski iki parola örneği
      tek test, iki ayrı `pg_restore` süre aşımı/temizlik/kilit testi); ikisi
      R3'ü doğrulayarak başarısız oldu (`true`, `false`). Laravel referans
      değerleri aynı yapay `.env` dosyalarıyla ayrıca doğrulandı (T7).
      Yalnız yapay kimlik bilgileri ve geçici klasörler kullanıldı;
      doğrulama betikleri repoya eklenmedi, geçici dosyalar temizlendi.
      `git -c core.whitespace=cr-at-eol diff --check 2084d41 2e984a3` temiz;
      varsayılan kontrol, mevcut CRLF düzenindeki `AGENTS.md`'nin eklenen
      12 satırını satır sonu boşluğu sayıyor. Biçim nedeniyle onay engeli yok.
    - Kullanılan skill'ler: `code-review`, `laravel-best-practices`,
      `testing-best-practices`, `supabase-postgres-best-practices`;
      `Dikkat_Edilecekler.md` okundu. Boost MCP bulunmadığından Laravel
      davranışı kurulu vendor kaynak kodundan doğrulandı. Yeni bağımlılık
      eklenmedi; ölçümsüz performans/refaktör önerisi üretilmedi.
    - Doğrulanamayan: `shellcheck` kurulu değil; bu tur gerçek PostgreSQL ile
      yedek/geri yükleme ve tam backend/frontend testleri tekrarlanmadı.
      Canlı sunucu, canlı araç sürümü ve zamanlayıcı ortamı denenmedi.
    - **Sonuç:** R1 ve R2 kapandı; yeni açık R3 [P2] nedeniyle görev
      `DUZELTME`, inceleme `CHANGES_REQUESTED`. R4 yalnız doküman notudur.
      Uygulama, betik ve test kodu değiştirilmedi; yalnız bu inceleme ve
      BY-001 durumları yazıldı. Canlıya alma veya sunucuda komut çalıştırma
      yapılmadı; §1.3 madde 8'deki kullanıcı talimatı kuralı geçerlidir.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · commit başlığı
    `fix(yedekleme): BY-001 .env degerleri Laravel env() ile donusturulur`
    - **R3 kabul, düzeltildi.** Bulgu kodda doğrulandı: yardımcı Laravel'in
      dönüşümünü elle yeniden yazıyordu ve `true` / `false` dalları eksikti.
      Eksik dalları eklemek yerine elle yazılan eşleme **kaldırıldı**; değer
      artık doğrudan `Illuminate\Support\Env::get()` ile dönüştürülüyor
      (`scripts/gecici-env-oku.php`). Böylece çerçevenin bugünkü ve ileride
      eklenecek bütün ayrılmış değerleri (parantezli, büyük-küçük harfli) aynen
      uygulanır. `Dotenv::parse` ile ayrıştırılan değerler yalnız yardımcı
      sürecin belleğindeki `$_SERVER` / `$_ENV` dizilerine konur; `putenv`
      çağrılmaz, sürecin ortam değişkenlerine yazılmaz (D2). Metne çevirme:
      `true` → `1`, `false` / `null` → boş (PDO'ya ulaşan karşılık).
      Önerilen "en küçük düzeltme"den (dalları tamamlamak) bilerek ayrıldım:
      aynı sınıf hatayı ikinci kez elle yazmamak için (G3).
    - Regresyon: test bölümü 10'a 10 örnek eklendi — `true`, `(true)`, `TRUE`,
      `"true"`, `false`, `(False)`, `(EMPTY)`, `(Null)` ve ayrılmış değere
      yalnız benzeyen iki sıradan parola (`truefalse`, `"true "`). Her biri
      çıkış kodunu ve `pg_dump`'a ulaşan parola dosyasının içeriğini denetler.
    - **Beklenen değerler gerçek Laravel ile doğrulandı (T7):** aynı yapay
      `.env` satırları, uygulamanın yükleme yoluyla (`Dotenv::create(Env::getRepository())`
      + `env()`) okundu ve yardımcının çıktısıyla karşılaştırıldı — yukarıdaki
      10 örnek ve R1'in iki örneği, 12'sinde de sonuç aynı.
    - **R4 kabul, düzeltildi.** Hata bendeydi: `2e984a3` kaydımı Codex'in ilk
      kaydının ortasına eklemiştim. Üç paragraf metinleri değiştirilmeden kendi
      kaydına (`2084d41` incelemesi) taşındı; kendi kaydımın başlığına commit
      numarası yazıldı. §1.4'e "yeni kayıt en sona eklenir" kuralı eklendi.
    - Kontroller: betik testi **77/77**; `bash -n`, `php -l` temiz; `pint`
      yardımcıyı biçimlendirdi (içe aktarma satırı), sonra temiz;
      `git diff --check` temiz.
    - Doğrulanamayan: `shellcheck` yok. Gerçek PostgreSQL denemesi bu tur
      tekrarlanmadı (değişen yalnız değer dönüşümü; lokal gerçek `.env` sıradan
      metin parola içeriyor). Canlı sunucu denenmedi.
    - Ders `Dikkat_Edilecekler.md` G3'e işlendi.
  - 2026-11-18 Codex → PASSED · incelenen commit `5ee2845`
    - **BY-001-R3 kapandı.** `scripts/gecici-env-oku.php:59-68` değerleri
      `Illuminate\Support\Env::get()` ile dönüştürüyor; elle yazılmış eksik
      eşleme kaldırılmış. Eksik dalları eklemek yerine mevcut Laravel
      fonksiyonunu kullanma gerekçesi kabul edildi (G3). Bu, uygulamayı
      başlatmadan `.env` okuyan bağımsız CLI yardımcısıdır; Laravel uygulama
      kodundaki `config()` kullanım kuralıyla çelişmez. Kurulu Laravel
      `v13.24.0`, phpdotenv `v5.6.4`; Boost MCP bulunmadığı için davranış
      vendor kaynaklarından doğrulandı.
    - **Uyumluluk ve gizlilik:** 10 yeni parola örneği ile eski R1'in iki
      örneği bağımsız geçici testte, aynı yapay `.env` dosyalarını normal
      `Dotenv::create(Env::getRepository())->load()` + `env()` yoluyla okuyan
      ayrı PHP süreçleriyle karşılaştırıldı; beş veritabanı alanının çıktısı
      **12/12 aynı**. Yardımcıya eklenen geçici kapanış gözleminde
      `getenv('DB_PASSWORD')` çalışmanın öncesinde ve sonrasında aynı kaldı;
      dosyadan okunan parola süreç ortamına yazılmadı (D2). `$_SERVER` /
      `$_ENV` atamaları yardımcı PHP sürecinin belleğinde kalıyor; Bash'e
      aktarım NUL ayracı ve geçici `PGPASSFILE` üzerinden sürüyor.
    - **BY-001-R4 kapandı.** Önceki Codex incelemesinin üç paragrafı metinleri
      korunarak `2084d41` kaydına taşınmış; Claude'un `2e984a3` kaydı bunların
      ardından başlıyor. §1.4'te günlüğün sonuna ekleme kuralı açıklaştırılmış.
    - Kontroller: `bash scripts/tests/gecici-gunluk-yedek.test.sh` → **77/77**;
      iki betikte `bash -n`, yardımcıda `php -l` ve
      `git diff --check 2e984a3 5ee2845` temiz. Üç bağımsız geçici test geçti:
      12 örnekte Laravel uyumu, süreç ortamının korunması ve regresyonların
      eski yardımcıyla kırmızıya dönmesi. Sonuncusunda yeni testler izole
      geçici proje kopyasında `2e984a3` yardımcısıyla çalıştırıldı; altı parola
      içeriği denetimi beklenen şekilde başarısız oldu, çıkış 1 (T5/T7).
      Güncel yardımcıyla aynı denetimler yukarıdaki 77/77 koşusunda geçti.
      Yalnız yapay bilgiler kullanıldı; geçici test/kopyalar temizlendi.
    - Kullanılan skill'ler: `code-review`, `laravel-best-practices`,
      `testing-best-practices`, `supabase-postgres-best-practices`;
      `Dikkat_Edilecekler.md` ve genişletilen G3 okundu. Yeni hata, güvenlik,
      performans veya bakım bulgusu saptanmadı. Sonraki `1481924` yalnız
      frontend dosyalarını değiştiriyor; bu BY-001 kararının kapsamı `5ee2845`.
    - Doğrulanamayan: `shellcheck` kurulu değil; gerçek PostgreSQL denemesi,
      tam backend/frontend testleri ve Pint bu tur tekrarlanmadı. Canlı sunucu,
      canlı araç sürümü, zamanlayıcı ortamı ve ertesi gün yedek oluşması
      denenmedi; ilgili canlı kabul kutusu açık bırakıldı.
    - **Sonuç:** R1–R4 kapalı; kod incelemesi `PASSED`. Uygulama/betik/test
      kodu değiştirilmedi; yalnız bu inceleme ve BY-001 durumları yazıldı.
      Canlı işlem, zamanlayıcı kurulumu, commit veya gönderme yapılmadı.
      Görev `CANLIDA` yapılmadı; canlı adımları ve elle sunucu dışı kopya,
      §1.3 madde 8 gereği kullanıcının ayrıca vereceği talimatı bekler.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · Fable incelemesi (BY-001…005
    bağımsız inceleme) düzeltmeleri; başlığı
    `fix(yedekleme): Fable incelemesi F1-F19` olan commit. Ayrıntı ve
    kontroller BY-005 günlüğünde. Bu göreve düşenler:
    - **F8 kabul.** `gecici-env-oku.php` `.env`'de olmayan anahtar için
      sürecin ortamındaki aynı adlı değişkeni döndürüyordu (Env::get getenv'e
      de bakar); başlıktaki "tanımsız anahtar boş değer verir" sözleşmesi
      tutmuyordu. Artık yalnız dosya. Test 13: ortamda
      `DB_HOST=ortamdan.gelen DB_PORT=9999` varken pgpass `127.0.0.1:5432`
      (önce kırmızı görüldü: `ortamdan.gelen:9999`).
    - **F18 kabul.** Taşımadan sonra klasör `sync` (D3); temizlik sırası
      `LC_ALL=C sort -r`. Güç kesintisi denenmedi.
    - Betik testleri 79/79.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `6c96ea8`
    - F8 için dosyada bulunmayan `DB_HOST` / `DB_PORT` sürece ait aynı adlı
      değişkenlerden okunmuyor. F18 kapsamındaki klasör eşitlemesi ve
      `LC_ALL=C` değişikliği kodda. WSL'de mevcut betik testleri **79/79**.
    - **BY-001-R5 [P2] — kilit hatası meşguliyet olarak raporlanıyor.**
      `scripts/gecici-gunluk-yedek.sh:133`. Kilit komutuna çakışma dışı
      bir hata döndüren testte betik "başka bir yedek çalışıyor" yazdı ve
      75 ile çıktı. **Etki.** Dosya sistemi veya kilit aracı hatası
      günlük yedeğin sürekli atlanmasına rağmen olağan çakışma gibi görünür.
      **Dayanak.** D6, G1 ve F4'ün BY-005'teki düzeltmesi.
      **Düzeltme.** `flock --conflict-exit-code` ile çakışmaya ayrı
      kod ver. Yalnız o kodu 75'e eşle, kalanını hata olarak kaydet.
      **Doğrulama.** Gerçek kilit çakışması testi 75'i korusun. Komut
      hatası taklidinde HATA, 1 ve yedek üretilmemesi beklensin.
      [flock(1)](https://man7.org/linux/man-pages/man1/flock.1.html)
    - **BY-001-R6 [P2] — devralınan PGPASSWORD parola dosyasını gölgeliyor.**
      `scripts/gecici-gunluk-yedek.sh:160`. Sentetik `PGPASSWORD` ile
      çalıştırıldığında mevcut "PGPASSWORD tanımlı değil" kontrolü düştü.
      Ayrı PostgreSQL 16 örneğinde doğru parola dosyası, yanlış ortam
      parolası verildiğinde bağlantı reddedildi. **Etki.** Çalışan
      `.env` ayarlarıyla bile kabuktan devralınan eski parola yedeği
      engeller. Aynı kalıp `scripts/sunucu-yedekleme-kurulumu.sh:624`'te
      parola dosyası doğrulamasını yanıltabilir. **Dayanak.** G1, D4 ve
      [libpq parola dosyası](https://www.postgresql.org/docs/17/libpq-pgpass.html).
      **Düzeltme.** İki çağrıda da parola dosyası kullanılmadan önce
      devralınan `PGPASSWORD`'ı kaldır. **Doğrulama.** Yedek betiği yanlış
      ortam parolasında dosyayla çalışmalı. Kurulum doğrulamasında doğru
      ortam parolası geçersiz dosyayı başarılı göstermemeli.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 6c96ea8 incelemesi (BY-001/003/004/005)` olan commit
    - **R5 kabul, düzeltildi.** `flock -n -E 75 9`: yalnız çakışma 75 ve
      "ATLANDI"; başka çıkış kodu `HATA: Yedek kilidi alınamadı (flock çıkış
      kodu N)` ve 1. Test (bölüm 8): sahte `flock` çakışma dışı 64 döndürünce
      çıkış 1, günlükte HATA, "başka bir yedek çalışıyor" yok, yedek yok; gerçek
      çakışma 75'i koruyor. Önce kırmızı görüldü (75 + ATLANDI).
    - **R6 kabul, düzeltildi.** Betiğin başında `unset PGPASSWORD`. Test
      (bölüm 2): devralınan yanlış `PGPASSWORD` pg_dump'a ulaşmıyor (önce
      kırmızı: ulaşıyordu). Kurulumdaki karşılığı BY-003 günlüğünde.
    - Ders: G12 (devralınan ortam ayarı gölgelemesin), D6 eki (kabukta
      `flock -E`).
    - Betik testleri **85/85**.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `775a0fd`
    - **R5 ve R6'nın PGPASSWORD senaryosu kapandı.** WSL'de 85/85.
      Gerçek çakışmada 75, araç hatasında 1. Kabuktan gelen `PGPASSWORD`
      `pg_dump`'a geçmiyor. Dört kabuk dosyası `bash -n` denetiminden geçti.
    - **BY-001-R7 [P2] — libpq bağlantı profili parola dosyasının önüne
      geçebiliyor.** `scripts/gecici-gunluk-yedek.sh:42` ve `:169`.
      Betik yalnız `PGPASSWORD`'ı kaldırıyor. Geçici PostgreSQL 16
      örneğinde aynı `.env` ile gerçek yedek başarılı oldu. Ardından
      eski parolası olan sentetik bir libpq bağlantı profili devralınınca
      yedek 1 ile sonlandı ve PostgreSQL kimlik doğrulamasını reddetti.
      **Etki.** Doğru parola dosyasıyla elle veya zamanlayıcıdan
      çalıştırılan yedek, o sürecin bağlantı ayarına göre başarısız olabilir.
      **Dayanak.** G12, BY-001'in parola kaynağı sözleşmesi ve
      [libpq bağlantı profili](https://www.postgresql.org/docs/16/libpq-pgservice.html).
      **Düzeltme.** Kurulum betiğindeki bağlantı kontrolü gibi `pg_dump`'ı
      yalnız gerekli ortam ayarları açıkça verilerek başlat. Bilerek
      desteklenen bağlantı ayarlarını açıkça geçir. **Doğrulama.** Yanlış
      `PGSERVICE` profili ve doğru betik ayarlarıyla yedek ve doğrulama
      başarılı olmalı. Mevcut parola ve süre sınırı testleri kalmalı.
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 775a0fd incelemesi (BY-001-R7, BY-005-R9/R10)` olan commit
    - **R7 kabul, düzeltildi.** `unset PGPASSWORD` yerine betiğin başında
      devralınan, adı `PG` ile başlayan bütün ortam değişkenleri temizleniyor
      (libpq'nun okuduğu değişkenlerin hepsi bu öneki taşır: PGSERVICE,
      PGSERVICEFILE, PGHOST, PGOPTIONS, PGSSLMODE…). Bağlantı yalnız `.env`'den
      gelen açık argümanlarla ve betiğin verdiği `PGPASSFILE` /
      `PGCONNECT_TIMEOUT` ile kuruluyor. `env -i` seçilmedi: süre sınırı ve
      sahte komut testleri için gereken diğer ortam (PATH, yerel ayar) korunur;
      libpq açısından sonuç aynı. Test (bölüm 2): `PGSERVICE`,
      `PGSERVICEFILE`, `PGHOST`, `PGOPTIONS`, `PGSSLMODE` devralınınca yedek
      başarılı ve pg_dump'a yalnız `PGCONNECT_TIMEOUT PGPASSFILE` ulaşıyor
      (önce kırmızı: beşi de ulaşıyordu). Gerçek bir libpq profiliyle
      denenmedi; sahte pg_dump ortamı kaydediyor.
    - G12'ye eklendi: tek değişkeni değil bütün aileyi temizle.
    - Betik testleri **87/87**.
  - 2026-11-19 Codex → PASSED · incelenen commit `03f1cba`
    - **R7 kapandı.** Betik devralınan libpq ortam değişkenlerini
      temizliyor, `pg_dump` için parola dosyasını ve bağlantı süresini
      açıkça belirliyor. Önceki turdaki senaryo bu tur gerçek geçici
      PostgreSQL 16 örneğinde tekrarlandı. Yanlış parolalı
      `PGSERVICE` profili, `PGPASSWORD` ve `PGOPTIONS` devralınmasına
      rağmen doğru `.env` ile yedek alındı ve `pg_restore` denetimi geçti.
    - Betik denetimleri **87/87**, `bash -n` ve `git diff --check`
      temiz. Canlıda çalışma veya zamanlayıcı kurulumu yapılmadı.

### BY-002 — Keşif: mevcut yedeği geri yükleme, PostgreSQL davranışları

- **Durum:** PASSED · **Bağımlı:** —
- **Tasarım:** README §7.7, §10, §5.2 (sahiplik), §6.4 (6–7. adım), §9
- **Ön koşul:** Canlı veritabanı sunucusunda okuma / deneme için kullanıcı onayı;
  denemeler mümkünse lokal PostgreSQL'de yapılır.
- **Kapsam:** Kod yok; çıktı `docs/yedekleme/kesif.md`. Yanıtlanacak sorular:
  1. Canlı PostgreSQL sunucu sürümü nedir (araçlar 18.6)? Yeni araçla alınan
     yedek bu sunucuya geri yükleniyor mu?
  2. Mevcut bir `erp_predeploy_*` yedeği ayrı bir veritabanına gerçekten geri
     yükleniyor mu; satır sayıları tutuyor mu?
  3. Uygulama veritabanı kullanıcısının yetkileri (veritabanı oluşturma, sahiplik)?
  4. `pg_restore --role` / `--no-owner` ile nesnelerin sahibi uygulama rolü oluyor mu?
  5. İki `ALTER DATABASE … RENAME` tek işlem (transaction) içinde çalışıyor mu?
  6. `ALLOW_CONNECTIONS false` + bağlantı koparma sırası beklendiği gibi mi?
  7. Kodlama, sıralama kuralı ve veritabanı düzeyindeki ayarlar (saat dilimi)
     nelerdir; yeni veritabanına nasıl taşınır?
  8. `pg_dump --snapshot` ile aynı andan sayım alma çalışıyor mu?
  9. `pg_restore --file=/dev/null` bozuk bir dosyada hata veriyor mu?
- **Kapsam dışı:** Canlı `erp` veritabanında değişiklik (yalnız okuma ve ayrı ada
  geri yükleme).
- **Kabul ölçütleri:**
  - [x] Dokuz sorunun her biri "doğrulandı / doğrulanamadı + nasıl denendi" ile yanıtlandı.
  - [x] Tasarımı değiştiren her bulgu README'ye işlendi (özellikle §6.4 7. adım, §7.7).
  - [x] Deneme için oluşturulan veritabanları silindi.
- **Canlı:** Yok (doküman).
- **İnceleme günlüğü:**
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · commit `a1c5b80` (lokal) + başlığı
    `docs(yedekleme): BY-002 kesif — canli salt okuma sonuclari` olan commit
    - Lokal (PostgreSQL 16.15): geçici roller (`by002_app` uygulama, `by002_svc`
      servis hesabı) ve veritabanlarıyla 9 sorunun lokalde denenebilenleri
      denendi; `erp` / `erp_test`'e yazılmadı, geride rol / veritabanı kalmadığı
      denetlendi. Deney betiği repoya eklenmedi (tek seferlik keşif).
    - Canlı (kullanıcı onayıyla, salt okuma): sürüm, uygulama kullanıcısının
      yetkileri, veritabanı özellikleri, en yeni yayın öncesi yedeğin tam
      okunması. Betik sunucuya geçici kopyalandı ve silindi; parola komut
      satırına girmedi (`scripts/gecici-env-oku.php` + `PGPASSFILE`).
    - Kullanıcı "canlıda ayrı veritabanına geri yükle"yi onayladı; uygulama
      kullanıcısının `CREATEDB` yetkisi olmadığı için yapılamadı, canlıda nesne
      oluşturulmadı. Soru 2'nin canlı yarısı "doğrulanamadı" — BY-003 (bilgi
      işlem talebi) ve BY-026'ya bırakıldı.
    - Önemli bulgular: canlı PostgreSQL **18.6** (CLAUDE.md'deki "17" eskiydi,
      düzeltildi), araçlarla eşleşiyor; canlı sıralama `en_US.UTF-8`,
      saat dilimi UTC, veritabanı düzeyi ayar yok; servis hesabının uygulama
      rolüne **üye** olması gerektiği (sahiplik, ad değiştirme, bağlantı
      koparma için) — README §5.2, §9.
    - Sınır: sahiplik / ad değiştirme / bağlantı koparma davranışları 16'da
      denendi, canlı 18; değişmediği varsayıldı (`kesif.md` "Sınırlar").
    - Kod değişmedi; uygulama testleri bu görev için çalıştırılmadı
      (`publish.sh` yine de tam koşuyu yaptı).
  - 2026-11-18 Claude → REVIEW_BEKLIYOR (ek, Codex incelemesinden önce) ·
    commit başlığı `docs(yedekleme): BY-002 canli geri yukleme dogrulandi, emor_yedek`
    - Kullanıcı "bilgi işlemden bir şey istemeyelim" dedi ve sunucunun `admin`
      şifresini verdi. Onayıyla, `sudo` ile PostgreSQL'de kalıcı yedekleme
      kullanıcısı **`emor_yedek`** açıldı: `LOGIN CREATEDB`, uygulama
      kullanıcısına üye (`GRANT erp TO emor_yedek`). Parolası rastgele; yalnız
      `/home/admin/.emor_yedek_pgpass` (600) dosyasında. `sudo` şifresi sunucuya
      SSH girdisiyle verildi; repoya, hafızaya, dokümana yazılmadı; yerel geçici
      dosya silindi.
    - Bu kullanıcıyla en yeni yayın öncesi yedek **canlı sunucuda geçici
      `erp_kesif`** veritabanına geri yüklendi (1 sn, çıkış 0): tablolar ve
      veritabanı `erp`'nin; uygulama kullanıcısı okuyor ve migration için tablo
      açabiliyor; 38 tablonun 36'sında sayım canlıyla aynı, farklı 2'si yedekten
      sonra büyüyen e-Fatura tabloları. `erp_kesif` silindi, geride dosya yok.
      Soru 2 artık canlıda da **doğrulandı**.
    - Canlıda kalıcı değişiklik: yalnız `emor_yedek` rolü ve parola dosyası
      (tasarımdaki servis hesabının veritabanı tarafı). Canlı uygulama
      veritabanına yazılmadı.
    - README ve bu dosyada "bilgi işlemden istenecekler" ifadeleri "kurulum
      betiğiyle (`admin` + `sudo`) yapılacaklar" olarak değiştirildi; BY-003
      yeniden tanımlandı.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commitler `a1c5b80`,
    `a6f0101`, `80de774` (BY-002 tesliminin tamamı)
    - **BY-002-R1 [P2] — custom arşivde bulunmak ile geri yüklemede uygulanmak karıştırılmış.**
      `docs/yedekleme/kesif.md:150-154` ve özetin 7. satırı: veritabanı
      ayarlarının yedeğe hiç girmediği, `pg_dump`'ın bunları yalnız `--create`
      ile yazdığı sonucu yanlış. Aynı PostgreSQL 16.15 ortamında bağımsız
      denemede kaynak geçici veritabanına `TimeZone=Europe/Istanbul` ve
      `statement_timeout=42s` verildi; **`pg_dump --format=custom` (`--create`
      olmadan)** alındı. Normal `pg_restore --list` bu girdiyi göstermedi,
      fakat **aynı dosyada** `pg_restore --list --create` `DATABASE PROPERTIES`
      girdisini gösterdi. Normal geri yükleme, önceden açılan geçici hedefte
      tabloyu/veriyi oluşturdu, ayarları uygulamadı. Aynı arşiv `pg_restore
      --create` ile, yalnız deneyin kaynak adı tekrar oluşturularak geri
      yüklendiğinde iki ayar da geri geldi.
      **Etki:** 7. sorunun "doğrulandı" diye sunulan sonucu ve README'nin
      gerekçesi yanlış; seçilmiş TOC listesindeki yokluk arşivde yokluk
      sayılmış. Farklı ada elle açılan veritabanına geri yüklemede ayarları
      manifestten taşıma kararı geçerlidir.
      **Dayanak:** T7 (deney varsayımını doğrula), G1 ve PostgreSQL'in
      [pg_dump --create](https://www.postgresql.org/docs/18/app-pgdump.html)
      açıklaması: bu çıktı seçeneği custom arşivde dikkate alınmaz; seçim
      `pg_restore` sırasında yapılır.
      **En küçük düzeltme:** keşfin §7'sini ve özetini "arşivde bulunabilir,
      seçilen `--create` içermeyen geri yükleme akışında otomatik uygulanmaz"
      şeklinde düzelt; README §5.3 ve §14'teki aynı genellemeyi tara (G1).
      Manifest kararını koru. Farklı ada geri yükleme akışına `--create`
      eklemek çözüm değildir: o seçenek arşivdeki veritabanı adını kullanır
      ([pg_restore](https://www.postgresql.org/docs/18/app-pgrestore.html)).
      **Doğrulama:** bu iki ayarı taşıyan aynı custom arşivde normal / `--create`
      TOC ve SQL çıktısını karşılaştır; önceden açılan hedefte ayarların
      uygulanmadığını, `--create` deneyinde geri geldiğini kaydet. Deney ve
      tasarım gerekçesi aynı ayrımı anlatmalı; canlıda tekrar gerekmez.
    - **BY-002-R2 [P3] — uygulama rolünün bağlantı sonlandırma yetkisi yanlış anlatılmış.**
      `docs/yedekleme/README.md:419-420`, `docs/yedekleme/kesif.md:120-122`:
      ayrı servis hesabının değerini açıklarken uygulamanın bağlantı koparma
      yetkisi taşımadığı söyleniyor. PostgreSQL, bir rolün kendi rolüne ait
      bağlantıları `pg_terminate_backend` ile sonlandırmasına izin verir;
      bunun için `CREATEDB` veya `pg_signal_backend` gerekmez.
      **Etki:** servis hesabının sağladığı güvenlik sınırı olduğundan geniş
      gösteriliyor; uygulama hesabı kendi bağlantılarını zaten kesebilir.
      **Dayanak:** `security-privileges` ve
      [PostgreSQL Server Signaling Functions](https://www.postgresql.org/docs/18/functions-admin.html#FUNCTIONS-ADMIN-SIGNAL).
      **En küçük düzeltme:** iki açıklamada sınırı doğru tanımla: uygulama
      hesabında `CREATEDB` yoktur; servis hesabı veritabanı oluşturma/ad
      değiştirme ve işletim sistemi servisleri/dosya yetkileri için ayrıdır.
      Kendi bağlantılarını sonlandırabilme istisnasını açıkla; mevcut canlı
      yetkileri değiştirmek gerekmez. **Doğrulama:** açıklamayı resmi yetki
      kuralıyla karşılaştır, iki belgede aynı kapsam yazsın (P1 doküman
      tutarlılığı). Bu P3 `PASSED`'ı engellemez.
    - Kabul ölçütleri: dokuz soru ve canlı/lokal sınırları belgelenmiş;
      `emor_yedek` üyeliği, uygulama rolü adına sahiplik ve PostgreSQL 16/18
      sınırı resmi PostgreSQL 18 belgeleriyle karşılaştırıldı. Sunulan canlı
      geri yükleme kaydı 2. soruyu karşılıyor; 36/38 sayım eşleşmesi ve iki
      e-Fatura tablosundaki zaman farkı açıkça yazılmış. Canlı deney bu tur
      bağımsız tekrarlanmadı; kayıtlar geliştiricinin sunduğu sonuçlardır.
    - Kontroller: `git diff --check 1481924 80de774` temiz. R1 için gerçek
      araçlarla bağımsız yerel test geçti (PostgreSQL/araçlar 16.15): aynı
      arşivle iki farklı geri yükleme ve ayar karşılaştırması. Yalnız bu test
      için üretilen rastgele adlı iki geçici veritabanına yazıldı; `erp` ve
      `erp_test` verisine dokunulmadı. İki veritabanı sonunda silindi ve
      katalogdan kalan sayısı **0** doğrulandı; geçici arşiv/test dosyaları
      bırakılmadı. Uygulama kodu değişmediği için backend/frontend testleri,
      build ve Pint çalıştırılmadı.
    - Kullanılan skill'ler: `code-review`, `supabase-postgres-best-practices`;
      `Dikkat_Edilecekler.md` okundu. Canlı sunucuya bağlanılmadı; mevcut
      parola dosyaları okunmadı, canlı rol/dosya/servis değişikliği yapılmadı.
    - **Sonuç:** R1 [P2] açık olduğu için `CHANGES_REQUESTED`, görev `DUZELTME`.
      Düzeltilmesi gereken keşif yorumu ve ilgili dokümanlardır. Bu tur yalnız
      inceleme günlüğü ve BY-002 durumları değiştirildi; commit/gönderme yok.
      BY-003, §1.3 madde 1 uyarınca BY-002 `PASSED` olduktan sonra başlar.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · commit başlığı
    `docs(yedekleme): BY-002 R1 R2 — arsivdeki vt ayarlari ve baglanti koparma`
    - **R1 kabul, düzeltildi.** Kendi deneyimle de doğrulandı (lokal 16.15,
      geçici `by002_r1`, sonunda silindi): `TimeZone` ve `statement_timeout`
      tanımlı veritabanının custom yedeğinde `--list` ve `--create`'siz SQL
      çıktısı ayarları göstermiyor; `--create` ile SQL çıktısı iki
      `ALTER DATABASE … SET` satırını gösteriyor; `--create`'siz geri yükleme
      uygulamıyor, `--create`'li geri yükleme uyguluyor. `kesif.md` §7 ve özet
      satırı yeniden yazıldı (tablo + "arşivde var, `--create` olmadan
      uygulanmaz" + `--create`'in neden kullanılamadığı); manifest kararı
      korundu, gerekçesi düzeltildi. **Kardeş hata (G1):** aynı yanlış
      genelleme kodlama / sıralama için de yazılmıştı ("yedekten gelmez") —
      onlar da arşivdeki veritabanı kaydının parçası; birlikte düzeltildi.
      Aynı iddianın kopyaları tarandı ve düzeltildi: README §5.3, §14 tablo
      satırı, BY-007 kapsam satırı. Yan fayda olarak not edildi: manifesti
      olmayan eski yedeklerde bu bilgiler `pg_restore --create --file=-`
      çıktısından okunabilir (BY-013).
    - **R2 kabul, düzeltildi.** README §5.2 (iki yer) ve `kesif.md` §3–§4:
      ayrı hesabın veritabanı tarafındaki değeri yalnız `CREATEDB` (ve ona
      bağlı ad değiştirme) olarak yazıldı; bağlantı koparmanın ayrım
      sağlamadığı, asıl ayrımın sunucu tarafında (yedek dosyaları, imza
      anahtarı, parola dosyası) olduğu açıklandı. Canlı yetkiler değişmedi.
    - Ders `Dikkat_Edilecekler.md`'ye işlendi: **T9** — "yok / yapamaz"
      sonucunu tek bir gözlemden çıkarma; ikinci yoldan dene ya da resmî belgeye bak.
    - Kontroller: değişen yalnız doküman; `git diff --check` temiz.
  - 2026-11-18 Codex → PASSED · incelenen commit `a643845`
    - **BY-002-R1 kapandı.** `kesif.md` §7 ve özet, README §5.3/§14 ve
      BY-007 kapsamı artık arşivde bulunma ile seçilen geri yükleme yolunda
      uygulanma ayrımını anlatıyor. Kodlama ve sıralama da düzeltilmiş (G1).
      Farklı ada önceden açılmış veritabanına geri yükleme, manifest kararı
      ve `--create` kullanmama gerekçesi tutarlı. Eski yedeklerde veritabanı
      bilgisini SQL çıktısından, veritabanına bağlanmadan okuma notu uygun.
    - **BY-002-R2 kapandı.** README §5.2 ve `kesif.md` §3–§4, uygulama
      rolünün kendi bağlantılarını sonlandırabildiğini doğru açıklıyor.
      Veritabanı oluşturma/ad değiştirme için `CREATEDB`, uygulama rolüne
      üyelik ve işletim sistemi düzeyindeki dosya/servis ayrımı korunmuş.
      PostgreSQL 18'in resmi `pg_restore` ve yönetim işlevleri belgeleriyle
      karşılaştırıldı. Yeni T9 kuralı iki bulgunun nedenini doğru kapsıyor.
    - **BY-002-R3 [P3] — `--create` açıklamasını nesne sahipliğine genelleme.**
      `docs/yedekleme/README.md:446-448` ve BY-007'nin bu commit'teki 728.
      satırı: listedeki "nesnelerin sahibi olan rol" de yalnız `--create`
      ile uygulanıyormuş gibi okunuyor. Nesne sahipliği varsayılan geri
      yüklemede `--create` olmadan da uygulanır; bunu `--no-owner` bastırır.
      **Etki:** seçeneğin kapsamı yanlış anlatılmış; mevcut tasarımın
      `--no-owner --role=<uygulama>` akışı doğru ve değiştirilmesi gerekmiyor.
      **Dayanak:** T9 ve
      [PostgreSQL pg_restore --no-owner](https://www.postgresql.org/docs/18/app-pgrestore.html).
      **En küçük düzeltme:** `--create` gerektiren veritabanı özellikleriyle
      nesne sahipliğini iki cümlede ayır; sahipliğin tasarımda `--no-owner`
      ve `--role` ile bilinçli yönetildiğini yaz. **Doğrulama:** aynı arşivin
      `--create` içermeyen SQL çıktısında `ALTER TABLE … OWNER TO` görülmeli;
      `--no-owner` eklenince kalkmalı. Bu dar kapsamlı doküman notu
      `PASSED`'ı engellemez.
    - Kontroller: `git diff --check 80de774 a643845` temiz; aynı yanlış
      iddiaların kopyaları dört belgede tarandı. Bağımsız yerel test geçti
      (PostgreSQL/araçlar 16.15): custom arşivin `--list` / `--list --create`
      farkı, `--create` SQL'inde ayar/kodlama/sıralama bilgileri ve sahiplik
      seçenekleri karşılaştırıldı. Gerçek `--create` içermeyen geri yüklemede
      tablo verisi ve kaynak nesne sahibi geri geldi; veritabanı ayarları
      uygulanmadı. Bu sonuç R1 düzeltmesini ve R3'ün kapsamını doğruluyor.
      Yalnız rastgele adlı iki geçici veritabanı ve bir geçici `NOLOGIN` rol
      kullanıldı; hepsi silindi, kalan veritabanı/rol sayıları katalogdan
      **0/0** doğrulandı. Geçici arşiv/test dosyaları bırakılmadı;
      `erp` / `erp_test` verisine dokunulmadı.
    - Kullanılan skill'ler: `code-review`, `supabase-postgres-best-practices`;
      `Dikkat_Edilecekler.md` ve T9 okundu. Değişiklikler yalnız doküman
      olduğu için backend/frontend testleri, build ve Pint çalıştırılmadı.
      Canlı deneme tekrarlanmadı; canlı PostgreSQL 18.6'da ad değiştirme ve
      bağlantı koparma sınaması keşifte belirtildiği gibi BY-028'e bağlı.
    - **Sonuç:** açık P1/P2 yok; R1/R2 kapalı, R3 yalnız P3 doküman notu.
      BY-002 `PASSED`; BY-003'ün bu bağımlılığı karşılandı. Bu tur yalnız
      inceleme günlüğü ve BY-002 durumları değiştirildi. Canlı sunucuya
      bağlanılmadı; parola dosyaları okunmadı; commit/gönderme yapılmadı.

### BY-003 — Sunucu kurulum betiği

- **Durum:** PASSED · **Bağımlı:** BY-002
- **Tasarım:** README §9, §5.5, §5.2, §4.8
- **Karar (kullanıcı, 2026-11-18):** bilgi işlemden bir şey istenmez. Sunucu
  ayarları `admin` kullanıcısı ve `sudo` ile yapılır; şifre kullanıcıdadır.
  (Eski adı "Bilgi işlem talep listesi".)
- **Kapsam:**
  - `scripts/sunucu-yedekleme-kurulumu.sh`: README §9 ve §5.5'teki sunucu
    ayarlarını yapan, **tekrar çalıştırılabilir** (ikinci çalıştırmada bir şey
    bozmayan), her adımı açıklayan betik: servis hesabının Linux kullanıcısı,
    yedek ve istek klasörleri ile dosya yetkileri, `emor_yedek` parola
    dosyasının servis hesabına taşınması, imza anahtarının yeri, çalıştırıcı
    servis tanımı, kuyruk işçisini durdurup başlatmak için dar `sudo`
    kuralı, inceleme için salt okunur veritabanı rolü.
  - Ayrı **doğrulama** kipi (`--denetle`): hiçbir şey değiştirmeden kurulumun
    durumunu raporlar.
  - Betik bu görevde **sunucuda çalıştırılmaz**; yazılır ve incelenir.
    Çalıştırılması BY-025'te, onu kullanacak kodla birlikte ve kullanıcı
    talimatıyla olur (§1.3 madde 8).
- **Kabul ölçütleri:**
  - [x] README §9'daki her yapılacak madde betikte bir adım; karşılığı olmayan madde yok.
    (Eşleme: [sunucu-kurulumu.md](sunucu-kurulumu.md) §1. Madde 4, 5 ve 8 betikle
    yapılamaz — hedefler ekrandan, izleyici ve sanal makine yedeği açık karar;
    gerekçeleri orada.)
  - [x] Betik iki kez çalıştırılınca ikincisi hata vermiyor ve bir şeyi değiştirmiyor.
  - [x] Şifre, parola ya da anahtar betiğe ve repoya yazılmıyor.
  - [x] Lokal bir ortamda (WSL ya da geçici kullanıcılarla) denendi; `--denetle` sonucu raporlandı.
  - [x] Geri alma adımları (kurulumu kaldırma) dokümante.
- **Canlı:** Hayır — çalıştırılması BY-025'te.
- **İnceleme günlüğü:**
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `feat(yedekleme): BY-003 sunucu kurulum betigi` olan commit
    - Yeni: `scripts/sunucu-yedekleme-kurulumu.sh` (kur + `--denetle`),
      `scripts/tests/sunucu-yedekleme-kurulumu.test.sh`,
      [sunucu-kurulumu.md](sunucu-kurulumu.md) (§9 eşlemesi, çalıştırma, geri alma).
    - Kontroller: `bash -n` temiz; test betiği WSL'de root olarak, deneme
      adlarıyla (kullanıcı `emor-yedek-deneme`, roller `*_deneme`, kök `/tmp`)
      **67/67 geçti**: ilk denetim eksik raporlar ve bir şey değiştirmez; kurulum
      (22 değişiklik); ikinci kurulum 0 değişiklik; denetim temiz; §5.5 izinleri
      üç kullanıcının gözünden davranışla (web / yayın / servis: okur, yazar,
      giremez, kilit alır); bozulan izin, ACL, üyelik seçeneği, servis tanımı,
      sudoers denetimde yakalanıp kurulumla düzeliyor; eski yerdeki parola
      dosyası taşınıyor (parola yenilenmeden); parola dosyası yoksa yenileniyor,
      geçici dosya kalmıyor, parola çıktıda yok; rol yok + parola dosyası var
      belirsizliğinde hiçbir şey açılmadan duruyor. Sonda geride kullanıcı,
      grup, rol, klasör kalmadığı denetlendi. `--denetle` çıktısı test sonunda basılır.
    - Kendi incelememde: GNU `chmod 0750` klasörde setgid bitini korur; kök
      klasörde setgid varsa betik her çalıştırmada "düzeltti" deyip
      düzeltemiyordu. Önce test eklendi, kırmızı görüldü (T5), `chmod 0<izin>`
      (beş hane) ile düzeltildi.
    - İlk çalıştırmada WSL'de `acl` paketi yoktu; betik ön denetimde durdu.
      Lokal denemeye `acl` kuruldu (sunucuda var).
    - Tasarım kararları (README güncellendi):
      - Kök `/var/lib/emor-yedek`; eski `/var/backups/emor` bırakılır, içindekiler
        BY-013'te taranır (README §5.5).
      - Yayın kullanıcısı (`admin`) servis grubuna **alınmaz**: §5.5'e göre yedek
        klasörüne erişmez. İstek ve durum klasörlerine erişimi ACL ile; durum
        klasöründe varsayılan ACL (servisin sonradan açtığı dosyaları da okur).
        Kilit dosyası önceden açılır (yazma izni olmayan `deploy.sh` yaratamazdı).
      - İmza anahtarı **üretilmez**, yalnız yeri hazırlanır: BY-025'e göre
        anahtar uygulamadan taşınır; yeni anahtar eski imzaları geçersiz kılar.
      - Zamanlayıcı için `sudo` yok: Laravel bakım modunda zamanlanmış işleri
        çalıştırmıyor (`Scheduling\Event`, vendor kodunda doğrulandı); bakım modu
        dosya sürücüsünde. README §6.4 ve §9 madde 1 buna göre düzeltildi (P1:
        "zamanlayıcı servisini durdur" ile çelişiyordu); bu görevin kapsam
        satırındaki "kuyruk / zamanlayıcı" da "kuyruk işçisi" yapıldı.
      - Parola komut satırına girmez (psql'e standart girdiden); parola
        dosyası rol güncellendikten sonra geçici addan taşınarak yazılır.
      - Servis tanımı kurulur, etkinleştirilmez; komut adı (`yedek:calistir`)
        BY-025'te kesinleşir, değişirse betik tanımı günceller.
    - BY-002 PASSED notu (P3) de bu commit'te: README §5.3'te veritabanı
      özellikleri ile nesne sahipliği ayrı cümlelerde.
    - Doğrulanamayan: gerçek systemd (`daemon-reload`, `is-enabled`) ve gerçek
      `/etc/sudoers.d` yolu — lokalde geçici dizine yazıldı; sudoers içeriği
      `visudo -cf` ile doğrulandı. Geri alma adımları gerçek adlarla
      denenmedi (test temizliği aynı işlemleri deneme adlarıyla yapıyor).
      `shellcheck` WSL'de kurulu değil, çalıştırılmadı. Betik sunucuda
      çalıştırılmadı (BY-025).
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `53e0b39`
    - **BY-003-R1 [P2] — hedef parola doğrulanmadan geçerli eski kopya siliniyor.**
      `scripts/sunucu-yedekleme-kurulumu.sh:285-294`: hedefte herhangi bir
      dosya varsa eski kopya gereksiz sayılarak siliniyor; gerçek bağlantı
      denemesi ancak 390–397. satırlarda. Bağımsız testte geçerli parola eski
      konuma kopyalandı, hedefteki parola bozuldu: kurulum 1 ile çıktı, fakat
      **geçerli eski dosya silinmişti**. Kesintiye uğrayan doğrudan `install`
      kopyası da iki dosyanın bu şekilde farklı kalmasına yol açabilir.
      **Etki:** başarısız kurulum çalışan kimlik bilgisinin son geçerli dosya
      kopyasını kaybedebilir; geri dönüş ve sonraki kurulum zorlaşır.
      **Dayanak:** D3, T8; kopyanın varlığı doğruluğunu kanıtlamaz (T9).
      **En küçük düzeltme:** kopyayı geçici ada yazıp atomik taşı; eski
      dosyayı ancak yeni dosyanın izinleri ve gerçek bağlantısı doğrulandıktan
      sonra sil. İki dosya farklıysa doğrulamadan hiçbirini silme; açık
      hatayla durmak yeterli. **Doğrulama:** eski geçerli / hedef bozuk
      senaryosunda eski dosyanın içeriği korunmalı ve onunla bağlantı hâlâ
      kurulabilmeli; yarım kopya senaryosunu da kapsa (parolayı çıktıya yazma).
    - **BY-003-R2 [P2] — yetkili inceleme rolü denetimden geçiyor.**
      `scripts/sunucu-yedekleme-kurulumu.sh:380-387`: var olan rolün özellikleri
      `f f f f` değilse yalnız `bilgi` yazılıyor; `SORUN` artmıyor. Bağımsız
      testte kurulumun oluşturduğu inceleme rolüne `SUPERUSER` verildi;
      **`--denetle` yine 0 döndü**. `NOLOGIN` bu rolü salt okunur yapmaz.
      **Etki:** kurulum/denetim, salt okunur olması gereken rolde geniş
      yetkiler bulunduğunu başarı sonucu içinde gizliyor; ileride inceleme
      erişimi bu rol üzerinden verilince salt okuma sınırı sağlanmaz.
      **Dayanak:** `security-privileges`, T9 ve
      [PostgreSQL rol özellikleri](https://www.postgresql.org/docs/18/sql-createrole.html).
      **En küçük düzeltme:** bilerek açılmış `LOGIN` durumuyla
      `SUPERUSER`/`CREATEDB`/`CREATEROLE` gibi tehlikeli özellikleri ayır;
      uygunsuz yetkide `eksik`/hata ve sıfır olmayan çıkış ver. Rolü otomatik
      değiştirmek istenmiyorsa elle düzeltme gereğini açıkça raporla.
      **Doğrulama:** bu özellikler ayrı ayrı açıldığında denetim başarısız
      olmalı, rolü sessizce değiştirmemeli; normal rol senaryosu geçmeli.
    - **BY-003-R3 [P2] — fazla ACL girdileri erişim matrisini aşabiliyor.**
      `scripts/sunucu-yedekleme-kurulumu.sh:202-207,223-234`: izin denetimi
      yalnız sahip/grup/mode değerine bakıyor; ACL yardımcısı da gereken
      girdilerin varlığını arıyor, ek yetkileri denetlemiyor. Bağımsız testte
      `yedekler/` üzerine yayın kullanıcısına `r-x` ACL'si eklendi. Dizin
      hâlâ beklenen `2750` görünüyordu; yayın kullanıcısı dizini gerçekten
      listeledi, fakat **`--denetle` 0 döndü**. §5.5'in "erişmez" koşulu bozuk.
      **Etki:** mevcut/sonradan eklenmiş ACL'ler yasaklanan erişimi sürdürebilir;
      mod bitlerinin doğru olması izin matrisini doğrulamıyor.
      **Dayanak:** T1/T9, `security-privileges` ve
      [ACL ile mod bitlerinin ilişkisi](https://man7.org/linux/man-pages/man5/acl.5.html).
      **En küçük düzeltme:** yönetilen dizinlerde erişim ve varsayılan
      ACL'leri izin verilen tam kümeyle karşılaştır; ek yetkileri denetimde
      bildir, kurulumda tanımlı matrise göre düzelt. Gerekli yayın ACL'lerini
      koru. **Doğrulama:** yayın kullanıcısına yedek/günlük erişimi veren ek
      ACL'de denetim 1 dönmeli; düzeltmeden sonra erişim reddedilmeli, istek ve
      durum/kilit erişimleri çalışmaya devam etmeli.
    - **BY-003-R4 [P2] — systemd dosyasının sahibi ve yazma izinleri denetlenmiyor.**
      `scripts/sunucu-yedekleme-kurulumu.sh:425-430`: içerik aynıysa dosya
      doğrudan güncel sayılıyor. Bağımsız testte oluşturulmuş servis dosyası
      `0666` yapıldı; içerik değişmedi ve **`--denetle` 0 döndü**. Kurulumun
      aynı içerik dalı da izinleri onarmıyor.
      **Etki:** root tarafından yüklenecek servis tanımı başka kullanıcılarca
      değiştirilebilir kalabiliyor; komut ve çalıştıran kullanıcı gibi servis
      ayarları korunmuyor. `sudoers` dosyasında yapılan `root:root 0440`
      denetiminin benzeri servis dosyasında eksik (G1).
      **Dayanak:** `code-review` güvenlik ölçütü, en az yetki ve T8/T9.
      **En küçük düzeltme:** içerikle birlikte `root:root 0644` sahiplik/izin
      kontrolü ekle; kurulumda dosyayı bu özelliklerle güvenli biçimde kur,
      denetimde değiştirmeden uygunsuzluğu bildir. **Doğrulama:** aynı içerik
      korunarak `0666` ve root olmayan sahip senaryolarında denetim 1 dönmeli;
      kurulum onarmalı, sonraki çalıştırma 0 değişiklik vermeli.
    - Kontroller: mevcut test betiği **67/67 geçti**; iki betikte `bash -n`
      ve `git diff --check a643845 53e0b39` temiz. Dört bağımsız geçici test
      yukarıdaki dört açığı doğrulayarak başarısız oldu. Her testte yeni,
      rastgele adlarla Linux servis/web/yayın hesapları ve PostgreSQL
      uygulama/yedek/inceleme rolleri kullanıldı; yalnız yapay `.env` ve
      parola dosyaları üretildi. Gerçek systemd/sudoers dizinlerine yazılmadı.
      Sonunda hesaplar, gruplar, roller ve geçici dizinler temizlendi;
      başlangıçtaki `www-data` grup üyelikleri de korundu. Testler repoya
      eklenmedi; üretim parolası kullanılmadı veya çıktıya yazılmadı.
    - Laravel **v13.24.0** kurulu; Boost MCP yok. Bakım modunun yeni
      zamanlanmış görevlerin uygunluğunu etkilediği vendor'daki
      `Scheduling/Event.php:285` üzerinden doğrulandı. İmza anahtarını
      üretmeme, servis tanımını etkinleştirmeme ve yayın hesabını servis
      grubuna almama kararları kapsamla uyumlu.
    - Önceden açık BY-002-R3 [P3]: README §5.3'te sahiplik açıklaması
      düzeltilmiş. Aynı genelleme BY-007'nin bu commit'teki 827. satırında
      ("sahip rol; ... yalnız --create") hâlâ var; mevcut P3 notunun bu
      görev metnindeki kopyası da düzeltilmeli. Bu yeni bir P2 değildir.
    - Kullanılan skill'ler: `code-review`, `supabase-postgres-best-practices`,
      `laravel-best-practices`, `testing-best-practices`;
      `Dikkat_Edilecekler.md` okundu. Doğrulanamayan: `shellcheck` kurulu değil;
      gerçek systemd çalıştırması, gerçek `/etc/sudoers.d` altında izin
      uygulaması ve gerçek adlarla kaldırma yapılmadı. Backend/frontend kodu
      değişmediği için tam uygulama testleri/build/Pint tekrarlanmadı.
    - **Sonuç:** dört açık P2 nedeniyle `CHANGES_REQUESTED`; BY-003 `DUZELTME`.
      Uygulama, kurulum ve test kodu değiştirilmedi; yalnız bu inceleme ve
      görev durumları yazıldı. Canlı sunucuya bağlanılmadı, kurulum/dağıtım
      yapılmadı; commit/gönderme yok. Canlı çalıştırma BY-025'te ve ayrıca
      kullanıcı talimatıyla yapılma kuralını korur.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): BY-003 R1-R4 tam yetki denetimi, dogrulanmis parola tasima`
    olan commit
    - Dört bulgu da kodda doğrulandı, hepsi kabul. Önce testler yazıldı ve
      kırmızı görüldü (T5: 91 geçti, 34 kaldı; dört senaryo dahil), sonra
      düzeltildi.
    - **R1 kabul, düzeltildi.** Eski kopya artık yalnız bölüm 5'in sonunda,
      yeni yerdeki **aynı içerikli** dosyayla servis hesabı gerçekten
      bağlandıktan sonra siliniyor. İki dosya farklıysa (bozuk hedef, yarım
      kopya) hiçbiri silinmiyor, "elle bakılacak" ile 1 dönüyor. Taşıma
      geçici ada kopyalayıp `mv -T` ile yerine koyuyor; kesilme sinyallerinde
      de EXIT tuzağı geçici dosyayı siliyor (D3). Testler (G1–G6): yalnız
      eski geçerli → taşınır, eski silinir, geçici dosya kalmaz; ikisi aynı ve
      geçerli → denetim söyler ve silmez, kurulum siler; **eski geçerli /
      hedef bozuk → eski içeriği aynen korunur, onunla hâlâ bağlanılır**,
      parola çıktıda yok; yarım kopya → eski korunur; ikisi aynı ama rolün
      parolası başka → eski korunur; yalnız eski ve geçersiz → kopyalanır,
      eski silinmez.
    - **R2 kabul, düzeltildi.** Rol özellikleri artık altı alan (giriş,
      veritabanı açma, yönetici, rol açma, çoğaltma, RLS atlama). İnceleme
      rolünde giriş dışındakilerden biri açıksa denetim `EKSİK` verir ve role
      dokunmaz; kurulum hepsini kapatır, girişe dokunmaz (giriş açıksa yalnız
      bilgi). Yedek rolünde de çoğaltma / RLS atlama artık denetleniyor.
      **Kardeş açık (G1/G5):** fazla rol üyeliği de yetkiyi genişletir
      (`emor_inceleme → erp`, `emor_yedek → pg_write_all_data`); ikisi de
      bildiriliyor, betik vermediği üyeliği geri almıyor (elle bakılır).
      Testler: yönetici açık → denetim 1, rol değişmedi, kurulum kapattı;
      veritabanı açma / rol açma / çoğaltma / RLS atlama ayrı ayrı → denetim
      1; yalnız giriş açık → denetim 0 + bilgi; iki fazla üyelik → denetim
      ve kurulum 1, üyelik duruyor.
    - **R3 kabul, düzeltildi.** `acl()` (gerekenin varlığına bakan) kaldırıldı;
      `acl_denetle` her yönetilen yolun ACL'sini (varsayılan ACL dahil)
      §5.5'in tam karşılığıyla karşılaştırıyor, farkı "fazla / eksik girdi"
      olarak yazıyor, kurulum `setfacl --set` (+ beklenmiyorsa `-k`) ile tam
      kümeye getiriyor. Kapsam: kök, `yedekler/`, `istekler/`, `durum/`,
      `gunluk/`, `gizli/`, kilit, `pgpass`, `imza.anahtar`. Ön deney (T7):
      `--set` varsayılan ACL'ye dokunmuyor (bu yüzden `-k`), setgid bitini
      koruyor. Testler: yayına `yedekler/` ek ACL'si (önce gerçekten
      listeleyebildiği ve izin `2750` göründüğü doğrulandı), `gunluk/`'e
      varsayılan ACL, `pgpass`'a web ACL'si → denetim 1 ve dokunmuyor; kurulum
      sonrası yayın listeleyemiyor, web hâlâ okuyor, yayın hâlâ istek
      bırakıyor ve kilit alıyor; sonraki kurulum 0 değişiklik.
    - **R4 kabul, düzeltildi.** Servis tanımı ve sudoers aynı denetimden
      geçiyor (`sistem_dosyasi_durumu`): yok / düz dosya değil (bağlantı) /
      içerik farklı / sahip-izin farklı. Yazma tek yerde (`guvenli_yaz`):
      aynı klasörde noktalı geçici ad → `root:root` + izin → (sudoers için
      `visudo -cf`) → `mv -T`. Önceki sudoers yazımı `/tmp`'de doğrulayıp
      `install` ile yazıyordu; artık o da tek adımda yerine geliyor (G1).
      Testler: `0666` ve sahibi `nobody` → denetim 1 ve dokunmuyor; kurulum
      `root:root 644` yapıyor; sonraki kurulum 0 değişiklik.
    - **BY-002-R3 [P3] kopyası düzeltildi:** BY-007 kapsamında veritabanı
      özellikleri (`--create` gerektirir) ile nesne sahibi (`--create`
      olmadan da uygulanır; `--no-owner` + `--role`, manifestteki sahip
      denetim içindir) iki maddeye ayrıldı.
    - `Dikkat_Edilecekler.md`: **G5** (yetki denetimi izin verilen tam
      kümeyle — R2…R4'ün ortak nedeni) ve **D4** (eskiyi, yenisi gerçek
      kullanımla doğrulanmadan silme — R1) eklendi. G1 taraması: diğer
      betiklerde (`gecici-gunluk-yedek.sh`, `deploy.sh`) ACL / rol denetimi
      yok; günlük yedekte eski dosya temizliği yeni yedek doğrulandıktan
      sonra — aynı hata yok.
    - [sunucu-kurulumu.md](sunucu-kurulumu.md): §1 madde 6, §2 "tam kümeyle
      karşılaştırma", §3 parola dosyası taşıma kuralı.
    - Kontroller: `bash -n` temiz; test betiği WSL'de root, deneme adlarıyla
      **125/125 geçti** (önceki 67'nin hepsi dahil; F'deki ACL mesaj kalıbı
      yeni biçime uyarlandı). Sonda kullanıcı, grup, rol, klasör kalmadı.
    - Doğrulanamayan (önceki gibi): gerçek systemd / `/etc/sudoers.d`
      (geçici dizine yazıldı), gerçek adlarla kaldırma, `shellcheck` (kurulu
      değil). Betik sunucuda çalıştırılmadı (BY-025); canlıya alınmadı.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `9112994`
    - **Önceki R1–R4 kapandı.** Geçerli eski parola kopyası bozuk hedefte
      korunuyor; aynı içerik ve gerçek bağlantı doğrulanmadan silinmiyor.
      İnceleme rolünün geniş özellikleri ve beklenmeyen üyelikler bildiriliyor;
      denetim değişiklik yapmıyor. Fazla ACL'ler yakalanıp kurulumda kaldırılıyor.
      Servis/sudoers içeriği, dosya türü ve sahip/izin kontrolleri ortak,
      yazım geçici addan atomik taşıma ile. Eski dört senaryo bağımsız
      testlerle yeniden denendi ve geçti. G5/D4 eklentileri uygun; BY-002-R3'ün
      BY-007 metnindeki kopyası da düzeltilmiş.
    - **BY-003-R5 [P1] — servis hesabının koyduğu sembolik bağlantı üzerinden root dosyası değiştirilebiliyor.**
      `scripts/sunucu-yedekleme-kurulumu.sh:231-246` (`izin_denetle` /
      `klasor`), ayrıca `310-312` ve `353-355`: yönetilen nesneye sahiplik,
      mod ve ACL uygulanmadan önce bağlantı/tür sınırı doğrulanmıyor. `-f`
      ve `-d` bağlantının hedefini kabul ediyor; `stat` bağlantının kendisini
      farklı izinli görünce "düzeltme" dalına giriliyor. Ardından `chown`,
      `chmod` ve ACL işlemleri hedefe uygulanıyor. Yeni sistem dosyası
      denetimindeki `-L` koruması bu yollarda yok (G1).
      **Ön koşul:** root kurulumunun yeniden çalıştırılmasından önce servis
      hesabı, yazabildiği yönetilen dizinde bir dosya/alt dizini sembolik
      bağlantıyla değiştirebiliyor. Bunun için root izni gerekmiyor.
      **Bağımsız kanıt:** yalnız test alanında, yönetilen yedek kökünün
      dışında root'a ait bir `0600` dosya ve bir `0700` dizin hazırlandı.
      Servis hesabının başlangıçta bunlara erişemediği doğrulandı. Sonra:

      1. Servis hesabı `gizli/pgpass` yerine root dosyasına bağlantı koydu.
         Root kurulumundan sonra dosyanın sahibi servis hesabı oldu ve
         hesap dosyayı okuyabildi. Betik bağlantı denemesinde 1 ile çıkmış
         olsa da korunan dosyanın sahipliği çoktan değişmişti.
      2. Servis hesabı `yedekler/` yerine root dizinine bağlantı koydu.
         Root kurulumundan sonra dizinin sahibi servis hesabı oldu, hesap
         dizine yazabildi; betik **0** ile çıktı.

      **Etki:** servis hesabı işletim sistemi yöneticisi olmamasına rağmen
      root kurulumunu kullanarak yönetilen alan dışındaki korunan nesnelere
      erişim kazanabiliyor. Bu, servis/root yetki sınırını bozan P1 güvenlik
      açığıdır; normal kurulum testlerinin geçmesi bunu engellemiyor.
      **Köken:** aynı yol tabanlı `chown`/`chmod` satırı `53e0b39`'da da vardı;
      açık bu düzeltmeyle getirilmedi, bu incelemede yeni saptandı.
      **Dayanak:** `code-review` güvenlik ölçütü, `security-privileges`, G1/G5
      ve [CWE-59: bağlantı hedefinin yanlış çözülmesi](https://cwe.mitre.org/data/definitions/59.html).
      **En küçük düzeltme:** yönetilen kök, üst bileşenler ve nesnelerde
      sembolik bağlantı/beklenmeyen türü sahiplik veya ACL değişikliğinden
      önce reddet; bağlantıyı normal bir izin farkı olarak onarma. Dosyayı
      değiştirme seçilecekse hedefini izlemeyen güvenli atomik değiştirme
      kullan. Yol doğrulaması ile değişiklik arasında servis hesabının yolu
      başka nesneye çevirebilmesini de önle; yalnız `chown -h` eklemek,
      sonraki `chmod`/ACL ve üst dizin bağlantılarını güvenli yapmaz.
      **Doğrulama:** iki senaryo kalıcı regresyon olsun; hedef root nesnesinin
      sahibi, modu, ACL'si ve içeriği değişmemeli, servis hesabı erişim
      kazanmamalı. `pgpass`, imza anahtarı, kilit ve yönetilen alt dizinlerde
      aynı kalıbı tara (G1); normal kurulum, salt denetim ve tekrar kurulum
      kontrolleri geçmeye devam etsin. Gerçek sistem dosyasıyla deneme yapma.
    - Kontroller: mevcut betik testi **125/125 geçti**; iki betikte `bash -n`
      ve `git diff --check 53e0b39 9112994` temiz. Altı bağımsız geçici testin
      dördü eski R1–R4'ün düzeltmelerini doğrulayarak geçti; ikisi R5'teki
      dosya/dizin bağlantısı açıklarını doğrulayarak başarısız oldu.
      Denemeler yalnız rastgele adlı yerel Linux hesapları, PostgreSQL
      rolleri, yapay `.env`/parola ve geçici root nesneleriyle yapıldı.
      `/etc` dosyalarına, gerçek parola dosyalarına veya canlıya dokunulmadı.
      Bütün deneme hesapları/grupları/rolleri ve geçici dizinler temizlendi;
      kalan sayıları **0** olarak ayrıca kontrol edildi. Başlangıçtaki
      `www-data` grup üyelikleri korundu; testler repoya eklenmedi.
    - Kullanılan skill'ler: `code-review`, `supabase-postgres-best-practices`,
      `laravel-best-practices`, `testing-best-practices`;
      `Dikkat_Edilecekler.md`, G5 ve D4 okundu. Kurulu Laravel `v13.24.0`.
      Doğrulanamayan: `shellcheck` kurulu değil; gerçek systemd çalıştırması,
      gerçek `/etc/sudoers.d` yolu ve gerçek adlarla kaldırma tekrarlanmadı.
      Backend/frontend kodu değişmediği için tam uygulama testleri/build/Pint
      çalıştırılmadı; canlı PostgreSQL/sunucuya bağlanılmadı.
    - **Sonuç:** R1–R4 kapalı; yeni açık R5 [P1] nedeniyle `CHANGES_REQUESTED`,
      görev `DUZELTME`. Bu tur yalnız inceleme günlüğü ve BY-003 durumları
      yazıldı; uygulama/kurulum/test kodu değiştirilmedi. Commit/gönderme veya
      canlı işlem yapılmadı. BY-025 ve açık kullanıcı talimatı koşulu sürer.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): BY-003 R5 baglanti guvenligi` olan commit
    - **R5 kabul, düzeltildi.** Kodda doğrulandı: `izin_denetle` / ACL
      düzeltmeleri yolu izliyordu; kökün sahibi servis olduğundan alt
      klasörler de değiştirilebiliyordu. Önce testler (F3) yazıldı, kırmızı
      görüldü (T5: 158'den 32'si kaldı; korunan root dosyası gerçekten
      servisin oldu), sonra düzeltildi.
    - Düzeltme (betikteki "Bağlantı güvenliği" notu):
      - **Kök klasörün sahibi root** (`root:emor-yedek 0750`; ACL aynı). Servis
        hesabı alt klasörleri silemez / yerine bağlantı koyamaz; böylece
        kökten itibaren yol bileşenleri sabit. Kurulum kökü (sahip, izin,
        ACL) alt klasörlerden önce düzeltir; eski kurulumdan servis sahipli
        kök kalmışsa `chown -h` ile root'a geçer.
      - **Üst klasörler:** kökün üstü, systemd ve sudoers klasörleri her
        bileşende bağlantı değil, sahibi root, grup/başkaları yazamaz
        (yapışkan bitli `/tmp` hariç) — değilse betik başta durur.
        `fs.protected_hardlinks=1` istenir. Sunucuda salt okuma ile
        doğrulandı: hepsi `root 755`, `protected_hardlinks=1`.
      - **Tür denetimi:** yönetilen her yol (kök, beş alt klasör, kilit,
        `pgpass`, `imza.anahtar`, eski `pgpass` yeri) dokunulmadan önce
        `tur_denetle`'den geçer; sembolik bağlantı, beklenmeyen tür ya da
        birden çok adı olan dosyada kurulum **o yola dokunmadan durur**,
        denetim eksik yazar ve yolu sonraki adımlarda atlar.
      - **Doğrulama–değişiklik arası yarış:** servis hesabına ait nesnelerde
        root yalnız `chown -h` yapar (bağlantıyı izlemez); `chmod` ve
        `setfacl` nesnenin sahibi olarak (`runuser`) çalışır. Yol arada
        bağlantıya çevrilse bile işlem servis hesabının yetkisiyle yapılır,
        root dosyasında `EPERM` alır. Kilit dosyasını ve parola dosyasını
        (taşıma ve yeni parola) servis hesabı yazar: root, servisin
        yazabildiği klasörde dosya açmaz. Parola yine standart girdiden.
    - **G1 taraması:** R5'in kalıbı (root'un bağlantı izleyen yazma/izin
      işlemi) betikte tek tek arandı: `install -d` yalnız root'un klasöründe;
      kök ACL'si root olarak (kök root'un); `guvenli_yaz` yalnız root'un
      klasörlerinde (üst yol denetimiyle); eski `pgpass` yalnız okunur / `rm`
      (bağlantıyı izlemez). Root'un geri kalan işlemleri okumadır (`stat`,
      `getfacl`, `cmp`), tür denetiminden sonra. Diğer betikler
      (`gecici-gunluk-yedek.sh`, `deploy.sh`) root olarak çalışmıyor.
    - Testler (F3, kalıcı regresyon): ön koşul olarak servisin korunan root
      dosyasına / klasörüne erişemediği; servisin alt klasörü taşıyamadığı,
      köke bağlantı koyamadığı. Servis hesabı `gizli/pgpass`,
      `gizli/imza.anahtar`, `durum/kilit` yerine korunan dosyaya bağlantı
      koyunca: denetim 1 ve bağlantıyı söylüyor, kurulum 1 ve duruyor;
      **korunan dosya ve klasörün sahibi, izni, ACL'si, içeriği aynı**, servis
      hâlâ erişemiyor. Kilit yerine sabit bağlantı → kurulum durur, korunan
      nesne aynı. `yedekler/` yerine bağlantı (eski kurulum durumu) → denetim
      ve kurulum 1, korunan nesneler aynı. Üst klasör `777` → kurulum durur.
      Geri konunca kurulum 0, denetim temiz. F'ye "kökün sahibi servis"
      bozulması eklendi; E'de kökün beklenen sahibi `root`.
    - Dokümanlar: README §5.5 (kökün sahibi root ve nedeni),
      [sunucu-kurulumu.md](sunucu-kurulumu.md) §2 tablo + "Bağlantı
      güvenliği". `Dikkat_Edilecekler.md`: **D5** (root, başkasının
      değiştirebildiği yolda bağlantı izleyerek işlem yapmasın).
    - Kontroller: `bash -n` temiz; test betiği WSL'de root, deneme adlarıyla
      **158/158 geçti** (önceki 125 dahil). Sonda kullanıcı, grup, rol,
      klasör kalmadı.
    - Doğrulanamayan (önceki gibi): gerçek systemd / `/etc/sudoers.d`,
      gerçek adlarla kaldırma, `shellcheck` (kurulu değil). Betik sunucuda
      çalıştırılmadı (BY-025); canlıya alınmadı. Sunucuya yalnız salt okuma
      için bağlanıldı (`protected_hardlinks`, klasör sahiplikleri).
  - 2026-11-18 Kullanıcı → **PASSED** · `fc8d86a`
    - Codex'in `fc8d86a` incelemesi aracın güvenlik içerik filtresine takıldı
      ("Bu içerik gösterilemiyor"); inceleme sonucu alınamadı. Kullanıcı
      kararıyla görev `PASSED` sayıldı.
    - R1–R4 Codex tarafından kapatılmıştı (önceki kayıt). **R5 düzeltmesi
      bağımsız incelemeden geçmedi**; yalnız Claude'un testleriyle (158/158,
      önce kırmızı) doğrulandı. BY-025'te betik sunucuda çalıştırılmadan önce
      `fc8d86a`'nın bağlantı güvenliği bölümüne yeniden bakılması önerilir.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · Fable incelemesi düzeltmeleri;
    başlığı `fix(yedekleme): Fable incelemesi F1-F19` olan commit (ayrıntı
    BY-005 günlüğünde). Bu göreve düşenler:
    - **F1 kabul, yöntemi değiştirilerek.** Sunucuda (salt okuma)
      `bootstrap/cache/*.php` ve `storage/framework/views` `admin:www-data`,
      g+w; kod klasörleri (`app`, `vendor`, `config`, `routes`) `admin:admin`.
      Servis bunları yükleseydi ele geçirilmiş web süreci servis hesabıyla
      kod çalıştırırdı. Servis tanımı artık `APP_CONFIG_CACHE`,
      `APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_ROUTES_CACHE`,
      `APP_EVENTS_CACHE`, `VIEW_COMPILED_PATH`'i yeni `onbellek/` (0700,
      servisin) klasörüne yönlendiriyor. **Önerilen `LARAVEL_STORAGE_PATH`
      uygulanmadı:** servis geri yüklemede `php artisan down` ile bakım
      moduna alır; storage yolu değişirse `down` dosyası servisin klasörüne
      yazılır, site bakım moduna girmez. Test E2: uygulama servis tanımındaki
      ortamla açılınca `get_included_files()`'ta uygulamanın
      `bootstrap/cache`'i ve `storage`'ı yok, `view.compiled` servisin
      klasöründe (eski betikte kırmızı görüldü).
    - **F2 kabul, yöntemi değiştirilerek.** Günlük kanalları (`single`,
      `daily`, `denetim`) `'permission' => 0664`: günün dosyasını servis
      (UMask 0027) açsa da web yazar, tersi de. `GunlukDosyasiIzniTest`.
    - **F3 kabul.** Role düz parola yerine SCRAM-SHA-256 doğrulayıcısı
      gönderiliyor. Test H: `log_statement=all` açıkken PostgreSQL
      günlüğünde komut var, düz parola yok (eski betikte düz parola günlükte
      bulundu — bulgu gerçek veriyle doğrulandı); yeni parolayla bağlanılıyor.
    - **F18 kabul.** `guvenli_yaz` ve parola dosyası yazımı: dosya ve
      taşımadan sonra klasör `sync`.
    - Kurulum testleri root ile 168/168 (eski betik: 162/168, yalnız yeni 6
      kontrol kırmızı).
    - **Kapsam dışı, kullanıcıya bildirildi:** aynı yazılabilir önbelleği
      bugün `deploy.sh` de `admin` (sudo yetkili) olarak yüklüyor. Ayrı iş
      olarak önerildi; değiştirilmedi.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `6c96ea8`
    - **F1'in önbellek ayrımı doğrulandı.** Servisle aynı önbellek ortam
      değişkenleriyle Laravel 13.24.0 açıldı. Paylaşılan `bootstrap/cache`
      ve `storage` altından PHP dosyası yüklenmedi. Derlenmiş görünüm yolu
      ayrıldı, bakım modu için uygulamanın storage yolu ortak kaldı.
    - **F3 doğrulandı.** Betikteki SCRAM üreticisiyle geçici PostgreSQL 16
      örneğinde deneme rolü açıldı. Parola dosyasıyla giriş başarılı oldu.
      Oturuma özel `log_statement=all` ile DDL kaydı görüldü, düz parola
      görünmedi. F18'in dosya ve dizin eşitleme sırası kodda görüldü.
    - **BY-003-R6 [P2] — servis, web hesabının değiştirebildiği günlük yoluna yazıyor.**
      `backend/config/logging.php:67` ve `:144`,
      `scripts/sunucu-yedekleme-kurulumu.sh:258`. F2'nin `0664` değişikliği
      dosya kipini düzenliyor. Laravel'in normal ve acil durum
      günlükleri web hesabının da yazabildiği ortak dizinde kalıyor.
      Monolog hedefi bağlantıyı izleyen bir açılışla açıyor ve ardından
      dosya izni uyguluyor. **Etki.** Daha az yetkili hesap günlük
      hedefini değiştirebildiğinde imza ve geri yükleme servisinin
      dosya yazma işlemini kendi günlük alanı dışında yönlendirebilir.
      Ayrıca `0664` ortak web günlüklerinin dosya düzeyinde diğer
      yerel kullanıcılara okuma izni veriyor. **Dayanak.** README §5.2
      servis hesabı sınırı, D5/D7 ve code-review yan etki denetimi.
      **Düzeltme.** Servis günlüklerini web hesabının değiştiremediği
      bir hedefe yaz. Servise özel dizin veya stderr/journald kullanılabilir.
      Acil durum kanalını ve varsa adıyla çağrılan dosya kanallarını da
      kapsa. Bakım modu dosyası ortak kalabilir. **Doğrulama.** Servis
      ve web kendi günlüklerine yazabilmeli, web servisin günlük hedefini
      değiştirememeli. Bir yazım hatasında yedek kanalın da ayrı kaldığını
      doğrula. Mevcut test yalnız dosya kipini sınar.
    - **BY-003-R7 [P3] — parola testi yerel PostgreSQL ayarını değiştiriyor.**
      `scripts/tests/sunucu-yedekleme-kurulumu.test.sh:44` ve `:296`.
      Başlangıç temizliği ve H adımı `postgres` rolünün `log_statement`
      ayarını sıfırlıyor. Önceden tanımlı rol ayarı kaybolabilir, ayrıca
      test sırasında aynı rolle açılan başka oturumlar da ayrıntılı günlük
      üretir. **Dayanak.** testing-best-practices `isolation`.
      **Düzeltme.** Günlük seçimini deneme oturumuna veya yalıtılmış
      PostgreSQL örneğine bağla. **Doğrulama.** Mevcut yönetici ayarı
      testten önce ve sonra aynı kalmalı, SCRAM ve düz parola kontrolleri
      geçmeli.
    - **Ortak bulgu BY-001-R6.** `PGPASSWORD` varken eski kopya silme
      kararını veren bağlantı testi `PGPASSFILE` ile kimlik doğrulandığını
      tek başına kanıtlamıyor. Kurulumdaki çağrıyı da düzelt ve sına.
    - Betik sözdizimi temiz. Root gerektiren 168 denetimli senaryo yeniden
      koşulmadı. Test yerel `postgres` rolünün ayarlarını sıfırlıyor;
      servis ortamı ve F3'ün ilgili bölümleri ayrı yerel deneylerle sınandı.
      Gerçek servis ve güç kesintisi denenmedi. `deploy.sh`'ın mevcut
      önbellek yolu BY-003 dışında. Canlı durum bu incelemede sorgulanmadı.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 6c96ea8 incelemesi (BY-001/003/004/005)` olan commit
    - **R6 kabul, düzeltildi.** `config/logging.php` bütün dosya kanallarının
      klasörünü `LOG_KLASORU`'ndan alıyor (boşsa `storage/logs`); servis
      tanımı `LOG_KLASORU=<kök>/gunluk` veriyor (servisin klasörü, web
      yalnız okur). F2'nin `0664` izni geri alındı (ayrı klasörde gereksiz;
      ortak dosyaları diğer yerel kullanıcılara okunur yapıyordu).
      **Bulgunun kapsamı genişti:** testler, Laravel 13'ün varsayılan
      config'inden birleşerek gelen `monthly` kanalının ve Boost'un (yalnız
      geliştirme) `browser` kanalının da `storage/logs`'ta kaldığını yakaladı;
      ikisi uygulama config'inde aynı klasörle tanımlandı.
      Testler: `GunlukKlasoruTest` — `LOG_KLASORU` verilince single, daily,
      monthly, browser, denetim, emergency hepsi o klasörde; boş / tanımsızsa
      `storage/logs`; **çalışma zamanındaki** her dosya kanalı uygulama
      config'inde tanımlı (çerçeve / paket yeni kanal eklerse kırmızı). Kurulum
      testi E2: uygulama servis ortamıyla açılınca bütün kanalların yolu
      `<kök>/gunluk/` altında. Eski config ve eski betikle kırmızı görüldü.
      **Kapsam notu:** bakım modu dosyası ortak kalıyor (Codex'in de dediği
      gibi); onu yazan kod da bağlantı izlememeli — BY-028 kabul ölçütüne yazıldı.
    - **R7 kabul, düzeltildi.** Test artık `postgres` rolüne ya da sunucuya
      kalıcı ayar yazmıyor: H adımında yalnız o çalıştırmanın oturumlarına
      `PGOPTIONS='-c log_statement=all'` veriliyor. Bunu mümkün kılan
      betikteki R6 düzeltmesi: servis bağlantı denetimi `env -i` ile temiz
      ortamda (aksi hâlde PGOPTIONS servise de geçerdi). Test, `postgres`
      rolünün `pg_db_role_setting` kaydının önce ve sonra aynı olduğunu sınıyor;
      koşudan sonra kayıt sayısı 0. `temizle`'deki sıfırlama kaldırıldı.
    - **BY-001-R6 (ortak) kabul, düzeltildi.** Bağlantı denetimi
      `runuser … env -i PATH=… PGPASSFILE=… psql`. Test G5b: rolün parolası
      başka, iki dosya aynı ve geçersiz, kabukta rolün GERÇEK parolası
      `PGPASSWORD` → kurulum "bağlanamıyor" der, eski kopya silinmez. Eski
      betikte kırmızı: ortam parolasıyla bağlantı başarılı sayılıp **eski
      kopya silindi** (bulgu gerçek veriyle doğrulandı).
    - Kurulum testleri (root) **174/174**; eski betikle 8 kırmızı.
  - 2026-11-18 Codex → PASSED · incelenen commit `775a0fd`
    - **R6 kapandı.** Kurulum servis ortamına `LOG_KLASORU` veriyor.
      Laravel'deki dosya kanalları, `monthly`, Boost `browser` ve
      `emergency` dahil, kurulum denemesinde servis klasörü altında.
      `0664` gevşetmesi kaldırıldı. Ortak bakım modu dosyasına ilişkin
      güvenli yazma kabul ölçütü BY-028'de.
    - **R7 ve BY-001-R6'nın kurulum karşılığı kapandı.** Oturuma özel
      günlük ayarı çalıştı ve kalıcı yönetici ayarı değişmedi. Doğru
      `PGPASSWORD` ile geçersiz parola dosyası denemesinde bağlantı
      reddedildi ve eski kopya korundu. SCRAM denetimleri de geçti.
    - **Kontroller.** WSL'de root ile deneme adları altında **174/174**.
      Test sonunda kullanıcı, grup ve roller kaldırıldı, `www-data`
      önceki üyeliğine döndü. Geçici systemd ve sudoers yolları kullanıldı.
      Gerçek systemd servisi veya canlı kurulum denenmedi.
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · BY-005-R11'in kurulum karşılığı
    (commit `fix(yedekleme): Codex 03f1cba incelemesi (BY-005-R11)`)
    - Uygulama klasör eşitlemesini kalıcı kökte (`durum/`) durduruyor; kökün
      üstü yalnız geçilebilir olabilir. Bu sözleşmenin gerçek olması için
      betik değişti; yalnız bu değişiklik incelenecek:
      - Her kurulumda kök ve alt klasörler, üst klasörleriyle birlikte
        diske zorlanıyor (`kalici_kil`). Klasör önceden açılmış olsa da
        (D3); denetim kipinde değil.
      - Kilit dosyası açılınca dosya ve `durum/` servis hesabıyla
        eşitleniyor (D5: root, servisin yazabildiği klasörde bağlantı
        izleyerek iş yapmaz).
    - Test: sahte `sync` çağrıları kaydediyor. Eski betikle 4 kırmızı;
      kurulum testleri (root) **179/179**. Ayrıntı BY-005 kaydında.
  - 2026-11-19 Codex → PASSED · incelenen commit `997dba3`
    - BY-005-R11'in kurulum karşılığı doğrulandı. Kurulum kökü,
      durum klasörünü ve kilit girdisini eşitliyor. Yeniden kurulum
      bu adımları tekrarlıyor. Denetim kipinde yazma yapılmıyor.
    - WSL'de yerel PostgreSQL ve test hesaplarıyla **179/179**.
      Bitişte test kullanıcısı, grubu, rolleri ve dizini temizlendi.
      Canlı sunucu, gerçek systemd ve güç kesintisi denenmedi.

---

## Faz 1 — Çekirdek (yerel diske planlı yedek)

### BY-004 — Veri modeli, yapılandırma, izinler

- **Durum:** PASSED · **Bağımlı:** BY-002
- **Tasarım:** README §5.4, §4.9, §4.2, §4.3
- **Kapsam:**
  - Migration'lar: `yedek_hedefleri`, `yedek_planlari`, `yedek_plan_hedefleri`,
    `yedek_ayarlari`, `yedekler`, `yedek_kopyalari`, `geri_yuklemeler`,
    `yedek_islem_kayitlari` (indeksler ve yabancı anahtarlar dahil).
  - Modeller, factory'ler, enum'lar (kaynak, durum, doğrulama durumu, hedef türü, sıklık türü).
  - `config/yedekleme.php`: `aktif`, yedek klasörü, dışarıda bırakılan tablolar,
    araç yolları, varsayılan süreler. `.env.example` güncellenir.
  - İzin kataloğu (`App\Yetki\Izin`): README §4.9'daki izinler; Roller ekranı etiketleri.
- **Kapsam dışı:** İş mantığı, komutlar, ekranlar.
- **Kabul ölçütleri:**
  - [x] `migrate` ve `migrate:rollback` temiz çalışıyor (SQLite ve PostgreSQL).
  - [x] Hedef parolası `encrypted` cast ile saklanıyor; API çıktısında hiç dönmüyor (test).
    (Hedef ucu henüz yok — BY-016; model serileştirmesinde gizli olduğu sınandı.)
  - [x] Kullanılan hedef / plan silinemiyor (mevcut `KayitSilme` kalıbı → 409).
  - [x] Yeni izinler Roller ekranında görünüyor; izin testleri güncel.
- **Canlı:** Evet (etkisiz; `YEDEKLEME_AKTIF` kapalı).
- **İnceleme günlüğü:**
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `feat(yedekleme): BY-004 veri modeli, yapilandirma, izinler` olan commit
    - Skill'ler: `laravel-best-practices` (migrations, eloquent, config,
      security, style), `testing-best-practices` (test-data, naming,
      assertions, review), `supabase-postgres-best-practices` (schema-*,
      query-partial-indexes). Boost MCP yok; Laravel 13 API'leri mevcut
      kodla (alarm migration'ı, EntegratorBaglanti / MailAyari) karşılaştırıldı.
    - **Migration** `2026_11_18_120043_create_yedekleme_tables` (tek dosya,
      alarm tabloları kalıbı): `yedek_hedefleri`, `yedek_planlari`,
      `yedek_plan_hedefleri`, `yedek_ayarlari`, `yedekler`, `yedek_kopyalari`,
      `geri_yuklemeler`, `yedek_islem_kayitlari`. Silme kuralları:
      kullanılan hedef (plan bağı ya da kopya) ve kopyası olan plan
      **RESTRICT**; kopyasız planın hedef bağları planla gider (CASCADE);
      yedek kaydı silinmez (durum `silindi`), silinirse kopyaları gider,
      geri yükleme geçmişi yedeği tutar (RESTRICT); kullanıcı silinirse
      kişi alanı boşalır. Durum / tür kolonları metin (mevcut kalıp),
      geçerli değerler enum'larda.
    - **Hedef sırları:** `gizli_bilgiler` (`encrypted:array`, `$hidden`) —
      parola, Drive yenileme anahtarı, S3 gizli anahtarı tek alanda; sır
      olmayan bağlantı bilgileri `baglanti` (jsonb).
    - **Enum'lar** (`App\Services\Yedekleme`, mevcut Kesinti / Entegrator
      yeri): YedekKaynagi, YedekDurumu, DogrulamaDurumu, HedefTuru,
      SiklikTuru + kolonları olan KopyaDurumu, GeriYuklemeTuru,
      GeriYuklemeDurumu (README §6.4 durumları). Modeller enum cast'li.
    - **Modeller:** YedekHedefi, YedekPlani (hedefler: pivot + saklama
      kolonları), YedekAyari (tek satır, `ANAHTAR`), Yedek, YedekKopyasi,
      GeriYukleme, YedekIslemKaydi (salt eklemeli, `UPDATED_AT = null`).
      İstekten doldurulacaklar (hedef, plan, ayar) `$fillable`; yalnız kodun
      doldurduğu kayıtlar `$guarded = ['id']` (EFaturaSenkronCalismasi kalıbı).
      **Factory'ler:** hedef (+ `ftp()`), plan, yedek, kopya. GeriYukleme ve
      işlem kaydı factory'si kullanan görevde eklenir (BY-026, BY-006/012).
    - **Yapılandırma** `config/yedekleme.php`: `aktif` (varsayılan kapalı),
      `klasor` (varsayılan `storage/app/yedekler`; BY-025'te
      `/var/lib/emor-yedek/yedekler`), verisi alınmayan tablolar, araç
      yolları, süre sınırları (D1). `.env.example` güncellendi.
    - **İzinler:** `yedekler.goruntule/al/sil/indir`, `geri_yukleme.kopya`,
      `yedek_planlari.goruntule/guncelle`; Roller ekranında iki satır
      (tr/en etiketleri). README §4.9 tablosuna `yedek_planlari.goruntule`
      eklendi (katalog her ekrana görüntüleme izni ister) ve eşleme yazıldı.
      Menüde henüz yedekleme ekranı olmadığından satırlar matrisin "Diğer"
      grubunda; menüye ekranlarla girer (BY-015, BY-017).
    - **İndeksler:** PostgreSQL'de "indekssiz yabancı anahtar" sorgusu
      (skill `schema-foreign-key-indexes`) **0 satır**. Kopya tablosunda
      saklama sorgusu için (plan, hedef, durum); `yedekler.alinma_zamani`;
      işlem kaydında `created_at`. Plan tablosuna "vadesi gelen" indeksi
      konmadı: birkaç satırlık tablo.
    - Testler: `YedeklemeVeriModeliTest` (7): sır ham kolonda düz metin
      değil, model çözüyor, `toArray` / `toJson`'da yok; plana bağlı hedef,
      kopyası olan hedef, kopyası olan plan → 409 `KAYIT_KULLANILIYOR` ve
      kayıt yerinde; kopyasız plan silinince bağları gider hedef kalır;
      kullanılmayan hedef silinir; yedek silinince kopyaları gider.
      `RolTest` katalog testi 32 ekran + iki yedekleme satırının tam içeriği.
      **Bozma denemesi:** RESTRICT'ler CASCADE yapılıp `$hidden` kaldırılınca
      4 test kırmızı; geri yüklenince 7/7.
    - Kontroller: backend **666/666**; Pint temiz; frontend typecheck, lint,
      Roller testleri (11/11) temiz. Migration: geçici SQLite dosyasında ve
      PostgreSQL `erp_test`'te migrate → rollback → migrate temiz
      (`erp_test`'te bekleyen eski bir migration da — pivot izin adı —
      bu sırada uygulandı).
    - Notlar / doğrulanamayan: `yedek_kopyalari` benzersizliği plan boşken
      (elle / yayın yedeği) PostgreSQL'de NULL'lar farklı sayıldığı için
      tekrarı engellemez; o kopyaların akışı BY-008'de belirlenir. Canlıya
      alınmadı. Başka bir iş için deploy olursa: tablolar boş açılır, modül
      kapalı; Roller ekranının "Diğer" grubunda iki yedekleme satırı görünür
      (izin vermek henüz bir şey açmaz — uç yok).
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `a1deab9`
    - **BY-004-R1 [P2] — plansız kopyanın tekrarı şemada engellenmiyor.**
      `backend/database/migrations/2026_11_18_120043_create_yedekleme_tables.php:126`
      plan kimliğine `NULL` izni veriyor. 141. satırdaki bileşik benzersizlik
      kuralı `NULL` değerlerini eşit saymıyor. Gerçek migration ile hem
      SQLite hem PostgreSQL 16.15 üzerinde aynı yedek, hedef ve boş planla
      iki satır kaydedildi. Aynı dolu planla ikinci satır reddedildi.
      Teslim notunda belirtilen durum SQLite için de geçerli.
      **Etki.** Bir yedeğin aynı hedefteki plansız kopyası birden fazla kez
      dizine yazılabiliyor. BY-008'de yeniden deneme ve BY-011'de dizini
      yeniden kurma aynı mantıksal kopyayı çoğaltabilir. Saklama hesabı da
      aynı dosyayı birden çok başarılı kopya olarak sayabilir. Bu akışlar
      henüz yazılmadı. Doğrulanan hata, mevcut tablonun mükerrer kaydı
      kabul etmesidir.
      **Dayanak.** README §5.4'teki kopya kimliği, T7 ve PostgreSQL
      `query-partial-indexes` kuralı. [PostgreSQL benzersizlik](https://www.postgresql.org/docs/18/ddl-constraints.html#DDL-CONSTRAINTS-UNIQUE-CONSTRAINTS),
      [SQLite benzersizlik](https://www.sqlite.org/lang_createindex.html#unique_indexes).
      **En küçük düzeltme.** Dolu planlar için mevcut kısıtı koru. Boş plan
      için `(yedek_id, yedek_hedefi_id)` üzerinde `WHERE yedek_plani_id IS NULL`
      koşullu benzersiz indeks ekle. **Doğrulama.** Aynı plansız ve aynı
      planlı kaydın ikincisi reddedilmeli. Aynı hedefte farklı planlara ve
      aynı yedekte farklı hedeflere izin verilmeli. Her iki veritabanında
      ileri → geri → ileri migration kontrolü sürmeli.
    - **BY-004-R2 [P2] — boş klasör ayarı belgelenen varsayılanı kullanmıyor.**
      `backend/config/yedekleme.php:15` ve `backend/.env.example:75`.
      Laravel 13.24.0 ile uygulama başlatılarak doğrulandı. Değişken
      tanımsızken `storage/app/yedekler` seçiliyor. `YEDEKLEME_KLASORU=`
      açıkça tanımlandığında ise `config('yedekleme.klasor')` boş metin.
      **Etki.** Örnekteki satırı yönergeye uyarak açan kurulum geçerli
      yedek kökü alamıyor. Sonraki komutların kullanacağı yapılandırma
      sözleşmesi tutarsız. **Dayanak.** Laravel `config` kuralı, T7 ve P1.
      **En küçük düzeltme.** Varsayılanı boş değere de uygula ve açık bir
      klasör verildiğinde onu koru. **Doğrulama.** Tanımsız, boş ve özel yol
      durumlarını gerçek Laravel yapılandırması üzerinden karşılaştır.
    - **BY-004-R3 [P3] — kopya factory'si belgelediği planlı veriyi üretmiyor.**
      `backend/database/factories/YedekKopyasiFactory.php:22` ve `29-31`.
      Varsayılan veri, planı olan bir kopya üretiyor ancak yedeğin kaynağı
      `elle` ve hedef planın hedeflerine bağlı değil. SQLite'ta factory ile
      oluşturulup iki ilişki de ayrı ayrı kontrol edildi. **Etki.** Silme
      testleri için yeterli olan bu kurgu, plan ve hedef bazında saklamayı
      sınayan sonraki testlerde tasarımın tersine bir örnek üretir.
      **Dayanak.** `testing-best-practices` test verisi kuralı ve README §4.3.
      **Öneri.** Planlı test verisi gereken yerde `Plan` kaynaklı yedek ve
      saklama ayarı olan gerçek plan–hedef bağı kullanan bir factory state
      veya test kurulumu oluştur. **Doğrulama.** Planlı kurgu doğru kaynağı,
      hedef bağını ve beklenen saklama değerini içermeli. P3 onayı engellemez.
    - **Kontroller.** Backend PHPUnit **666/666** (2.487 doğrulama), Roller
      **11/11**, frontend tür, lint ve derleme geçti. Lint değişmeyen dört
      konumda uyarı verdi. Derleme paket boyutu uyarısıyla tamamlandı. Node
      18 ile ilk Vitest denemesi bağımlılık yüklemede durdu. Kurulu Node
      **24.13.0** ile frontend kontrolleri tamamlandı. `git diff --check`
      temiz. İki veritabanında migration'ın ileri → geri → ileri çalışması
      bağımsız doğrulandı. PostgreSQL'de her yabancı anahtarın kolonları,
      geçerli bir indeksin ilk kolonları olarak doğrulandı. **Eksik indeks yok**.
      Ayrı geçici PostgreSQL kümesi ve bellek içi SQLite kullanıldı.
    - `encrypted:array` + `$hidden` model serileştirmesinde ve 409 silme
      sözleşmesi `KayitSilme` testlerinde doğrulandı. İki izin satırı katalogda
      ve tr/en çevirilerinde var. Modül kapalı ve bu teslimde yedekleme
      rotası, menü girişi veya zamanlayıcı eklenmiyor. Sırlar için hedef API
      doğrulaması BY-016 kapsamına bağlı.
    - **Sonuç.** R1 ve R2 açık, BY-004 `DUZELTME`. İncelemede `code-review`,
      `laravel-best-practices`, `supabase-postgres-best-practices`,
      `testing-best-practices` ve `vercel-react-best-practices` kullanıldı.
      Boost MCP oturumda yok, sürüme bağlı davranış kurulu Laravel üzerinden
      kontrol edildi. Pint tekrarlanmadı. Bu tur yalnız pano ve inceleme
      günlüğü güncellendi. Canlıda işlem yapılmadı.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): BY-004 R1-R3 plansiz kopya tekilligi, bos env varsayilani`
    olan commit
    - Üç bulgu da kodda doğrulandı, hepsi kabul. R1 ve R2 testleri önce
      yazıldı, doğru nedenle kırmızı görüldü (T5): plansız ikinci kopya
      kabul ediliyordu; `YEDEKLEME_KLASORU=` boş yol, boş araç yolları boş
      metin veriyordu (tanımsız ve verilmiş durumlar zaten geçiyordu).
    - **R1 kabul, düzeltildi.** Mevcut `unique(yedek_id, yedek_hedefi_id,
      yedek_plani_id)` korundu; `yedek_kopyalari_plansiz_tek` koşullu
      benzersiz indeksi eklendi (`(yedek_id, yedek_hedefi_id) WHERE
      yedek_plani_id IS NULL`, alarm migration'ındaki `DB::statement`
      kalıbı). Migration canlıya gitmediği için dosyanın kendisi düzeltildi;
      lokal `erp_test` eski sürümden geri alınıp yeniden çalıştırıldı.
      Testler: aynı plansız kopyanın ikincisi ve aynı planlı kopyanın
      ikincisi `UniqueConstraintViolationException`; aynı hedefe farklı plan,
      aynı hedefe plansız ve aynı yedekten farklı hedefe kopya açılıyor.
      `YedeklemeVeriModeliTest` SQLite'ta ve **PostgreSQL `erp_test`'te
      11/11**. Migration iki veritabanında ileri → geri → ileri; indeks
      PostgreSQL'de `pg_indexes`'te, SQLite'ta `sqlite_master`'da görüldü;
      indekssiz yabancı anahtar yine 0.
    - **R2 kabul, düzeltildi.** `config/yedekleme.php`'de varsayılanı olan
      her değişken `env('X') ?: varsayılan` (klasör, üç araç yolu, iki süre
      sınırı). **Kardeş hata (G1):** süreler boş bırakılınca `(int) ''` = 0
      oluyordu — süre sınırı fiilen kalkardı (D1); o da düzeltildi. Yeni
      `YedeklemeYapilandirmasiTest` (5): klasör tanımsız / boş → varsayılan,
      verilmiş → o yol; boş araç yolları ve süreler → varsayılan; verilmiş
      araç yolu ve süre → o değer. Yapılandırma dosyası gerçek Laravel
      `env()` okumasıyla, `$_SERVER` / `$_ENV` / `putenv` üzerinden yeniden
      değerlendiriliyor; testten sonra ortam geri yükleniyor.
      **G1 taraması, düzeltilmeyen:** `config/erp.php` `ERP_ADMIN_KULLANICI`
      ve `config/entegrator.php` `EFATURA_SENKRON_AKTIF` de boş değerde
      varsayılanı kullanmıyor (boş → kullanıcı adı `''`, senkron kapalı).
      Bu görevin kapsamı dışı (başka modüller); kullanıcıya bildirildi.
    - **R3 kabul, düzeltildi.** `YedekKopyasiFactory` varsayılanı artık
      tutarlı planlı kurgu: yedeğin kaynağı `plan`, hedef plana
      `sakla_son_adet = 14` ile bağlanıyor (`configure` / `afterCreating`,
      bağ yoksa). `plansiz()` durumu: elle yedeğin plansız kopyası. Test:
      planlı kurguda kaynak, plan–hedef bağı ve saklama değeri doğrulanıyor.
      Kopyası olan hedef testi artık `plansiz()` ile (hedefin plan bağı yok,
      RESTRICT yalnız kopyadan geliyor).
    - `Dikkat_Edilecekler.md`: **G6** (`env()` boş değerde varsayılanı
      kullanmaz) ve **G7** (NULL olabilen kolonlu benzersizlik tekrarı
      engellemez).
    - Kontroller: backend **675/675**; Pint temiz. Frontend değişmedi.
      Canlıya alınmadı.
  - 2026-11-18 Codex → PASSED · incelenen commit `eb36e71`
    - **R1 kapandı.** Koşullu benzersiz indeks aynı yedek ve hedef için ikinci
      plansız kopyayı reddediyor. Mevcut kısıt planlı tekrarı engelliyor.
      Farklı plan ve hedeflerle kayıt yapılabildiği test edildi. SQLite ve
      PostgreSQL 16.15'te ileri → geri → ileri çalıştırıldı ve koşullu indeks
      yeniden oluştu. Migration'ın canlıya uygulanmadığı bildirildi.
    - **R2 kapandı.** Boş klasör, araç yolları ve süre ayarları beklenen
      varsayılanlara dönüyor. Verilen geçerli yol ve süre korunuyor.
      Beş yapılandırma testi geçti.
    - **R3 kapandı.** Varsayılan kopya fabrika verisinde kaynak `plan`, hedef
      plana bağlı ve bağda saklama değeri var. `plansiz()` elle alınmış
      yedeği ve boş planı üretiyor. Veri modeli testi bağı ve saklama
      değerini gerçek veritabanı üzerinden doğruluyor.
    - **BY-004-R4 [P3] — G6'yı uygularken geçerli `false` ve `0` değerleri
      korunmalı.** `Dikkat_Edilecekler.md:48` tüm ayarlarda `?:` öneriyor.
      Bu ifade boş metinle birlikte açıkça verilen `false` ve `0` değerlerini
      de varsayılanla değiştirir. Örneğin kalıbı varsayılanı `true` olan
      `EFATURA_SENKRON_AKTIF`'e uygulamak, açıkça kapatılmış senkronu açar.
      **Öneri.** G6, bir ayarın veri tipine ve geçerli değerlerine göre
      `null` ve boş metni açıkça ele almayı tarif etsin. **Doğrulama.**
      Boş ayar varsayılanı alsın, geçerli `false` ve `0` korunsun. ERP admin
      ve e-Fatura yapılandırması bu incelemenin kapsamı dışında.
    - **BY-004-R5 [P3] — geçersiz süreler sessizce dönüştürülüyor.**
      `backend/config/yedekleme.php:44` ve `:46`. Boş ayar düzeldi. Geçici
      ortam değerleriyle `abc` ve `00` için `0`, `-10` için negatif süre
      çıktığı doğrulandı. Kurulu Symfony Process, `0` süreyi sınırsız olarak
      yorumluyor. Komutlar bu ayarı BY-007 ve geri yükleme görevlerinde
      kullanacak (D1, Laravel `config` kuralı). **Öneri.** Tüketen komutlar
      yazılırken yalnız pozitif tam sayıları kabul et, geçersiz ayarı açık
      hatayla bildir veya belgelenen güvenli varsayılanı kullan.
      **Doğrulama.** Boş, geçerli ve geçersiz değerlerde çalışan sınırı ölç.
    - **BY-004-R6 [P3] — planlı test dosyasının adı `erp_elle_` ile başlıyor.**
      `backend/database/factories/YedekKopyasiFactory.php:31` kaynağı `plan`
      yapıyor, `backend/database/factories/YedekFactory.php:32` adı `elle`
      önekiyle üretiyor. Gerçek factory çıktısında görüldü. Kaynağa göre
      dosya adını sınayan içe alma testlerinde örnek veri kafa karıştırır
      (README §5.5, test verisi kuralı). **Öneri.** İhtiyaç duyulduğunda
      kaynak ve adı birlikte üreten bir factory state kullan.
      **Doğrulama.** Planlı ve plansız örnekte ad öneki kaynakla eşleşsin.
    - Backend SQLite **675/675** (2.504 doğrulama), ayrı geçici PostgreSQL
      kümesinde veri modeli **11/11** (32 doğrulama) ve `git diff --check`
      geçti. Geçici küme temizlendi. Frontend değişmediği için önceki
      kontroller korundu. Pint bu tur yeniden çalıştırılmadı.
    - Kullanılan skill'ler `code-review`, `laravel-best-practices`,
      `supabase-postgres-best-practices` ve `testing-best-practices`.
      **Sonuç.** R1–R3 kapalı ve açık P1/P2 yok. BY-004 `PASSED`. Pano ile
      inceleme günlüğü güncellendi. Canlı işlem yapılmadı.
  - 2026-11-18 Claude → P3 notları (durum PASSED kalır)
    - **R4 kabul, düzeltildi:** `Dikkat_Edilecekler.md` G6 artık `?:`'yi
      yalnız boşun asla geçerli olmadığı metinlere öneriyor; `false` / `0`
      geçerli olabilen ayarda `null` ve `''` ayrıca denetlenir. (Bunu, e-Fatura
      izin listesi kilidiyle aynı commit'e giren düzeltme olarak yaptım.)
      `config/yedekleme.php`'de değişiklik gerekmedi: orada `0` geçerli bir
      süre değil (Symfony Process'te sınırsız), varsayılana dönmesi istenen.
    - **R5 kabul, BY-007'ye:** süreleri tüketen komut yalnız pozitif tam
      sayıyı kabul edecek; geçersiz değer (`abc`, `-10`) açık hata. BY-007
      kabul ölçütlerine eklenecek.
    - **R6 kabul, gerektiğinde:** kaynak ve dosya adı önekini birlikte üreten
      factory durumu, adı kaynağa göre sınayan ilk testte (BY-011 tarama /
      BY-013 içe alma) eklenecek.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · Fable incelemesi düzeltmeleri;
    başlığı `fix(yedekleme): Fable incelemesi F1-F19` olan commit (ayrıntı
    BY-005 günlüğünde). Migration canlıya ve yerel veritabanlarına gitmediği
    için yerinde değiştirildi. Bu göreve düşenler:
    - **F6 kabul (şema).** Aynı hedefi paylaşan iki plan aynı yedek + hedef
      için iki kopya satırı açıyordu; fiziksel dosya tek. Kopya artık
      `(yedek_id, yedek_hedefi_id)` tekil; plan bağı yeni
      `yedek_kopya_planlari` tablosunda (kopya CASCADE, plan RESTRICT).
      BY-004-R1'in koşullu indeksi gereksizleşti (kolonlar NULL olamaz).
      "Bir hedef tek plana bağlanır" seçeneği seçilmedi: README §4.2'nin
      varsayılan önerisi (günlük + saatlik + haftalık) aynı hedefi paylaşır.
      `YedekKopyasi::planlar()`, `YedekPlani::kopyalar()` BelongsToMany.
    - **F7 kabul (şema).** `yedekler.yerel_silinme_zamani`: yerel dosya
      gidip uzak kopya kalınca durum `silindi` olmaz. Davranışı BY-010'un
      kabul ölçütüne yazıldı.
    - **F10 kabul.** `YedekIslemKaydi` güncellemeyi ve silmeyi reddediyor
      (model olayı, LogicException); `SONUC_*` sabitleri yerine
      `IslemSonucu` enum'u ve cast.
    - **F11 kabul.** `YedekAyari::$attributes` migration varsayılanlarını
      aynen yansıtıyor (tek satırın anahtarı `ANAHTAR` dahil — sabit artık
      kullanılıyor); test kaydedilen satırla nesneyi karşılaştırıyor.
    - **F9 kabul + G1.** `(bool) env()` → `App\Support\AcKapa::cevir()`
      (boş → varsayılan, tanınmayan → kapalı): `YEDEKLEME_AKTIF`,
      `EFATURA_SENKRON_AKTIF`, `EFATURA_ERP_ISTISNA_YAZ`, `APP_DEBUG`.
      Canlı `.env` değerleri (salt okuma) davranışı değiştirmiyor:
      `APP_DEBUG=false`, `EFATURA_ERP_ISTISNA_YAZ=true`, diğer ikisi
      tanımsız. `AcKapaAyarlariTest` (36 durum).
    - **F12 kabul.** `KAYIT_KULLANILIYOR` mesajı genel: "Bu kayıt başka
      kayıtlarda kullanıldığı için silinemez." (tr, en, ön yüz testi).
    - **F14 kısmen.** İlişki metotları migration'daki yabancı anahtarların
      karşılığı olarak kaldı. `GeriYukleme` RESTRICT kuralı test edildi
      (geri yükleme geçmişi olan yedek silinemez — 409).
    - **R6 artık gerekli:** `YedekFactory::plan()` (kaynak `plan`, ad
      `erp_plan_`); plan kopyası factory'si onu kullanıyor (T10).
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `6c96ea8`
    - **F6/F7 tasarımı.** Bir fiziksel kopyayı paylaşan planlar ve yerel
      dosyanın silinmesini genel silinmeden ayırma README §4.2–4.3 ile
      uyumlu. Benzersiz kopya, birden çok plan ve indeksler temiz şemada
      doğrulandı. F6 için hedef değişikliği sözleşmesi aşağıda açık.
      F7'nin uzak kopyadan yeniden kurma davranışı BY-010/BY-011'de sınanmalı.
      Şema kararları kullanıcı onayını bekliyor.
    - **BY-004-R7 [P2] — uygulanmış migration yeni şemaya yükselmiyor.**
      `backend/database/migrations/2026_11_18_120043_create_yedekleme_tables.php:127`
      ve `:165`. BY-004 önceki günlüğünde eski migration'ın `erp_test`'te
      çalıştırıldığı yazıyor. Ayrı SQLite dosyasında `eb36e71` migration'ı
      çalıştırıldı ve `6c96ea8` ile normal `migrate` çağrıldı. "Nothing to
      migrate" sonucu verdi. Yeni pivot tablo ve `yerel_silinme_zamani`
      gelmedi. **Etki.** Önceki şemaya sahip veritabanı veya o şemadan geri
      yüklenen yedek yeni modelle uyumsuz kalır. Yeni kurulum testleri
      bu yolu görmez. **Dayanak.** Laravel migrations `Treat Deployed
      Migrations as Immutable` ve README §4.5 migration listesi.
      **Düzeltme.** Eski migration'ı uygulanmış hâlinde koru ve şema
      değişimini ileri migration ile yap. Eski plan bağlarını taşı,
      aynı çiftte birden çok kopya varsa güvenli birleştirme kuralını yaz.
      **Doğrulama.** Örnek satırlı önceki şemadan normal `migrate` ile
      geç. Kopya ve plan bağlarını doğrula. Boş kurulum ve yükseltme
      sonunda aynı şema SQLite ve PostgreSQL'de oluşsun.
    - **BY-004-R8 [P2] — kopyanın plan bağı saklama kuralı olmadan kalabiliyor.**
      `backend/database/migrations/2026_11_18_120043_create_yedekleme_tables.php:165`
      ve `backend/app/Models/YedekKopyasi.php:64`. Bellek içi SQLite
      denemesinde hedefi hiç kullanmayan bir plan kopyaya bağlanabildi.
      Geçerli planın hedef ilişkisi kaldırılınca da plan-kopya bağı kaldı,
      o bağı yöneten saklama kuralı kayboldu. **Etki.** BY-010 bu bağı
      hangi kuralla kaldıracağını bilemez. Dosya gereksiz tutulabilir veya
      yanlış varsayım kullanılırsa bir planın saklama kararı atlanabilir.
      **Dayanak.** README §4.3, K3 ve Laravel migrations `Define
      Foreign-Key Constraints Deliberately`. **Düzeltme.** Bağı kopyanın
      kendi hedefindeki gerçek plan-hedef ilişkisine bağla. Bu ilişki
      kullanılırken hedefin kaldırılmasını engelle veya saklama kuralını
      yaşatacak açık bir geçiş tanımla. **Doğrulama.** Yanlış hedefe bağlı
      plan ekleme reddedilmeli. Mevcut hedef çıkarılırken saklama kuralı
      kaybolmamalı. Aynı hedefi paylaşan iki geçerli planın testi kalmalı.
    - **Diğer F maddeleri.** F9, F10 (model işlemleri), F11, F12 ve
      F14'teki silme davranışı mevcut testlerde geçti. Tam backend
      788/788, ön yüz 310/310, tür, lint ve build geçti. Lint değişmeyen
      dosyalarda dört uyarı, build paket boyutu uyarısı verdi.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 6c96ea8 incelemesi (BY-001/003/004/005)` olan commit
    - **R7 gerekçeyle reddedildi (§1.3 madde 6).** Migration hiçbir paylaşılan
      ya da canlı veritabanında uygulanmadı:
      - Canlı (salt okuma, 2026-11-18): sunucunun HEAD'i BY-004'ten önceki
        bir kesinti commit'i; `a1deab9` nesnesi sunucuda yok; sunucunun
        `database/migrations` klasöründe yedekleme migration'ı yok (0).
      - Yerel `erp`: `php artisan migrate:status` → `Pending`.
      - `erp_test` ve SQLite: testler `RefreshDatabase` ile her koşuda
        sıfırdan kurar; önceki günlükte geçen "erp_test'te çalıştırıldı" bu.
      - Codex'in deneme dosyası tek kullanımlık.
      laravel-best-practices *Treat Deployed Migrations as Immutable*:
      "For a local migration that has not been shared or deployed, editing
      and rerunning it may be simpler." Geçmiş şemadan veri taşıyan ileri
      migration, hiç var olmamış satırlar için kod olurdu (K1) ve tek bir
      migration'ı iki parçaya bölerdi. **Taahhüt:** migration canlıda ya da
      paylaşılan bir veritabanında bir kez çalıştıktan sonra her değişiklik
      ileri migration'la yapılır. K6'ya bu kapsam ve kanıt şartı eklendi.
      Anlaşmazlık sürerse karar kullanıcının (§1.3 madde 7).
    - **R8 kabul, düzeltildi.** `yedek_kopya_planlari` artık
      `(yedek_kopyasi_id, yedek_hedefi_id, yedek_plani_id)`; iki bileşik
      yabancı anahtar: (kopya, hedef) → `yedek_kopyalari(id, yedek_hedefi_id)`
      (CASCADE; hedef tablosuna `unique(id, yedek_hedefi_id)` eklendi) ve
      (plan, hedef) → `yedek_plan_hedefleri(yedek_plani_id, yedek_hedefi_id)`
      (RESTRICT). Sonuç: hedefi kullanmayan plan bağlanamaz; bağ varken
      plan-hedef ilişkisi (saklama kuralı) silinemez; plan silinince ilişki
      CASCADE ile gideceği için kopyası olan plan yine silinemez (409). API
      tarafındaki karşılığı BY-017 kabul ölçütüne yazıldı.
      Testler (SQLite ve PostgreSQL 17/17): hedefi kullanmayan plana bağ
      reddediliyor; kopyanın kendi hedefi dışındaki kural bağıyla bağ
      reddediliyor; kopyası olan plan-hedef bağı kaldırılamıyor ve
      `sakla_son_adet` duruyor; aynı hedefi paylaşan iki plan testi kaldı.
      **Bozma denemesi:** plan-hedef anahtarı kaldırılınca 3 test, kopya-hedef
      anahtarı yalnız kopyaya indirilince 1 test kırmızı. PostgreSQL'de
      başarısız `detach` testin işlemini bozuyordu → savepoint (T6).
  - 2026-11-18 Kullanıcı → F6 ve F7 şema kararları **onaylandı** (metin §5
    "Verilmiş kullanıcı kararları"). Uygulama ölçütleri BY-010, BY-011 ve
    BY-017'ye işlendi. `775a0fd` için Codex incelemesi sürer; canlıya alma
    talimatı ayrıca verilecek.
  - 2026-11-18 Codex → PASSED · incelenen commitler `775a0fd`, `4d945de`
    - **R7 itirazı kabul edildi.** Önceki deneyde uygulanan migration'ın
      aynı dosya adıyla yeniden çalışmadığı doğrulandı. O sonucu
      `erp_test` için kalıcı yükseltme gereksinimi saymıştım. Bu incelemede
      yerel `erp` veritabanında migration'ın beklediğini, uygulanmış
      kaydın yeniden kurulan `erp_test`'te olduğunu salt okunur sorguyla
      doğruladım. Canlı için Claude'un günlüğe yazdığı salt okunur
      tespit esas alındı. Paylaşılan/kalıcı bir şema olmadığından yerinde
      değişiklik Laravel migrations *Treat Deployed Migrations as
      Immutable* kuralına uygun ve bu bulgu kapanmıştır.
    - **R8 kapandı.** İki bileşik kısıt hem kopya-hedef eşleşmesini
      hem planın o hedefteki saklama kuralına bağlılığı zorluyor.
      Yanlış hedef ve mevcut kuralı kaldırma testleri SQLite ile geçici
      PostgreSQL 16'da geçti. Kullanıcının F6/F7 onayı BY-010, BY-011,
      BY-017 kabul ölçütlerine doğru işlendi.
    - **BY-004-R9 [P3] — plan bağı kalmayan kopyanın açıklaması F6 ile
      çelişiyor.** `backend/app/Models/YedekKopyasi.php:61` ve
      `backend/database/migrations/2026_11_18_120043_create_yedekleme_tables.php:165`.
      Açıklamalar boş plan bağının yalnız zamanlanmamış yedeği gösterdiğini
      söylüyor. Onaylanan F6'da son bağ kalksa da koruma kuralı bir plan
      yedeğini tutabilir. **Etki.** BY-010 bu yorumu izlerse plan yedeğine
      yanlış saklama kuralı uygulayabilir. **Dayanak.** F6 ve P1.
      **Öneri.** Yorumlarda yedeğin kaynağını bağ sayısından ayır.
      **Doğrulama.** BY-010'daki son bağ kaldırılan korumalı kopya testi
      yedeğin `plan` kaynağının da korunduğunu sınasın.
    - **Kontroller.** Tam SQLite backend **795/795**, ayrı PostgreSQL
      16'da veri modeli, kilit, durum ve günlük **86/86**.
      Ürün kodu ve migration değiştirilmedi, canlı veritabanına erişilmedi.
  - 2026-11-19 Claude → R9 [P3] uygulandı (durum PASSED kalır; yalnız yorum) ·
    başlığı `fix(yedekleme): Codex 775a0fd incelemesi (BY-001-R7, BY-005-R9/R10)`
    olan commit. Migration ve `YedekKopyasi::planlar()` yorumları: bağ sayısı
    yedeğin türünü söylemez; bağsız kopya zamanlanmamış yedeğin de, son bağı
    kalkıp koruma kuralıyla tutulan plan yedeğinin de olabilir; saklama kuralı
    `yedekler.kaynak`'tan seçilir. BY-010'un F6 ölçütüne "bağsız korumalı plan
    yedeğine zamanlanmamış kural uygulanmıyor" testi eklendi. Şema değişmedi.
  - 2026-11-19 Codex · `03f1cba` ile **R9 [P3] kapandı**.
    Model ve migration yorumları kaynak ile bağ sayısını ayırıyor.
    Migration farkı yalnız yorum. BY-010'un kabul ölçütü, son bağı
    kalkmış korumalı plan yedeğine zamanlanmamış saklama kuralının
    uygulanmadığının sınanmasını istiyor. Görev `PASSED`.

### BY-005 — Dosya kilidi ve işlem durumu dosyası

- **Durum:** PASSED · **Bağımlı:** BY-004
- **Tasarım:** README §5.2 (kilit), §6.4 (yeniden başlatmaya dayanıklılık), §7.2
- **Kapsam:**
  - `YedekKilidi`: işletim sistemi düzeyinde dosya kilidi; veritabanına ve
    önbelleğe dayanmaz. "Al / alamazsan vazgeç" ve "bekle" kipleri.
  - `IslemDurumu`: dosyada tutulan işlem durumu (JSON). **Şeması bu görevde bu
    dosyaya yazılır:** işlem kimliği, tür, adım, başlangıç, son güncelleme,
    hata, erişim anahtarı özeti. Atomik yazma (geçici dosya + taşıma).
  - "Açık geri yükleme var mı" sorgusu (BY-029 kullanacak).
- **Dosyaların yeri:** kilit `<durum klasörü>/kilit`, işlem durumu
  `<durum klasörü>/islem.json`. Sunucu düzeninde durum klasörü
  `/var/lib/emor-yedek/durum` (BY-003; kilit önceden açılmış, yayın kullanıcısı
  yalnız okuyabilir — kilit bu yüzden salt okunur açılır). Geçiş döneminde
  (uygulama kullanıcısı) yapılandırmadan gelir; yapılandırma anahtarı ilk
  kullanan görevle (BY-007) eklenir. Sınıflar yolu yapıcıdan alır.
- **Kalıcı kök (BY-005-R11):** sınıflar yolun yanında kurulumun açıp diske
  eşitlediği kökü de yapıcıdan alır; durum klasörü kökün kendisi ya da
  altında olmalıdır. Klasör eşitlemesi kökte durur. Kökün üstü yalnız
  geçilebilir (okumaya kapalı) olabilir; BY-003 bunu kabul eder. Sunucuda
  kök `/var/lib/emor-yedek/durum`'dur: kurulum onu ve kilit dosyasını
  eşitler. Geçiş döneminde kök yapılandırmadan gelir (BY-007), ve kurulmuş
  olmalıdır (ör. `storage/app`); kök yoksa klasör açılmaz.
- **İşlem durumu dosyası şeması (sürüm 1)** — `IslemKaydi`. Tek JSON nesnesi,
  o anki ya da son işlem; geçmiş veritabanında ve olay günlüğünde.

  | Alan | Tür | Anlamı |
  |---|---|---|
  | `surum` | tam sayı, `1` | Şema sürümü; bilinmeyen sürüm bozuk sayılır |
  | `kimlik` | UUID | İşlem kimliği (`geri_yuklemeler.kimlik` ile eşleşir) |
  | `tur` | `yedek` \| `sinama_geri_yuklemesi` \| `geri_yukleme_kopya` \| `geri_yukleme_ustune` | `IslemTuru` |
  | `adim` | boş olmayan metin | İşlemi yürüten kodun adım adı (ör. `bakim_modu`, `pg_restore`, `onay_bekliyor`); değerleri işlemi yazan görev belirler (BY-007, BY-026, BY-028) |
  | `acik` | true / false | İşlem uygulamayı kapalı tutuyor (bakım modu, kuyruk durmuş). Üstüne geri yükleme 2. adımda `true` yapar, servisler açılınca (başarı ya da geri sarma) `false` |
  | `baslangic`, `son_guncelleme` | `YYYY-AA-GGTSS:DD:SS.mikrosaniye+00:00` (UTC) | — |
  | `hata` | `null` \| `{kod, mesaj}` (yalnız bu iki metin alanı) | Son hata |
  | `erisim_anahtari_ozeti` | `null` \| 64 karakter küçük harf hex | İlerleme / onay anahtarının SHA-256 özeti (README §7.2); anahtarın kendisi dosyada yok |
  | `erisim_anahtari_bitis` | `null` \| tarih (yukarıdaki biçim) | Anahtarın geçerliliğinin bittiği an. Özetle **birlikte** verilir ya da ikisi de `null` (süresiz anahtar yok) |
  | `yedek_kimligi` | `null` \| UUID | İşlemin yedeği (alınan ya da geri yüklenen) — manifest kimliği |
  | `hedef_veritabani` | `null` \| `^[a-z_][a-z0-9_]{0,62}$` | Geri yüklemenin açtığı veritabanı (ör. `erp_geri_*`); tırnak gerektirmeyen ad |
  | `onceki_veritabani` | `null` \| aynı desen | Üstüne geri yüklemede eski veritabanının yeni adı (`erp_onceki_*`) |
  | `isteyen_kullanici_id` | `null` \| pozitif tam sayı | İşlemi isteyen; `null` = sistem / komut satırı |

  Son beş alan **kurtarma bağlamıdır** (Fable incelemesi F5): üstüne geri
  yüklemede yer değiştirmeden sonra `geri_yuklemeler` satırı eski veritabanında
  kalır; çökmeden sonra devam / geri sarma kararı, hangi veritabanının
  silineceği ve geçmiş kaydının yeniden yazılması yalnız bu dosyaya dayanabilir.
  Alanların hepsi her zaman bulunur (`null` olabilir); eksik alan bozuk sayılır.

  **Tek yazar:** dosyayı yalnız durum klasörünün kilidini (`<durum
  klasörü>/kilit`, `IslemDurumu::kilit()`) tutan süreç yazar;
  `IslemDurumu::yaz($kayit, $kilit)` o dosyanın kilidi tutulmuyorsa (başka
  bir dosyanın kilidi de olsa — aygıt + düğüm karşılaştırılır) dosyaya
  dokunmadan reddeder (Fable F16, BY-005-R6). Yazılan, doğrulanmış kaydın
  serileştirilmesidir (BY-005-R7). Okumak için kilit gerekmez.

  **Okuma kuralı:** dosyanın yokluğu doğrulanmışsa (üst klasör okunuyor ve
  dosya listede yok) "hiç işlem olmamış". Klasöre erişilemeyen, okunamayan,
  JSON olmayan ya da herhangi bir alanı şemaya uymayan dosya
  `IslemDurumuBozuk` fırlatır; "işlem yok" sayılmaz. Tarih tam biçimde ve
  UTC olmalı (geri biçimlenip karşılaştırılır). **"Açık geri yükleme var mı"** = `tur` üstüne geri
  yükleme **ve** `acik` true; dosya bozuksa istisna — yayın, planlı yedek,
  kuyruk ve zamanlayıcı bunu da "başlama" diye yorumlar (BY-013, BY-029).
  **Yazma:** kayıt önce okumadaki doğrulamadan geçer (geçersizse dosyaya
  dokunulmaz); aynı klasörde nokta önekli geçici dosya, izin 0640, içerik,
  `fsync`, tek adımda `rename`, ardından klasör `fsync` (ilk yazımda yeni
  klasörlerin üstleri de). Hata yolunda geçici dosya silinir, önceki durum
  yerinde kalır; eşitleme hatası başarı sayılmaz.
- **Kabul ölçütleri:**
  - [x] İki süreç aynı anda kilidi alamıyor (test); süreç ölünce kilit düşüyor.
  - [x] Veritabanı bağlantısı yokken kilit ve durum dosyası çalışıyor (test: bağlantı kapalı).
  - [x] Durum dosyası yarım yazılmış hâlde okunmuyor (atomik yazma testi).
  - [x] Bozuk durum dosyası anlaşılır hata veriyor; sessizce "işlem yok" sayılmıyor.
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:**
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `feat(yedekleme): BY-005 dosya kilidi ve islem durumu dosyasi` olan commit
    - Skill'ler: `laravel-best-practices` (architecture, error-handling,
      style), `testing-best-practices` (isolation, assertions, naming,
      review). Laravel 13 `Process` arayüzü (`start`, `signal`, `waitUntil`,
      `running`, `wait`) vendor'dan doğrulandı; Boost MCP yok.
    - Yeni (`App\Services\Yedekleme`): `YedekKilidi` (`al`, `bekleyerekAl`
      süre sınırlı — D1, `birak`; var olan dosya salt okunur açılır, yoksa
      klasörüyle 0640 açılır; "meşgul" false, "açılamıyor" istisna),
      `IslemDurumu` (`oku`, `yaz`, `acikGeriYuklemeVarMi`), `IslemKaydi`
      (şema + denetim), `IslemTuru`, `IslemDurumuBozuk`. Artisan istisnayı
      `app/Exceptions`'a açtı; projede o klasör yok, mevcut kalıp gereği
      servis klasörüne taşındı.
    - Atomik yazmada `tempnam` bilerek kullanılmadı: klasör yazılamazsa
      sistemin geçici klasörüne düşer, taşıma artık tek adım olmaz.
    - Testler (`YedekKilidiTest` 8, `IslemDurumuTest` 23): ikinci tutamaç
      alamaz; **ayrı PHP süreci** tutarken alınamaz, süreç `SIGKILL` ile
      öldürülünce alınır; kilit tutulurken **`flock -n` komutu da alamaz**
      (deploy.sh uyumu); `bekleyerekAl` bırakılınca alır (~1 sn bekleyerek),
      süre dolunca vazgeçer (0,5–3 sn); yazma izni olmayan (0440) dosyada da
      kilit alınır (BY-003'teki yayın kullanıcısı); dosya yoksa klasörüyle
      0640 açılır; açılamıyorsa istisna (meşgul sanılmaz). Durum: yazılan
      kayıt aynen okunur, dosya 0640 ve geride geçici dosya yok; dosya yoksa
      null; açık geri yükleme yalnız açık üstüne geri yüklemede (5 durum);
      şemaya uyan dosya okunur; 12 ayrı bozuk içerik (boş, yarım JSON, liste,
      sürüm, eksik alan, tür, `acik` metin, kimlik, tarih, boş adım, hata,
      özet) istisna ve mesajda dosya yolu; **ayrı süreç 1,5 sn boyunca 200 KB'lık
      durumlar yazarken eşzamanlı okuyucu hiç yarım ya da karışık içerik
      görmüyor**; klasör yazılamazsa önceki durum korunuyor, geçici dosya
      kalmıyor (T8); veritabanı ve önbellek bağlantısı yokken kilit ve durum
      çalışıyor.
    - **Bozma denemesi (T5'in ruhu):** yazma atomik olmayan
      `file_put_contents`'e çevrilince 3 test kırmızı (eşzamanlı okuyucu
      `IslemDurumuBozuk` gördü); bozuk dosya sessizce "yok" sayılınca 12 test
      kırmızı; kilit flock'suz "alındı" deyince 6 test kırmızı; geri
      yüklenince 31/31. Kilit testleri 3 kez art arda çalıştırıldı (zamanlama
      kararlılığı), hepsi geçti.
    - Öz-inceleme düzeltmeleri: tarih hata mesajında ham değer vardı (D2) →
      kaldırıldı; "belki lazım olur" mikrosaniyesiz tarih ayrıştırması (K1)
      kaldırıldı ve bozuk-içerik testlerinin geçerli tabanı yazılan biçime
      çekildi (her durum yalnız kendi nedeniyle reddedilsin; taban ayrıca
      `test_semaya_uyan_dosya_okunur` ile sınanıyor). İlk denemede bir test
      süreç durumunun geç güncellenmesine bağlıydı (yarış) → ölçüt "~1 sn
      beklendi"ye çevrildi.
    - **BY-013 için not:** geçiş döneminde kilit dosyası uygulama
      kullanıcısının klasöründe açılır; `deploy.sh`'ın (yayın kullanıcısı)
      onu okuyabilmesi BY-013'te ele alınmalı (sunucu düzeninde BY-003 ACL'i
      bunu çözüyor).
    - Kontroller: backend **715/715**; Pint temiz. Frontend değişmedi.
      Canlıya alınmadı.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `34e3f48`
    - **BY-005-R1 [P2] — erişilemeyen durum dosyası yok sayılıyor.**
      `backend/app/Services/Yedekleme/IslemDurumu.php:31`. Üst klasöre
      girilemediğinde `file_exists()` false dönüyor ve okuma null ile bitiyor.
      WSL'de açık geri yükleme kaydı yazılıp klasör erişimi kesilince
      `acikGeriYuklemeVarMi()` true yerine false döndü. **Etki.** BY-013 ve
      BY-029 bu sonuca dayanırsa yayın veya otomatik servis başlangıcı
      açık geri yükleme sırasında devam edebilir. **Dayanak.** BY-005 okuma
      sözleşmesi, README §6.4 ve Laravel `error-handling`.
      **En küçük düzeltme.** Null'u yalnız yokluğu doğrulanmış yol için
      döndür. Yolun üst bileşenlerine erişilemiyorsa veya dosya okunamıyorsa
      `IslemDurumuBozuk` fırlat. **Doğrulama.** Erişilebilir klasörde
      dosya yokken null, içindeki açık kayıt izin nedeniyle okunamadığında
      istisna bekle. Geçiş düzeninde ilk çalıştırmayı da kapsa.
    - **BY-005-R2 [P2] — geçersiz kayıt son geçerli durumun üzerine yazılıyor.**
      `backend/app/Services/Yedekleme/IslemDurumu.php:51` ve
      `backend/app/Services/Yedekleme/IslemKaydi.php:29`. Yapıcı boş adımı
      kabul ediyor, yazıcı kaydı denetlemeden yerleştiriyor. Yerel denemede
      `yaz()` başarıyla döndü, ardından `oku()` istisna verdi ve eski kayıt
      kayboldu. **Etki.** Tek hatalı çağrı son geçerli durumu okunamaz
      hâle getirir. **Dayanak.** D3, T8 ve BY-005 şeması.
      **En küçük düzeltme.** Okuma ve yazmada ortak şema doğrulaması kullan,
      geçersiz kaydı dosyaya dokunmadan reddet. **Doğrulama.** Geçerli kayıt
      varken boş adım ya da geçersiz hata alanı yazmayı dene. Yazım hata
      vermeli, önceki dosya baytlarıyla korunmalı ve geçici dosya kalmamalı.
    - **BY-005-R3 [P2] — atomik taşıma elektrik kesintisine karşı tamamlanmıyor.**
      `backend/app/Services/Yedekleme/IslemDurumu.php:67` ve `:71`. Geçici
      dosyaya `fsync` uygulanıyor, taşıma sonrasında üst dizine uygulanmıyor.
      **Etki.** Ani kesintide başarılı bildirilen yeni durumun hedef adla
      kalacağı garanti edilemiyor. Geri yüklemenin ilk açık kaydı
      kaybolursa yeniden başlatma koruması devreye girmeyebilir.
      **Dayanak.** README §6.4,
      [Linux fsync(2)](https://man7.org/linux/man-pages/man2/fsync.2.html).
      **En küçük düzeltme.** İzinleri dosya `fsync` öncesi tamamla ve
      `rename` sonrasında üst dizini de eşitle. İlk yazımda yeni oluşturulan
      dizinlerin girdilerini ve taşıma sonrası eşitleme hatasını hesaba kat.
      **Doğrulama.** WSL'deki PHP ile dizin tanıtıcısına `fsync` uygulanabildi.
      Düzeltmede dosya ve dizin eşitleme sırasını ve eşitleme hatasının
      başarı olarak dönmediğini doğrula. Gerçek güç kesintisi denenmedi.
    - **BY-005-R4 [P3] — okuyucu şemanın dışındaki bazı değerleri kabul ediyor.**
      `backend/app/Services/Yedekleme/IslemKaydi.php:80`, `:103` ve `:129`.
      Yerel denemede 30 Şubat, UTC dışı saat farkı, kısa mikrosaniye alanı ve
      sonuna satır sonu eklenmiş UUID veya özet kabul edildi. **Etki.**
      Tarih normalleştirilebilir, kimlik veya özet tam eşleşmeyebilir.
      **Dayanak.** BY-005 şeması ve `testing-best-practices` doğrulama kapsamı.
      **Öneri.** Tarihi UTC'de şemadaki biçime döndürüp girdiyle karşılaştır.
      UUID ve özet desenlerini mutlak metin sonunda bitir.
      **Doğrulama.** Örnekleri bozuk içerik tablosuna ekle, yazıcının
      ürettiği geçerli biçimin okunmaya devam ettiğini doğrula.
    - **BY-005-R5 [P3] — kilit bekleme süresi sistem saatine bağlı.**
      `backend/app/Services/Yedekleme/YedekKilidi.php:63`. Sistem saati
      geriye alınırsa bekleme uzar, ileri alınırsa erken biter.
      **Dayanak.** BY-005 süre sınırı ve
      [PHP hrtime](https://www.php.net/manual/en/function.hrtime.php).
      **Öneri.** Geçen süreyi monoton saatle ölç, sonlu olmayan süre
      girdilerini başta reddet. **Doğrulama.** Mevcut süre dolumu testini
      koru ve geçersiz sürelerin beklemeden reddedildiğini sına.
    - **Kontroller.** WSL, PHP 8.5.9, Laravel 13.24.0 ve SQLite bellek içi
      test veritabanında hedef testler **31/31**, tam backend **715/715**
      (2.567 doğrulama). `git diff --check` temiz. Gerçek sunucu izinleri
      ve yeniden başlatma denenmedi. Uygulama kodu ile testler
      değiştirilmedi. İncelemede `code-review`, `laravel-best-practices`
      ve `testing-best-practices` kullanıldı.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): BY-005 R1-R5 erisim/yokluk ayrimi, yazma dogrulamasi, dizin fsync`
    olan commit
    - Beş bulgu da kodda doğrulandı, hepsi kabul. R1, R2, R4, R5 testleri
      önce yazıldı ve kırmızı görüldü (T5: 46'dan 13'ü kaldı).
    - **R1 kabul, düzeltildi.** `oku()` null'u yalnız yokluğu doğrulanmış
      yolda döndürüyor: üst klasör listelenebiliyor ve dosya listede yoksa;
      üst klasör de yoksa aynı soru bir üste soruluyor (geçiş düzeninde ilk
      çalıştırma). Klasöre erişilemiyorsa `IslemDurumuBozuk`. Testler: açık
      kayıt varken klasör 0000 → istisna (dosya yolu mesajda); dosya 0000 →
      istisna (koruma testi, zaten geçiyordu); klasör hiç açılmamışken null
      (koruma testi).
    - **R2 kabul, düzeltildi.** `yaz()` kaydı önce okumadaki doğrulamanın
      AYNISINDAN geçiriyor (`IslemKaydi::diziden(diziye())`); geçersizse
      dosyaya dokunmadan `InvalidArgumentException`. Testler (4 geçersiz
      kayıt: boş adım, eksik hata alanı, UUID olmayan kimlik, SHA-256 olmayan
      özet): yazım reddediliyor, önceki dosya **baytlarıyla** aynı, geçici
      dosya yok.
    - **R3 kabul, düzeltildi.** Sıra: izin (0640) → içerik → dosya `fsync` →
      `rename` → **klasör `fsync`**; ilk yazımda yeni açılan her klasörün üst
      klasörü de eşitleniyor. Eşitleme hatası `RuntimeException` (başarı
      dönmüyor). Bozma denemesi: klasör eşitlemesi hep başarısız yapılınca 15
      yazma testi bu hatayla düştü (ilk yazımda `/tmp` de eşitleniyor — yeni
      klasör girdisi yolu çalışıyor); geri yüklenince geçti. Dizin `fsync`'i
      her yazma testinde çalışıyor (WSL / Linux). Gerçek güç kesintisi
      denenmedi.
    - **R4 kabul, düzeltildi.** UUID ve özet desenleri `D` bayrağıyla metin
      sonunda bitiyor; tarih aynı biçime geri çevrilip girdiyle birebir
      karşılaştırılıyor ve saat farkı yalnız UTC. Bozuk içerik tablosuna 5
      durum eklendi (30 Şubat, `+03:00`, kısa mikrosaniye, sonunda satır sonu
      olan kimlik ve özet); yazıcının ürettiği biçim okunmaya devam ediyor.
    - **R5 kabul, düzeltildi.** `bekleyerekAl` süreyi `hrtime` ile ölçüyor;
      sonsuz, NaN ve negatif süre beklemeden `InvalidArgumentException`
      (3 test). Mevcut süre dolumu ve "bırakılınca alır" testleri korundu.
    - `Dikkat_Edilecekler.md`: **G10** (yazan da okuyanın doğrulamasından
      geçsin), **G11** (tam metin ve geçerli değer doğrulaması), **D3** eki
      (klasör `fsync`, `tempnam` değil), **D6** (yok ↔ erişemiyorum), **D1**
      eki (monoton saat).
    - Kontroller: BY-005 testleri **46/46** (2 kez art arda), backend
      **730/730**; Pint temiz. Canlıya alınmadı.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Fable incelemesi F1-F19` olan commit
    - **Bağlam.** Kullanıcı BY-001…BY-005 kodunu Claude Fable 5.2'ye bağımsız
      olarak inceletti (19 bulgu: 7 P2, 12 P3). Her bulgu kodda ve mümkünse
      çalıştırılarak doğrulandı. Dersler `ff5e0fc`'de Dikkat_Edilecekler'e
      işlenmişti; D7 bu commit'te düzeltildi (aşağıda F1). BY-001, BY-003
      ve BY-004'e düşen düzeltmeler kendi günlüklerinde; bu commit dört
      görevi birlikte incelemeye açar.
    - Skill'ler: `laravel-best-practices` (eloquent, config, migrations,
      error-handling), `testing-best-practices` (isolation, assertions,
      test-data, review), `supabase-postgres-best-practices`
      (schema-constraints, partial-indexes), `code-review`.
    - **F4 kabul.** `YedekKilidi::al()` her flock hatasını "meşgul" sayıyordu.
      Artık `$would_block` soruluyor; meşgul değilse `RuntimeException`.
      **Doğrulanamadı:** WSL'de flock'u desteklemeyen dosya sistemi
      bulunamadı (`/mnt/c`, `/proc`, `/sys` destekliyor); hata yolu test
      edilemedi. Meşgul yolu mevcut testlerle korunuyor ($would_block
      gelmeseydi kırmızı olurlardı).
    - **F5 kabul.** Şemaya kurtarma bağlamı eklendi (yukarıdaki tablo):
      `erisim_anahtari_bitis` (özetle birlikte zorunlu), `yedek_kimligi`,
      `hedef_veritabani`, `onceki_veritabani` (tırnak gerektirmeyen ad
      deseni — ad SQL'e güvenle girer), `isteyen_kullanici_id`. Dosya hiçbir
      yerde yazılmadığı için sürüm 1 kaldı. 10 yeni bozuk içerik ve 2 yeni
      geçersiz kayıt durumu (önce kırmızı).
    - **F13 kabul.** İzin kaldıran 4 test root altında atlanıyor (root ile
      çalıştırıldı: 62 test, 4 atlandı, 0 kırmızı); `SIGKILL` sabiti yerine
      9 (pcntl gerekmez; Symfony Process de böyle yapar); `flock` komutu
      yoksa ilgili 2 test atlanıyor; alt süreçler doğrulama düşse de
      tearDown'da öldürülüyor.
    - **F15 kabul.** `bekleyerekAl` üst sınırı 86 400 sn; `1e300` ve
      `86400.5` beklemeden reddediliyor (önce kırmızı).
    - **F16 kabul.** Tek yazar: `IslemDurumu::yaz($kayit, $kilit)` kilit
      tutulmuyorsa dosyaya dokunmadan `LogicException`
      (`YedekKilidi::tutuyorMu()`). Eşzamanlı okuyucu testinde yazıcı süreç
      kilidi kendisi alıyor. Acil durum komutunun kilidi alması BY-029
      kabul ölçütüne yazıldı.
    - **F17, F19 not.** Henüz kodları yok: `adim` sabit / enum (BY-028) ve
      dizinin kimlikle güncellenmesi (BY-011) kabul ölçütlerine yazıldı.
    - **T5:** her düzeltmenin testi önce kırmızı görüldü — veri modeli 8,
      kilit / durum 17, ayarlar + günlük izni 24 test; kurulum betiği eski
      sürümle 6 kontrol; geçici yedek betiği 1 kontrol.
    - Kontroller: backend **788/788** (SQLite); yedekleme modeli, kilit ve
      durum testleri PostgreSQL `erp_test`'te **76/76**; kurulum betiği
      (root) **168/168**; geçici yedek betiği **79/79**; ön yüz Para
      Birimleri testi geçti (mesaj metni); Pint uygulandı. Güç kesintisi ve
      gerçek sunucu izinleriyle çalıştırma denenmedi. Canlıya alınmadı.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commitler
    `98751fd` ve `6c96ea8`
    - **Önceki bulgular.** R1 için erişilemeyen açık işlem artık
      `IslemDurumuBozuk` veriyor. R4'teki tarih, UUID ve özet örnekleri
      reddediliyor. R5 monoton saatle sınırlanıyor. R2 boş adımı,
      R3 var olan durumda dizin eşitlemesini düzeltiyor. İki kalan
      senaryo aşağıda. F4'ün meşguliyet yolu testlerde geçti;
      flock desteklemeyen dosya sistemi yolu kodda incelendi.
    - **BY-005-R6 [P2] — yazıcı durumun kendi kilidini aramıyor (F16).**
      `backend/app/Services/Yedekleme/IslemDurumu.php:69` ve
      `backend/app/Services/Yedekleme/YedekKilidi.php:76`. Aynı durum
      dosyasına ait `kilit` tutulurken ikinci bir dosyanın kilidi de
      alındı. İkinci kilitle `yaz()` başarılı oldu ve ilk kaydı
      değiştirdi. **Etki.** Bir çağıranın yanlış kilit yolu kullanması
      tek yazar korumasını kaldırır. **Dayanak.** F16, K3, README §5.2.
      **Düzeltme.** Durum dosyası yazımını yapılandırılmış ortak kilide
      bağla. **Doğrulama.** İki farklı kilit dosyası tutulurken başka
      kilitle yazım hata vermeli ve mevcut içerik korunmalı. Ortak
      kilitle yazım geçmeli.
    - **BY-005-R7 [P2] — doğrulanan dizi ile yazılan dizi farklı olabiliyor (R2).**
      `backend/app/Services/Yedekleme/IslemDurumu.php:75` ve `:85`,
      `backend/app/Services/Yedekleme/IslemKaydi.php:163`. Okuyucunun
      `diziden()` sonucunda yalnız `kod` ve `mesaj` tutuluyor ancak
      yazıcı doğrulanmış sonucu kullanmıyor. Ek bir iç içe hata alanıyla
      yazım başarılı oldu. Ardından okuyucu JSON derinlik sınırı nedeniyle
      dosyayı reddetti; eski kayıt yerinde kalmadı. **Dayanak.** G10, T8.
      **Düzeltme.** Doğrulanmış sonuçtan şemadaki alanları serileştir
      veya tanımsız alanı taşımadan reddet. **Doğrulama.** Ek hata alanı
      dosyayı okunamaz bırakamamalı. Reddedilen yazımda önceki dosya
      baytlarıyla korunmalı.
    - **BY-005-R8 [P2] — ilk çalıştırmada kilidin oluşturduğu durum dizini
      kalıcı hâle getirilmiyor (R3).** `YedekKilidi.php:132` ve
      `IslemDurumu.php:142` (aynı servis dizini). Boş geçiş dizininde
      önce kilit, sonra durum yazıldı. Gerçek `fsync` çağrıları
      izlendiğinde durum dizini eşitlendi, onu içeren üst dizin
      eşitlenmedi. `yaz()` dizini zaten var gördüğü için o adımı atlıyor.
      **Etki.** İlk kayıttan sonra ani kesintide dizin girdisi kaybolursa
      README §6.4'ün açık işlem koruması boşa düşebilir.
      **Dayanak.** D3 ve [Linux fsync(2)](https://man7.org/linux/man-pages/man2/fsync.2.html).
      **Düzeltme.** Yol ilk oluşturulduğunda ortak kurulum veya dizini
      oluşturan kilit adımı üst girdileri kalıcı hâle getirsin.
      **Doğrulama.** İlk kilit alımından sonra ilk yazımda gereken üst
      dizin çağrıları gözlenmeli ve eşitleme hatası başarı sayılmamalı.
    - **F5/F13/F15/F17/F19.** Kurtarma ve süre alanları, bozuk veri ve
      bekleme denetimleri var. F17, BY-028'de; F19, BY-011'de kabul
      ölçütü olarak bulundu. Bu iki görevin uygulaması henüz yok.
    - **Kontroller.** WSL PHP 8.5.9, Laravel 13.24.0 ve PHPUnit 12.5.33
      ile tüm backend **788/788**. Ayrı PostgreSQL 16 örneğinde veri
      modeli, kilit ve durum **76/76**. SQLite/PostgreSQL temiz kurulumları
      geçti. Ürün kodu değişmedi. `code-review`, `laravel-best-practices`,
      `supabase-postgres-best-practices` ve `testing-best-practices`
      kullanıldı. Laravel davranışı vendor kaynaklarından kontrol edildi.
      Güç kesintisi, gerçek servis ve canlı veritabanı denenmedi.
  - 2026-11-18 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 6c96ea8 incelemesi (BY-001/003/004/005)` olan commit
    - **R6 kabul, düzeltildi.** `IslemDurumu::kilit()` durum dosyasının
      kendi kilidini (`<durum klasörü>/kilit`) verir; `yaz()` yalnız o
      dosyanın kilidini kabul eder: `YedekKilidi::dosyayiTutuyorMu($yol)`
      tutulan tutamaç ile yolun aygıt + düğüm numarasını karşılaştırır
      (farklı yazılmış aynı yol eşleşir, başka dosya eşleşmez).
      `tutuyorMu()` kaldırıldı. Test: başka dosyanın kilidi tutulurken yazım
      `LogicException`, önceki içerik baytlarıyla aynı. Bozma denemesi: yol
      denetimi kaldırılınca 2 test kırmızı.
    - **R7 kabul, düzeltildi (iki katman).** `hata` yalnız `{kod, mesaj}`
      (fazladan anahtar okumada da yazımda da bozuk); `yaz()` artık
      doğrulanmış kaydı serileştiriyor (`diziden(...)->diziye()`). Testler:
      20 düzey iç içe fazladan alanlı kayıt yazılmıyor, önceki dosya
      korunuyor; fazladan anahtarlı dosya bozuk. Bozma denemesi: anahtar
      denetimi kaldırılınca 2 test kırmızı.
    - **R8 kabul, düzeltildi.** Klasör açma ve eşitleme ortak
      `KaliciKlasor`'da (eskiden `IslemDurumu`'nun özel metotlarıydı);
      `YedekKilidi` de klasörü onunla açıyor ve yeni kilit dosyasının
      girdisini eşitliyor. İlk açan hangisi olursa olsun üst girdiler
      eşitleniyor. **Doğrulanamadı:** WSL'de `strace` yok; fsync sırası
      gözlenemedi. Bozma denemesi: eşitleme hep başarısız yapılınca kilit
      testlerinden 4'ü kırmızı (kilit artık eşitleme yolunu çağırıyor).
    - Kontroller: backend **795/795**; yedekleme modeli, kilit, durum ve
      günlük testleri PostgreSQL'de **86/86**; kurulum (root) **174/174**;
      geçici yedek betiği **85/85**; Pint uygulandı. Ön yüz değişmedi. Güç
      kesintisi ve gerçek servis denenmedi. Canlıya alınmadı.
  - 2026-11-18 Codex → CHANGES_REQUESTED · incelenen commit `775a0fd`
    - **R6 ve R7 regresyonları geçti.** Başka dosyanın kilidi reddediliyor.
      Şema dışı hata alanı okumada ve yazmada reddediliyor, önceki dosya
      korunuyor. Taze geçiş dizininde ilk oluşturma yolu da üst dizini
      eşitliyor. R8'in başarısız eşitlemeden sonra yeniden deneme yolu açık.
    - **BY-005-R9 [P2] — ilk eşitleme başarısızsa sonraki deneme üst dizini
      atlıyor.** `backend/app/Services/Yedekleme/KaliciKlasor.php:25` ve
      `:38`. İlk kilit alımında üst dizin `fsync` hatası taklit edildi.
      Klasör oluşturuldu ve çağrı hata verdi. Hata kaldırılıp kilit ve
      durum yazımı tekrarlandığında başarılı oldu, üst dizine yeniden
      `fsync` çağrılmadı. **Etki.** Başarılı bildirilen açık geri yükleme
      kaydının bulunduğu dizin ani kesintiden sonra kaybolabilir.
      **Dayanak.** D3, P3 ve BY-005-R8. **Düzeltme.** Geçiş dizininin
      üst girdilerinin eşitlenmesini kilit/durum başarı koşulu yap.
      Var olan dizinin daha önce başarıyla eşitlendiğini varsayma.
      **Doğrulama.** İlk üst dizin eşitlemesini bir kez başarısız yap.
      Yeniden denemede üst dizin eşitlenmeden başarı dönmemeli.
      [Linux fsync(2)](https://man7.org/linux/man-pages/man2/fsync.2.html)
    - **BY-005-R10 [P3] — dosya kimliği denetimi eski stat sonucunu
      kullanabiliyor.** `backend/app/Services/Yedekleme/YedekKilidi.php:88`.
      Yerel denemede dosya kimliği kontrol edildikten sonra kilit dosyası
      ayrı süreçte değiştirildi. Önceki dosyanın kilidiyle durum yazımı
      başarılı oldu. PHP dosya durumu önbelleği temizlenince kimlik
      kontrolü doğru biçimde reddetti. **Etki.** Karşılaştırma, bakımda
      değiştirilen kilit dosyasının güncel kimliğini kaçırabilir.
      **Dayanak.** F16 ve K3. **Öneri.** Kimlik kontrolünden önce yolun
      stat önbelleğini temizle. İşlem süresince kilit dosyasını sabit
      tutan mevcut düzeni koru. **Doğrulama.** Önce kimliği kontrol et,
      ayrı süreçte dosyayı değiştir ve önceki tutamaçla yazımın
      reddedildiğini doğrula.
      [PHP clearstatcache](https://www.php.net/manual/en/function.clearstatcache.php)
    - **Kontroller.** Tam backend 795/795, PostgreSQL yedekleme testleri
      86/86. Sync çağrıları geçici dizinde izlenerek hata ve yeniden
      deneme yolu sınandı. Gerçek güç kesintisi denenmedi.
      İncelemede `code-review`, `laravel-best-practices`,
      `supabase-postgres-best-practices` ve `testing-best-practices`
      kullanıldı. Ön yüzde kod değişikliği bulunmadığı için bu turda
      test veya build yeniden çalıştırılmadı.
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · başlığı
    `fix(yedekleme): Codex 775a0fd incelemesi (BY-001-R7, BY-005-R9/R10)` olan commit
    - **R9 kabul, düzeltildi.** `KaliciKlasor::ac()` klasör ZATEN VARSA da
      klasörden köke kadar her girdinin üst klasörünü eşitliyor; "var"
      "kalıcı" sayılmıyor. `IslemDurumu::yaz()` her yazımda, kilit de dosyayı
      açtığında bunu çağırıyor; eşitleme hatası istisna. Testler **gerçek
      hatayla**: üst klasör 0300 (yazılabilir, okunamaz) → alt klasör açılıyor
      ama üst klasör eşitlemeye açılamıyor. Kilit: ilk deneme ve klasör artık
      varken yeniden deneme ikisi de "eşitlenemedi" ile duruyor, izin
      düzelince alınıyor. Durum: klasör kilitle açılmışken üstü
      eşitlenemiyorsa yazım başarı dönmüyor, izin düzelince yazılıyor. Önce
      kırmızı görüldü (yeniden deneme başarı dönüyordu). Root altında atlanıyor (T11).
    - **R10 kabul (P3), uygulandı.** `dosyayiTutuyorMu` stat'tan önce
      `clearstatcache(true, $yol)`. Test: kimlik denetiminden sonra başka
      süreç (`exec` ile `mv`) dosyayı yenisiyle değiştiriyor → eski tutamaç
      tutmuyor sayılıyor. **Doğrulanamadı:** PHP 8.5.9 CLI'da önbellekli
      eski kimlik yerelde üretilemedi (`stat()` değişikliği önbelleği
      temizlemeden de gördü); test düzeltmeden önce de geçti. Test yine
      tutuldu: değiştirilen dosyanın tanınması davranışını korur (yol metni
      karşılaştırmasına dönülürse kırmızı olur).
    - D3'e eklendi: "klasör var" ≠ "klasör kalıcı"; yeniden denemede de eşitle.
    - Kontroller: backend **798/798**; yedekleme modeli, kilit, durum ve
      günlük testleri PostgreSQL'de **89/89**; root altında kilit / durum 68
      test, 6 atlandı, 0 kırmızı; geçici yedek betiği **87/87**; Pint temiz.
      Kurulum betiği bu turda değişmedi (önceki koşu 174/174). Ön yüz
      değişmedi. Güç kesintisi denenmedi. Canlıya alınmadı.
  - 2026-11-19 Codex → CHANGES_REQUESTED · incelenen commit `03f1cba`
    - **R9'un yeniden deneme senaryosu ve R10 kapandı.** İlk eşitleme
      hatasından sonra izin düzelmeden başarı dönmediği testlerle
      doğrulandı. Önceki incelemedeki önbellek senaryosu ayrı PHP süreci
      ile tekrarlandı. Aynı durumda eski kilitle yazım `775a0fd` ile
      kabul edildi, `03f1cba` ile reddedildi.
    - **BY-005-R11 [P2] — her yazımda köke kadar eşitleme mevcut dizinde
      yeni bir izin gereksinimi getiriyor.**
      `backend/app/Services/Yedekleme/KaliciKlasor.php:32` ve `:44`;
      `backend/app/Services/Yedekleme/IslemDurumu.php:101`.
      Önceden oluşturulmuş durum dizininde yalnız geçiş izni bulunan
      bir üst dizinle denendi. İşlem durumu okunabildi ve mevcut kilit
      alındı. `775a0fd` ile yazım başarılı, `03f1cba` ile üst dizin
      eşitlenemediği için başarısız. BY-003 üst dizin kontrolü
      (`scripts/sunucu-yedekleme-kurulumu.sh:138`) okumaya kapalı
      fakat geçilebilir üst dizinlere izin veriyor.
      **Etki.** Kurulum denetiminden geçen bir yolda çalıştırıcı,
      kendi durum dizinine yazma hakkı varken geçerli kayıt
      yazamıyor. **Dayanak.** BY-003 izin sözleşmesi, BY-005 dosya
      yolu ve D3. **Düzeltme.** Eşitleme zincirini kurulumla kalıcılığı
      sağlanan bir üst dizinde sınırla. Sınırın altında oluşturulan
      girdilerin eşitlenmesini yeniden denemede de zorunlu kıl.
      **Doğrulama.** Sınırın üstünde yalnız geçiş izni varken
      önceden kurulmuş durum dosyası güncellenmeli. Sınırın altında
      gerekli eşitleme başarısızsa ilk deneme ve yeniden deneme
      yine hata vermeli.
    - **Kontroller.** WSL, PHP 8.5.9 ve Laravel 13.24.0 ile tam backend
      **798/798**. Geçici PostgreSQL 16'da veri modeli, günlük, kilit ve
      durum **89/89**. İncelemede `code-review`,
      `laravel-best-practices` ve `testing-best-practices` kullanıldı.
      Güç kesintisi ve canlı servis denenmedi. Bu turda kurulum ve ön yüz
      testleri yeniden çalıştırılmadı.
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · commit
    `fix(yedekleme): Codex 03f1cba incelemesi (BY-005-R11)`
    - **R11 kabul, düzeltildi.** Bulgu doğru: fsync için klasörün okunarak
      açılması gerekir. Yalnız geçilebilir (`--x`) bir üst klasörde
      `03f1cba` yazımı reddediyordu. **Kalıcı kök** eklendi: kurulumun açıp
      diske eşitlediği klasör. `KaliciKlasor::ac($klasor, $kaliciKok)`
      eşitleme zincirini kökte durdurur. Kökün altında açılan her girdi,
      klasör önceden var olsa da her seferinde eşitlenir (R9 korunur).
      `YedekKilidi` ve `IslemDurumu` kökü yapıcıdan alır (zorunlu).
      Kökün dışındaki ya da `.` / `..` içeren yol yapıcıda reddedilir.
      Kök yoksa klasör açılmaz, çünkü kökü burada açmak onun girdisini
      eşitlenmemiş bırakırdı.
    - **Sözleşmenin karşılığı (BY-003):** kurulum betiği artık kökü,
      alt klasörleri ve kilit dosyasını diske zorluyor. Klasör önceden
      açılmış olsa da bunu her kurulumda yapıyor (D3). Denetim kipinde
      yapmıyor. Kilit dosyasını, onu açan servis hesabı eşitliyor (D5).
    - **Testler (T5):**
      - Durum yazımı ve kilit açılışı: kökün üstünde yalnız geçiş izni
        varken çalışıyor. İkisi de `03f1cba` ile kırmızıydı.
      - Kök dışı yol, adı kökle başlayan yol, `..` içeren yol ve kurulmamış
        kök reddediliyor ve hiçbir klasör açılmıyor (eski kodla kırmızı).
      - R9'un iki testi kökün altındaki 0300 klasörle hata vermeyi
        sürdürüyor. Kökün altındaki eşitleme kaldırılınca ikisi de
        kırmızı oluyor (mutasyon denemesi).
      - Kurulum testi sahte bir `sync` ile çağrıları kaydediyor (sonra
        gerçeğini çalıştırıyor):
        - denetim kipinde eşitleme yok;
        - ilk kurulumda kökün üstü + kök, kök + `durum/` ve kilit + `durum/` eşitleniyor;
        - ikinci kurulumda var olan klasörler yeniden eşitleniyor.
        - Eski betikle dört madde kırmızı.
    - **Dosyaların yeri** güncellendi: sunucuda kalıcı kök
      `/var/lib/emor-yedek/durum`. Geçiş döneminde kök yapılandırmadan
      gelir (BY-007).
    - D3'e eklendi: eşitleme zinciri izin sözleşmesinin dışına
      çıkmamalı.
    - **Kontroller:**
      - backend **801/801**;
      - PostgreSQL'de yedekleme modeli, kilit, durum ve günlük testleri **92/92**;
      - root altında kilit ve durum testleri 63 geçti, 8 atlandı (izin testleri), 0 kırmızı;
      - kurulum testleri (root) **179/179**;
      - Pint temiz.
      - Geçici yedek betiği değişmedi.
      - Güç kesintisi denenmedi. Canlıya alınmadı.
  - 2026-11-19 Codex → CHANGES_REQUESTED · incelenen commit `997dba3`
    - **R11 kapandı.** İzinleri sınırlı üst dizin varken yazım çalışıyor.
      Kalıcı kökün altındaki eşitleme hatası yeniden denemede de hata.
      İlgili testler WSL'de SQLite ve PostgreSQL ile geçti.
    - **BY-005-R12 [P2] — Kurulması gereken kök yoksa okuma açık geri
      yükleme yok diyor.**
      [IslemDurumu.php:61](../../backend/app/Services/Yedekleme/IslemDurumu.php#L61)
      ve [satır 148](../../backend/app/Services/Yedekleme/IslemDurumu.php#L148).
      Alt klasör yokluğunu denetleyen özyineleme, kalıcı kökün de üstüne
      çıkabiliyor. Oysa [KaliciKlasor.php:49](../../backend/app/Services/Yedekleme/KaliciKlasor.php#L49)
      yazma tarafında kökün kurulu olmasını şart koşuyor. Geçici dizinde
      kurulu kök ve eksik kök iki ayrı durumda denendi. İki durumda da
      `oku()` null döndü. Eksik kökte `acikGeriYuklemeVarMi()` false
      döndü. Durum klasörü taşıma, disk veya kurulum hatasıyla erişimden
      çıkarsa BY-029 bu sonucu çalışmaya izin olarak yorumlayabilir.
      **Düzeltme.** Yokluk denetimini kalıcı kökte sınırla. Eksik veya
      erişilemeyen kalıcı kökte `IslemDurumuBozuk` ver. Kök okunabiliyorsa
      henüz oluşturulmamış durum dosyası için null davranışını koru.
      **Doğrulama.** Kurulu boş kök, kurulmamış kök ve erişim izni
      kaldırılmış kökü ayrı sınayan testte ilk durum null, diğer ikisi
      istisna vermeli. R11'in yalnız geçilebilir üst dizin testi de geçmeli.
      **Dayanak.** BY-005'in dosya yokluğu ile durumun bilinmemesini
      ayırma kuralı ve BY-029 başlangıç koruması.
    - **Kontroller.** WSL'de backend 872/872. Ayrı PostgreSQL 16
      kümesinde Protel, kilit ve durum testleri 142/142. Pint geçti.
      Güç kesintisi ve canlı servis denenmedi.
  - 2026-11-19 Claude → REVIEW_BEKLIYOR · commit: bu düzeltmeyi içeren commit
    (`fix(protel): Codex 4c92ccf incelemesi …` — Protel düzeltmeleriyle aynı commit)
    - **R12 kabul, düzeltildi.** `IslemDurumu::oku()`: dosya yokken önce kalıcı
      kök denetlenir — kök yoksa `IslemDurumuBozuk` ("kalıcı kökü … yok; kurulum
      açmalı"). `yoklukDogrulandi()` özyinelemesi kalıcı kökte durur: kök
      okunamıyorsa "yok" değil "erişilemiyor" (istisna). Kurulu ve okunabilir
      kökte henüz açılmamış durum klasörü için `null` korunur (ilk çalıştırma).
    - Test `test_yokluk_kalici_kokte_durur_kurulmamis_ya_da_okunamayan_kok_hata_verir`:
      kurulmamış kök → istisna (kökün üstündeki sistem geçici klasörü okunabilir
      olsa da); kurulu boş kök → null; izni kaldırılmış kök (0000, root değilken)
      → istisna. Düzeltme öncesi kodla kırmızı ("Kurulmamış kalıcı kök 'açık geri
      yükleme yok' sayıldı").
    - R11'in yalnız geçilebilir üst klasör testleri ve kilit testleri geçiyor
      (`IslemDurumuTest` + `YedekKilidiTest` 72/72; PostgreSQL'de de).
    - G1: `scandir` ile yokluk arayan başka kod yok.
    - D6'ya eklendi: yokluk araması kurulumun garanti ettiği kökün üstüne çıkmaz.
    - Kontroller: backend 877/877, Pint temiz. Canlıya alınmadı.
  - 2026-11-20 Codex → PASSED · incelenen commit `f714924`
    - **BY-005-R12 kapandı.** Kurulmamış kök `IslemDurumuBozuk` veriyor.
      Yokluk araması kalıcı kökün üstüne çıkmıyor. Kurulu ve okunabilir
      kökte henüz durum dosyası yoksa null davranışı korunuyor.
    - Kök eksik, kurulu boş kök, okunamayan kök ve yalnız geçilebilir
      üst dizin testleri WSL'de root dışı kullanıcıyla geçti.
      SQLite backend **877/877** ve ayrı PostgreSQL 16 kümesinde
      yedekleme ile e-Fatura kapsamındaki seçili testler **362/362**.
      Pint geçti. Doğrulama yerel dosya sistemiyle yapıldı.

### BY-006 — Manifest, imza, olay günlüğü

- **Durum:** YAPILACAK · **Bağımlı:** BY-004
- **Tasarım:** README §5.3, §4.5 (güven sınırı), §7.5
- **Kapsam:**
  - `YedekManifesti`: README §5.3'teki alanlar; yaz / oku / doğrula. Şema sürümü alanı.
  - İmza: veritabanı dışında duran anahtarla (yapılandırmadan dosya yolu) manifest
    imzası; doğrulama. Anahtar yoksa anlaşılır hata.
  - `APP_KEY` parmak izi (anahtarın kendisi değil; geri döndürülemez).
  - `OlayGunlugu`: salt eklemeli dosya (satır başına JSON): sabitleme, doğrulama
    sonucu, kopya durumu, silinme. Manifest + olay günlüğünden "güncel durum" hesabı.
- **Kabul ölçütleri:**
  - [ ] Manifestin bir baytı değişince imza doğrulaması başarısız (test).
  - [ ] Yedek dosyası değişince özet doğrulaması başarısız (test).
  - [ ] Olay günlüğünden durum yeniden kurulabiliyor: sabitle → çöz → sabitle sonucu "sabit" (test).
  - [ ] Bozuk / eksik manifest "güvenilmeyen" olarak sınıflanıyor, istisna fırlatıp akışı kesmiyor.
  - [ ] Parmak izinden `APP_KEY` elde edilemiyor; günlüklere anahtar yazılmıyor.
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:** —

### BY-007 — Yedek alma (`yedek:al`)

- **Durum:** YAPILACAK · **Bağımlı:** BY-005, BY-006
- **Tasarım:** README §4.1, §6.1, §7.1, §5.2 (dış komutlar, parola dosyası), §4.4 (1. düzey)
- **Kapsam:**
  - `YedekAlici` servisi + `yedek:al {--kaynak=} {--not=}` komutu, §6.1'in 1–5. adımları.
  - Anlık görüntü: sayımlar ve migration listesi yedekle aynı andan (`--snapshot`).
  - Geçici tabloların verisi dışarıda (`--exclude-table-data`), şeması içeride.
  - Geçici ad → asıl ad; hata yolunda geçici dosya silinir.
  - 1. düzey doğrulama: boyut, SHA-256, `pg_restore --list`, `pg_restore --file=/dev/null`.
  - Parola `PGPASSFILE` ile; komut satırında ve ortam değişkeninde yok.
  - Veritabanının geri yükleme yolunda uygulanmayan özellikleri manifeste: kodlama, sıralama, ayarlar (arşivde vardır ama yalnız `--create` ile uygulanır — kesif.md §7).
  - Nesnelerin sahibi olan rol de manifeste; nedeni farklı: sahiplik `--create` olmadan da arşivden uygulanır, tasarım onu `--no-owner` ile kapatıp `--role=<uygulama rolü>` ile verir. Manifestteki sahip, geri yüklemenin doğru role yapıldığını denetlemek içindir (README §5.3).
  - Geçiş dönemi: yedeği uygulama kullanıcısı alır; klasör yapılandırmadan (README §5.2).
- **Kapsam dışı:** Hedeflere kopyalama, planlar, temizlik.
- **Kabul ölçütleri:**
  - [ ] Başarılı çalışmada `.dump` + imzalı `.json` oluşuyor; `yedekler` kaydı `tamam`.
  - [ ] `pg_dump` hata verirse kayıt `basarisiz`, klasörde yarım / geçici dosya yok (test).
  - [ ] Doğrulama adımı hata verirse yedek `basarisiz` sayılıyor, dosya listeye girmiyor (test).
  - [ ] Kilit başka süreçteyken komut anlaşılır mesajla çıkıyor, ikinci yedek almıyor (test).
  - [ ] Çalıştırılan komut argümanlarında parola yok (test: `Process::assertRan`).
  - [ ] Disk alanı eşiğin altındaysa yedek başlamıyor (test).
  - [ ] Süre sınırı yalnız pozitif tam sayı kabul ediliyor; `abc`, `0`, `-10` gibi
    geçersiz ayar açık hatayla duruyor, sınırsız çalışmaya dönüşmüyor (test; BY-004-R5).
  - [ ] Lokal PostgreSQL'de gerçek araçlarla bir kez uçtan uca denendi (günlükte not).
- **Canlı:** Evet (etkisiz; komut elle çalıştırılabilir).
- **İnceleme günlüğü:** —

### BY-008 — Hedef soyutlaması, Yerel disk hedefi, kopyalama

- **Durum:** YAPILACAK · **Bağımlı:** BY-007
- **Tasarım:** README §4.6, §4.2 (tek yedek, çok hedef), §6.1 (6. adım)
- **Kapsam:**
  - `YedekHedefi` arayüzü: yaz, oku, sil, listele, sına (yaz – oku – doğrula – sil), kalan alan.
  - "Yerel disk" bağlayıcısı (hedef klasörü yapılandırılabilir).
  - `YedekKopyalayici`: bir yedeği bir hedefe kopyalar; `yedek_kopyalari` kaydı;
    özet doğrulaması; yeniden deneme; olay günlüğüne yazma.
  - Bir hedefin başarısızlığı diğer hedefleri ve yedeğin kendisini etkilemez.
- **Kapsam dışı:** Uzak hedef türleri (Faz 3), şifreleme (BY-019).
- **Kabul ölçütleri:**
  - [ ] Yeni hedef türü eklemek yalnız yeni bir bağlayıcı sınıfı gerektiriyor (arayüz testi).
  - [ ] İki hedeften biri hata verince diğerinin kopyası `tamam`, hatalınınki `basarisiz` (test).
  - [ ] Kopyadan okunan dosyanın özeti kaynakla aynı değilse kopya `basarisiz` (test).
  - [ ] "Sına" başarısız yazma / okuma / silmeyi ayrı ayrı raporluyor (test).
  - [ ] Kopyalanmakta olan yedek temizlikten korunuyor (kullanımda işareti; test).
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:** —

### BY-009 — Planlar ve zamanlayıcı

- **Durum:** YAPILACAK · **Bağımlı:** BY-008
- **Tasarım:** README §4.2, §5.2 (zamanlama kalıbı), §7.8
- **Kapsam:**
  - Vade hesabı: N saatte bir (saat aralığı ve gün süzgeciyle), her gün, N günde
    bir, haftalık, aylık. İstanbul saatiyle. Ayın 29–31'i kuralı (o gün yoksa
    ayın son günü).
  - "Sıradaki N çalışma" hesabı (önizleme için).
  - `yedek:zamanla` komutu dakikada bir (`routes/console.php`): vadesi gelen
    planları bulur; **aynı ana düşen planlar için tek yedek** alır ve planların
    hedeflerine kopyalar.
  - Kaçırılan çalışma bir kez telafi edilir. Bakım modunda da çalışır.
  - `YEDEKLEME_AKTIF` kapalıysa hiçbir şey yapmaz.
  - Planın hedefleri arasında yerel disk yoksa, kopyalar tamamlanınca yerel dosya silinir.
- **Kabul ölçütleri:**
  - [ ] Her sıklık türü için vade hesabı testli (ay sonu, 3 günde bir, saat aralığı dahil).
  - [ ] Aynı dakikaya düşen üç plan tek `pg_dump` çalıştırıyor, üç hedefe kopya gidiyor (test).
  - [ ] Sunucu 3 gün kapalı kaldıktan sonra günlük plan bir kez çalışıyor, üç kez değil (test).
  - [ ] Ayar kapalıyken komut hiçbir süreç başlatmıyor (test).
  - [ ] Saat hesabı sunucunun ve veritabanının saat diliminden bağımsız (test).
- **Canlı:** Evet (kapalı). Açma kararı kullanıcının.
- **İnceleme günlüğü:** —

### BY-010 — Saklama ve temizlik

- **Durum:** YAPILACAK · **Bağımlı:** BY-009
- **Tasarım:** README §4.3, §6.2
- **Kapsam:**
  - Plan + hedef başına kural ("son N yedek" ve / veya "N günden eski").
  - Zamanlanmamış yedeklerin süreleri (yayın öncesi, elle, geri yükleme öncesi).
  - Koruma kuralları: son başarılı yedek, sabitlenmiş, doğrulaması geçmiş en
    yeni, kullanımdaki; hedef başına en az sayı; yeni başarılı yedek gelmedikçe silmeme.
  - Önizleme kipi: "bu kuralla şunlar silinecek" (silmeden).
  - `yedek:temizle` komutu; her yedekten sonra ve günde bir.
  - Her silme nedeniyle denetim kaydına ve olay günlüğüne.
- **Kabul ölçütleri:**
  - [ ] Her koruma kuralı için ayrı test: kural olmadan silinecek yedek, kuralla korunuyor.
  - [ ] Bir hedefteki silme, aynı yedeğin diğer hedefteki kopyasına dokunmuyor (test).
  - [ ] Son 10 yedek de başarısızsa hiçbir başarılı yedek silinmiyor (test).
  - [ ] Önizleme ile gerçek çalıştırma aynı listeyi veriyor (test).
  - [ ] Hedef erişilemezken temizlik o hedefi atlayıp diğerlerini sürdürüyor (test).
  - [ ] **Kullanıcı kararı F6 (§5):** aynı hedefi paylaşan iki planda (tek
    kopya, iki bağ) bir planın kuralı yalnız KENDİ bağını kaldırıyor; dosya,
    diğer plan hâlâ tutuyorken silinmiyor; son bağ gidince de ancak koruma
    kuralları izin veriyorsa siliniyor (test: son bağ kalkan ama "son başarılı"
    / sabitlenmiş kopya duruyor). Bağsız kalan korumalı plan yedeğine
    zamanlanmamış yedeklerin kuralı UYGULANMIYOR: saklama kuralı bağ
    sayısından değil `yedekler.kaynak`'tan seçiliyor (test; BY-004-R9).
  - [ ] **Kullanıcı kararı F7 (§5):** yerel dosya silinip uzakta DOĞRULANMIŞ
    kopya kalınca yedek `silindi` olmuyor, `yerel_silinme_zamani` doluyor;
    uzak kopya doğrulanmamışsa (ya da başarısızsa) yerel dosya silinmiyor;
    durum yalnız hiçbir kopya kalmayınca `silindi` (test). Dizin yeniden
    kurulurken (BY-011) uzak kopyası olan yedeğin bulunabilmesi için gereken
    kayıt (manifestin yerelde kalması ya da olay günlüğü) bu görevde
    tasarlanıp README'ye yazılır.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-011 — Klasör tarama, dizini yeniden kurma

- **Durum:** YAPILACAK · **Bağımlı:** BY-006, BY-008
- **Tasarım:** README §5.2 (doğruluk kaynağı dosyalar), §7.3, §4.5 (güven sınırı)
- **Kapsam:** `yedek:tara`: yedek klasöründeki manifestleri ve olay günlüğünü
  okuyup `yedekler` / `yedek_kopyalari` dizinini kurar ya da günceller. İmzası
  geçersiz ya da manifestsiz dosyalar "güvenilmeyen" olarak işaretlenir. Diskte
  olmayan kayıtlar "dosya yok" olur (silinmez).
- **Kabul ölçütleri:**
  - [ ] Tablolar boşaltılıp tarama çalıştırılınca liste eski hâline dönüyor; sabitlemeler duruyor (test).
  - [ ] İmzasız dosya listede "güvenilmeyen"; geri yükleme için seçilemiyor (test).
  - [ ] Tarama iki kez çalışınca kayıt çoğalmıyor (test).
  - [ ] Tek bir bozuk manifest taramanın tamamını durdurmuyor (test).
  - [ ] Kayıtlar manifest kimliğiyle GÜNCELLENİR, silinip yeniden açılmaz:
    `yedekler` satırı silinse kopyalar ve plan bağları CASCADE ile gider,
    geri yükleme geçmişi RESTRICT ile silmeyi engeller, denetim kaydının bağı
    boşalır (test: geri yükleme geçmişi olan yedekte tarama hatasız; Fable F19).
  - [ ] **Kullanıcı kararı F7 (§5):** yerel dosyası silinmiş, uzakta doğrulanmış
    kopyası olan yedek, tablolar boşaltılıp tarama çalıştırılınca listede
    yine bulunuyor; durumu `silindi` değil, uzak kopyası görünüyor (test).
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:** —

### BY-012 — Bildirimler ve yaşam sinyali

- **Durum:** YAPILACAK · **Bağımlı:** BY-009
- **Tasarım:** README §4.8
- **Ön koşul:** README §13 karar 7 (alıcılar) ve 11 (dış izleyici). Kararlar
  yoksa: alıcı ayarı boş bırakılabilir, sinyal adresi yapılandırılabilir yazılır.
- **Kapsam:**
  - Başarısızlık bildirimleri (yedek, kopya, doğrulama, disk eşiği, beklenmedik temizlik).
  - Mail ayarlarının dosya kopyası; çalıştırıcı maili kuyruğa koymadan, doğrudan gönderir.
  - Üç ayrı yaşam sinyali (yerel yedek, sunucu dışı kopya, sınama geri yüklemesi):
    yapılandırılabilir adrese başarı sonrası istek; adres boşsa atlanır.
  - Uygulama içi sessizlik göstergesi (ekran için): son başarılı yedekten bu yana süre.
- **Kabul ölçütleri:**
  - [ ] Kuyruk tablosu boş / erişilemezken başarısızlık maili gidiyor (test: sahte mailer).
  - [ ] Sinyal adresi erişilemezse yedek yine `tamam`; hata günlüğe yazılıyor (test).
  - [ ] Sinyal isteği gizli bilgi (parola, veri) taşımıyor.
  - [ ] Aynı hata için dakikada bir mail gitmiyor (yineleme sınırı; test).
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-013 — `deploy.sh` entegrasyonu, eski yedeklerin içeri alınması

- **Durum:** YAPILACAK · **Bağımlı:** BY-007, BY-011
- **Tasarım:** README §5.2 (kilit), §5.5, §4.5 (159 imzasız yedek)
- **Kapsam:**
  - `deploy.sh` yayın öncesi yedeği `yedek:al --kaynak=yayin` ile alır; kendi
    `pg_dump`'ını çalıştırmaz. Aynı kilide katılır; açık bir geri yükleme varsa
    başlamaz. Yedek alınamazsa yayın durur (`--no-backup` davranışı korunur).
  - `yedek:eski-yedekleri-al`: mevcut `erp_predeploy_*` dosyaları için bir kereye
    mahsus manifest üretir ve "eski, sonradan içeri alındı" olarak işaretler.
  - BY-001'in geçici betiği ve zamanlayıcı satırı kaldırılır.
  - `deploy.sh`'ın kendi `.env` okuması (`env_val`) kaldırılır: tırnakları
    silip satır sonu yorumunu değere katıyor ve parolayı `PGPASSWORD` ile ortam
    değişkenine koyuyor (BY-001-R1'de bulunan hatanın aynısı; `Dikkat_Edilecekler.md`
    G2, G3, D2). Yedek `yedek:al` üzerinden alınınca bu okuma gereksizleşir.
- **Kabul ölçütleri:**
  - [ ] Yedekleme çalışırken başlatılan yayın bekliyor ya da anlaşılır mesajla duruyor.
  - [ ] Yayın öncesi yedek listede "yayın öncesi" kaynağıyla, imzalı görünüyor.
  - [ ] İçeri alınan eski yedekler ayrı işaretli; temizlik kuralı onlara da uygulanıyor.
  - [ ] `deploy.sh` yedek başarısızken migration çalıştırmıyor.
  - [ ] Geçici önlem (BY-001) canlıdan kaldırıldı.
- **Canlı:** Evet. **Dikkat:** `deploy.sh` değişikliği bir sonraki yayını etkiler;
  önce lokalde denenir.
- **İnceleme günlüğü:** —

---

## Faz 2 — Ekranlar

### BY-014 — API: Yedekler

- **Durum:** YAPILACAK · **Bağımlı:** BY-010, BY-011
- **Tasarım:** README §4.9, §4.10 (1), §5.2 (istek yalnız kaydedilir), §8
- **Kapsam:** `v1/yedekler` uçları: liste (süzgeç, sıralama, sayfalama), özet
  şeridi verisi, elle yedek isteği, doğrulama isteği, sabitle / çöz, not, sil,
  klasörü tara. Uzun işlemler isteği kaydeder, işi çalıştırıcı yapar; durum
  yoklanabilir. Her işlem denetim kaydına. Hız sınırları.
- **Kapsam dışı:** Tarayıcıya indirme (README §13 karar 6 kapanana kadar).
- **Kabul ölçütleri:**
  - [ ] Her uç için: izinsiz 403, girişsiz 401, geçersiz girdi 422 (`kod` alanıyla).
  - [ ] Sabitlenmiş yedek silinemiyor (409); kullanımdaki yedek silinemiyor.
  - [ ] Elle yedek isteği web isteğinin içinde `pg_dump` çalıştırmıyor (test).
  - [ ] Yanıtlarda dosya yolu, parola, anahtar yok.
  - [ ] Her değiştiren işlem denetim kaydı ve dosya günlüğüne yazılıyor.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-015 — Ekran: Yedekler

- **Durum:** YAPILACAK · **Bağımlı:** BY-014
- **Tasarım:** README §4.10 (1), §4.8 (özet şeridi), §4.4
- **Kapsam:** Ayarlar grubunda "Yedekler" sayfası (`pages/Yedekler`, ortak
  parçalar `features/yedekleme`): özet şeridi (üç ayrı zaman, sıradaki yedek,
  boyut, boş alan), liste (tarih, kaynak, boyut, doğrulama, kopyalar, sabit,
  not), satır işlemleri, "Şimdi Yedek Al", "Klasörü Tara", çalışan işlemin
  durumunu yoklama. Menü, rota, izin; `YEDEKLEME_AKTIF` kapalıyken menüde yok.
- **Kabul ölçütleri:**
  - [ ] İzne göre düğmeler: yalnız görüntüleme izniyle işlem düğmeleri yok.
  - [ ] Silme ve sabit çözme onay ister; toplu seçimde varsayılan seçim yok.
  - [ ] İşlem sürerken düğmeler kapalı, bitince liste yenileniyor.
  - [ ] "Güvenilmeyen" yedek belirgin işaretli; geri yükleme seçeneği yok.
  - [ ] Açık ve koyu temada, dar ekranda kullanılabilir.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-016 — API + Ekran: Yedek Hedefleri

- **Durum:** YAPILACAK · **Bağımlı:** BY-008, BY-015
- **Tasarım:** README §4.6, §4.10 (4)
- **Kapsam:** Hedef tanımı ekleme / düzenleme / silme, tür seçimi (bu görevde
  yalnız "Yerel disk"; diğer türler kendi görevlerinde eklenir), "Bağlantıyı
  Sına", kalan alan, son kopya durumu. Mevcut bağlantı tanımı ekranlarının
  (SQL / entegratör) kalıbı izlenir.
- **Kabul ölçütleri:**
  - [ ] Parola kaydedildikten sonra hiçbir yanıtta dönmüyor; düzenlemede boş bırakılırsa değişmiyor.
  - [ ] Bir planın kullandığı hedef silinemiyor (409, anlaşılır mesaj).
  - [ ] "Sına" sonucu adım adım gösteriliyor (bağlan, yaz, oku, sil).
  - [ ] Tür listesi bağlayıcılardan geliyor; yeni tür eklemek ekranda kod değişikliği gerektirmiyor.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-017 — API + Ekran: Yedek Planları

- **Durum:** YAPILACAK · **Bağımlı:** BY-010, BY-016
- **Tasarım:** README §4.2, §4.3, §4.10 (2)
- **Kapsam:** Plan listesi ve formu: ad, sıklık, saat, etkin, hedefler ve hedef
  başına saklama kuralı. Kaydetmeden önce "sıradaki çalışmalar" ve "bu kuralla
  silinecekler" önizlemesi. Son çalışmanın sonucu. "Şimdi çalıştır".
- **Kabul ölçütleri:**
  - [ ] Kullanıcının örneği kurulabiliyor: her gün yerel, 3 günde bir ikinci hedef, haftalık üçüncü hedef.
  - [ ] Hedefsiz plan kaydedilemiyor; saklama kuralı boş olamıyor.
  - [ ] Önizleme, kaydedildikten sonra zamanlayıcının hesapladığıyla aynı (test).
  - [ ] Çakışan planlar ve ayın 29–31'i formda uyarıyla gösteriliyor.
  - [ ] Plan pasife alınınca zamanlayıcı onu atlıyor; geçmişi duruyor.
  - [ ] **Kullanıcı kararı F6 (§5):** kopyası olan bir hedef plandan
    çıkarılırken saklama kuralı kaybolmuyor: veritabanı reddeder (BY-004-R8,
    bileşik anahtar); uç anlaşılır 409 döner ve ekran nedenini açıklıyor
    (bu plana bağlı kopya sayısı, önce saklamayla temizlenmesi gerektiği) (test).
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:** —

### BY-018 — Ekran: İşlem Günlüğü ve Yedekleme Ayarları

- **Durum:** YAPILACAK · **Bağımlı:** BY-012, BY-015
- **Tasarım:** README §4.9, §4.10 (5), §4.3, §4.8
- **Kapsam:** Denetim kaydı listesi (süzgeç: tarih, kişi, işlem, sonuç). Ayarlar:
  zamanlanmamış yedeklerin süreleri, hedef başına en az sayı, disk eşiği,
  bildirim alıcıları, yaşam sinyali adresleri.
- **Kabul ölçütleri:**
  - [ ] Günlük salt okunur; ekrandan silinemiyor / değiştirilemiyor.
  - [ ] Ayar değişikliği denetim kaydına ve ayar dosya kopyasına yazılıyor.
  - [ ] En az sayı 1'in altına indirilemiyor.
- **Canlı:** Evet. Bu görevden sonra kullanıcı `YEDEKLEME_AKTIF`'i canlıda açabilir.
- **İnceleme günlüğü:** —

---

## Faz 3 — Sunucu dışı hedefler

### BY-019 — Şifreleme (sunucu dışı kopyalar)

- **Durum:** YAPILACAK · **Bağımlı:** BY-008
- **Tasarım:** README §4.7, §4.6 (şifreli kopyanın özeti)
- **Ön koşul:** README §13 karar 4 (özel anahtar kimde, nerede).
- **Kapsam:** Yerel disk dışındaki hedeflere giden kopya açık anahtarla
  şifrelenir (`gpg`); özel anahtar sunucuda bulunmaz. Şifreli dosyanın özeti
  olay günlüğüne. Geri yükleme için şifre çözme adımı (özel anahtar o anda
  sağlanır). Açık anahtarın yüklenmesi / parmak izinin ekranda gösterilmesi.
- **Kabul ölçütleri:**
  - [ ] Açık anahtar tanımlı değilken uzak hedefe kopya **gitmez** (hata; sessizce şifresiz göndermez).
  - [ ] Sunucuda özel anahtar aranmıyor / saklanmıyor.
  - [ ] Yanlış anahtarla çözme anlaşılır hata veriyor; yarım dosya bırakmıyor (test).
  - [ ] Şifreli kopyanın özeti kayıtlı; uzak dosya bununla doğrulanabiliyor.
- **Canlı:** Evet (kapalı / hedef yokken etkisiz).
- **İnceleme günlüğü:** —

### BY-020 — Hedef: FTP ve FTPS

- **Durum:** YAPILACAK · **Bağımlı:** BY-016, BY-019
- **Tasarım:** README §4.6 (tablo)
- **Ön koşul:** Bağımlılık onayı (FTP için Laravel dosya sistemi sürücüsü paketi);
  denemek için bir FTP sunucusu.
- **Kapsam:** FTP (düz) ve FTPS bağlayıcısı; hedef formunda sunucu, port,
  kullanıcı, parola, klasör, pasif kip, şifreli bağlantı seçeneği. Düz FTP
  seçilince ekranda uyarı.
- **Kabul ölçütleri:**
  - [ ] "Sına" yaz – oku – doğrula – sil döngüsünü gerçek sunucuda geçiyor (günlükte not).
  - [ ] Bağlantı koparsa kopya `basarisiz`, yeniden denemede tamamlanıyor; hedefte yarım dosya kalmıyor.
  - [ ] Düz FTP uyarısı görünüyor; FTPS'te sertifika hatası sessizce yok sayılmıyor.
  - [ ] Zaman aşımı tanımlı; asılı kalan bağlantı kilidi sonsuza dek tutmuyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-021 — Hedef: Google Drive

- **Durum:** YAPILACAK · **Bağımlı:** BY-016, BY-019
- **Tasarım:** README §4.6 (Google Drive bağlantısı), §7.10
- **Ön koşul:** Bağımlılık onayı; kullanıcının Google tarafında uygulama kaydı
  açması (kişisel Gmail → "herkese açık" + "yayında"); README §13 karar 3 ve 16
  (yurt dışı bulut). Google'ın güncel kuralları bu görevin başında doğrulanır.
- **Kapsam:** "Google ile bağlan" akışı, yenileme anahtarının şifreli saklanması,
  yalnız uygulamanın oluşturduğu dosyalara erişim yetkisi, klasör, kota
  gösterimi, "Bağlantıyı kes", izin kaldırıldığında "yeniden bağlanın" durumu.
- **Kabul ölçütleri:**
  - [ ] İstenen yetki en dar olan; hesabın diğer dosyaları listelenemiyor.
  - [ ] Yenileme anahtarı hiçbir yanıtta ve günlükte görünmüyor.
  - [ ] İzin Google tarafından kaldırılınca kopya `basarisiz`, hedef "yeniden bağlanın" (test: sahte yanıt).
  - [ ] Bağlantı akışında sahte istek koruması var (durum değeri doğrulanıyor).
  - [ ] Kota dolu yanıtı anlaşılır hata olarak gösteriliyor.
  - [ ] Gerçek bir hesapla uçtan uca denendi; 8 gün sonra hâlâ çalıştığı kontrol edilecek (günlükte tarihli not).
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-022 — Hedef: SFTP

- **Durum:** YAPILACAK · **Bağımlı:** BY-020
- **Tasarım:** README §4.6
- **Ön koşul:** Bağımlılık onayı; kullanıcı bu hedefi istiyorsa (istemiyorsa `IPTAL`).
- **Kapsam:** SFTP bağlayıcısı; parola ya da anahtarla kimlik doğrulama; sunucu
  parmak izinin ilk bağlantıda kaydedilip sonrakilerde doğrulanması.
- **Kabul ölçütleri:**
  - [ ] Sunucu parmak izi değişince bağlantı reddediliyor (uyarıyla).
  - [ ] BY-020'deki sınama ve hata ölçütleri bu tür için de geçiyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-023 — Hedef: Ağ paylaşımı (NAS) ve S3

- **Durum:** YAPILACAK · **Bağımlı:** BY-020
- **Tasarım:** README §4.6
- **Ön koşul:** Kullanıcı bu hedefleri istiyorsa; NAS için ağdaki paylaşımın
  sunucuya bağlanması (`admin` + `sudo`); S3 için bağımlılık onayı.
- **Kapsam:** NAS (sunucuya bağlanmış klasör; "bağlı mı" denetimi) ve S3 uyumlu
  bağlayıcı (silinmeye karşı koruma destekleniyorsa bilgi olarak gösterilir).
- **Kabul ölçütleri:**
  - [ ] NAS bağlı değilken yedek yerel diske yazılıp "kopyalandı" sayılmıyor.
  - [ ] S3'te uygulamanın silme yetkisi yoksa temizlik bunu hata saymıyor, bilgi olarak yazıyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-024 — Kurtarma paketi ve kurulum dokümanı

- **Durum:** YAPILACAK · **Bağımlı:** BY-019
- **Tasarım:** README §9 (kurtarma paketi), §7.5
- **Ön koşul:** README §13 karar 5 ve 13.
- **Kapsam:** `docs/yedekleme/kurtarma.md`: boş bir sunucuda sıfırdan ayağa
  kaldırma adımları; saklanacak sırların listesi ve nerede durdukları (değerleri
  değil); PostgreSQL rolleri ve eklentileri; araç sürümleri. Olay günlüğü,
  denetim günlüğü ve ayar kopyasının sunucu dışına gitmesi (kod).
- **Kabul ölçütleri:**
  - [ ] Dokümanda hiçbir gizli değer yok; yalnız "nerede, kimde".
  - [ ] Adımlar lokalde boş bir veritabanıyla bir kez baştan sona uygulandı (günlükte not).
  - [ ] Günlükler ve ayar kopyası uzak hedefte görünüyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

---

## Faz 4 — Geri yükleme

### BY-025 — Çalıştırıcı servis ve istek klasörü (servis hesabı)

- **Durum:** YAPILACAK · **Bağımlı:** BY-003, BY-013
- **Tasarım:** README §5.2 (servis hesabı, imza anahtarı, isteğe güvenmeme), §5.5, §9
- **Ön koşul:** BY-003 kurulum betiği `PASSED` ve kullanıcı talimatıyla sunucuda
  çalıştırıldı (`admin` + `sudo`). Çalıştırılmadıysa `BEKLIYOR`. Çalıştırmadan
  önce BY-003'ün R5 (bağlantı güvenliği, `fc8d86a`) düzeltmesine yeniden bakılır:
  Codex incelemesi araç filtresine takıldığı için bağımsız incelemeden geçmedi.
- **Kapsam:** İstek klasörü (web yazar, çalıştırıcı okur); çalıştırıcı komutu
  ve servis tanım dosyası (repoda; kurulumu BY-003 betiğiyle); isteğin
  yetkisinin çalıştırıcıda yeniden doğrulanması; yedek almanın ve imza
  anahtarının servis hesabına taşınması; web sürecinin yedek klasörünü yalnız okuması.
- **Kabul ölçütleri:**
  - [ ] Web süreci yedek klasörüne yazamıyor, imza anahtarını okuyamıyor (canlıda denendi).
  - [ ] Yetkisiz kullanıcı adına yazılmış istek dosyası çalıştırıcıda reddediliyor (test).
  - [ ] Bozuk / kötü niyetli istek dosyası (yol kaçışı, beklenmeyen alan) reddediliyor (test).
  - [ ] Geçiş: eski (uygulama kullanıcısıyla alınmış) yedekler listede kalıyor.
- **Canlı:** Evet (BY-003 betiğinin çalıştırılmasıyla birlikte; kullanıcı talimatıyla).
- **İnceleme günlüğü:** —

### BY-026 — Geri yükleme çekirdeği: farklı ada + sınama geri yüklemesi

- **Durum:** YAPILACAK · **Bağımlı:** BY-025
- **Tasarım:** README §4.5-A, §6.3, §4.4 (2. düzey), §5.3 (veritabanı özellikleri)
- **Kapsam:** Hedef ad üretimi ve denetimi (serbest metin yok); veritabanının
  manifestteki kodlama / sıralama / ayarlarla oluşturulması; `pg_restore
  --exit-on-error --single-transaction`; sahipliğin uygulama rolünde kalması;
  sayım ve migration listesi karşılaştırması; hata yolunda yarım veritabanının
  silinmesi. Sınama geri yüklemesi: her yedekten sonra geçici veritabanına
  yükle, karşılaştır, sil; sonucu olay günlüğüne ve yaşam sinyaline.
- **Kabul ölçütleri:**
  - [ ] Hedef ad kalıp dışıysa ya da mevcut bir veritabanıysa işlem başlamıyor (test).
  - [ ] İmzası geçersiz yedek geri yüklenemiyor (test).
  - [ ] Geri yükleme hatasında geçici veritabanı kalmıyor (test).
  - [ ] Lokal PostgreSQL'de: geri yüklenen veritabanında uygulama rolü okuyup yazabiliyor; sıralama ve saat dilimi kaynakla aynı.
  - [ ] Sayım uyuşmazlığı yedeği "doğrulama başarısız" yapıyor ve bildirim üretiyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-027 — Ekran: Geri Yüklemeler (kopya)

- **Durum:** YAPILACAK · **Bağımlı:** BY-026, BY-015
- **Tasarım:** README §4.5-A, §4.10 (3)
- **Ön koşul:** README §13 karar 10 ve 17.
- **Kapsam:** Yedekler listesinden "farklı adla geri yükle"; Geri Yüklemeler
  sayfası: işlemler ve durumları, bekleyen kopyalar (ad, boyut, yaş), silme,
  otomatik silme tarihi ve uyarısı.
- **Kabul ölçütleri:**
  - [ ] İzin `geri_yukleme.kopya` olmadan düğme yok, uç 403.
  - [ ] Kopya silme onay ister; `erp` ve kalıp dışı adlar ekrandan silinemez (test).
  - [ ] Otomatik silme süresi dolmadan uyarı gösteriliyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:** —

### BY-028 — Üstüne geri yükleme çekirdeği

- **Durum:** YAPILACAK · **Bağımlı:** BY-026
- **Tasarım:** README §6.4 (1–8. adım, hata kuralı, yarıda kalan işlem), §7.1, §7.4
- **Kapsam:** §6.4'ün durum makinesi: ön denetimler; bakım modu ve servislerin
  durdurulması; geri yükleme öncesi yedek (bakım modundan sonra); yeni
  veritabanına geri yükleme; migration'ın ayrı süreçte yeni veritabanında
  çalıştırılması; bağlantıya kapatma + koparma; yer değiştirme (BY-002'de
  doğrulanan yöntemle); dizini yeniden kurma, ayarları uzlaştırma. Her adım
  durum dosyasına; yarıda kalan işlemi sürdürme / geri sarma.
- **Kapsam dışı:** Onay adımı ve servis korumaları (BY-029), ekran (BY-030).
- **Kabul ölçütleri:**
  - [ ] Her adımda hata üreten ayrı test: 7. adımdan önceki hatada `erp` değişmemiş ve servisler açık; 7. adımdan sonraki hatada bakım modu korunuyor.
  - [ ] Migration adımı canlı `erp`'ye tek sorgu göndermiyor (test: bağlantı izleme).
  - [ ] Yedekte olup kodda bulunmayan migration varsa işlem başlamıyor (test).
  - [ ] Süreç her adımda öldürülüp devam ettirildiğinde sonuç tutarlı (lokal PostgreSQL'de denendi, günlükte tablo).
  - [ ] Durum dosyasındaki `adim` değerleri serbest metin değil, tek yerde
    tanımlı sabit / enum; karar veren her kod (devam, geri sarma, onay) onları
    kullanıyor (Fable F17). Kurtarma bağlamı (yedek kimliği, hedef / önceki
    veritabanı, isteyen) ilk yazımda dolduruluyor; yer değiştirmeden sonra
    `geri_yuklemeler` satırı bu dosyadan yeniden yazılıyor (test).
  - [ ] Eski veritabanı `erp_onceki_*` adıyla duruyor ve bağlantıya kapalı.
  - [ ] Servis bakım modu dosyasını (`storage/framework/down`, web'in
    yazabildiği klasör) bağlantı izlemeden yazıyor: yol bağlantıysa durur;
    test: web kullanıcısının koyduğu bağlantıyla servisin dosyası değişmiyor
    (D5, BY-003-R6 kapsam notu).
- **Canlı:** Hayır — BY-029 ve BY-031 `PASSED` olana kadar canlıda kullanılmaz.
- **İnceleme günlüğü:** —

### BY-029 — "Onay bekleniyor" korumaları ve acil durum komutu

- **Durum:** YAPILACAK · **Bağımlı:** BY-028
- **Tasarım:** README §6.4 (9–11. adım, yeniden başlatmaya dayanıklılık), §7.6, §4.8
- **Kapsam:** "Onay bekleniyor" durumu; kuyruk işçisi ve zamanlayıcının
  başlarken durum dosyasına bakıp açık geri yükleme varsa çalışmayı reddetmesi;
  `deploy.sh` ve planlı yedeğin aynı denetimi; onay ve "geri dön" işlemleri;
  acil durum komutu (`yedek:acil durum|onayla|geri-don`); onay için gösterilecek
  bilgilerin hesabı (aktif ERP ortamı, senkron durumu, ayar farkı, kullanıcı /
  rol farkı, kaybolacak yükleme sayısı); bildirimlerin doğrudan gönderilmesi.
- **Kabul ölçütleri:**
  - [ ] Durum "onay bekleniyor" iken kuyruk işçisi ve zamanlayıcı iş çalıştırmıyor (test).
  - [ ] Aynı durumda `deploy.sh` ve planlı yedek başlamıyor.
  - [ ] Onaydan önce hiçbir alarm maili / senkron çalışmıyor (test).
  - [ ] "Geri dön" sonrası `erp` eski veritabanı; geri yüklenen kopya silinmemiş, adı belirli.
  - [ ] Acil durum komutu, web ve veritabanı erişilemezken çalışıyor.
  - [ ] Acil durum komutu durumu yazmadan önce yedek kilidini alıyor
    (`IslemDurumu::yaz` kilitsiz yazmayı reddeder — tek yazar, Fable F16);
    çalıştırıcı kilidi tutuyorsa komut beklemeden anlaşılır mesajla duruyor.
  - [ ] Erişim anahtarı süresi (`erisim_anahtari_bitis`) dolunca ilerleme ve
    onay ucu anahtarı reddediyor; komut satırı yolu açık kalıyor (test).
- **Canlı:** Hayır — BY-031'e kadar.
- **İnceleme günlüğü:** —

### BY-030 — Ekran: Üstüne geri yükleme

- **Durum:** YAPILACAK · **Bağımlı:** BY-029, BY-027
- **Tasarım:** README §4.5-B, §7.2, §6.4 (9–10. adım)
- **Ön koşul:** README §13 karar 8.
- **Kapsam:** Yalnız sistem yöneticisine görünen "üstüne geri yükle"; onay
  penceresi (veritabanı adını yazarak, neyin kaybedileceği açıkça); bakım
  modundan muaf ilerleme ucu; işleme özel, süreli erişim anahtarı; adım adım
  ilerleme; onay ekranı (BY-029'un hesapladığı bilgiler) ve "Onayla" / "Geri dön".
- **Kabul ölçütleri:**
  - [ ] Sistem yöneticisi olmayan kullanıcı için düğme yok, uç 403.
  - [ ] İlerleme ucu erişim anahtarı olmadan 401/403; süresi dolan anahtar reddediliyor (test).
  - [ ] İlerleme ucu veritabanı erişilemezken yanıt veriyor (test).
  - [ ] Erişim anahtarı yalnız o işlemi okuyabiliyor; başka hiçbir uca yetki vermiyor.
  - [ ] Tarayıcı kapanıp yeniden açılınca işlem kaybolmuyor (acil durum yolu ekranda anlatılıyor).
  - [ ] Onay penceresinde yedeğin tarihi ve kaybolacak dönem açıkça yazıyor.
- **Canlı:** Hayır — BY-031'e kadar.
- **İnceleme günlüğü:** —

### BY-031 — Arıza senaryoları, tatbikat, işletim dokümanı

- **Durum:** YAPILACAK · **Bağımlı:** BY-030
- **Tasarım:** README §10
- **Kapsam:** README §10'daki arıza senaryolarının her biri (otomatik test ya da
  yazılı elle deneme); `docs/yedekleme/isletim.md`: geri yükleme adım listesi,
  acil durum yolu, sık hatalar; canlıda **kopya** ile prova; ardından kullanıcı
  onayıyla canlıda ilk tatbikat.
- **Kabul ölçütleri:**
  - [ ] Her senaryo için sonuç tablosu: denendi / sonuç / mevcut veri korundu mu / uygulama yanlışlıkla açıldı mı.
  - [ ] Sunucu dışındaki şifreli kopyadan geri yükleme bir kez baştan sona yapıldı.
  - [ ] "Onay bekleniyor" durumunda sunucu yeniden başlatma denendi.
  - [ ] İşletim dokümanını izleyen biri (Codex) adımları eksiksiz buluyor.
- **Canlı:** Evet — bu görev `PASSED` olunca üstüne geri yükleme canlıda kullanılabilir.
- **İnceleme günlüğü:** —

---

## Faz 5 — Sonra

### BY-032 — Dosya yedeği (`storage/app`)

- **Durum:** YAPILACAK · **Bağımlı:** BY-010
- **Tasarım:** README §1 (kapsam), §7.9
- **Ön koşul:** Uygulamada dosya saklayan bir özellik gelmesi; o zamana kadar bekler.
- **Kapsam:** Dosyaların veritabanı yedeğiyle birlikte ve tutarlı alınması;
  tasarımı o gün README'ye yazılır.
- **Kabul ölçütleri:** Görev başlarken yazılır.
- **Canlı:** —
- **İnceleme günlüğü:** —

---

## 5. Açık kararlar ve dış bağımlılıklar

README §13'teki kararların hangi görevi beklettiği:

| Karar / bağımlılık | Bekleyen görev |
|---|---|
| BY-001 için canlıda zamanlayıcı onayı; `.env` kopyasının yeri | BY-001 |
| Bildirim alıcıları (7); dış izleyici (11) | BY-012 |
| Yedek dosyasını tarayıcıya indirme (6) | BY-014 (kapsam dışı bırakıldı) |
| Şifreleme özel anahtarının yeri (4) | BY-019 |
| Bağımlılık onayları (FTP, Google Drive, SFTP, S3 paketleri) | BY-020 – BY-023 |
| Yurt dışı bulut ve KVKK teyidi (3, 16) | BY-021 |
| `.env` / `APP_KEY` ve kurtarma paketinin yeri (5, 13) | BY-024 |
| Sunucu kurulum betiğinin çalıştırılması — `admin` + `sudo`, kullanıcı talimatı (9, 12 kapandı) | BY-025 ve sonrası |
| Kopyaların bekleme süresi (10); inceleyecek kişiler (17) | BY-027 |
| Üstüne geri yüklemeyi kimler yapar, ikinci onay (8) | BY-030 |
| Veri kaybı / ayağa kalkma hedefleri (1), varsayılan planlar (2) | BY-017 (varsayılanlar) |
| Hazır paket kullanımı (18) | BY-007'den önce; karar yoksa README §15 önerisi geçerli |

Sunucu kurulumu yapılmadıysa Faz 1–3 beklemeden ilerler; Faz 4 `BEKLIYOR` olur.

### Verilmiş kullanıcı kararları

- **F6 — tek fiziksel kopya (kullanıcı kararı 2026-11-18, onaylandı).** Bir
  yedeğin bir hedefte tek fiziksel kopyası olur. Aynı hedefi kullanan planlar
  bu kopyaya bağlanır; her plan yalnız **kendi** bağını kaldırabilir. Dosya
  ancak son bağ kalktığında **ve** mevcut koruma kuralları (README §4.3: son
  başarılı yedek, sabitlenmiş, doğrulaması geçmiş en yeni, kullanımdaki, en az
  sayı) izin verdiğinde silinir. Bağlı kopyalar varken hedef plandan
  çıkarılmaya çalışılırsa ekran nedenini açıklar. Şema: BY-004 (`775a0fd`);
  uygulama: BY-010 (saklama), BY-017 (plan ekranı).
- **F7 — yerel dosya silinmesi ≠ yedeğin silinmesi (kullanıcı kararı
  2026-11-18, onaylandı).** Uzakta **doğrulanmış** bir kopya duruyorsa yerel
  dosyanın silinmesiyle yedek `silindi` durumuna geçmez (`yerel_silinme_zamani`
  dolar). Dizin yeniden kurulduğunda da uzak kopya listede bulunabilir. Yedek
  ancak hiçbir kopyası kalmadığında `silindi` sayılır. Şema: BY-004
  (`yedekler.yerel_silinme_zamani`); uygulama: BY-010 (saklama), BY-011
  (dizini yeniden kurma).
