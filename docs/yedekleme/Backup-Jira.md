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

- `BEKLIYOR`: dış bir ön koşul bekleniyor (kullanıcı kararı, bilgi işlem). Nedeni yazılır.
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
| BY-001 | Geçici önlem: günlük yedek ve elle sunucu dışı kopya | 0 | — | REVIEW_BEKLIYOR |
| BY-002 | Keşif: mevcut yedeği geri yükleme, PostgreSQL davranışları | 0 | — | YAPILACAK |
| BY-003 | Bilgi işlem talep listesi | 0 | BY-002 | YAPILACAK |
| BY-004 | Veri modeli, yapılandırma, izinler | 1 | BY-002 | YAPILACAK |
| BY-005 | Dosya kilidi ve işlem durumu dosyası | 1 | BY-004 | YAPILACAK |
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

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** —
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

### BY-002 — Keşif: mevcut yedeği geri yükleme, PostgreSQL davranışları

- **Durum:** YAPILACAK · **Bağımlı:** —
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
  - [ ] Dokuz sorunun her biri "doğrulandı / doğrulanamadı + nasıl denendi" ile yanıtlandı.
  - [ ] Tasarımı değiştiren her bulgu README'ye işlendi (özellikle §6.4 7. adım, §7.7).
  - [ ] Deneme için oluşturulan veritabanları silindi.
- **Canlı:** Yok (doküman).
- **İnceleme günlüğü:** —

### BY-003 — Bilgi işlem talep listesi

- **Durum:** YAPILACAK · **Bağımlı:** BY-002
- **Tasarım:** README §9, §5.5, §4.8
- **Kapsam:** `docs/yedekleme/bilgi-islem-talepleri.md`: bilgi işleme
  iletilecek, teknik olmayan dille de okunabilen talep metni — servis hesabı ve
  yetki matrisi, yedek klasörü ve dosya yetkileri, çalıştırıcı servis tanımı,
  dış izleyici, inceleme rolü, sanal makine yedeği sorusu. Her talep için "neden
  gerekli" ve "verilmezse ne olur".
- **Kabul ölçütleri:**
  - [ ] README §9'daki her madde bir talep olarak yer alıyor; BY-002 bulgularıyla tutarlı.
  - [ ] Hangi görevin hangi talebe bağlı olduğu tabloda yazılı.
- **Canlı:** Yok (doküman).
- **İnceleme günlüğü:** —

---

## Faz 1 — Çekirdek (yerel diske planlı yedek)

### BY-004 — Veri modeli, yapılandırma, izinler

- **Durum:** YAPILACAK · **Bağımlı:** BY-002
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
  - [ ] `migrate` ve `migrate:rollback` temiz çalışıyor (SQLite ve PostgreSQL).
  - [ ] Hedef parolası `encrypted` cast ile saklanıyor; API çıktısında hiç dönmüyor (test).
  - [ ] Kullanılan hedef / plan silinemiyor (mevcut `KayitSilme` kalıbı → 409).
  - [ ] Yeni izinler Roller ekranında görünüyor; izin testleri güncel.
- **Canlı:** Evet (etkisiz; `YEDEKLEME_AKTIF` kapalı).
- **İnceleme günlüğü:** —

### BY-005 — Dosya kilidi ve işlem durumu dosyası

- **Durum:** YAPILACAK · **Bağımlı:** BY-004
- **Tasarım:** README §5.2 (kilit), §6.4 (yeniden başlatmaya dayanıklılık), §7.2
- **Kapsam:**
  - `YedekKilidi`: işletim sistemi düzeyinde dosya kilidi; veritabanına ve
    önbelleğe dayanmaz. "Al / alamazsan vazgeç" ve "bekle" kipleri.
  - `IslemDurumu`: dosyada tutulan işlem durumu (JSON). **Şeması bu görevde bu
    dosyaya yazılır:** işlem kimliği, tür, adım, başlangıç, son güncelleme,
    hata, erişim anahtarı özeti. Atomik yazma (geçici dosya + taşıma).
  - "Açık geri yükleme var mı" sorgusu (BY-029 kullanacak).
- **Kabul ölçütleri:**
  - [ ] İki süreç aynı anda kilidi alamıyor (test); süreç ölünce kilit düşüyor.
  - [ ] Veritabanı bağlantısı yokken kilit ve durum dosyası çalışıyor (test: bağlantı kapalı).
  - [ ] Durum dosyası yarım yazılmış hâlde okunmuyor (atomik yazma testi).
  - [ ] Bozuk durum dosyası anlaşılır hata veriyor; sessizce "işlem yok" sayılmıyor.
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:** —

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
  - Veritabanının yedekte bulunmayan özellikleri manifeste (kodlama, sıralama, ayarlar, sahip rol).
  - Geçiş dönemi: yedeği uygulama kullanıcısı alır; klasör yapılandırmadan (README §5.2).
- **Kapsam dışı:** Hedeflere kopyalama, planlar, temizlik.
- **Kabul ölçütleri:**
  - [ ] Başarılı çalışmada `.dump` + imzalı `.json` oluşuyor; `yedekler` kaydı `tamam`.
  - [ ] `pg_dump` hata verirse kayıt `basarisiz`, klasörde yarım / geçici dosya yok (test).
  - [ ] Doğrulama adımı hata verirse yedek `basarisiz` sayılıyor, dosya listeye girmiyor (test).
  - [ ] Kilit başka süreçteyken komut anlaşılır mesajla çıkıyor, ikinci yedek almıyor (test).
  - [ ] Çalıştırılan komut argümanlarında parola yok (test: `Process::assertRan`).
  - [ ] Disk alanı eşiğin altındaysa yedek başlamıyor (test).
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
- **Ön koşul:** Kullanıcı bu hedefleri istiyorsa; NAS için bilgi işlemin
  paylaşımı sunucuya bağlaması; S3 için bağımlılık onayı.
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
- **Ön koşul:** Bilgi işlem servis hesabını, yetkileri ve servis tanımını
  sağladı (BY-003). Sağlanmadıysa `BEKLIYOR`.
- **Kapsam:** İstek klasörü (web yazar, çalıştırıcı okur); çalıştırıcı komutu
  ve servis tanım dosyası (repoda, kurulumu bilgi işlem yapar); isteğin
  yetkisinin çalıştırıcıda yeniden doğrulanması; yedek almanın ve imza
  anahtarının servis hesabına taşınması; web sürecinin yedek klasörünü yalnız okuması.
- **Kabul ölçütleri:**
  - [ ] Web süreci yedek klasörüne yazamıyor, imza anahtarını okuyamıyor (canlıda denendi).
  - [ ] Yetkisiz kullanıcı adına yazılmış istek dosyası çalıştırıcıda reddediliyor (test).
  - [ ] Bozuk / kötü niyetli istek dosyası (yol kaçışı, beklenmeyen alan) reddediliyor (test).
  - [ ] Geçiş: eski (uygulama kullanıcısıyla alınmış) yedekler listede kalıyor.
- **Canlı:** Evet (bilgi işlemle birlikte).
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
  - [ ] Eski veritabanı `erp_onceki_*` adıyla duruyor ve bağlantıya kapalı.
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
| Servis hesabı, yetki matrisi, servis tanımı — bilgi işlem (9, 12) | BY-025 ve sonrası |
| Kopyaların bekleme süresi (10); inceleyecek kişiler (17) | BY-027 |
| Üstüne geri yüklemeyi kimler yapar, ikinci onay (8) | BY-030 |
| Veri kaybı / ayağa kalkma hedefleri (1), varsayılan planlar (2) | BY-017 (varsayılanlar) |
| Hazır paket kullanımı (18) | BY-007'den önce; karar yoksa README §15 önerisi geçerli |

Bilgi işlem gecikirse Faz 1–3 beklemeden ilerler; Faz 4 `BEKLIYOR` olur.
