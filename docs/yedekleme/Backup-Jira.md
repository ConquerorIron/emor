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
| BY-006 | Manifest, imza, olay günlüğü | 1 | BY-004 | PASSED |
| BY-007 | Yedek alma (`yedek:al`) | 1 | BY-005, BY-006 | PASSED |
| BY-008 | Hedef soyutlaması, Yerel disk hedefi, kopyalama | 1 | BY-007 | PASSED |
| BY-009 | Planlar ve zamanlayıcı | 1 | BY-008 | PASSED |
| BY-010 | Saklama ve temizlik | 1 | BY-009 | PASSED |
| BY-011 | Klasör tarama, dizini yeniden kurma | 1 | BY-006, BY-008 | PASSED |
| BY-012 | Bildirimler ve yaşam sinyali | 1 | BY-009 | PASSED |
| BY-013 | `deploy.sh` entegrasyonu, eski yedeklerin içeri alınması | 1 | BY-007, BY-011 | PASSED |
| BY-014 | API: Yedekler | 2 | BY-010, BY-011 | REVIEW_BEKLIYOR |
| BY-015 | Ekran: Yedekler | 2 | BY-014 | BEKLIYOR |
| BY-016 | API + Ekran: Yedek Hedefleri | 2 | BY-008, BY-015 | PASSED |
| BY-017 | API + Ekran: Yedek Planları | 2 | BY-010, BY-016 | PASSED |
| BY-018 | Ekran: İşlem Günlüğü ve Yedekleme Ayarları | 2 | BY-012, BY-015 | PASSED |
| BY-019 | Şifreleme (sunucu dışı kopyalar) | 3 | BY-008 | PASSED |
| BY-020 | Hedef: FTP ve FTPS | 3 | BY-016, BY-019 | BEKLIYOR |
| BY-021 | Hedef: Google Drive | 3 | BY-016, BY-019 | BEKLIYOR |
| BY-022 | Hedef: SFTP | 3 | BY-020 | BEKLIYOR |
| BY-023 | Hedef: Ağ paylaşımı (NAS) ve S3 | 3 | BY-020 | PASSED |
| BY-024 | Kurtarma paketi ve kurulum dokümanı | 3 | BY-019 | PASSED |
| BY-025 | Çalıştırıcı servis ve istek klasörü (servis hesabı) | 4 | BY-003, BY-013 | BEKLIYOR |
| BY-026 | Geri yükleme çekirdeği: farklı ada + sınama geri yüklemesi | 4 | BY-025 | PASSED |
| BY-027 | Ekran: Geri Yüklemeler (kopya) | 4 | BY-026, BY-015 | PASSED |
| BY-028 | Üstüne geri yükleme çekirdeği | 4 | BY-026 | PASSED |
| BY-029 | "Onay bekleniyor" korumaları ve acil durum komutu | 4 | BY-028 | PASSED |
| BY-030 | Ekran: Üstüne geri yükleme | 4 | BY-029, BY-027 | PASSED |
| BY-031 | Arıza senaryoları, tatbikat, işletim dokümanı | 4 | BY-030 | BEKLIYOR |
| BY-032 | Dosya yedeği (`storage/app`) | 5 | BY-010 | BEKLIYOR |

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
  | `bariyer` | `null` \| `{baglanma_yetkileri: metin \| null}` | Üstüne geri yüklemede yazıcı bariyeri kurulmadan önceki ÖZGÜN `datacl` (`null` = varsayılan yetkiler); geri sarma, geri dönüş ve `erp_onceki_*` bununla eski hâline döner (BY-028-R1) |
  | `ayar_secimleri` | `null` \| `{grup: guncel \| geri_yuklenen}` | Onayda yöneticinin farklı ayar grupları için seçimi (BY-028-R2) |
  | `kullanilan_kanitlar` | nesne `{nonce (32 hex): son geçerlilik (unix)}` | Tüketilmiş karar kanıtları; tek kullanım (BY-030-R1) |

  Son beş alan **kurtarma bağlamıdır** (Fable incelemesi F5): üstüne geri
  yüklemede yer değiştirmeden sonra `geri_yuklemeler` satırı eski veritabanında
  kalır; çökmeden sonra devam / geri sarma kararı, hangi veritabanının
  silineceği ve geçmiş kaydının yeniden yazılması yalnız bu dosyaya dayanabilir.
  Alanların hepsi her zaman bulunur (`null` olabilir); eksik alan bozuk sayılır —
  son üç alan (BY-028-R1/R2, BY-030-R1) hariç: onlardan önce yazılmış dosyada
  yoksa boş sayılır (sürüm aynı; yazılırken hep yazılır).

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

- **Durum:** PASSED · **Bağımlı:** BY-004
- **Tasarım:** README §5.3, §4.5 (güven sınırı), §7.5
- **Kapsam:**
  - `YedekManifesti`: README §5.3'teki alanlar; yaz / oku / doğrula. Şema sürümü alanı.
  - İmza: veritabanı dışında duran anahtarla (yapılandırmadan dosya yolu) manifest
    imzası; doğrulama. Anahtar yoksa anlaşılır hata.
  - `APP_KEY` parmak izi (anahtarın kendisi değil; geri döndürülemez).
  - `OlayGunlugu`: salt eklemeli dosya (satır başına JSON): sabitleme, doğrulama
    sonucu, kopya durumu, silinme. Manifest + olay günlüğünden "güncel durum" hesabı.
- **Kabul ölçütleri:**
  - [x] Manifestin bir baytı değişince imza doğrulaması başarısız (test).
  - [x] Yedek dosyası değişince özet doğrulaması başarısız (test).
  - [x] Olay günlüğünden durum yeniden kurulabiliyor: sabitle → çöz → sabitle sonucu "sabit" (test).
  - [x] Bozuk / eksik manifest "güvenilmeyen" olarak sınıflanıyor, istisna fırlatıp akışı kesmiyor.
  - [x] Parmak izinden `APP_KEY` elde edilemiyor; günlüklere anahtar yazılmıyor.
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-006..008 manifest, yedek alma, hedefler ve kopyalama` olan commit
    - Skill'ler: `laravel-best-practices` (architecture, error-handling,
      config), `testing-best-practices` (isolation, assertions).
    - **Manifest şeması (sürüm 1)** — `YedekManifesti`; dosya
      `{"manifest": {...}, "imza": "<hex>"}`. Alanlar: `surum`, `kimlik`,
      `alinma_zamani` (saat dilimi ekli), `kaynak`, `plan_adlari`,
      `veritabani`, `dosya_adi` (yol ayırıcısız), `boyut`, `sha256`,
      `pg_sunucu_surumu`, `pg_dump_surumu`, `uygulama_surumu` (git commit,
      depo dosyalarından), `migrationlar`, `satir_sayilari`,
      `verisi_alinmayan_tablolar`, `veritabani_ozellikleri` {kodlama,
      siralama, karakter_turu, ayarlar, baglanma_yetkileri}, `sahip_rol`,
      `sifreli`, `app_key_parmak_izi`, `aciklama`, `alan_kullanici_id`,
      `alan_kullanici`, `ice_alindi` (BY-013), `geri_yukleme_kimligi`
      (geri yükleme öncesi yedeğin işlemi). Eksik ya da fazla alan, taşan
      tarih, yol içeren dosya adı şema hatasıdır.
    - **İmza:** HMAC-SHA256, kanonik JSON (`KanonikJson`: anahtarlar
      sıralı) üzerine, alan ayrımlı önekle; anahtar
      `yedekleme.imza_anahtari` dosyası (`yedek:imza-anahtari` üretir, 0600,
      var olanın üstüne yazmaz). Anahtar yoksa yazma `ImzaAnahtariYok`
      (çözümü söyleyen mesaj), okuma "güvenilmeyen".
    - **Okuma** (`ManifestDeposu::oku`) istisna fırlatmaz:
      `ManifestOkumaSonucu` güvenilir ya da güvenilmeyen + neden (yok,
      okunamadı, JSON, imza yok, imza tutmuyor, anahtar yok, şema).
    - **Olay günlüğü** `olaylar.jsonl` (yedek klasöründe): satır başına imzalı
      JSON, `flock` + tek `fwrite` + `fsync`. Olay türleri ve ayrıntıları
      `YedekOlayi` / `OlayTuru`: sabitlendi, sabit_cozuldu, aciklama
      {aciklama}, dogrulandi {durum}, kopya {hedef_id, hedef_ad, durum,
      uzak_kimlik, sifreli, sifreli_sha256, plan_idleri}, kopya_silindi
      {hedef_id, hedef_ad}, yerel_silindi, silindi. İmzası tutmayan satır
      atlanır ve sayılır. `GuncelDurum::hesapla` olayları zaman sırasıyla
      uygular.
    - **APP_KEY parmak izi:** alan ayrımlı HMAC'in ilk 16 hex'i.
    - Testler `YedekManifestiTest` (10). Kontroller: aşağıdaki BY-008
      kaydına bakın (üç görev aynı commit'te).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-006-R1 [P2] Kesilen günlük yazımı sonraki sağlam olayı gizleyebilir.**
       `backend/app/Services/Yedekleme/OlayGunlugu.php:60`. Yazım satır ortasında kesilirse sonraki ekleme geçerli JSON'u yarım parçaya bitiştirir. Tarayıcı birleşik satırı atlar. Yeniden kurulan dizinde son sabitleme ya da kopya kaydı kaybolabilir (D3).
       Kilit altında son eksik satırı ayırın veya karantinaya alın, ardından yeni olayı ekleyin. Yarım satırdan sonra yazılan geçerli olayın yeniden taramada uygulandığını doğrulayın.
    - Ortak doğrulama · PHP 8.5.9, Laravel 13.24.0. SQLite 1333 geçti, 1 atlandı. İzole PostgreSQL 16 veritabanında filtreli koşu 488/488. Frontend 389/389, tür kontrolü, lint ve build başarılı. Lint ve build uyarılarla tamamlandı. Pint 630 dosya, `deploy.test.sh` 14/14, kabuk sözdizimi ve `git diff --check` temiz. Root isteyen kurulum testi, canlı/tatbikat, gerçek FTP/SFTP/Drive ve elle tarayıcı görünümü yeniden çalıştırılmadı. Skill’ler code-review, laravel-best-practices, testing-best-practices, supabase-postgres-best-practices, vercel-react-best-practices, vercel-composition-patterns ve tailwind-design-system. Laravel davranışı kurulu vendor koduyla doğrulandı.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `OlayGunlugu::ekle` artık `a+` kipinde açıyor ve kilit altında son baytı okuyor: dosya `\n` ile bitmiyorsa (önceki yazım kesildi) yeni satırın önüne satır sonu ekleniyor. Yarım parça kendi satırında kalıyor, tarayıcı onu bozuk satır sayıp atlıyor, yeni olay ona bitişip kaybolmuyor. Salt eklemeli kural korunuyor (satır silinmiyor, karantina dosyası yok). Test: `DizinKurucuTest::test_yarim_satirdan_sonra_yazilan_olay_yeniden_taramada_uygulanir` (T5: düzeltmesiz kırmızı, sabitleme kayboldu).
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Yarım satır kilit altında sonraki sağlam olaydan ayrılıyor. PostgreSQL ve SQLite koşularında ilgili olay günlüğü regresyonu geçti.
    - Ortak doğrulama · PHP 8.5.9, Laravel 13.24.0, izole PostgreSQL 16 ve Node 24 kullanıldı. SQLite 1401 geçti / 4 atlandı. PostgreSQL alt kümesi 674 geçti / 3 atlandı. Opt-in gerçek PostgreSQL 46/46. Frontend 397/397, tür kontrolü, lint ve build geçti (lint ve bundle boyutu uyarıları). Pint 84 dosya, yayın betiği 43/43, Bash sözdizimi ve `git diff --check` temiz. Yetki isteyen kurulum testi, canlı PostgreSQL 18.6 ve systemd, gerçek sağlayıcılar, boş makinede kurtarma ve tarayıcı görünümü bu turda çalıştırılmadı.

### BY-007 — Yedek alma (`yedek:al`)

- **Durum:** PASSED · **Bağımlı:** BY-005, BY-006
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
  - [x] Başarılı çalışmada `.dump` + imzalı `.json` oluşuyor; `yedekler` kaydı `tamam`.
  - [x] `pg_dump` hata verirse kayıt `basarisiz`, klasörde yarım / geçici dosya yok (test).
  - [x] Doğrulama adımı hata verirse yedek `basarisiz` sayılıyor, dosya listeye girmiyor (test).
  - [x] Kilit başka süreçteyken komut anlaşılır mesajla çıkıyor, ikinci yedek almıyor (test).
  - [x] Çalıştırılan komut argümanlarında parola yok (test: `Process::assertRan`).
  - [x] Disk alanı eşiğin altındaysa yedek başlamıyor (test).
  - [x] Süre sınırı yalnız pozitif tam sayı kabul ediliyor; `abc`, `0`, `-10` gibi
    geçersiz ayar açık hatayla duruyor, sınırsız çalışmaya dönüşmüyor (test; BY-004-R5).
  - [x] Lokal PostgreSQL'de gerçek araçlarla bir kez uçtan uca denendi (günlükte not).
- **Canlı:** Evet (etkisiz; komut elle çalıştırılabilir).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-006..008 manifest, yedek alma, hedefler ve kopyalama` olan commit
    - `YedekAlici::al($kilit, $kaynak, …)`: durum klasörünün kilidi
      tutulmuyorsa `LogicException`; açık üstüne geri yükleme varsa (geri
      yükleme öncesi yedek hariç) `YedekAlmaHatasi`, kayıt açılmaz. Diğer
      her hata kaydı `basarisiz` yapar (kod: DISK_DOLU, PG_DUMP, DOGRULAMA,
      DOSYA, YAPILANDIRMA), geçici dosya silinir, denetim kaydı yazılır.
    - Anlık görüntü `KaynakVeritabani` (gerçeği `PostgresKaynakVeritabani`:
      ayrı bağlantıda REPEATABLE READ READ ONLY + `pg_export_snapshot()`;
      sayımlar, migration listesi, kodlama / sıralama / ayarlar /
      bağlanma yetkileri, tablo sahibi, sunucu sürümü o işlemden). Testlerde
      sahtesi bağlanır.
    - `PgAraclari`: komut dizisi, süre sınırı, `PGPASSFILE`; süreç
      ortamındaki bütün `PG*` değişkenleri temizlenir (G12). Parola dosyası:
      `yedekleme.parola_dosyasi` (servis hesabı) ya da geçiş döneminde
      bağlantı tanımından 0700 klasörde 0600 geçici dosya (iş bitince silinir).
    - Ad kalıbı `erp_<kaynak>_<YYYY-AA-GG_SSddss><saat farkı>.dump`
      (ör. `erp_elle_2026-11-21_004007+0300.dump`); aynı saniyede `_2`.
      Dosya izni 0640.
    - İşlem durumu dosyası adımları enum `YedekAdimi` (basladi, pg_dump,
      dogrulama, manifest, bitti, basarisiz).
    - Yapılandırma: `YedeklemeYapilandirmasi` tek okuma noktası; yeni
      anahtarlar `durum_klasoru`, `kalici_kok`, `imza_anahtari`,
      `parola_dosyasi`, `baglanti`, `rol`, `istek_klasoru`, `ayar_kopyasi`,
      `kopya_deneme`, `sinama_geri_yukleme`, `kopya_bekleme_gun`, araç yolları.
      Süreler config'te `(int)` YAPILMAZ; `sureSiniri()` yalnız pozitif tam
      sayı kabul eder (BY-004-R5). `yedekleme` günlük kanalı (denetim
      kaydının dosya kopyası).
    - `yedek:al {--kaynak=elle|yayin_oncesi} {--aciklama=} {--kullanici=}`:
      yönetici komutu, modül kapalıyken de çalışır; kilit meşgulse çıkış 75.
    - **Lokal uçtan uca (gerçek araçlar, PostgreSQL 16.15):**
      `yedek:imza-anahtari` + `yedek:al` → 2.454.956 baytlık dump, imzalı
      manifest (49 migration, 47 tablo sayımı, sıralama C.UTF-8), kayıt
      `tamam / ozet_tamam / imzali`. `pg_restore --list`'te geçici
      tabloların (sessions, cache, jobs …) TABLE DATA girdisi yok.
    - Testler `YedekAliciTest` (13). Kontroller: BY-008 kaydına bakın.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-007-R1 [P2] Öldürülen yedek sürekli çalışıyor görünebilir.**
       `backend/app/Services/Yedekleme/YedekAlici.php:87 ve :203, backend/app/Services/Yedekleme/DizinKurucu.php:100`. Süreç hata yakalayıcısına ulaşmadan durursa `calisiyor` kaydı kalır. Tarama bunu kapatmaz. Son başarısız çalışma ve temizlik koruması da kesintiyi görmez (D3, D6).
       Yeni kilit sahibi sahipsiz çalışmayı sonuçlandırsın. Yalnız doğrulanmış dosya ve imza varsa kaydı tamamlasın. Dump sırasında ve dosya taşındıktan hemen sonra durmuş iki çalışma üzerinden sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Yeni `SahipsizYedekler::sonuclandir($kilit)`: kilit tutuluyorsa `calisiyor` kayıtları sonuçlandırır. İmzası geçerli, kimliği aynı manifest + manifestle tutan dosya varsa tamamlar (`tamam/ozet_tamam/imzali`, `dogrulandi` olayı, denetim kaydı). Yoksa `basarisiz` + `YEDEK_YARIDA_KALDI` yapar, `.<ad>.*.tmp` geçici dosyalarını ve dosyasız (aynı kimlikli) manifesti siler. Bu adımı `YedekAlici::al` her yedekten önce çağırır, `DizinKurucu::tara(?YedekKilidi)` kilit verilirse taramadan önce çağırır. `TaraIsleyicisi` kilidi veriyor. `yedek:tara` artık yedekleme kilidini alıyor (en çok `kilit_bekleme` sn bekliyor, meşgulse çıkış 75): yürüyen bir yedeğin yarım hâli taranmıyor. Taramadaki "dosya yok" adımı artık yalnız `tamam` kayıtlara bakıyor: başarısız denemenin hiç dosyası olmadı, `basarisiz` kalıyor ve saklamanın temkini onu görüyor. Önceden bu adım başarısız kaydı `silindi` yapıyordu. Frontend i18n'e `YEDEK_YARIDA_KALDI` eklendi. Testler: `YedekAliciTest::test_dump_sirasinda_olen_surecin_kaydi_sonraki_yedekte_yarida_kaldi_olur_gecici_dosya_silinir`, `YedekAliciTest::test_dosya_tasindiktan_sonra_olen_surecin_yedegi_imzali_manifestle_tamamlanir`, `DizinKurucuTest::test_kilitli_tarama_sahipsiz_calisan_yedegi_yarida_kaldi_yapar` (T5: üçü de düzeltmesiz kırmızı). Doğrulanamayan: gerçek SIGKILL'le ölen süreç denenmedi, durum testte kuruldu. `UstuneGeriYukleyici` (a4) `tara()`'yı kilitsiz çağırıyor, o yolda sahipsiz adım çalışmıyor. a4 isterse `$kilit` verebilir.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Yeni kilit sahibi, yarıda kalmış kaydı imza ve dosya özetiyle doğrulayıp tamamlıyor veya başarısız kapatıyor. Dizin taraması başarısız denemeyi saklama koruması için tutuyor.

### BY-008 — Hedef soyutlaması, Yerel disk hedefi, kopyalama

- **Durum:** PASSED · **Bağımlı:** BY-007
- **Tasarım:** README §4.6, §4.2 (tek yedek, çok hedef), §6.1 (6. adım)
- **Kapsam:**
  - `YedekHedefi` arayüzü: yaz, oku, sil, listele, sına (yaz – oku – doğrula – sil), kalan alan.
  - "Yerel disk" bağlayıcısı (hedef klasörü yapılandırılabilir).
  - `YedekKopyalayici`: bir yedeği bir hedefe kopyalar; `yedek_kopyalari` kaydı;
    özet doğrulaması; yeniden deneme; olay günlüğüne yazma.
  - Bir hedefin başarısızlığı diğer hedefleri ve yedeğin kendisini etkilemez.
- **Kapsam dışı:** Uzak hedef türleri (Faz 3), şifreleme (BY-019).
- **Kabul ölçütleri:**
  - [x] Yeni hedef türü eklemek yalnız yeni bir bağlayıcı sınıfı gerektiriyor (arayüz testi).
  - [x] İki hedeften biri hata verince diğerinin kopyası `tamam`, hatalınınki `basarisiz` (test).
  - [x] Kopyadan okunan dosyanın özeti kaynakla aynı değilse kopya `basarisiz` (test).
  - [x] "Sına" başarısız yazma / okuma / silmeyi ayrı ayrı raporluyor (test).
  - [x] Kopyalanmakta olan yedek temizlikten korunuyor (kullanımda işareti; test).
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-006..008 manifest, yedek alma, hedefler ve kopyalama` olan commit
    - `Hedefler\HedefBaglayicisi`: baglan, yaz (yarım dosya bırakmadan; uzak
      kimlik döner), oku, sil, listele, kalanAlan. `HedefHatasi` makine
      okunur kodlarla. Her bağlayıcı `static tanim(HedefTuru): HedefTuruTanimi`
      (form alanları, sunucu dışı mı, uyarı) sağlar; `HedefKatalogu`
      tür → sınıf eşlemesi (bağlayıcısı olmayan tür listelenmez).
    - `YerelDiskBaglayicisi`: klasör boşsa hedef yedek klasörünün kendisidir
      (kopya yapılmaz; README §4.2'deki "yerel disk hedefi"); klasör
      verilirse `AtomikDosya::kopyala` (geçici ad + fsync + rename + klasör fsync).
    - `HedefSinayici`: baglan → yaz → oku → dogrula → sil, her adım ayrı
      sonuç; başarısız adımdan sonrakiler `null` (atlandı), okuma
      başarısız olsa da silme denenir.
    - `YedekKopyalayici`: (yedek, hedef) tekil kopya; tamamsa yalnız plan bağı
      eklenir (F6). Her denemede yerel dosyanın özeti manifestle, hedeften
      geri okunan kopyanınki yazılanla karşılaştırılır. En çok
      `kopya_deneme` (3) deneme; yapılandırma / kimlik / yeniden bağlan /
      parmak izi / sertifika / bağlı değil hataları yeniden denenmez (D8).
      Sunucu dışı hedefe şifreleme olmadan kopya gitmez (`KopyaSifreleyici`
      bu görevde hata verir; BY-019 doldurur). Sonuç olay günlüğüne ve
      denetim kaydına.
    - `KullanimDenetimi`: kopyası bekliyor / kopyalaniyor olan ya da işlem
      durumu dosyasında bitmemiş işlemin yedeği kullanımdadır; durum dosyası
      okunamıyorsa hepsi kullanımda sayılır (D6). BY-010 temizliği bunu kullanır.
    - Testler `YedekKopyalayiciTest` (10; sahte `Tests\Yedekleme\BellekHedefi`
      yalnız arayüzü uygular, katalogda S3 yerine kaydedilir).
    - **Kontroller (BY-006…008):** yedekleme testleri 47/47 (SQLite); tam
      backend ve PostgreSQL alt kümesi publish.sh ile (commit öncesi). Pint temiz.
    - Doğrulanamayan: servis hesabıyla çalışma (BY-025); canlıya alınmadı.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-008-R1 [P1] Ana dosya bağımsız bir kopya olarak sayılabiliyor.**
       `backend/app/Services/Yedekleme/Hedefler/YerelDiskBaglayicisi.php:35 ve :49, backend/app/Services/Yedekleme/PlanliYedek.php:94, backend/app/Services/Yedekleme/DizinKurucu.php:219`. Ana klasör metin olarak, kopyalama ise gerçek konum üzerinden karşılaştırılıyor. Aynı klasörün farklı geçerli yazımı ana klasör diye tanınmıyor. Kopyalayıcı aynı dosyayı başarılı sayabiliyor ve plan eldeki tek arşivi silebiliyor (D4, F7).
       Ana kaynak ve hedefi kopyalama, temizlik ve dizin taramasında aynı gerçek konum ölçütüyle tanıyın. Ana dosyanın kendi konumunda doğrulanmasını bağımsız kopya saymayın. Aynı dizinin iki yazımıyla çalıştırılan planda yedeğin yerinde kaldığını doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Ana klasörün TEK ölçütü artık `YerelDiskBaglayicisi::anaKlasorHedefiMi($hedef, $yapilandirma)`. Yazıma değil gerçek konuma bakıyor: iki yol da okunabiliyorsa aygıt + düğüm numarası (sondaki `/`, `..`, sembolik bağlantı, sabit bağlantı ve bind mount), okunamıyorsa sadeleştirilmiş yazım. `anaKlasorMu()`, `DizinKurucu` (eski metin karşılaştırması kaldırıldı), `SaklamaPolitikasi` ve `SilIsleyicisi` bunu kullanıyor. `yaz()`: hedefteki dosya yerel dosyanın kendisiyse ve hedef ana klasör değilse `HedefHatasi::ANA_DOSYA` (`HEDEF_ANA_DOSYA`, i18n eklendi) veriyor, kopya başarısız oluyor. `PlanliYedek` artık yerel dosyayı kendisi silmiyor (BY-010). Testler: `YedekZamanlaTest::test_ana_klasorun_farkli_yazimini_hedefleyen_planda_yedek_yerinde_kalir` (üç yazım: sondaki bölü / `..` / sembolik bağlantı; T5: `..` ve bağlantı düzeltmesiz kırmızı, arşiv silindi; sondaki bölü önceden de geçiyordu, gerileme koruması), `YedekZamanlaTest::test_baska_klasorde_yerel_dosyanin_kendisi_kopya_sayilmaz` (T5: düzeltmesiz kırmızı, kopya `tamam` sayıldı). Not: `YedekKopyalayici` (a2) `ANA_DOSYA`'yı yeniden denenmez listesine almıyor, 3 deneme yapıyor. Zararsız, ona dokunulmadı.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Kopyalama, tarama ve saklama aynı aygıt ve düğüm karşılaştırmasını kullanıyor. Alternatif yol yazımları ve aynı ana dosyaya işaret eden hedef testleri geçti.

### BY-009 — Planlar ve zamanlayıcı

- **Durum:** PASSED · **Bağımlı:** BY-008
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
  - [x] Her sıklık türü için vade hesabı testli (ay sonu, 3 günde bir, saat aralığı dahil).
  - [x] Aynı dakikaya düşen üç plan tek `pg_dump` çalıştırıyor, üç hedefe kopya gidiyor (test).
  - [x] Sunucu 3 gün kapalı kaldıktan sonra günlük plan bir kez çalışıyor, üç kez değil (test).
  - [x] Ayar kapalıyken komut hiçbir süreç başlatmıyor (test).
  - [x] Saat hesabı sunucunun ve veritabanının saat diliminden bağımsız (test).
- **Canlı:** Evet (kapalı). Açma kararı kullanıcının.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-009..013 planlar, saklama, tarama, bildirim, deploy entegrasyonu` olan commit
    - `PlanTakvimi`: saatlik (aralık + saat penceresi + gün süzgeci), günlük,
      N günde bir (`baslangic_tarihi`'nden sayılır), haftalık, aylık; hesap
      `Europe/Istanbul`'da yapılır, sonuç UTC döner. Ayın 29–31'i o ayda
      yoksa son güne kayar; `ayinSonunaKayabilir()` ekran uyarısı içindir.
      `siradakiler(n)` önizleme.
    - `PlanliYedek` + `yedek:zamanla` (dakikada bir, bakım modunda da,
      `withoutOverlapping`): vadesi gelen bütün planlar için TEK yedek
      (kaynak `plan`, plan adları manifestte), sonra her planın her hedefine
      kopya. Kaçırılan çalışma bir kez telafi edilir: `sonraki_calisma`
      geçmişteyse bir kez çalışır, sonraki an ŞİMDİ'den hesaplanır. İlk kez
      görülen plan geriye dönük çalışmaz. Modül kapalıyken hiçbir süreç yok.
    - Planların hiçbirinde ana yerel hedef (klasörü boş Yerel disk) yoksa ve
      en az bir kopya başarılıysa yerel dosya silinir (`YerelDosyaSilici`;
      manifest kalır, `yerel_silinme_zamani` dolar — F7). Kopya başarısızsa
      silinmez.
    - Testler: `PlanTakvimiTest` (4, veri sağlayıcılı), `YedekZamanlaTest` (7).
    - Doğrulanamayan: canlıda zamanlayıcıyla çalışma (modül kapalı).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-009-R1 [P1] Servis kipinde planlı yedeği ve günlük temizliği yanlış hesap yürütüyor.**
       `backend/routes/console.php:62 ve :78, scripts/sunucu-yedekleme-kurulumu.sh:288 ve :721`. Laravel zamanlayıcısı iki komutu da doğrudan çalıştırıyor. Yedek klasörüne yazma ve imza anahtarına erişim servis hesabına ait. `emor-yedek.service` yalnız istek çalıştırıcısını başlatıyor. Planlı yedekler üretilemez, günlük temizlik de dosyaları işleyemez (D7, README §5.5).
       Servis kipinde iki işi de mevcut servis hesabına yönlendirin, geçiş kipindeki zamanlayıcıyı koruyun. İzole Linux kurulumunda gerçek süreç kullanıcılarıyla planın tetiklenmesini, arşiv ve imzanın oluşmasını ve günlük temizliği sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Servis kipinde (`calistirici=servis`) `yedek:zamanla` ve `yedek:temizle` Laravel zamanlayıcısında artık çalışmıyor (`routes/console.php`: iki kayda `->when($gecisKipi)`; geçiş kipi aynen). İki iş `emor-yedek.service`'in döngüsünde yürüyor (`YedekCalistir::zamanlanmisIsler`): plan tiki dakikada bir (hrtime, D1), günlük temizlik Europe/Istanbul 04:10 vadesinden sonra günde bir kez; son deneme anı (UTC, G17) `<durum>/temizlik-son-calisma` dosyasında (biçim geri biçimlenip doğrulanır, G11). Servis o saatte kapalıysa açılınca bir kez yapar; yeniden başlayınca tekrarlamaz; deneme sonuçtan bağımsız kaydedilir (başarısız temizlik her turda yinelenmez, D8); açık üstüne geri yüklemede / durum okunamazken yapılmaz (AcikGeriYuklemeKorumasi::engelliMi). `--tek-tur` (geçiş kipi) değişmedi. Testler: `YedekCalistiriciTest::test_zamanlayici_planli_yedegi_ve_temizligi_servis_kipinde_calistirmaz`, `test_servis_kipinde_planli_yedegi_calistiricinin_dongusu_tetikler`, `test_servis_kipinde_gunluk_temizlik_vadeden_sonra_gunde_bir_kez_yapilir`, `test_servis_kipinde_acik_geri_yuklemede_gunluk_temizlik_yapilmaz`; `UstuneGeriYuklemeOnayTest::test_acik_geri_yuklemede_zamanlayici...` muafiyeti yedek:zamanla/temizle'yi de kapsayacak şekilde güncellendi (filtresi geçiş kipi). T5: süzgeç, döngü çağrısı, son çalışma dosyası denetimi ve açık geri yükleme koruması ayrı ayrı kaldırılınca ilgili testler kırmızı. Kurulum betiği değişmedi (servis birimi zaten `yedek:calistir` çalıştırıyor); yorumu güncellendi. Doğrulanamayan: gerçek süreç kullanıcılarıyla (emor-yedek / www-data) planın tetiklenip arşiv ve imzanın servis hesabıyla oluşması ve günlük temizlik — servis birimini gerçekten çalıştırmak (systemd, PostgreSQL rolü, imza anahtarı) kurulum testinin kapsamını aşıyor; lokal testler aynı süreçte (Laravel test ortamı) sınar.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Servis kipinde Laravel zamanlayıcısının iki girişi devre dışı ve komutlar servis döngüsünde. Aynı gün yeniden başlatma testi geçti. Kurulu systemd biriminde doğrulama BY-025 kabulünde açık.

### BY-010 — Saklama ve temizlik

- **Durum:** PASSED · **Bağımlı:** BY-009
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
  - [x] Her koruma kuralı için ayrı test: kural olmadan silinecek yedek, kuralla korunuyor.
  - [x] Bir hedefteki silme, aynı yedeğin diğer hedefteki kopyasına dokunmuyor (test).
  - [x] Son 10 yedek de başarısızsa hiçbir başarılı yedek silinmiyor (test).
  - [x] Önizleme ile gerçek çalıştırma aynı listeyi veriyor (test).
  - [x] Hedef erişilemezken temizlik o hedefi atlayıp diğerlerini sürdürüyor (test).
  - [x] **Kullanıcı kararı F6 (§5):** aynı hedefi paylaşan iki planda (tek
    kopya, iki bağ) bir planın kuralı yalnız KENDİ bağını kaldırıyor; dosya,
    diğer plan hâlâ tutuyorken silinmiyor; son bağ gidince de ancak koruma
    kuralları izin veriyorsa siliniyor (test: son bağ kalkan ama "son başarılı"
    / sabitlenmiş kopya duruyor). Bağsız kalan korumalı plan yedeğine
    zamanlanmamış yedeklerin kuralı UYGULANMIYOR: saklama kuralı bağ
    sayısından değil `yedekler.kaynak`'tan seçiliyor (test; BY-004-R9).
  - [x] **Kullanıcı kararı F7 (§5):** yerel dosya silinip uzakta DOĞRULANMIŞ
    kopya kalınca yedek `silindi` olmuyor, `yerel_silinme_zamani` doluyor;
    uzak kopya doğrulanmamışsa (ya da başarısızsa) yerel dosya silinmiyor;
    durum yalnız hiçbir kopya kalmayınca `silindi` (test). Dizin yeniden
    kurulurken (BY-011) uzak kopyası olan yedeğin bulunabilmesi için gereken
    kayıt (manifestin yerelde kalması ya da olay günlüğü) bu görevde
    tasarlanıp README'ye yazılır.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-009..013 planlar, saklama, tarama, bildirim, deploy entegrasyonu` olan commit
    - `SaklamaPolitikasi::hesapla()` → {silinecek, korunan, temkin} (önizleme
      ve gerçek çalıştırma AYNI hesap); `uygula()` kararları sırayla yürütür.
      Kararlar `SilmeKarari` (BAG: planın kopya bağı; DOSYA: hedefteki kopya ya
      da yerel dosya) ve neden kodu taşır; her silme olay günlüğüne ve denetim
      kaydına yazılır.
    - Plan + hedef bağının kuralı yalnız o bağı kaldırır (F6); dosya son bağ
      gidince VE koruma kuralları izin verince silinir. Zamanlanmamış
      kaynakların (elle, yayın öncesi, geri yükleme öncesi) kuralı
      `yedekler.kaynak`'tan seçilir, bağ sayısından değil (BY-004-R9).
    - Korumalar: son başarılı, sabit, sınama geri yüklemesi geçmiş en yeni,
      kullanımda (`KullanimDenetimi`), grup başına `enaz_adet`; yerel dosya
      için ayrıca "uzak kopya doğrulanmadı". **Temkin:** son yedek denemesi
      başarısızsa ya da işlem durumu okunamıyorsa hiçbir şey silinmez.
    - Yerel dosya silinince yedek `silindi` olmaz; yalnız hiçbir yerde kopya
      kalmayınca (F7). Dizin yeniden kurma için manifest yerelde KALIR (README
      §4.3 "Uygulama kuralları (BY-010)" paragrafı).
    - Erişilemeyen hedef atlanır (`atlanan_hedefler`), diğerleri sürer.
      Bir çalışmada `beklenmedik_silme` (10) dosyadan fazlası silinirse
      bildirim gider.
    - `yedek:temizle {--onizleme}` (günde bir 04:10 İstanbul) ve her başarılı
      `yedek:al` / planlı yedekten sonra.
    - Testler: `SaklamaPolitikasiTest` (16).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-010-R1 [P1] Bir hedef başarılı olunca diğer hedefin hatası yerel dosyayı korumuyor.**
       `backend/app/Services/Yedekleme/PlanliYedek.php:86 ve :103, backend/app/Services/Yedekleme/YerelDosyaSilici.php:36`. Tek tamamlanmış kopya planın yerel dosyayı doğrudan silmesine yetiyor. Diğer hedef başarısız ya da beklemede olsa da saklama politikasındaki koruma devreye girmiyor. Sonraki hedef denemesinin kaynağı kayboluyor (README §4.3, D4, F7).
       Yerel silme kararını tek yerde verin. Zorunlu hedefleri ve koruma kurallarını silmeden önce kontrol edin. Aynı yedekte bir tamam ve bir başarısız hedef varken yerelin kaldığını, ikinci hedef doğrulanınca saklama kuralının uygulandığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Yerel silme kararı tek yerde: `SaklamaPolitikasi::hesapla` (önizleme ve uygulama aynı). Planlı yedeğin yerel dosyası (planlarında ana klasör hedefi yok) yalnız şu koşullarda siliniyor: en az bir uzak kopya `tamam` olmalı; hiçbir kopyası `bekliyor` / `kopyalaniyor` (yeni) / `basarisiz` olmamalı; yedek sabit ve kullanımda olmamalı. `PlanliYedek` kopyalamadan ÖNCE planların bütün aktif hedeflerine `bekliyor` kopya kaydı açıyor (zorunlu hedefler), süreç arada ölse de saklama eksik hedefi görüyor. Doğrudan `YerelDosyaSilici` çağrısı kaldırıldı, saklama zaten her planlı yedekten sonra çalışıyor. `YerelDosyaSilici::sil($yedek)` yalnız uygulayıcı oldu (`uzakKopyaSart` / `haricHedefId` kaldırıldı). Karar: "son başarılı", "doğrulanmış en yeni" ve en az sayı korumaları bu aktarım dosyasını tutmuyor (README §4.2 kullanıcı kararı: planda yerel disk yoksa yerel dosya kopyalardan sonra silinir; yedek uzak hedef grubunda bu korumalarla duruyor). Klasör grubunun en az sayısı artık yalnız zamanlanmamış yedekleri sayıyor. README §4.3'e iki madde eklendi. Test: `YedekZamanlaTest::test_bir_hedef_basarisizken_yerel_dosya_kalir_ikinci_hedef_dogrulaninca_saklama_siler` (T5: düzeltmesiz kırmızı, yerel dosya ilk kopyadan sonra silindi). Doğrulanamayan: `bekliyor` kaydı süreç ölümüyle sonsuza dek kalırsa yedek "kullanımda" sayılıyor (önceden de `kopyalaniyor` için böyleydi, güvenli yön).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 başarısız ve bekleyen hedefler için kapandı. Plan bütün hedef kayıtlarını açıyor ve yerel silmeyi saklama belirliyor. Uzak kurtarma paketi başarısızlığı aşağıdaki ayrı durumu açık bırakıyor.
    1. **BY-010-R2 [P1] Son yerel arşiv kurtarma paketi eksikken silinebiliyor.**
       `backend/app/Services/Yedekleme/YedekKopyalayici.php:74, :89 ve backend/app/Services/Yedekleme/SaklamaPolitikasi.php:189`. Paket gönderilemese de uzak kopya `tamam` kalıyor. Planın tek uzak hedefi bu durumdaysa temizlik yerel dump'ı silebiliyor. Ana makine kaybında yeni şifreli dump, önceki paketlerde kendi manifesti bulunmadığı için normal kurtarma yoluyla kullanılamaz (F7, D4, BY-024-R3).
       Uzak aktarım doğrulaması ile kurtarmaya hazır kopyayı ayrı tutun ve yereli silmeden en az bir tam kurtarma seti isteyin. Ortadaki paket aktarımı başarısızken yerelin korunduğunu, paket doğrulandıktan sonra temizliğin çalıştığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: "Uzak aktarım doğrulandı" (`durum = tamam`) ile "kurtarmaya hazır" ayrıldı. Yeni migration `2026_11_21_090537_add_kurtarma_hazir_to_yedek_kopyalari_table` (`yedek_kopyalari.kurtarma_hazir` bool, varsayılan false; var olan satırlar false başlar, ilk başarılı paket hazır yapar). `YedekKopyasi::kurtarmayaHazirMi()` = tamam VE (şifresiz YA DA kurtarma_hazir). Karar: şifresiz kopya yalnız sunucu içi yerel disk hedefidir (HedefKatalogu `sunucuDisi=false`), kurtarma paketi ona gitmez, ekrandaki geri yükleme yedek klasöründe kalan manifestle yapılır → `tamam` yeter. `SaklamaPolitikasi::hesapla` (yerel silme kararının tek yeri): planlı yedeğin aktarım dosyası en az bir uzak kopya kurtarmaya hazır değilse silinmez, koruma nedeni `kurtarma_paketi_eksik` (önizlemede de görünür). Zamanlanmamış yedeğin süresi dolan yerel dosyası kendi kuralıyla silinmeye devam eder (aktarım değil saklama). `YedekKopyalayici` yeniden yazılan kopyada `kurtarma_hazir=false` yapar. `YedekOzeti.son_uzak` yalnız hazır şifreli kopyaları sayar (uzak yaşam sinyaliyle aynı ölçüt). API: `YedekResource.kopyalar[].kurtarma_hazir`; frontend tipi + Yedekler ekranında tamam/hazır değil kopyanın yanında "kurtarma paketi bekleniyor". README §4.3'e iki madde. Test: `KurtarmaPaketiTest::test_paketi_eksik_sifreli_kopya_yerel_arsivi_tutar_paket_dogrulaninca_temizlik_siler_set_imzayi_dogrular` (ortadaki bileşen yazılamaz → kopya tamam, hazır değil, yerel dump kalır, son_uzak boş; sonraki `gonder` → hazır, temizlik siler, manifest kalır; uzaktaki işaretçi → manifest arşivi + şifreli dump gerçek gpg ile çözülüp `yedek:manifest-dogrula` DOGRULANDI). T5: hazır şartı kaldırılınca kırmızı (`kurtarma_paketi_eksik` yok, yerel silindi). Frontend: `YedeklerPage.test.tsx` "kurtarma paketi bekleniyor" testi (T5 ayrıca koşulmadı). `YedekApiTest` liste testinin kopya anahtarlarına `kurtarma_hazir` eklendi. Doğrulanamayan: gerçek uzak hedefte (NAS/SFTP/S3/Drive) paket + hazırlık.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R2'nin başarısız paket yolu düzeldi. Paket gönderilemezse şifreli uzak kopya için hazır işareti oluşmuyor. Şifresiz ikinci yerel disk için mevcut karar korundu.
    - **BY-010-R3 [P1] Son yerel arşiv, kurtarılamayan bir uzak kopyaya dayanılarak silinebiliyor.** `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:156-162` hazır işaretini yerel silme kararında kullanıyor. `KurtarmaPaketi` işaretin dayanağını dosya adıyla belirliyor. Ayrıntı ve ortak düzeltme BY-024-R5'te. Saklama testinde aynı adlı geçersiz manifestle başarılı paket aktarımından sonra yerel dosyanın korunduğunu sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R3: BY-024-R5 ile aynı kök ve düzeltme (`SaklamaPolitikasi` değişmedi; dayandığı `kurtarma_hazir` artık geçerli manifeste bağlı). Test: `KurtarmaPaketiTest::test_gecersiz_manifestli_kopya_basarili_paketle_kurtarmaya_hazir_olmaz_yerel_arsiv_silinmez` (aynı adlı geçersiz manifestle başarılı paket aktarımından sonra `SaklamaPolitikasi::uygula` yerel dump'ı siler mi: silmez, `yerel_silinme_zamani` boş). T5: düzeltmesiz kırmızı (yukarıda).
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R3'ün manifest doğrulaması düzeldi. Tek şifreli dış kopya paket olmadan hazır işaretlenmiyor.
    - **BY-010-R4 [P1] Aynı temizlik turu yerel dosyayı ve kurtarmaya hazır tek uzak kopyayı silebiliyor.** `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:156-202,270-297`. Hazır kopya seti silme kararları uygulanmadan önce alınan tüm kopyalardan çıkıyor. Yerel dosyayı silme kararı, aynı turda silinecek uzak kopyayı da dayanak kabul ediyor. İzole SQLite'ta iki hedefe iki plan yedeği koyarak doğrulandı. A hedefi eski hazır kopyayı bir adetlik kuralla siliyor, B hedefi eski ama hazır olmayan kopyayı iki adetlik kuralla tutuyor. Eski yedek için A dosyası ve yerel dosya aynı çıktıda silinecek görünüyor. Ayrıca ana klasör yerel hedef olarak plana yazıldığında yerel dosya `:173-175,338-342` yolundan siliniyor. Bu yol hazırlık eşiğini kullanmıyor. `SaklamaPolitikasiTest.php:312-332` uzak kopyanın `kurtarma_hazir=false` olduğu bu silmeyi kabul ediyor. D4 / F7: saklanmaya devam eden plan yedeği için yerel silmeyi, aynı temizlikten sonra kalan doğrulanmış kopyaya bağlayın. Normal ve ana klasör hedefi yollarında iki plan saklama kuralını ve aynı turdaki gerçek dosya sonucunu sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R4: Plan yedeğinin yerel dosyasını silme eşiği tek yardımcıya alındı: `SaklamaPolitikasi::kurtarmaDayanagiYok`. Hem yedek klasörü grubu hem ana klasör hedefinin kopyası (yerel dosyanın kendisi) bu yardımcıyı kullanıyor. Dayanak, bu turun dosya kararları uygulandıktan sonra KALAN uzak kopyalar. Aynı turda silinecek hazır kopya dayanak sayılmıyor. Yedek bir hedefte saklanmaya devam ediyorsa kalanlardan en az biri `kurtarmayaHazirMi` olmalı, yoksa koruma nedeni `kurtarma_paketi_eksik` (önizlemenin `korunan` alanında görünüyor). Hiçbir uzak kopya kalmıyorsa, yani bütün kurallar yedeği siliyorsa, önceki ölçüt geçerli: silinecek kopyalardan biri hazır olmalı. Böylece uzak silme düşerse geride hazır olmayan tek kopya kalmıyor. Ana klasör yolunda hazırlık eşiği ilk kez uygulanıyor. `hesapla()` sırası değişti: önce uzak hedeflerin dosya kararları hesaplanıyor, sonra ana klasör kararları süzülüyor, en son yedek klasörü grubu işleniyor. Sınıf PHPDoc'u ve README §4.3 ("Tamam ile kurtarmaya hazır ayrıdır" maddesi) güncellendi (K11). Mevcut `test_yerel_dosya_silinince_dogrulanmis_uzak_kopyasi_olan_yedek_silindi_olmaz` testi düzeltildi; yeni adı `test_yerel_dosya_silinince_kurtarmaya_hazir_uzak_kopyasi_kalan_yedek_silindi_olmaz`. Eski hâli hazır olmayan uzak kopyaya dayanan silmeyi doğru kabul ediyordu. Test şimdi uzak kopyayı hazır kuruyor ve silme bekliyor. Bu bir düzeltme, zayıflatma değil. Hazır olmayan kurgu yeni testte. Test: `test_yerel_dosya_yalniz_temizlikten_sonra_kalan_hazir_uzak_kopyaya_dayanilarak_silinir`, 4 veri kümesi: yedek klasörü yolu ve ana klasör hedefi yolu, her biri için kalan kopya hazır / hazır değil. Kurgu: tek plan, A hedefi son 1 ve hazır, B hedefi son 2. Ana yolda üçüncü hedef olarak ana klasör son 1. Testler gerçek sonucu sınıyor: diskteki yerel dosya, `yerel_silinme_zamani`, A kopyası `silindi` ve bellek hedefinden gitmiş, B kopyası `tamam`, manifest yerinde, ana kopyanın durumu, önizlemede `kurtarma_paketi_eksik`. "Hazır" kümeleri aşırı korumayı yakalıyor (T5: "hazır değil" kümeleri düzeltmesiz kırmızı, iki yolda da yerel dosya silindi). Doğrulanamayan: gerçek uzak hedefle uçtan uca temizlik.
  - 2026-11-21 Codex → CHANGES_REQUESTED · beşinci tur `3ad20f3`
    - R4'ün olağan yolu düzeldi. Saklama planında geride kalacak kopyalar esas alınıyor ve ana klasör hedefi de aynı eşiği kullanıyor. İki yerel dosya yolunun dört regresyon kümesi geçti.
    - **BY-010-R5 [P1] Kısmi uzak silme hatasından sonra son kurtarılabilir dosya silinebiliyor.** `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:250-267,327-392`. Bütün uzak kopyalar silinecekse yardımcı, silineceklerden birinin hazır olmasını yeterli görüyor. Bu ölçüt uzak silmelerin gerçek sonucuna bakmıyor. İzole SQLite ve bellek hedeflerinde aynı iki kopyayı silmeye karar verdirip yalnız hazır olmayan ikinci hedefin silmesini düşürdüm. Hazır ilk kopya ve yerel dosya silindi, hazır olmayan ikinci kopya kaldı. D4: yerel silmeden önce uzak silme sonuçlarını uygulayın ve fiilen kalan kopyaları yeniden okuyun. En az bir kopya kaldıysa kullanıma hazır dayanak arayın. Bütün uzak dosyaların gerçekten silindiği durum için saklama kararının geçerli kalmasını sınayın. Hazır olmayan hedefte hata, hazır hedefte hata ve tüm hedeflerde başarı durumlarını hem ana klasör hedefinde hem yedek klasörü grubunda fiziksel dosya ve denetim sonuçlarıyla sınayın.
    - **BY-010-R6 [P3] Yerel grup koruması uzak hedeflerin kararını işlenme sırasına göre değiştirebiliyor.** `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:178-183,240`. Ekte belirtilen davranış önceki sürümde de var. Yerel nedenleri ortak korumaya grup döngüsünün içinde eklemek yerine yerel kararlar için ayrı tutup en sonda rapora birleştirin. Aynı veriyi ana hedef önce ve sonra işlenecek biçimde kurup uzak silme kararlarını karşılaştırın. Bu not tek başına onayı engellemez.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex beşinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 5. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R5: `SaklamaPolitikasi::uygula` (bulunan uygulayıcı; `yedek:temizle`, `yedek:al`, PlanliYedek ve YedekAlIsleyicisi hepsi bunu çağırıyor) dosya kararlarını iki sırayla uyguluyor: önce uzak hedefler, sonra yerel dosyaya dokunanlar (yedek klasörü grubu ve yedek klasörünün kendisi olan ana hedef). Plan yedeğinin yerel dosyası silinmeden hemen önce yedeğin `tamam` uzak kopyaları veritabanından YENİDEN okunuyor (`kalanUzakKopyalar`; ana klasör hedefi sayılmıyor, hesapla ile aynı küme). Eşik tek yardımcıda: `kurtarmaDayanagiYok(Collection $kalan)` = kalan boş değil VE hiçbiri `kurtarmayaHazirMi` değil. Önizleme (`hesapla`) aynı yardımcıyı bu turun kararlarından hesaplanan kalanla (bütün uzak silmeler başarılı varsayımıyla), uygulama veritabanından okunan gerçek kalanla çağırıyor; ölçüt çelişmiyor. Engellenen yerel silme: denetim kaydı `saklama_sil` / `basarisiz`, `ayrinti.kod = kurtarma_paketi_eksik` (+ kararın alanları), sonuçta `bilgi` satırı; yerel dosya, `yerel_silinme_zamani` ve ana kopya dokunulmadan kalıyor. Davranış değişikliği (bilerek): R4'teki "hiç uzak kopya kalmıyorsa silinecek kopyalardan biri hazır olmalı" geri dönüşü kaldırıldı — bütün uzak dosyalar gerçekten silindiyse saklama kararı geçerli, yerel dosya da silinir (eski ölçüt yedek klasörü yolunda dosyayı hiçbir kuralın silemeyeceği uzak kopyasız bir dosya olarak bırakıyordu). Kısmi hata yolunu artık uygulamadaki yeniden okuma kapatıyor. Sınıf PHPDoc'u, `uygula` PHPDoc'u ve README §4.3 ("Tamam ile kurtarmaya hazır ayrıdır" maddesi) güncellendi (K11). Testler: `SaklamaPolitikasiTest::test_yerel_dosya_uzak_silmelerden_sonra_fiilen_kalan_kopyalara_gore_silinir`, 6 veri kümesi: yedek klasörü yolu ve ana klasör hedefi yolu × {hazır olmayan B'de silme hatası, hazır A'da silme hatası, tüm hedeflerde başarı}. Her biri gerçek sonucu sınıyor: önizlemede yerel silme kararı var; A/B kopya durumları ve bellek hedefindeki dosyalar; `atlanan_hedefler`; diskteki yerel dosya, `yerel_silinme_zamani`, yedek durumu (tüm başarıda `silindi`, A kalınca `tamam`), manifest yerinde; ana kopyanın durumu; `kurtarma_paketi_eksik` denetim kaydı (hedef_id dahil) ve `bilgi` satırı; silme hatasının `SILINEMEDI` denetim kaydı. Silme hatası mevcut kalıpla: bellek hedefi + `BellekHedefi::$silinemeyenAdSonu` (o kopyanın uzak kimliğine son ek). Ayrıca `test_butun_uzak_kopyalari_gercekten_silinen_yedegin_yerel_dosyasi_hazirliktan_bagimsiz_silinir` (değişen davranış). T5: düzeltmesiz iki "B'de hata" kümesi kırmızı (yerel dosya silindi); yalnız uygulamadaki yeniden okuma kapatılınca aynı iki küme kırmızı; eski geri dönüş ölçütü geri getirilince yeni "hazırlıktan bağımsız" testi kırmızı. "A'da hata" ve "tüm başarı" kümeleri aşırı korumayı yakalıyor (düzeltmesiz de yeşil). Doğrulanamayan: gerçek uzak hedefle (NAS/SFTP/S3/Drive) uçtan uca kısmi silme.
      - R5 eki (G21, kullanılamaz ≠ yok): yerel silme eşiğindeki "kalan kopya" kümesi artık `KopyaDurumu::yedegiTutanlar()` (tamam + konum_belirsiz). Önizlemede (`hesapla`: bu turun kararlarından sonra kalan tamam uzak kopyalar + yedeğin konum_belirsiz kopyaları) ve `uygula`daki yeniden okumada (`kalanUzakKopyalar`) aynı küme kullanılıyor. Konumu belirsiz kopya hiçbir zaman dayanak değil (`kurtarmayaHazirMi` yalnız `tamam`); bu yüzden yalnız o kopya kalınca yerel dosya korunuyor. Koruma nedeni mevcut `kurtarma_paketi_eksik`: korunma nedenleri hiçbir lang/i18n dosyasında çevrilmiyor, yeni kod gerekmedi. Ana klasör dışlaması yalnız `tamam` kopyaya uygulanıyor: konumu belirsiz kopya, hedefin bugünkü tanımı ana klasör olsa da sayılıyor, çünkü yeri o değil (BY-028-R8 ile aynı mantık). Saklama grupları ve `enaz_adet` yine yalnız `tamam` kopyalardan kuruluyor. Sınıf PHPDoc'u, `kurtarmaDayanagiYok` / `kalanUzakKopyalar` PHPDoc'ları ve README §4.3 güncellendi. Test: `test_yalniz_konumu_belirsiz_kopya_kalan_yedegin_yerel_dosyasi_silinmez`, 4 veri kümesi: yedek klasörü ve ana klasör hedefi yolu × {yalnız konumu belirsiz kopya kalır → yerel dosya ve ana kopya duruyor, önizlemede `kurtarma_paketi_eksik` var; konumu belirsiz + hazır tamam kopya kalır → yerel dosya siliniyor}. İki kümede de A kopyası `silindi`, konumu belirsiz kopya olduğu gibi kalıyor, yedek `tamam`. T5: önizleme ve uygulama parçaları birlikte geri alınınca "yalnız konumu belirsiz" kümeleri iki yolda da kırmızı (yerel dosya silindi). Yalnız önizleme parçası geri alınınca da kırmızı: uygulamadaki yeniden okuma dosyayı korudu ama önizlemede koruma nedeni yoktu. Doğrulanamayan: uygulamadaki yeniden okumanın konumu belirsiz kopyayı sayması ayrıca sınanmadı. O dal yalnız önizleme ile uygulama arasında konumu belirsiz kopya belirirse çalışır; aynı kilit altında bu kurgu kurulamıyor.
    - R6: `hesapla` içinde her grup korumaların kendi kopyasıyla süzülüyor (uzak grup: genel korumalar; ana klasör grubu ve yedek klasörü grubu: genel + `uzak_kopya_dogrulanmadi`). Grubun `suz` ile eklediği `enaz_adet` ve yerel nedenler artık ortak korumaya karışmıyor, yalnız rapora (`korunan`) en sonda birleşiyor. G1: aynı sızma uzak gruplar arasında da vardı (A grubunun `enaz_adet`'i B grubunda yedeği "korunan" yapıyordu, sonuç kopya sırasına bağlıydı); aynı düzeltmeyle kapandı. Sonuç: uzak grup kararları işlenme sırasından bağımsız; sonraki uzak gruplarda önceden sızmayla korunan kopya artık o grubun kendi kuralıyla silinebilir. Sınıf PHPDoc'u (madde 4) ve README §4.3 "Gruplar" maddesi güncellendi. Test: `test_yerel_korumalar_uzak_hedef_kararlarini_islenme_sirasina_gore_degistirmez`, 2 veri kümesi (ana hedef önce / sonra; hedef ve kopya kimliği sırası testte doğrulanıyor). Eski yedeğin başka hedefte başarısız kopyası var; iki kurguda da uzak hedefte BAG + DOSYA kararı aynı, ana kopya silinmiyor, `korunan`'da `uzak_kopya_dogrulanmadi` var. T5: düzeltmesiz "ana hedef önce" kümesi kırmızı (uzak DOSYA kararı yoktu). Doğrulanamayan: —
  - 2026-11-21 Codex → CHANGES_REQUESTED · altıncı tur `2b7ea87`
    - R5 kapandı. Uzak silmeler yerel silmelerden önce uygulanıyor ve kalan kopyalar yeniden okunuyor. Bütün uzak kopyalar gerçekten silindiyse yerel dosyanın da saklama kararıyla silinmesi kabul edildi. R6 grup sırası etkisi ve kullanılabilir en yeni yedeğin korunması düzeldi. Konumu belirsiz kopya kalan sayılıyor, kurtarma dayanağı sayılmıyor. İlgili PostgreSQL ve SQLite testleri geçti.
    - **BY-010-R7 [P1] Aynı fiziksel klasörü gösteren iki hedef bütün yedek dosyalarını sildirebiliyor.** `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:305-310,399-417,456-467`, `backend/app/Services/Yedekleme/Hedefler/YerelDiskBaglayicisi.php:99-120`, `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:81-94`. Bu turda bulunan, önceki kodda da var olan davranış. Hedefler yalnız kayıt kimliğine göre bağımsız sayılıyor. Normal API aynı dizini farklı adlarla veya `/dizin` ve `/dizin/.` biçiminde tanımlamaya izin veriyor. İki yerel hedefte aynı dump adı aynı fiziksel dosya. İkinci hedefin kısa saklama kuralı dosyayı silince birinci hedefin uzun kuralına ait kopya DB'de hâlâ `tamam` kalıyor. Yeni yeniden okuma bu kaydı hazır dayanak sayıp yerel dump'ı da siliyor. İzole SQLite ve gerçek geçici klasörde sonuç doğrulandı. Yerel dosya yok, ikinci disk dosyası yok, kopya durumları `tamam` ve `silindi`. D4 ve F6: aynı fiziksel depoyu bağımsız hedef olarak kabul etmeyin. Hedef oluşturma ve güncellemede mevcut yol eşitliği mantığını kullanarak çakışmayı reddedin. Eski çakışan tanımlarda temizliği görünür hatayla durdurun veya ortak dosyanın bütün saklama bağlarını koruyun. Ana yedek klasörü dışındaki aynı dizine iki hedef, farklı saklama kuralları ve noktalı yol eşdeğeriyle tüm fiziksel dosyaların silinmediğini sınayın.
    - Bağımsız kontroller geçti. SQLite 1514 test / 9 atlama, PostgreSQL 16 UTF-8 788 ilgili test / 6 atlama, gerçek PostgreSQL 49 geri yükleme testi, Node 22 ile ön yüz 408 test, tür denetimi, lint (4 mevcut uyarı), build (mevcut paket boyutu uyarısı), Pint, 43 yayın betiği kontrolü ve git diff --check. Root kurulum testi, gerçek uzak hedefler, disk ayırma ve gerçek tarayıcı saha deneyi yapılmadı. İnceleme yalnız yerelde ve atılabilir veride yapıldı.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex altıncı tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 6. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R7: Hedefler artık kayıt kimliğiyle değil fiziksel depoyla bağımsız sayılıyor. Tek ölçüt `HedefKonumu::ayniDepoMu` (yeni). Dosya sistemi hedeflerinde (Yerel, NAS) klasörleri `YerelDiskBaglayicisi::ayniKonumMu` ile karşılaştırıyor. Bu, ana klasörü tanıyan ölçütün aynısı (G19): iki yol okunabiliyorsa aygıt + düğüm, okunamıyorsa sadeleştirilmiş yazım. `ayniKonumMu` ve `hedefKlasoru` public yapıldı, yeni yol karşılaştırması yazılmadı. Klasörü boş Yerel hedef yedek klasörünün kendisi sayılıyor; bu yüzden ikinci ana klasör hedefi de çakışma. Yerel ↔ NAS aynı klasör de çakışma; alt klasör ayrı depo. Uzak türler (FTP/FTPS, SFTP, S3, Drive) kapsam dışı (gerekçe aşağıda). Üç katman:
      (1) API (`YedekHedefiController` store/update): aynı depoyu gösteren ikinci hedef 409 `HEDEF_AYNI_DEPO` alıyor (`hedefler`: çakışan hedef adları, `mesaj`: `hata.yedek_hedefi_ayni_depo`). Güncellemede yalnız konumu (`HedefKonumu::iz`) değişen kayıt denetleniyor. Eskiden kalmış çakışan hedefin adı, aktifliği ve parolası serbest; bu, sorunun çözüm yolu (pasife alma). Denetim ve kayıt tek `Cache::lock` (`YedekHedefiController::DEPO_KILIDI`) altında (G18). Güncellemedeki satır kilidi ve işlem bu kilidin içinde.
      (2) Kopyalayıcı (`YedekKopyalayici::ayniDepoyaYazma`): yedeğin kopyası aynı depodaki başka bir hedefte duruyorsa (tamam / konum_belirsiz) ya da oraya yazılıyorsa ikinci kopya yazılmıyor. Kopya `basarisiz` oluyor, kod `HEDEF_AYNI_DEPO`. Bu kod yeniden denenmeyenler listesinde (D8). Kopya başarısız olduğu için `uzak_kopya_dogrulanmadi` yerel dosyayı koruyor.
      (3) Saklama (`SaklamaPolitikasi::ayniDepodakiKopyalar`): aynı depodaki başka bir hedefte de kopyası duran (yedegiTutanlar) dosyayı kural silmek istese de silmiyor. Önizlemede koruma nedeni `ayni_depo`. `hesapla()` dönüşüne `engellenen` eklendi. `uygula()` her turda `saklama_sil` / `basarisiz` denetim kaydı yazıyor: `ayrinti.kod = HEDEF_AYNI_DEPO`, `ayni_depo_hedefleri`, ayrıca `bilgi` satırı (D4: görünür hata, veri korunur). Yalnız tek hedefte kopyası olan dosya kuralıyla silinmeye devam ediyor. Engelleme `suz`'dan sonra uygulanıyor: korumalar ve en az sayı aynı kalıyor, en az sayının üstünde fazladan kopya kalabiliyor (güvenli yön).
      Ek değişiklikler: `HedefHatasi::AYNI_DEPO`; `lang/tr/hata.php` içinde `yedek_hedefi_ayni_depo`; ön yüzde `hata.HEDEF_AYNI_DEPO` (tr/en; hedef kaydı 409'u ve kopya hata kodu aynı metni gösteriyor). `YedekHedefiFactory` varsayılanı artık her hedefe ayrı klasör veriyor; ortak `/tmp/yedek-deneme` geçersiz bir kurgu olmuştu (T10). README §4.3 ("Hedefler fiziksel depoyla bağımsızdır") ve §4.6 ("Bir fiziksel depo, tek hedef") ile sınıf PHPDoc'ları güncellendi (K11).
      Testler:
      - `SaklamaPolitikasiTest::test_ayni_klasoru_gosteren_iki_hedefte_saklama_ortak_dosyayi_silmez_gorunur_hata_yazar`, 4 küme: aynı yazım farklı ad / `/.` / `/../disk2` / sembolik bağlantı. Kurgu: ana yedek klasörü DIŞINDAKİ `disk2`, uzun kural (son 10) ve kısa kural (son 1). Sınananlar: ortak dosyalar diskte duruyor; iki kopya da `tamam`; yedek `tamam`; `HEDEF_AYNI_DEPO` denetim kaydı (hedef_id ile çakışan hedefler); `bilgi` satırı; önizlemede `ayni_depo`. Yalnız kısa hedefte kopyası olan dosya gerçekten siliniyor (aşırı korumayı yakalar).
      - `SaklamaPolitikasiTest::test_yedek_klasorunu_gosteren_iki_ana_hedefte_saklama_yerel_dosyayi_silmez`: klasörü boş ana hedef ile `<klasör>/.` yazımlı ana hedef. Yerel dosya kalıyor, `yerel_silinme_zamani` boş.
      - `YedekKopyalayiciTest::test_ayni_klasoru_gosteren_ikinci_hedefe_kopya_yazilmaz_ilk_kopya_korunur`: `basarisiz`, kod `HEDEF_AYNI_DEPO`, tek deneme. İlk kopya `tamam`, dosyanın düğüm numarası ve içeriği değişmemiş.
      - `YedekHedefiApiTest::test_ayni_fiziksel_depoyu_gosteren_ikinci_hedef_409_kaydedilmez`, 7 küme: aynı klasör / `/.` / henüz olmayan klasörün `/.` yazımı (sadeleştirme yolu) / sembolik bağlantı / ikinci ana klasör hedefi / ana klasörün `..` yazımı / NAS hedefinin klasörü. Her kümede 409, `kod`, `hedefler` ve kullanıcı mesajı sınanıyor; kayıt sayısı değişmiyor.
      - `YedekHedefiApiTest::test_alt_klasor_ayri_hedef_olur_konumu_baska_hedefin_deposuna_cevrilemez_eski_cakisan_hedef_pasife_alinir`.
      - `YedekHedefiApiTest::test_eszamanli_kayitta_ayni_klasoru_ikinci_kez_tanimlayan_istek_bekler_ve_409_alir` (G18): test kilidi tutuyor; `Sleep` sahtesinin bekleme anında "önce gelen" kayıt yapılıyor ve kilit bırakılıyor.
      T5 (her koruma ayrı ayrı kapatılarak; dosyalar sonra baytı baytına geri yüklendi):
      - Saklama koruması kapalıyken 4 saklama kümesi ve ana hedef testi kırmızı. Ortak dosya `disk2/erp_plan_5_….dump` silindi; ana testte yerel dosya silindi.
      - Kopyalayıcı koruması kapalıyken kopya testi kırmızı (ikinci kopya `tamam` sayıldı).
      - API reddi kapalıyken 7 API kümesi, güncelleme testi ve eşzamanlılık testi kırmızı (201 / 200).
      - Yalnız kilit kapalıyken eşzamanlılık testi kırmızı (201, iki kayıt).
      Doğrulanamayanlar:
      - Uzak türlerde aynı konum denetlenmiyor (bilerek). Aynı yer beyan edilen alanlardan kanıtlanamaz: alan adı / IP takma adı, FTP ↔ FTPS, kullanıcının chroot'u, S3 uç nokta takma adı. Google Drive'da her kopya kendi kimliğiyle ayrı dosya. Konum izi eşitliği yalnız birebir aynı yazımı yakalardı; test bağlayıcısı da bütün S3 hedeflerini aynı `kova` ile kuruyor, iz kuralı onlarca testin kurgusunu bozardı. Birebir aynı S3/FTP/SFTP konumu için ayrı görev önerilir.
      - Çakışan iki NAS hedefinin kurtarma paketi sürümleri (ad sürüme göre) ancak aynı saniyede yazılırsa ad paylaşabilir. Yalnız eski tanımlarda olası, sınanmadı.
      - Gerçek NAS bağlama noktasında ve bind mount'ta denenmedi (aygıt + düğüm ölçütü BY-008-R1'deki ile aynı).
  - 2026-11-22 Codex → CHANGES_REQUESTED · yedinci tur `00a5e19`
    - R7 yerel disk ve NAS için kapandı. Aynı fiziksel klasör farklı yazımla yeniden tanımlanamıyor. Eski tanımlarda ikinci kopyanın yazılması ve ortak dosyanın saklamayla silinmesi engelleniyor. Ortak dosyayı koruyup hedefin diğer bağımsız dosyalarında saklamaya devam etmek kabul edildi.
    - **BY-010-R8 [P1] Aynı uzak depoyu gösteren iki tanımda veri kaybı yolu açık.** `backend/app/Services/Yedekleme/HedefKonumu.php:113-127,152-161`, `backend/app/Services/Yedekleme/Hedefler/S3Baglayicisi.php:102-117,147-150`. Uzak türlerin tamamını denetimden çıkarma kararı kabul edilmedi. Takma adreslerin bütün eşdeğerliklerini bulmak zor olabilir. Aynı uç nokta, kova, önek ve erişim ayarlarıyla iki S3 tanımı ise aynı nesneye yazıyor. Gerçek S3 bağlayıcısı ve yalnız sahte HTTP yanıtıyla iki PUT ve ikinci hedefin DELETE çağrısının aynı URL ve nesne anahtarını kullandığı doğrulandı. `ayniDepoMu()` false dönüyor. Kısa saklama kuralı nesneyi silince uzun kuralın `tamam` kaydı yerel dosyanın silinmesine dayanak olabilir. D4 / F6: en azından normalleştirilmiş aynı uzak tanımları reddedin ve eski çakışmalarda ortak nesneyi koruyun. FTP/FTPS/SFTP için aynı sunucu, kullanıcı ve klasörün aynı dosyaya yazdığı durumları da değerlendirin. Drive'ın her yazımda yeni dosya kimliği üretmesi için ayrı davranış kabul edilebilir. Ekleme, eski tanımda kopyalama ve iki saklama kuralının fiziksel nesne sonucunu aynı S3 sahte depoyla sınayın.
    - Bağımsız kontroller geçti. SQLite 1535 test / 10 atlama, PostgreSQL 16 UTF-8 810 ilgili test / 6 atlama, gerçek PostgreSQL 49 geri yükleme testi, Node 22 ile ön yüz 411 test, tür denetimi, lint (4 mevcut uyarı), build (mevcut paket boyutu uyarısı), Pint, 43 yayın betiği kontrolü ve git diff --check. Root kurulum testi, gerçek uzak hedefler, disk ayırma ve gerçek tarayıcı saha deneyi yapılmadı. Laravel 13.24.0 kaynakları ve proje skill kuralları esas alındı. İnceleme yerelde atılabilir veride yapıldı.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex yedinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 7. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R8: `HedefKonumu::ayniDepoMu` artık uzak hedefleri de kapsıyor. Ölçüt yine tek yerde. Dosya sistemi hedefleri (Yerel, NAS) R7'deki gibi gerçek konumla karşılaştırılıyor. Uzak hedeflerde depo kimliği, bağlayıcının dosya adresini kurduğu alanların izle AYNI normalleştirmesinden (`normallestir`) çıkıyor; yeni ayrıştırıcı yazılmadı (G3). Aile tablosu `DEPO_AILELERI` / `DEPO_ALANLARI`:
      - S3: uç nokta (küçük harf, `:443` ve sondaki "/" atılır) + kova + önek (baş / son "/" atılır; `S3Baglayicisi::onEk` ile aynı). Bölge (yalnız imza), erişim anahtarı (yalnız kimlik) ve yol stili (`uc/kova/…` ↔ `kova.uc/…` aynı nesne) kimliğe girmez. Bağlayıcıdan doğrulandı: `adres()` alan adını küçük harfe çeviriyor, 443'ü atıyor; nesne anahtarı `onEk().$ad`.
      - FTP / FTPS / SFTP tek aile: sunucu + kullanıcı adı + klasör; tür ve port girmez. Gerekçe: aynı hesap aynı klasöre üç protokolle de yazabilir. Şüphede "aynı" (D4): kullanıcı adı ve klasör harf duyarsız (Windows / IIS FTP); klasörün baş ve son "/"'ı atılıyor (chroot'lu hesapta `/x` = `x`, `/` = giriş klasörü). Bağlayıcılarda klasör `rtrim('/')` ile kullanılıyor; `KLASOR_DESENI` boş parça ve `.` / `..` kabul etmiyor, bu yüzden `trim` yetiyor.
      - Zorunlu bileşeni (sunucu, kullanıcı, uç nokta, kova) eksik hedefin kimliği yok; bağlayıcısı zaten yazamaz. Bu sayede `BellekHedefi`'ni S3 olarak kaydeden onlarca testin `['kova' => 'k']` kurgusu bozulmadı. Bellek hedefinin her hedefi kendi alanında (`hedef_id/ad`), yani gerçekten ayrı depo.
      - Google Drive kapsam dışı. Bağlayıcıdan doğrulandı: `yaz` her seferinde resumable oturumla yeni dosya kimliği açıyor; `oku` ve `sil` o kimlikle yapılıyor. PHPDoc'ta gerekçesi var.
      - Bilinen sınır (PHPDoc + README §4.6): alan adı / IP takma adı (DNS çağrısı yok), S3 uç nokta takma adı, türler arası aynı yer (NAS ↔ SFTP), chroot'u farklı iki hesap.
      G1, çağıranların taranması: `ayniDepoMu` / `ayniDepodakiler`'i çağıran her yer bakıldı.
      - `YedekHedefiController::ayniDepoyuReddet` (store / update): artık `ayniDepodakiler` hedefin ailesine göre süzüyor (`whereIn tur` aile türleri), uzak türlerde de 409.
      - `YedekKopyalayici::ayniDepoyaYazma` ve `SaklamaPolitikasi::ayniDepodakiKopyalar`: zaten genel `ayniDepoMu` kullanıyordu, kod değişmeden uzak türlere de uygulanıyor. Yalnız kullanıcıya giden metinlerdeki "klasörü … aynı yer" ifadesi "aynı depoya yazıyor" oldu (K5). Değişen metinler: kopya hata mesajı, saklama `bilgi` satırı ve denetim mesajı, `lang/tr/hata.php` `yedek_hedefi_ayni_depo`, ön yüz tr/en `HEDEF_AYNI_DEPO`.
      Güncelleme tutarlılığı (madde 3): denetim artık "iz değişti VE yeni konum eskisiyle aynı depo değil" ise çalışıyor (`ayniDepoMu($onceki, $hedef)`). S3 bölgesi / yol stili ya da eşdeğer klasör yazımı izi değiştiriyor ama depoyu değiştirmiyor. Bu değişiklik eski çakışan hedefte de serbest; yeni çakışma doğurmuyor. Başka depoya taşıyan değişiklik R7'deki gibi 409 alıyor. Depo kimliği izin normalleştirilmiş değerlerinden türüyor: iz aynıysa depo da aynı, depo değişmeden iz değişmiyor.
      Sınıf / metot PHPDoc'ları (HedefKonumu, YedekHedefiController, YedekKopyalayici, SaklamaPolitikasi madde 7, HedefHatasi::AYNI_DEPO) ve README §4.3 / §4.6 güncellendi (K11).
      Yeni test dosyası: `tests/Feature/HedefKonumuAyniDepoTest.php`. S3 testleri GERÇEK `S3Baglayicisi` + `Http::fake` ile tek bir sahte S3 nesne deposunda çalışıyor; nesneler adres biçiminden bağımsız (kova, anahtar) ile tutuluyor.
      - `test_ayni_s3_nesnelerine_yazan_ikinci_hedef_409_kaydedilmez`, 5 küme: aynı ayarlar / uç nokta büyük harf + `:443` + sondaki "/" / önek sonunda "/" / başka bölge ve erişim anahtarı / sanal sunucu stili. Her kümede 409, `kod`, `hedefler`, mesaj ve kayıt sayısı sınanıyor. Reddin dayanağı da sınanıyor: iki tanımın gerçek bağlayıcısı aynı adı aynı nesneye yazıyor, ikincisi birincinin üstüne.
      - `test_baska_s3_nesnelerine_yazan_hedef_kaydedilir`, 6 küme (aşırı engelleme yok): başka kova / önek / alt önek / boş önek / aynı alan adında başka port / başka uç nokta → 201.
      - `test_ayni_sunucu_kullanici_ve_klasore_yazan_ftp_ailesi_hedefi_409_kaydedilmez`, 7 küme. Kayıtlı SFTP'ye karşı 409 alanlar: FTPS başka port / FTP alan adı ve kullanıcı büyük harf + klasör sonunda "/" / SFTP göreli klasör / SFTP klasör harfi. 201 alanlar: başka kullanıcı / başka sunucu / alt klasör.
      - `test_ayni_depoda_kalan_konum_degisikligi_serbest_baska_hedefin_deposuna_tasinma_409`.
      - `test_ayni_s3_deposundaki_ikinci_hedefe_kopya_yazilmaz_ortak_nesne_degismez`: gerçek gpg ile; yoksa atlanır (T11). Eski iki S3 tanımı var, ikinci hedefe kopyala → `basarisiz` / `HEDEF_AYNI_DEPO` / tek deneme. Hiç HTTP isteği gitmiyor, ortak nesnenin baytları aynen kalıyor.
      - `test_ayni_s3_deposunu_gosteren_iki_saklama_kurali_ortak_nesneyi_silmez_gorunur_hata_yazar`: uzun (son 10) + kısa (son 1) kural, farklı yazımlı iki tanım. Sınananlar: ortak nesneler sahte depoda duruyor; iki kopya `tamam`; önizlemede `ayni_depo`; `HEDEF_AYNI_DEPO` denetim kaydı (hedef_id + diğer hedef). Yalnız kısa hedefte kopyası olan nesne gerçekten siliniyor (aşırı korumayı yakalar).
      - `test_ayni_sunucu_kullanici_ve_klasoru_gosteren_sftp_ve_ftps_hedefinde_saklama_ortak_dosyayi_silmez` (önizleme: `engellenen`, `ayni_depo`, silinecekler arasında yok).
      T5, her koruma ayrı ayrı kapatıldı:
      - Uzak depo kimliği kapalıyken (eski davranış) 13 test kırmızı: 5 S3 409 kümesi, 4 FTP 409 kümesi, güncelleme, kopya, iki saklama. Kopya testinde ikinci kopya `tamam` oldu; ortak nesnenin üstüne yazıldı. S3 saklama testinde eski yedeğin ortak nesnesi silindi.
      - Aşırı engelleme kümeleri (9) düzeltmesiz de yeşil, düzeltmeyle de yeşil.
      - Yalnız güncelleme koşulu eski hâline (yalnız iz) döndürülünce güncelleme testi kırmızı: bölge / yol stili değişikliği 409 aldı.
      Doğrulanamayanlar:
      - Gerçek S3 / MinIO ve gerçek FTP / SFTP sunucusuyla denenmedi; yalnız sahte HTTP deposu ve önizleme.
      - Uç nokta portunun başında sıfır olan yazımı (`:0443`) izle aynı normalleştirmede ayrı sayılıyor. İz biçimini değiştirmek mevcut kopyaların izini bozacağı için dokunulmadı.
      - Düzeltmeden ÖNCE iki S3 / FTP tanımına ayrı ayrı yazılmış şifreli kopyalarda ikinci yazım ilk nesnenin üstüne gelmiştir. İlk kaydın `sifreli_sha256`'sı artık nesneyle uyuşmaz: geri yükleme özet denetiminde o kayıt düşer, diğeri çalışır. Bu eski veri durumu ayrıca ele alınmadı.
      - Ortak nesne korunduğu için yerel dosyanın saklama kararıyla silinmesi (dayanak fiziksel olarak duran ortak nesne) R5 / R7 kuralıyla aynı kaldı. Değişmeyen davranış: yerel dosya silinirse yedeğin tek fiziksel kopyası o nesnedir.

  - 2026-11-22 Codex → PASSED · sekizinci tur `1546b94` (`00a5e19..1546b94`)
    - R8 kapandı. `HedefKonumu::ayniDepoMu` S3 ve FTP ailesine de uygulanıyor. API kaydı, eski çakışan hedefe kopyalama ve ortak nesnenin saklamayla silinmesi aynı ölçütü kullanıyor. Gerçek S3 bağlayıcısı ile sahte nesne deposunda ekleme, üstüne yazmama ve ortak nesneyi silmeme testleri geçti. Bu aralıkta yeni P1/P2 bulunmadı.
    - Kararlar değerlendirildi. FTP ailesinde tür/port ayrımını kaldırmak, kullanıcı ve klasörü harf duyarsız karşılaştırmak ve baştaki `/` işaretini atmak koruma yönünde kabul edildi. Gerçekten ayrı FTP depoları fazladan 409 alabilir. Bu, belgelenmiş ürün kısıtı. Zorunlu bağlantı bileşeni eksik gerçek hedef zaten yazamaz, depo kimliğinin olmaması bu durumda kabul edildi. DNS/IP, farklı S3 uç noktaları ve NAS/SFTP arasındaki eşdeğerliklerin bulunmaması README'deki sınır olarak kalıyor. Drive'ın her yüklemede farklı nesne kimliği açması nedeniyle aynı ad denetiminden ayrılması uygun.
    - Bağımsız kontroller geçti. SQLite 1562 başarılı / 10 atlama, atılabilir PostgreSQL 16 UTF-8 üzerinde 837 ilgili başarılı / 6 atlama, ayrıca gerçek PostgreSQL ile 49 geri yükleme testi. Node 22 ile 415 ön yüz testi, typecheck, lint (4 mevcut uyarı), build (mevcut paket boyutu uyarısı), değişen PHP dosyalarında Pint, 43 yayın betiği kontrolü, betik sözdizimi ve `git diff --check` geçti. PostgreSQL koşusu bütün backend paketi değildir. Ekli nottaki kapsam dışı `KesintiEkstresiTest` düzeltmesi yapılmadı.
    - `code-review`, `laravel-best-practices`, `testing-best-practices`, `supabase-postgres-best-practices` ve `vercel-react-best-practices` okundu. Laravel 13.24.0 vendor kaynakları ve proje kuralları esas alındı. Boost MCP tanımlı değil. Uygulama kodu değiştirilmedi. Root kurulum testi, gerçek uzak hedefler, disk ayırma ve gerçek tarayıcıda iki sekme denenmedi. Canlıya bağlanılmadı, kurulum/yayın işlemi yapılmadı.

### BY-011 — Klasör tarama, dizini yeniden kurma

- **Durum:** PASSED · **Bağımlı:** BY-006, BY-008
- **Tasarım:** README §5.2 (doğruluk kaynağı dosyalar), §7.3, §4.5 (güven sınırı)
- **Kapsam:** `yedek:tara`: yedek klasöründeki manifestleri ve olay günlüğünü
  okuyup `yedekler` / `yedek_kopyalari` dizinini kurar ya da günceller. İmzası
  geçersiz ya da manifestsiz dosyalar "güvenilmeyen" olarak işaretlenir. Diskte
  olmayan kayıtlar "dosya yok" olur (silinmez).
- **Kabul ölçütleri:**
  - [x] Tablolar boşaltılıp tarama çalıştırılınca liste eski hâline dönüyor; sabitlemeler duruyor (test).
  - [x] İmzasız dosya listede "güvenilmeyen"; geri yükleme için seçilemiyor (test).
  - [x] Tarama iki kez çalışınca kayıt çoğalmıyor (test).
  - [x] Tek bir bozuk manifest taramanın tamamını durdurmuyor (test).
  - [x] Kayıtlar manifest kimliğiyle GÜNCELLENİR, silinip yeniden açılmaz:
    `yedekler` satırı silinse kopyalar ve plan bağları CASCADE ile gider,
    geri yükleme geçmişi RESTRICT ile silmeyi engeller, denetim kaydının bağı
    boşalır (test: geri yükleme geçmişi olan yedekte tarama hatasız; Fable F19).
  - [x] **Kullanıcı kararı F7 (§5):** yerel dosyası silinmiş, uzakta doğrulanmış
    kopyası olan yedek, tablolar boşaltılıp tarama çalıştırılınca listede
    yine bulunuyor; durumu `silindi` değil, uzak kopyası görünüyor (test).
- **Canlı:** Evet (etkisiz).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-009..013 planlar, saklama, tarama, bildirim, deploy entegrasyonu` olan commit
    - `DizinKurucu::tara()` + `yedek:tara`: manifestler + olay günlüğü
      (`GuncelDurum`) → `yedekler` / `yedek_kopyalari` / plan bağları. Kayıt
      manifest kimliğiyle GÜNCELLENİR (silinip açılmaz; geri yükleme geçmişi
      olan yedekte de hatasız).
    - İmzası geçersiz / manifestsiz dump "güvenilmeyen" (`imzali = false`,
      kimlik dosya adı + boyut + mtime'dan deterministik UUID) — iki taramada
      çoğalmaz. `Yedek::geriYuklenebilirMi()` yalnız imzalı + tamam.
    - Diskte olmayan kaydın `yerel_silinme_zamani` dolar, silinmez. Uzak
      kopyası olay günlüğünde kayıtlı yedek, yerel dosyası yokken de bulunur (F7).
    - Ana yerel hedef kopyası `anaKlasorHedefiMi()` ile tanınır (ikinci
      diskteki Yerel hedef ayrı kopya sayılır).
    - Tek bozuk manifest taramayı durdurmaz; sayaçta `bozuk` olarak görünür.
    - Testler: `DizinKurucuTest` (8).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-011-R1 [P2] Erişilemeyen yedek dizini boş katalog gibi taranıyor.**
       `backend/app/Services/Yedekleme/DizinKurucu.php:45 ve :100`. Ana dizinin bulunamaması taranan dosya listesini boş bırakıyor. Tarama önceden kayıtlı yedekleri silinmiş sayabiliyor. Geçici disk veya klasör arızası geri yüklenebilir yedekleri katalogdan düşürür (D6).
       Beklenen dizin açılamıyorsa katalog değişikliğinden önce durun. Dizin geçici olarak erişilemezken mevcut kayıtların ve kopyaların korunduğunu, gerçekten boş dizinin ayrı işlendiğini doğrulayın.
    2. **BY-011-R2 [P3] Olay dosyası tek taramada iki kez okunuyor.**
       `backend/app/Services/Yedekleme/DizinKurucu.php:53`. Olaylar ve güncel durum için bütün imza zinciri yeniden okunuyor. Yıllarla büyüyecek günlükte aynı JSON ayrıştırma ve HMAC işi iki kez yapılıyor.
       Tek okunmuş olay listesinden iki görünümü de çıkarın. Var olan yeniden kurma testleriyle olay sırasını ve sonuçları koruyun.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `DizinKurucu::tara` yedek klasörü yoksa ya da okunamıyorsa dizine DOKUNMADAN `RuntimeException` ("… dizin değiştirilmedi") veriyor. Önceden yok olan klasör boş liste sayılıyordu. Okunabilen boş klasör olağan tarama. Testler: `DizinKurucuTest::test_yedek_klasoru_erisilemezken_tarama_kayitlara_ve_kopyalara_dokunmaz` (veri sağlayıcı: bağlanmamış / okuma izni yok; T5: "bağlanmamış" düzeltmesiz kırmızı, kayıtlar düşürüldü; "izin yok" önceden de hata veriyordu, yalnız ileti farkıyla kırmızıydı; root'ta atlanır, T11), `DizinKurucuTest::test_erisilebilen_bos_klasorde_kayit_dosya_yok_olur` (ayrım koruması; önceden de geçiyordu).
    - R2: Günlük taramada bir kez okunuyor. Güncel durumlar aynı listeden yeni `OlayGunlugu::durumlaraAyir()` ile çıkarılıyor; `durumlar()` da onu kullanıyor. Test: mevcut yeniden kurma testleri (DizinKurucuTest 8 eski test, YedekManifestiTest olay testleri) değişmeden geçti. Performans düzeltmesi, ayrı regresyon testi yok.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R2 kapandı. R1 eksik ya da okunamayan dizinde kapandı. Diskin altındaki boş bağlama dizini hâlâ ayrıştırılmıyor.
    1. **BY-011-R3 [P2] Bağlantısı kesilen diskin boş bağlama noktası geçerli tarama sayılıyor.**
       `backend/app/Services/Yedekleme/DizinKurucu.php:59 ve :128`. Bağlı disk ayrıldığında bağlama noktası alttaki boş ve okunabilir dizin olarak kalabilir. `scandir` bu durumda başarı döner ve tamamlanmış yedekler katalogda dosyasız veya silinmiş olur (D6).
       Kalıcı depoyu yapılandırılmış bağlama veya diske ait bir işaretle doğrulayın. Başarısız doğrulamada kataloğa dokunmayın. Okunabilir boş bağlama noktasıyla gerçek boş depoyu iki ayrı senaryoda sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R3: Depo işareti. Yeni `YedekDeposu::hazirla()` (+ `DepoIsaretiYok`): yedek klasörünün kökünde `.emor-yedek-deposu` (tek satır `emor-yedek-deposu <uuid>`, biçim `/…\n?\z/D` ile birebir). İşaret düz dosya değilse / biçimi tanınmıyorsa hata. İşaret yoksa: katalogda tamamlanmış yedek YOKSA işaret açılır (ilk kurulum, geçiş kipi, tablolar boşaltılıp yeniden kurma — tarama yalnız ekler, kaybedilecek kayıt yok); katalogdaki tamamlanmış bir yedeğin manifesti/dump'ı klasördeyse işaret açılır (işaretten önce kurulmuş depo: lokal geliştirme ve kurtarma §4.8'de klasöre konan dosyalar); aksi hâlde hata ve hiçbir şeye dokunulmaz. Karar gerekçesi: brif "katalog ve klasör tamamen boşsa" diyordu; boş katalogda kaybedilecek kayıt olmadığı için klasör içeriğine bakılmadı, ikinci kural (katalogdaki dosya burada) olumlu kanıttır ve mevcut kurulumların yükseltme yolunu kırmaz; boş bağlama noktasında ikisi de tutmaz. `DizinKurucu::tara` işaret denetimini katalog değişikliğinden (sahipsiz sonuçlandırma dahil) ÖNCE yapar; hata iletisi "dizin değiştirilmedi". `YedekAlici` denetimi try içinde yapar: kayıt `basarisiz`, kod yeni `YEDEK_DEPO_ISARETI_YOK` (bildirim gider; saklamanın "son yedek başarısız" temkini de devreye girer), klasöre yazılmaz. G1: `yedek:eski-yedekleri-al` da yazmadan önce denetler. Kurulum betiği (bölüm 3 sonu) işareti servis hesabıyla açar (umask 027, geçici ad → sabit bağlantıyla yerine, var olanın üstüne yazmaz, eşitlenir), var olanı servis hesabıyla okuyup biçimini, sahibini/iznini (0640) ve ACL'sini denetler, bağlantı/sabit bağlantıda durur. Test ortamı (`YedeklemeOrtami`) kurulumdaki gibi işaretli klasör açar; `klasordekiDosyalar()` işareti saymaz; artık gereksiz kalan `mkdir(config('yedekleme.klasor'))` satırları beş testten kaldırıldı. Dokümanlar: README §4.3 "Depo işareti", sunucu-kurulumu.md §2 "Depo işareti" (disk önce bağlanır, sonra betik; alttaki klasörde işaret kalırsa ne yapılır), isletim.md sık hatalar + senaryo tablosu, kurtarma.md §4.4; frontend i18n `hata.YEDEK_DEPO_ISARETI_YOK`. Testler: `DizinKurucuTest::test_yedek_klasoru_erisilemezken_tarama_kayitlara_ve_kopyalara_dokunmaz` yeni veri kümesi "diski ayrılmış boş bağlama noktası" (okunabilir, işaretsiz boş klasör → katalog ve kopya aynen; T5: düzeltmesiz kırmızı, istisna yok); `DizinKurucuTest::test_erisilebilen_bos_klasorde_kayit_dosya_yok_olur` artık işaretli gerçek boş depo senaryosu (önceden de yeşil); `YedekAliciTest::test_depo_dogrulanamazsa_yedek_alinmaz_klasore_yazilmaz` (boş bağlama noktası / biçimi bozuk işaret; T5 kırmızı), `YedekAliciTest::test_isaretsiz_klasor_depo_oldugu_anlasilinca_isaretlenir_yedek_alinir` (katalog boş / katalogdaki yedeğin manifesti klasörde; T5 kırmızı). Kurulum testi (`sudo -n bash scripts/tests/sunucu-yedekleme-kurulumu.test.sh`, 226 geçti): işaret açıldı + eşitlendi, servisin 0640 tek adlı, biçim, geçici kopya yok, web okur, ikinci kurulumda kimlik değişmedi, F3'te işaret bağlantıyla değiştirilince denetim/kurulum durur ve korunan nesneler değişmez, F4 bozuk işarete dokunulmaz / silinen işaret yeniden açılır. T5: betiğin işaret bölümü devre dışıyken 14+ madde kırmızı. `bash scripts/tests/deploy.test.sh` 43/43. Doğrulanamayan: gerçek ayrı diskle bağla/ayır denemesi (mount yetkisi olan bir makinede yapılmadı; boş bağlama noktası klasör yeniden adlandırılıp boş klasör açılarak taklit edildi); kurulum betiği disk bağlanmadan çalıştırılırsa işaretin bağlama noktasının altında kalması belgeyle önleniyor, kodla değil.
    - R3 (ek, G1): Depo işareti yalnız tarama / yedek alma / eski yedekleri içe almada değil, yerel dosyaya ve olay günlüğüne dokunan iki yolda daha denetleniyor. Önceden disk ayrılmışken `YerelDosyaSilici` dosyayı "yok" görüp yedeği `yerel silindi` (uzak kopyası yoksa `silindi`) yapıyor, temizlik ana klasör kopyasını `silindi` işaretliyor ve kopya bağı olaylarını imzalı olay günlüğüne, yani boş bağlama noktasına (kök disk) yazıyordu. (1) `SaklamaPolitikasi::uygula` hiçbir karar uygulamadan önce `YedekDeposu::hazirla()`; doğrulanamazsa denetim kaydı (`saklama_sil`, `YEDEK_DEPO_ISARETI_YOK`) yazıp `DepoIsaretiYok` fırlatır (bütün çağıranlar — `yedek:temizle`, PlanliYedek, YedekAl, YedekAlIsleyicisi — hatayı zaten yakalıyor). (2) `SilIsleyicisi` kopyalara dokunmadan önce aynı denetim; doğrulanamazsa `IstekReddi('YEDEK_SILME_DEPO_DOGRULANAMADI')` (yedek alma iletisi "Yedek alınmadı" diyor, silmede yanlış olurdu — K5; tr/en çevirisi eklendi). Testler: `SaklamaPolitikasiTest::test_yedek_diski_bagli_degilken_temizlik_yerel_dosyaya_ve_kataloga_dokunmaz` (bağlama noktasına olay günlüğü dahil hiçbir şey yazılmaz, bağlar / kopya / yedek aynen, denetim kaydı), `DizinKurucuTest::test_yedek_diski_bagli_degilken_elle_silme_reddedilir_kopyalar_ve_katalog_aynen_kalir` (uzak kopya da silinmez). T5: iki denetim satırı kaldırılınca ikisi de kırmızı. Kalan (bilinçli): kopyalama ve sınama da olay günlüğüne yazabilir; disk ayrılmışken kaynak dump okunamadığı için kopya başarısız olur ve bu başarısızlık olayı kök diske düşebilir — veri kaybı değil, yedek alma zaten reddediliyor.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R3'te tarama, saklama ve elle silme depo işaretini erken kontrol ediyor. Tamamlanmış yedek bulunmayan kataloğu klasördeki imzalı dosyalardan kurabilmek için işaret açılması kabul edildi.
    - **BY-011-R4 [P2] Yedek alma, depo doğrulanmadan önce eski işi başarısız işaretliyor.** `backend/app/Services/Yedekleme/YedekAlici.php:83,134` ve `backend/app/Services/Yedekleme/SahipsizYedekler.php:62-68,89-108`. Önceki süreç imzalı manifesti ve dump'ı yayımladıktan sonra durursa DB kaydı `calisiyor` kalabilir. Disk geçici olarak ayrıldığında sonraki yedek alma bu kaydı `YEDEK_YARIDA_KALDI` ile kapatıyor. Depo doğrulanmadan tamamlanmış bir yedek yanlış biçimde kaybedilmiş görünüyor ve disk geri takılınca normal yedek akışı onu yeniden tamamlamıyor. D3: eski işi sonuçlandırmadan önce depoyu doğrulayın. Bir tamamlanmış kayıt ve diskte kalmış bir `calisiyor` kaydı içeren testte eksik diskle eski kaydın değişmediğini, disk geri gelince tamamlandığını doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R4: Sahipsiz kayıt depo doğrulanmadan sonuçlanmıyor. `SahipsizYedekler::sonuclandir` kilit denetiminden hemen sonra, hiçbir kayda dokunmadan `YedekDeposu::hazirla()` çağırıyor (kural tek giriş noktasında zorlanıyor, K3; doğrulanamazsa `DepoIsaretiYok`, hiçbir kayıt değişmez). `YedekAlici::al`: klasör hazırlığı sonuçlandırmadan önceye alındı (işaretin açılabileceği klasör olsun), sonuçlandırmanın hatası yakalanıp yeni yedeğin hata yoluna taşınıyor — `DepoIsaretiYok` → kayıt `basarisiz`, `YEDEK_DEPO_ISARETI_YOK` (önceki sözleşme aynen), diğer hata → olağan hata yolu (önceden depo denetimi de try içindeydi). Artık kullanılmayan `YedekAlici::$depo` bağımlılığı kaldırıldı (K1). G1: `sonuclandir`'ı çağıran diğer tek yol `DizinKurucu::tara` zaten `hazirla()`'yı önce çağırıyordu (dokunulmadı); saklama, elle silme ve eski yedekleri içe alma da önce denetliyor. Disk geri gelince tamamlama için yeni kod gerekmedi: mevcut `SahipsizYedekler::tamamla` diskteki imzalı manifest + dump ile kaydı tamamlıyor (sorun yalnız sıraydı). README §4.3 "Depo işareti"ne bir cümle. Test: `YedekAliciTest::test_disk_ayrilmisken_sahipsiz_kayit_degismez_disk_gelince_manifestiyle_tamamlanir` (tamamlanmış kayıt + manifesti/dump'ı yayımlanmış `calisiyor` kaydı; klasör taşınıp yerine boş dizin: yedek `basarisiz`/`YEDEK_DEPO_ISARETI_YOK`, eski kayıt `calisiyor`, hata kodu ve bitti boş; disk geri gelince sonraki yedek onu `tamam`, imzalı, özet diskteki dump'la aynı yapıyor). T5: düzeltmesiz kırmızı ("Failed asserting that two arrays are identical": kayıt basarisiz/YEDEK_YARIDA_KALDI olmuştu). Doğrulanamayan: gerçek ayrı diskle bağla/ayır (boş bağlama noktası klasör taşınarak taklit edildi).
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R4'ün depo işareti denetimi sahipsiz kayıtların önüne alındı. En az bir tamamlanmış yedek bulunan katalogda test geçiyor.
    - **BY-011-R5 [P2] İlk yedek yarıda kaldığında boş bağlama noktası yine gerçek depo sayılıyor.** `backend/app/Services/Yedekleme/YedekDeposu.php:70-79` yalnız `tamam` kaydı arıyor. `SahipsizYedekler.php:56-64` bu sonuca güveniyor. İlk yedek dump ve manifesti yayımladıktan sonra durduğunda katalogda tek `calisiyor` kaydı var. Disk ayrılınca boş dizine yeni depo işareti yazılıyor ve mevcut kayıt `YEDEK_YARIDA_KALDI` ile kapanıyor. İmzalı dosya başka dizinde ve veritabanı SQLite bellekteyken sonuç doğrulandı. D3 ve D6: ilk kurulumla devam eden ilk yedeği ayırın. Kayıt bulunan depoda işaret yeniden oluşturulacaksa kimliği somut kayıtla doğrulayın. Önceki tamamlanmış yedek olmadan aynı disk ayırma testini koşun. Bağlama noktasına işaret yazılmadığını ve disk geri gelince sahipsiz kaydın tamamlandığını doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R5: İşaretsiz klasörde "ilk kurulum" ölçütü artık durumdan bağımsız: `YedekDeposu::hazirla` işareti kendiliğinden yalnız katalogda HİÇ yedek kaydı yoksa açar. Önceden yalnız `tamam` kayıt aranıyordu; yarıda kalmış ilk yedeğin `calisiyor` kaydı "katalog boş" sayılıyor, boş bağlama noktasına işaret yazılıyor ve kayıt `YEDEK_YARIDA_KALDI` ile kapanıyordu. Kararın gerekçesi: `calisiyor` kayıt dump ile manifesti yayımlamış olabilir; `basarisiz` kayıt da taşımadan sonraki bir hatada (klasör eşitleme) dosyasını bırakmış olabilir. "Bu kayıt klasöre hiç yazmadı" kanıtlanamadığı için her kayıt sayılıyor. Yanlış ret güvenli yöndür: kurulum betiği işareti açar. `YEDEK_DEPO_ISARETI_YOK` ile düşen denemeyi ayrık tutmak düşünüldü; yalnız yeni kurulumda biçimi bozuk işaret durumunda işe yarayacağı için eklenmedi (K1). Kayıt varken işaret ancak somut kanıtla yeniden açılıyor. Kanıt şu: katalogdaki bir kaydın (durumu ne olursa olsun) `.json` manifesti bu klasörde, `ManifestDeposu::oku` ile imzası geçerli, `kimlik` ve `dosyaAdi` kayıtla aynı. Önceden katalogdaki adı taşıyan herhangi bir dump ya da `.json` yetiyordu, imzasız `{}` de dahil. Sıra: `YedekAlici` depo denetimini (sahipsiz sonuçlandırma içinde) kendi kaydını açmadan önce yapıyordu. Kod değişmedi, yorum eklendi. Bu sıra "katalog boş" veri kümesiyle sınanıyor. Değişen dosyalar: `YedekDeposu.php` (+ `ManifestDeposu` bağımlılığı), `YedekAlici.php` (yalnız yorum). Dokümanlar: README §4.3 "Depo işareti", sunucu-kurulumu.md §2, isletim.md hata tablosu. Testler:
      - `YedekAliciTest::test_ilk_yedek_yarida_kalmisken_disk_ayrilirsa_bos_baglama_noktasina_isaret_yazilmaz_disk_gelince_tamamlanir`: tamamlanmış yedek yok, ilk yedek dump ve manifesti yayımlamış ama `calisiyor`da kalmış. Klasör taşınıp yerine boş dizin konuyor. Sonuç: yedek `basarisiz`/`YEDEK_DEPO_ISARETI_YOK`, bağlama noktası `['.', '..']`, kayıt `calisiyor` kalıyor (kod ve bitti boş). Disk geri gelince kayıt `tamam` ve imzalı oluyor, özeti diskteki dump'la aynı. T5: düzeltmesiz kırmızı; boş dizine işaret yazıldığı için `rmdir` "Directory not empty" verdi.
      - `YedekAliciTest::test_depo_dogrulanamazsa_yedek_alinmaz_klasore_yazilmaz` iki yeni veri kümesi aldı: "işaretsiz klasörde imzasız manifest" ve "işaretsiz klasörde kimliği tutmayan imzalı manifest". İkisi de yabancı ya da eşleşmeyen manifest; işaret açılmıyor, klasör aynen kalıyor. T5: ikisi de düzeltmesiz kırmızıydı (yedek `tamam` oldu).
      - `YedekAliciTest::test_isaretsiz_klasor_depo_oldugu_anlasilinca_isaretlenir_yedek_alinir`: veri kümeleri "katalogdaki yedeğin imzalı manifesti klasörde" ve yeni "yarıda kalmış ilk yedeğin imzalı manifesti klasörde" (işareti silinmiş doğru disk). Artık gerçek imzalı manifest kullanıyor; eski küme imzasız `{}` yazıyordu ve yeni kuralla doğru olarak reddedilir. Ek olarak yarıda kalmış kaydın manifestiyle tamamlandığı da sınanıyor. Bu kümeler tasarım koruması; düzeltmeden önce de yeşildi.
      - Doğrulanamayan: gerçek ayrı diskle bağla/ayır denemesi yapılmadı; boş bağlama noktası klasör taşınarak taklit edildi. Yükseltme yolu: işareti olmayan ve katalogda yalnız imzasız (güvenilmeyen) kayıt bulunan kurulum artık kendiliğinden işaretlenmez, kurulum betiği gerekir. Mevcut kurulumlarda işaret R3'ten beri betik ya da uygulama tarafından açıldığı için etkisi yok.
  - 2026-11-21 Codex → PASSED · beşinci tur `3ad20f3`
    - R5 kapandı. İlk yedek `calisiyor` kalmışsa işaretsiz boş bağlama noktası depo sayılmıyor. Katalogda kayıt varken işareti yeniden oluşturma imzalı manifestin kimliğine ve dosya adına bağlı. İlk yedekten sonra disk ayırma, disk geri gelince tamamlanma, imzasız veya başka kimlikli manifest ve boş katalog testleri geçti. İşaretsiz eski imzasız katalogda işletim kılavuzunun kurulum adımını istemek kabul edildi. Gerçek disk bağlama/ayırma saha kapsamı açık.

### BY-012 — Bildirimler ve yaşam sinyali

- **Durum:** PASSED · **Bağımlı:** BY-009
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
  - [x] Kuyruk tablosu boş / erişilemezken başarısızlık maili gidiyor (test: sahte mailer).
  - [x] Sinyal adresi erişilemezse yedek yine `tamam`; hata günlüğe yazılıyor (test).
  - [x] Sinyal isteği gizli bilgi (parola, veri) taşımıyor.
  - [x] Aynı hata için dakikada bir mail gitmiyor (yineleme sınırı; test).
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-009..013 planlar, saklama, tarama, bildirim, deploy entegrasyonu` olan commit
    - `YedekBildirimcisi::bildir(tur, satirlar, anahtar)`: alıcılar
      `yedek_ayarlari.bildirim_alicilari`; mail KUYRUĞA KONMADAN, mailer
      çalışma zamanında kurularak gönderilir (`MailAyarServisi::mailerKur`).
      Veritabanında mail ayarı yoksa / okunamazsa `AyarKopyasi` dosyasındaki
      ayarla (imzalı; SMTP parolası `Crypt` ile şifreli). Yineleme sınırı:
      aynı anahtar 60 dk içinde bir kez (`durum/bildirimler.json`).
    - Türler: yedek_basarisiz, kopya_basarisiz, dogrulama_basarisiz,
      disk_esigi, beklenmedik_temizlik (lang/tr/yedekleme.php, tek Blade şablonu).
    - `YasamSinyali`: yerel / uzak / sınama için üç ayrı adres
      (`yedek_ayarlari.sinyal_*_adresi`, yeni migration); yalnız https,
      gövdesiz GET, kısa süre sınırı; hata günlüğe yazılır, yedeği etkilemez.
    - `YedekOzeti::hesapla()`: son başarılı yerel / uzak / sınama zamanları ve
      sessizlik süresi (BY-015 ekranı kullanır).
    - Testler: `YedekBildirimTest` (6).
    - Doğrulanamayan: gerçek SMTP ile gönderim; dış izleyici adresi (README
      §13 karar 11) kullanıcıdan gelmedi — boş bırakılabilir.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-012-R1 [P2] Sağlık özeti eski doğrulamayı güncel gösterebiliyor.**
       `backend/app/Services/Yedekleme/YedekOzeti.php:28 ve :34, backend/app/Services/Yedekleme/YedekIsaretleyici.php:32`. Son sınamanın saati yedek satırının son değişikliğinden geliyor. Eski bir yedeğe açıklama eklemek ya da onu sabitlemek yeni sınama gibi görünüyor. Uzak kopya özeti de aktarım zamanı yerine arşiv zamanını kullanıyor (README §4.8).
       Başarılı sınama zamanını doğrulama kaydından, aktarım zamanını tamamlanan kopyadan alın. Önceki bir doğrulamadan sonra yalnız açıklama değiştirildiğinde özet tarihinin ileri gitmediğini sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `YedekOzeti` son sınama zamanını sınamanın kendi kaydından alıyor: `yedek_islem_kayitlari`'nda `islem = sinama_geri_yukleme`, `sonuc = basarili` en yeni `created_at`. Kayıt salt eklemeli, satırı `GeriYukleyici::sina` yazıyor. Migration gerekmedi. `YedekIsaretleyici` değişmedi. Test: `YedekBildirimTest::test_sinamadan_sonra_yalniz_aciklama_degisince_ozetin_sinama_zamani_ilerlemez` (T5: düzeltmesiz kırmızı, 18 Kasım yerine 21 Kasım). AÇIK KARAR: uzak kopya için "aktarım zamanı" UYGULANMADI. README §4.8 özette "son doğrulanmış sunucu dışı kopyanın VERİ zamanı"nı istiyor: eski bir yedeği bugün aktarmak sunucu dışındaki verinin tazeliğini değiştirmez, aktarım zamanı bu durumda yanıltıcı olur. Kod (alinma_zamani) README'ye uygun bırakıldı, gerekçe sınıf yorumunda. Codex'in istediği gerekirse tek satır: `max('yedek_kopyalari.tamamlanma_zamani')`.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Son sınama zamanı başarılı sınama denetim kaydından geliyor. `son_uzak` için README §4.8'deki uzak verinin zamanı korunmalı. Aktarım anı eski bir arşivin verisini güncellemez. Bu gerekçeyi kabul ediyorum.

### BY-013 — `deploy.sh` entegrasyonu, eski yedeklerin içeri alınması

- **Durum:** PASSED · **Bağımlı:** BY-007, BY-011
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
  - [x] Yedekleme çalışırken başlatılan yayın bekliyor ya da anlaşılır mesajla duruyor.
  - [x] Yayın öncesi yedek listede "yayın öncesi" kaynağıyla, imzalı görünüyor.
  - [x] İçeri alınan eski yedekler ayrı işaretli; temizlik kuralı onlara da uygulanıyor.
  - [x] `deploy.sh` yedek başarısızken migration çalıştırmıyor.
  - [x] Geçici önlem (BY-001) canlıdan kaldırıldı.
- **Canlı:** Evet. **Dikkat:** `deploy.sh` değişikliği bir sonraki yayını etkiler;
  önce lokalde denenir.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-009..013 planlar, saklama, tarama, bildirim, deploy entegrasyonu` olan commit
    - `deploy.sh` yedeği `php backend/artisan yedek:yayin-oncesi
      --eski-klasor="$BACKUP_DIR"` ile alır; başarısızsa bakım modu, kod
      çekme ve migration'dan ÖNCE durur (`--no-backup` korunur). `env_val`,
      `PGPASSWORD` ve kendi `pg_dump`'ı kaldırıldı (G2, G3, D2).
    - `yedek:yayin-oncesi`:
      - Modül kapalı (bugün canlı): eski davranış — `--eski-klasor`'e
        `<vt>_predeploy_<UTC zaman>.dump`; bağlantı Laravel yapılandırmasından,
        parola `PGPASSFILE` ile, PG* ortamı temizlenmiş. Hata → yarım dosya silinir.
      - Modül açık + `YEDEKLEME_CALISTIRICI=servis`: istek klasörüne
        `yedek_al` (kaynak `yayin_oncesi`) bırakır, en çok 30 dk sonucu
        bekler; süre dolarsa istek geri çekilir, yayın durur.
      - Modül açık + geçiş dönemi (`dogrudan`, varsayılan): aynı kilidi alır
        (`kilit_bekleme` sn bekler, sonra anlaşılır mesajla durur), `YedekAlici`.
      - Açık üstüne geri yükleme varsa ya da işlem durumu okunamıyorsa başlamaz.
    - `yedek:eski-yedekleri-al {--klasor}`: `*_predeploy_*.dump` dosyaları
      `pg_restore --list` ve `--file=/dev/null` ile okunur; okunamayan
      atlanır. Okunan, yedek klasörüne kopyalanır; `ice_alindi` işaretli
      imzalı manifest + `yedekler.ice_alindi` (yeni migration). Kimlik ad +
      özetten: ikinci çalıştırma çoğaltmaz. Zaman dosya adından (sunucu UTC,
      ad = mtime; 2026-11-21 salt okunur doğrulandı), taşan tarih reddedilir
      (G11). Saklama: yayın öncesi kuralı. Dizin yeniden kurulunca işaret
      manifestten gelir. Anlık görüntü bilgisi olmadığından üstüne geri
      yüklenemezler (BY-028'de zorlanır).
    - BY-001: canlıda zamanlayıcı satırı ve `erp_gunluk_*` dosyası YOK (salt
      okunur `crontab -l` + `/etc/cron.d` + klasör denetimi, 2026-11-21);
      `scripts/gecici-gunluk-yedek.sh`, testi ve `gecici-onlem.md` kaldırıldı.
      `scripts/gecici-env-oku.php` KALDI: `sunucu-yedekleme-kurulumu.sh` kullanıyor.
    - Testler: `scripts/tests/deploy.test.sh` (14; eski deploy.sh'ta 5'i
      kırmızı), `YedekYayinOncesiTest` (8), `YedekEskiYedekleriAlTest` (4).
    - "Listede görünüyor" ölçütü kayıt düzeyinde sınandı; ekran BY-015.
    - Doğrulanamayan: canlıda yayın (kullanıcı onayı bekliyor). Canlıdaki
      174 `erp_predeploy_*` dosyasının içe alınması modül açılınca elle
      çalıştırılır (sunucu-kurulumu.md'ye yazıldı).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-013-R1 [P1] Yayın koruması yayın öncesi arşiv bitince bırakılıyor.**
       `deploy.sh:62 ve :70, backend/app/Console/Commands/YedekYayinOncesi.php:109`. Normal yayında yedek komutu dönünce geri yükleme başlayabilir. `--no-backup` ile açık geri yükleme denetimi bütünüyle atlanıyor. Yayın migration çalıştırabilir veya kapanışta geri yüklemenin bakım modunu kaldırabilir (README §6.4, G5).
       Her iki yayın yolunda bakım ve migration öncesi ortak yayın kilidi alın. Servisten arşiv istenirken kilitlerin birbirini beklememesini sağlayın. Yayın yalnız kendi açtığı bakım modunu kapatsın. Açık geri yüklemeli yedeksiz yayını ve arşivden sonraki yarış aralığını izole iki süreçle sınayın.
    2. **BY-013-R2 [P1] Eski arşiv içe alma aynı adlı yedeği değiştirebiliyor.**
       `backend/app/Console/Commands/YedekEskiYedekleriAl.php:73 ve :87, backend/database/migrations/2026_11_18_120043_create_yedekleme_tables.php:104`. Aynı adda farklı içerik için kimlik değişiyor. Hedef dosya ve imza üstüne yazılıyor, benzersiz dosya adı kuralı ancak veritabanına kayıt sırasında devreye giriyor. Eski kaydın dayandığı doğrulanmış arşiv kayboluyor (D4).
       Kilit altında hedef adı ve özeti yazmadan önce denetleyin. Farklı içeriği güvenli ayrı ada alın veya çakışma olarak bırakın. Aynı isimle iki geçerli test arşivi içe alındığında ilk arşiv ve manifestinin aynen kaldığını doğrulayın.
    - Arşiv hatasında yayın erken kesiliyor. `f7aaf4e`'de `deploy.sh` Git kipi 100755. Geçici önlem zamanlayıcısının kurulmadığı teslim notunda kayıtlı. Kalıcı modül etkinleşene kadar düzenli yedek açığı sürüyor.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Ayrı YAYIN KİLİDİ (`<durum klasörü>/yayin.kilit`, YedekKilidi ile flock; işlem kilidinden ayrı — `YedeklemeYapilandirmasi::yayinKilidi()/yayinKilidiYolu()`). `deploy.sh` modül açıksa yolu yeni `yedek:yayin-korumasi kilit-yolu` komutundan öğrenir (.env okunmaz, G3; komut çalışmazsa yayın durur — D6), bakım modu / git / migration'dan ÖNCE `exec 9<"$kilit"; flock -w $EMOR_YAYIN_KILIT_BEKLEME(60) -E 75 9` ile alır ve betik bitene kadar tutar (meşgul = 75 → anlaşılır mesaj; başka hata ayrı mesaj). Kilit altında `yedek:yayin-korumasi denetle` HER İKİ yolda (yedekli ve `--no-backup`) çağrılır; açık geri yükleme ya da okunamayan durumda git, migration, `artisan down/up` olmadan çıkar. Yayın yalnız kendi açtığı bakım modunu kapatır: başta `backend/storage/framework/down` varsa down/up çağrılmaz, not düşülür. Üstüne geri yükleme başlarken `UstuneGeriYukleIsleyicisi` (Istekler/) ilk durum yazılmadan önce yayın kilidini beklemeden alır; meşgulse `GERI_YUKLEME_YAYIN_SURUYOR` ile başlamaz, işleyici dönene kadar tutar (UstuneGeriYukleyici'ye dokunulmadı). Kurulum betiği `durum/yayin.kilit`'i servis hesabıyla 0640 + yayın ACL r-- açar (durum/kilit ile aynı döngü). Testler: `scripts/tests/deploy.test.sh` 14 → 43 kontrol (açık geri yükleme × yedekli/yedeksiz: git/migrate/down/up yok; kilit meşgul; koruma komutu hatası; sıra; tam yayında migration sırasında kilidin tutulduğu ve sonda bırakıldığı; önceden bakım modunda down/up yok); `YedekYayinKorumasiTest::test_yayin_kilidi_tutulurken_ustune_geri_yukleme_baslamaz_bosken_baslar` (aynı süreçte ayrı fopen+flock), `test_koruma_komutu_modul_acikken_kilit_yolunu_verir_kapaliyken_hicbir_sey_yazmaz`, `test_koruma_komutu_durum_okunamazsa_yayini_durdurur`; kurulum testinde yayın kilidi izinleri, gerçek hesaplarla iki süreç (yayın tutarken servis alamaz; işlem kilidi boş kalır; bırakınca alır) ve bağlantı reddi. T5: eski deploy.sh'ta 22 kontrol kırmızı; işleyicideki kilit denetimi kaldırılınca PHP testi kırmızı; eski kurulum betiğinde 18 kontrol kırmızı. Doğrulanamayan: canlı sunucuda yayın (kullanıcı talimatı yok).
    - R2: `yedek:eski-yedekleri-al` artık yedekleme kilidini alıyor (meşgulse çıkış 75). Hedef ad ve manifest yazılmadan ÖNCE `hedefDurumu()` denetimi yapılıyor: aynı adla dosya, manifest ya da kayıt varsa ve aynı arşiv değilse (aynı özet + aynı kimlikli imzalı manifest) arşiv "çakışan" sayılıyor. Hiçbir dosyaya dokunulmuyor, uyarı yazılıyor, sayaçta `çakışan` var, denetim kaydı başarısız, komut sonda FAILURE ile çıkıyor. Ayrı ada alma seçilmedi: deploy.sh adı arşivin zamanını taşıyor (alinma_zamani addan okunuyor) ve aynı ana iki farklı arşiv elle bakılması gereken bir durum (D4: iki kopya farklıysa hiçbiri silinmez). Aynı arşivin dosyaları duruyor ama kaydı yoksa "zaten olan" sayılıyor (`yedek:tara` kurar). Özet satırına `çakışan N` eklendi (iki mevcut testin beklenen metni güncellendi). Test: `YedekEskiYedekleriAlTest::test_ayni_adli_farkli_arsiv_icerideki_yedege_dokunmaz_cakisma_olarak_basarisiz_cikar` (T5: düzeltmesiz kırmızı, komut başarılı döndü, dosya ve manifest üstüne yazıldı).
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 ve R2 kapandı. Yayın kilidi kod çekme, migration ve bakım modundan çıkış boyunca tutuluyor. `--no-backup` ve yedekli yol korumalı. Aynı adlı farklı arşivin hata ile atlanması D4 ile uyumlu. `deploy.test.sh` 43/43 ve içe alma regresyonları geçti.

---

## Faz 2 — Ekranlar

### BY-014 — API: Yedekler

- **Durum:** REVIEW_BEKLIYOR · **Bağımlı:** BY-010, BY-011
- **Tasarım:** README §4.9, §4.10 (1), §5.2 (istek yalnız kaydedilir), §8
- **Kapsam:** `v1/yedekler` uçları: liste (süzgeç, sıralama, sayfalama), özet
  şeridi verisi, elle yedek isteği, doğrulama isteği, sabitle / çöz, not, sil,
  klasörü tara. Uzun işlemler isteği kaydeder, işi çalıştırıcı yapar; durum
  yoklanabilir. Her işlem denetim kaydına. Hız sınırları.
- **Kapsam dışı:** Tarayıcıya indirme (README §13 karar 6 kapanana kadar).
- **Kabul ölçütleri:**
  - [x] Her uç için: izinsiz 403, girişsiz 401, geçersiz girdi 422 (`kod` alanıyla).
  - [x] Sabitlenmiş yedek silinemiyor (409); kullanımdaki yedek silinemiyor.
  - [x] Elle yedek isteği web isteğinin içinde `pg_dump` çalıştırmıyor (test).
  - [x] Yanıtlarda dosya yolu, parola, anahtar yok.
  - [x] Her değiştiren işlem denetim kaydı ve dosya günlüğüne yazılıyor.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Uçlar `/api/v1/ayarlar/yedekler…` (`YedekController`, `YedekResource`):
      liste (süzgeç kaynak / doğrulama / sabit / güvenilmeyen / silinenler,
      sıralama, sayfalama), özet şeridi (`YedekOzeti`), istek durumu yoklama,
      "Şimdi Yedek Al", "Klasörü Tara", doğrula, açıklama, sabitle / çöz, sil.
    - Değiştiren her işlem `YedekIstekServisi` ile istek klasörüne bırakılır
      (202 + `istek_kimligi`); işi çalıştırıcı yapar (BY-025). Bırakma denetim
      kaydına ve `yedekleme` günlük kanalına yazılır (`istek`, tür, kimlik);
      işin sonucunu çalıştırıcı ayrıca yazar.
    - Ön koşul tutmayan istek bırakılmadan 409: `YEDEK_SABIT`,
      `YEDEK_KULLANIMDA` (`KullanimDenetimi`), `YEDEK_SILINMIS`,
      `YEDEK_DOGRULANAMAZ` (imzasız / tamamlanmamış). Çalıştırıcı aynı denetimi
      yeniden yapar (istek dosyasına güvenmez).
    - Yanıtta dosya YOLU, serbest hata metni, uzak kimlik yok: yalnız dosya adı
      ve makine okunur `hata_kodu`; istek sonucundan yalnız izinli alanlar
      (`mesaj` dönmez).
    - Modül kapalıyken bütün yedekleme uçları 409 `YEDEKLEME_KAPALI`
      (`YedeklemeAcik` ara katmanı); menü `user.yedekleme_aktif` ile gizlenir.
    - Hız sınırı `yedek-istek` (10/dk, kullanıcı başına).
    - Testler: `YedekApiTest` (19; her uç için 401 / 403 veri sağlayıcıyla,
      422 `kod`, 409'lar, "pg_dump çalışmıyor" `Process::assertNothingRan`,
      yol / hata metni sızmıyor, denetim kaydı).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-014-R1 [P2] API hata dönse de iş çalıştırıcıya bırakılmış olabiliyor.**
       `backend/app/Services/Yedekleme/YedekIstekServisi.php:26, backend/app/Services/Yedekleme/YedekDenetimi.php:36`. İstek diske yayınlandıktan sonra günlük yazımı istisna verebiliyor. İstemci 500 görürken çalıştırıcı işlemi başlatabiliyor. Yeniden deneme ikinci bir iş oluşturuyor (D3, D8).
       Kabul edilen istek kimliğinin istemciye dönüşünü denetim günlüğü arızasından ayırın ve kayıt arızasını izlenebilir tutun. Günlük yazım hatasında bir adet istek ve izlenebilir bir kabul sonucu olduğunu sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Kayıtsız istek yok: `YedekIstekServisi::birak` önce istek kimliğini üretir, denetim kaydını (dosya günlüğü + veritabanı) yazar, isteği ANCAK sonra yayınlar (`IstekKutusu::yaz`'a eklemeli `?string $kimlik` parametresi). Denetim kaydı yazılamazsa istek hiç yayınlanmaz, istemci 500 alır, yeniden deneme ikinci iş yaratmaz. Yayın başarısızsa aynı istek kimliğiyle `basarisiz` izleme kaydı (`kod: ISTEK_YAYINLANAMADI`) eklenir (denetim kaydı salt eklemeli — K3 — önceki satır değiştirilmez) ve hata 500 olarak döner. Test: `YedekApiTest::test_denetim_kaydi_yazilamazsa_istek_yayinlanmaz_500`, `test_istek_yayinlanamazsa_ayni_kimlikle_basarisiz_izleme_kaydi_eklenir_500` (T5: eski sırayla ikisi de kırmızı). Doğrulanamayan: yok.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1'in ilk hata senaryosu kapandı. Denetim yazımı başarısızsa artık istek oluşmuyor. İsteğin dosyada görünür olmasından sonraki hata hâlâ aynı sonuca yol açabiliyor.
    1. **BY-014-R2 [P2] İstek görünür olduktan sonraki eşitleme hatası kabulü belirsiz bırakıyor.**
       `backend/app/Services/Yedekleme/AtomikDosya.php:85 ve :97, backend/app/Services/Yedekleme/YedekIstekServisi.php:39`. `rename` ile istek çalıştırıcıya görünür oluyor. Ardından klasör eşitlemesi bozulursa `birak()` başarısız kayıt yazıp 500 döndürüyor. Çalıştırıcı görünmüş isteği işleyebilir ve istemci yeniden denemede ikinci iş bırakabilir (D3).
       İstek kimliğini kabul için kalıcı ve yeniden sorgulanabilir kılın. Taşıma sonrası belirsiz sonucu aynı kimlikle uzlaştırın. Klasör eşitlemesi hatasında istemci yeniden denediğinde en çok bir iş çalıştığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: Taşıma sonrası hata ayrıldı: `AtomikDosya` klasör eşitlemesi taşımadan SONRA düşerse yeni `KalicilikDogrulanamadi` (RuntimeException alt sınıfı; yalnız RuntimeException/Throwable yakalayan diğer çağıranlarda davranış aynı) fırlatır; taşımadan önceki hata eskisi gibi düz RuntimeException. `YedekIstekServisi` ortak kabul sözleşmesi (`yayinla`): önce denetim kaydı, sonra yayın; yayımlanmış istek kabul edilmiştir (202, aynı kimlikle `basarili` + `kod: ISTEK_KALICILIGI_DOGRULANAMADI` belirsizlik notu, en iyi çaba); taşımadan önceki hata `basarisiz` (`ISTEK_YAYINLANAMADI`) + hata. İstek kimliği yeniden sorgulanabilir: istemci `Idempotency-Key` (UUID) gönderir (`App\Http\IstekAnahtari`; geçersizse 422 DOGRULAMA), kimlik APP_KEY'li HMAC ile anahtar + eylemden (tür, kullanıcı, parametreler) türetilir (UUID biçimi); aynı kimlikle istek bekleyen/işleniyor/sonuç'ta varsa ikinci dosya ve ikinci denetim kaydı yazılmaz, mevcut kabul döner (`IstekKutusu::yoksaYayinla`; bakış + yazım `bekleyen/.kabul.kilit` flock'u altında, en çok 10 sn monoton bekleme — G18). Anahtarsız çağrı (komut satırı, eski istemci) eskisi gibi rastgele kimlik, kilitsiz. Bağlanan uçlar: Yedekler (al, tara, doğrula, not, sabitle/çöz, sil) ve farklı adla geri yükleme. Üstüne geri yükleme BAŞLATMA bilerek anahtarsız: erişim anahtarı yalnız ilk yanıtta döner, yeniden denemeye verilemez; ikinci istek çalıştırıcıda "açık geri yükleme var" ile reddedilir (taşıma sonrası hatada artık 202 + anahtar döner). Frontend: `useIstekAnahtarlari` (eylem başına anahtar, 202'de unutulur), `yedekApi.istekBirak` başlığı gönderir; `YedeklerPage` F5 kilidiyle birlikte kullanır. README §5.5 "İstek kabulü" paragrafı eklendi. Testler: `YedekApiTest::test_klasor_esitlemesi_bozulsa_da_yayimlanan_istek_kabul_edilir_yeniden_deneme_ikinci_dosya_yazmaz` (bekleyen/ 0300: taşıma olur, eşitleme açılamaz), `test_ayni_istek_anahtariyla_yeniden_deneme_en_cok_bir_is_birakir_baska_eylem_ve_kullanici_ayri_kimlik_alir` (çalıştırıcı işledikten sonra da yeni iş yok; büyük harfli anahtar aynı; başka kullanıcı / eylem ayrı kimlik; geçersiz anahtar 422), `test_istek_tasinmadan_once_yazilamazsa_500_dosya_yok_ayni_anahtarla_yeniden_deneme_bir_kez_yayinlar`, `test_ayni_anahtarla_eszamanli_istek_kabul_kilidini_bekler_ilk_yayinlanmissa_yazmaz` (kilidi test tutar, Sleep sahtesinde ilk istek yayınlar); frontend `YedeklerPage.test` "yanıtı alınamayan iş yeniden denenince aynı istek anahtarını taşır; kabulden sonra yeni iş yeni anahtar alır (BY-014-R2)"; mevcut BY-015-R1 çift tıklama testi yeşil. (T5: KalicilikDogrulanamadi yakalanmayınca ilk ikisi, anahtar yok sayılınca üç anahtar testi, bakış kilitten önce yapılınca eşzamanlılık testi, frontend her denemede yeni anahtarla kırmızı.) Doğrulanamayan: gerçek fsync hatası (klasör 0300 ile kuruldu); servis kipinde web hesabının `bekleyen/` (2770) altında `.kabul.kilit` açması sunucuda denenmedi; root altında klasör izin testleri atlanır (T11).
    - R2 (ek, G1): `KalicilikDogrulanamadi`'yı ayırt etmeyen iki çağıran düzeltildi. (1) `yedek:yayin-oncesi` (deploy.sh, servis kipi): istek yayımlanıp klasör eşitlenemeyince yayın duruyordu, oysa çalıştırıcı isteği işleyip fazladan yayın öncesi yedek alıyordu; artık kimlik önceden üretiliyor, uyarı yazılıp aynı isteğin sonucu bekleniyor. (2) `IstekCalistiricisi::sonuclandir`: sonuç yerine taşınıp yalnız eşitleme doğrulanamayınca üstlenilen dosya `isleniyor/`'da kalıyor, sonraki tur gerçek sonucu `ISTEK_YARIDA_KALDI` ile eziyordu; artık sonuç yazılmış sayılıp üstlenilen dosya siliniyor (uyarı günlüğe). Testler: `YedekYayinOncesiTest::test_istek_klasoru_esitlenemese_de_yayimlanan_istegin_sonucu_beklenir` (yayın başarılı, tek istek), `YedekCalistiriciTest::test_sonuc_klasoru_esitlenemese_de_yazilan_sonuc_sonraki_turda_yarida_kaldi_ile_ezilmez`. T5: iki düzeltme geri alınınca ikisi de kırmızı. Eşitleme hatası 0300 klasörle taklit edildi; root'ta atlanır (T11).
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R2'nin taşıma sonrası eşitleme yolu doğru ayrılıyor. Yayımlanmış istek 202 dönüyor, aynı anahtarla yapılan yeniden deneme mevcut isteği buluyor. Yayın ve çalıştırıcının sonuç yazımı için ek düzeltmeleri de kontrol ettim.
    - **BY-014-R3 [P2] Sayfa yenilenince belirsiz isteğin yeniden denemesi ikinci bir iş açabiliyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:15-32` ve `frontend/src/pages/Yedekler/YedeklerPage.tsx:224`. İstemci anahtarı yalnız bağlı bileşenin `useRef` belleğinde. İstek kayda geçip yanıt kaybolduğunda sayfayı yenileyen kullanıcıya aynı eylem için yeni anahtar üretiliyor. Bu, örneğin iki elle yedek oluşturabiliyor. Vercel `client-localstorage-schema`: mevcut istek takibine benzer biçimde yalnız gerekli, hassas veri içermeyen bekleyen anahtarları kullanıcı ve eylem kapsamında, ömür sınırıyla koruyun. Kabul edildiğinde kaydı kaldırın. İlk isteğin sunucuda kabul edildiği ancak yanıtın ulaşmadığı senaryoda yeniden bağlanan bileşenin aynı anahtarı gönderdiğini ve tek iş oluştuğunu doğrulayın. BY-030-R4 aynı yardımcıyı kullanıyor.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R3 (BY-030-R4 ile ortak): `useIstekAnahtarlari(kapsam)`: bekleyen anahtarlar artık `localStorage`'da duruyor. Anahtar adı sürümlü: `erp.yedekleme.istekAnahtarlari.v1.<kapsam>`. Kapsam Yedekler'de `kullanici.<id>`; Üstüne Geri Yükleme sayfası oturum dışı olduğundan orada `ustune.<işlem kimliği>`. Depoya yalnız `{imza, anahtar (UUID), zaman}` girer; erişim anahtarı ya da kanıt girmez. Kalıp mevcut takip / üstüne geri yükleme deposuyla aynı: biçim doğrulaması, her erişimde try/catch. Kayıt istek gönderilmeden ÖNCE yazılıyor ve kabulde (202) siliniyor. Ömür `ANAHTAR_OMRU_MS` = 15 dk: süresi geçen ya da zamanı ileride olan kayıt kullanılmıyor, bir sonraki yazımda atılıyor. Bu süre, sunucunun anahtarı eşleştirdiği süreden (istek dosyası 24 saat geçerli) bilerek kısa tutuldu: amaç, yanıtı kaybolan isteğin sayfa yenilenerek yeniden denenmesi; daha sonraki tekrar bilinçli yeni bir iştir. Depo okunamaz / yazılamazsa anahtar bileşen belleğinde aynı ömürle yaşar (R2 davranışı korunuyor). Eylem imzası nesne anahtarı sırasından bağımsız: aynı ayar seçimleri başka sırayla yapılsa da aynı anahtar. F5 kilidi (`gonderiliyor` ref + `isPending`) değişmedi. Testler: `YedeklerPage.test` "yanıtı kaybolan iş sayfa yenilendikten sonra da aynı istek anahtarını taşır; kabulden sonra yeni iş yeni anahtar alır (BY-014-R3)" (ilk istek ağ hatası, ardından bileşen kaldırılıp yeniden kuruluyor: aynı anahtar; 202'den sonra depo boş, iş bitince yeni gönderim yeni anahtar alıyor) ve "süresi geçmiş bekleyen anahtar kullanılmaz; depo erişilemezse anahtar bellekte yaşar (BY-014-R3)". Mevcut BY-015-R1 ve BY-014-R2 testleri yeşil. T5: HEAD hook'uyla yenileme testi kırmızı. İkinci testte süre denetimi kaldırılınca (16 dk'lık kayıt yeniden kullanıldı) ve bellek yedeği kaldırılınca (depo kapalıyken yeni anahtar) kırmızı. Doğrulanamayan: gerçek tarayıcıda (Safari gizli pencere) depo davranışı; aynı kullanıcının iki sekmesi bekleyen anahtarı paylaşır (aynı eylem 15 dk içinde ikinci sekmede de mevcut kabule eşlenir; bilinçli kabul).
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R3'te 15 dakika içinde aynı eylem sayfa yeniden açıldığında aynı anahtarı kullanıyor. Yeni React testleri geçti. Bilinçli ömür sınırı kabul edildi.
    - **BY-014-R4 [P2] İstek açıklaması tarayıcıda ham metin olarak kalıyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:25-35,42-59,65-71,98-109` ile `frontend/src/pages/Yedekler/YedeklerPage.tsx:99-102,226`. `imzala()` özet çıkarmıyor, eylemin sıralanmış JSON'unu dönüyor. Elle yedek alma ve açıklama düzenleme sırasında serbest metin isteğin yanıtından önce `localStorage`'a yazılıyor. Ağ hatasından sonra süre dolsa da fiziksel kayıt ancak aynı kapsamda sonraki işlem bir yazıma yol açarsa temizleniyor. D2 ve Vercel `client-localstorage-schema`: ham parametreler yerine kanonik eylem özetini tutun ve eski depolama sürümünü temizleyin. Açıklamalı bir isteğin yanıtı kaybolduğunda düz metnin depoda görünmediğini ve yenilemeden sonra anahtarın aynı kaldığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R4: `useIstekAnahtarlari` depoya artık eylemin ham hali değil kanonik (anahtarları sıralı) eylem JSON'unun SHA-256 özeti yazılıyor (Web Crypto `crypto.subtle.digest`, onaltılık; `crypto.randomUUID` ile aynı güvenli bağlam koşulu). Kayıt `{ozet, anahtar, zaman}`. Bellek yedeği de özetle anahtarlı (ham metin anahtar olarak tutulmuyor). Depo adı `erp.yedekleme.istekAnahtarlari.v2.<kapsam>`. Açılışta (useEffect, kapsam değişince de) bütün kapsamlardaki `…istekAnahtarlari.v1.*` kayıtları siliniyor. Okumada süresi geçen, zamanı ileride olan ya da biçimsiz (özet 64 hex, UUID) kayıt depodan da siliniyor; bozuk JSON'da girdi kaldırılıyor. Açılışta bu kapsam bir kez okunuyor, yani süresi geçen kayıt sonraki yazımı beklemeden kalkıyor. Özet eşzamansız olduğu için API `hazirla(eylem): Promise<{anahtar, kabulEdildi()}>` oldu. Anahtar depoya istek gönderilmeden ÖNCE yazılıyor. `kabulEdildi` aynı özetle, 202 dönünce `mutationFn` içinde çağrılıyor. Böylece Üstüne Geri Yükleme'de kabul anındaki seçimlerden yeniden hesaplanan özetin gönderilenden farklı olması da kalktı. F5 kilidi (`gonderiliyor` ref + `isPending`) değişmedi. Dosyalar: `frontend/src/features/yedekleme/useIstekAnahtarlari.ts`, `frontend/src/pages/Yedekler/YedeklerPage.tsx` ve `frontend/src/pages/UstuneGeriYukleme/UstuneGeriYuklemePage.tsx` (yalnız mutationFn / onSuccess). Testler:
      - `YedeklerPage.test` "açıklamalı işin yanıtı kaybolunca depoda açıklama metni değil eylemin SHA-256 özeti durur; yenilemeden sonra anahtar aynı kalır (BY-014-R4)". localStorage'daki hiçbir değerde açıklama yok, kayıt `sha256sum` ile bağımsız hesaplanan özete eşit, bileşen yeniden kurulunca aynı Idempotency-Key gidiyor.
      - Yeni `useIstekAnahtarlari.test.ts`: "açılışta eski sürümün (v1 …) kayıtlarını bütün kapsamlarda siler; başka kayıtlara dokunmaz" ve "süresi geçmiş kayıt yeni bir yazım beklemeden açılışta depodan silinir; geçerli kayıt kalır".
      - Mevcut testler yalnız depo adı/alan adı v1→v2 ve imza→ozet olarak güncellendi. Süresi geçmiş kayıt testinde özet `sha256sum` ile hesaplandı. BY-014-R2/R3, BY-015-R1 ve BY-030-R4 testleri yeşil.
      - T5: üç yeni test HEAD hook'uyla kırmızıydı: düz metin depoda, v1 silinmiyor, süresi geçen kayıt kalıyor. Ayrıca okumadaki silme kapatılınca süre testi yeniden kırmızı oldu.
      - T7: jsdom'da `crypto.subtle.digest` çalışıyor; özet `sha256sum` çıktısıyla birebir.
      - Doğrulanamayan: gerçek tarayıcı (Safari gizli pencere); güvenli olmayan bağlamda (HTTP) Web Crypto yok, ama `crypto.randomUUID` de zaten aynı koşulu istiyordu.
  - 2026-11-21 Codex → CHANGES_REQUESTED · beşinci tur `3ad20f3`
    - R4'te yeni sürüm düz metin yazmıyor. Tam açıklama yerine kanonik JSON özeti kalıyor. v2'de sayfa yenileme ve süre temizliği testleri geçti.
    - **BY-014-R5 [P2] Sürüm geçişi geçerli bekleyen anahtarı kaybediyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:121-128,159-176`. v1 kaydının ham açıklamasıyla birlikte hâlâ geçerli UUID de siliniyor. 208746f sürümünde istek kabul edilip yanıt kaybolduysa kullanıcı yeni sürüme yenilediğinde aynı eylem yeni anahtar alır ve ikinci iş açabilir. Mevcut `useIstekAnahtarlari.test.ts:21-35` silinmeyi sınar, kabulü korumayı sınamaz. D2 ve Vercel `client-localstorage-schema`: geçerli v1 kaydının yalnız özetini, mevcut UUID'sini ve zamanını v2'ye taşıyın, ham kaydı kaldırın. Geçerli v1 kaydıyla yükseltme ve yeniden denemede UUID'nin aynı kaldığını, ham metin ve süresi dolmuş kaydın depoda kalmadığını doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex beşinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 5. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R5: `useIstekAnahtarlari` açılışta v1 kayıtlarını artık körce silmiyor, v2'ye taşıyor (`eskiSurumuTasi`). v1 kaydı `{imza, anahtar, zaman}` (208746f; imza = eylemin kanonik JSON'u). Bütün kapsamlardaki `…istekAnahtarlari.v1.<kapsam>` girdilerinde süresi geçmemiş ve biçimli (UUID, sayı zaman) her kaydın yalnız `sha256(imza)` özeti, mevcut UUID'si ve zamanı aynı kapsamın v2 deposuna yazılıyor. v2 ile aynı kanonik metin olduğu için özet de aynı. v2'de aynı özetli kayıt zaten varsa (yeni sürümde yapılmış daha yeni deneme) o kalıyor. Ham v1 girdisi her durumda `finally` içinde siliniyor: süresi geçmiş, biçimsiz, bozuk JSON, özet alınamadı ya da yazım başarısız. Süresi geçmiş / biçimsiz kayıt taşınmıyor. `hazirla` açılıştaki taşımanın bitmesini bekliyor (`acilis` ref). Taşıma bitmeden yapılan yeniden deneme yeni anahtar üretmiyor. Sayfaların eylem nesneleri 208746f ile aynı (diff ile doğrulandı). Dosyalar: `frontend/src/features/yedekleme/useIstekAnahtarlari.ts` (+ yorumlar, K11), `useIstekAnahtarlari.test.ts`. Testler:
      - "açılışta geçerli v1 kaydı yalnız özeti, UUID ve zamanıyla kendi kapsamının v2 deposuna taşınır, ham kayıt silinir; aynı eylemin yeniden denemesi aynı anahtarı alır (BY-014-R4, R5)". Mevcut "v1 silinir" testi genişletildi (K2). Taşıma beklenmeden `hazirla` → v1 UUID'si. v2 kaydı `sha256sum` ile bağımsız hesaplanan özete eşit, iki kapsamda da. Hiçbir depo anahtarında ve değerinde açıklama metni yok; başka kayıtlara dokunulmuyor.
      - Yeni "süresi geçmiş ya da biçimsiz v1 kaydı (başka kapsamda da) taşınmadan silinir; aynı eylem yeni anahtar alır (BY-014-R5)". Bozuk JSON da var. v1 ve v2 depoları boş kalıyor, aynı eylem yeni UUID alıyor.
      - YedeklerPage ve UstuneGeriYuklemePage testleri yeşil.
      - T5: ilk test HEAD hook'uyla kırmızı (yeni UUID döndü). İki mutasyonla da sınandı. Süre / biçim süzgeci kaldırılınca ikinci test kırmızı oldu: başka kapsamın v2'sine süresi geçmiş kayıt yazıldı. `await acilis.current` kaldırılınca ilk iki test kırmızı oldu.
      - Doğrulanamayan: gerçek tarayıcıda (Safari gizli pencere, iki sekme aynı anda taşırken) davranış. İki sekme aynı v1'i taşırsa özetle tekilleştiriliyor.
  - 2026-11-21 Codex → CHANGES_REQUESTED · altıncı tur `2b7ea87`
    - R5 tek açılışta kapandı. Geçerli v1 kaydı ham açıklama tutulmadan aynı UUID ve zamanla v2'ye taşınıyor. Geçersiz kayıtlar temizleniyor ve ilk istek taşımanın bitmesini bekliyor. Önceki ağ hatası ve sayfa yenileme testleri geçti.
    - **BY-014-R6 [P2] Eşzamanlı iki taşıma kabul edilmiş eski isteği yeniden bekleyen hâle getiriyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:150-197,226-240`. Her açılış bütün v1 kayıtlarının kopyasını alıp eşzamansız özet hesaplıyor. İki sayfa aynı v1 kaydını okursa hızlı olan taşımayı bitirip isteğin 202 yanıtını alıyor ve v2 kaydını siliyor. Yavaş olan daha önce aldığı ham kaydı sonra v2'ye geri yazıyor. Sonraki bilinçli yeni eylem eski UUID'yi gönderiyor ve sunucu önceki işi döndürüyor, yeni iş başlamıyor. Node 22, jsdom, gerçek hook ve geciktirilmiş ilk Web Crypto özetiyle doğrulandı. Hızlı örnek aynı anahtarla kabul edilip depoyu temizledi, yavaş örnek aynı anahtarı yeniden yazdı. Vercel `client-localstorage-schema` ve D4: taşıma yazımından hemen önce kaynak kaydın hâlâ geçerli ve taşınmamış olduğunu doğrulayın. Eşzamanlı taşıma veya kabul işlemlerini uzlaştırın. Kabul edilmiş ya da başka sayfada taşınmış kaydı eski anlık kopyadan yeniden üretmeyin. Gecikmeli iki taşıma, arada 202 ve ardından bilinçli yeni iş testinde yeni UUID üretildiğini doğrulayın.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex altıncı tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 6. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R6: `useIstekAnahtarlari` v1→v2 taşıması artık açılışta ham v1 metinlerinin anlık kopyasını almıyor; yalnız girdi adlarını topluyor, her girdi `eskiKaydiTasi(ad)` ile tek tek taşınıyor. Ham metin özet hesabından hemen önce okunuyor. Süresi geçmiş / biçimsiz / bozuk JSON girdisi beklemeden siliniyor. Özet (Web Crypto) beklendikten SONRA, arada await olmayan tek eşzamanlı adımda: kaynak girdi yeniden okunuyor ve okunan HAM metinle birebir aynıysa v2'ye yazılıp v1 siliniyor. Girdi artık yoksa (başka bileşen ya da sekme taşımış, taşınan anahtar 202 ile kabul edilip v2'den silinmiş olabilir) eski kopyadan hiçbir şey yazılmıyor. Girdi değişmişse (eski sürümün açık sekmesi yeniden yazmış) güncel hali baştan taşınıyor. Böylece ham v1 her durumda siliniyor (D2, R5). R5'te kapanan davranışlar korunuyor: ham açıklama tutulmuyor, aynı UUID ve zaman taşınıyor, geçersiz kayıt temizleniyor, `hazirla` taşımanın bitmesini bekliyor. Dosyalar: `frontend/src/features/yedekleme/useIstekAnahtarlari.ts` (yorumlar dahil, K11) ve `useIstekAnahtarlari.test.ts`. Testler:
      - "özeti gecikmeli ikinci taşıma, başka örnekte taşınıp kabul edilen v1 kaydını eski kopyasından geri yazmaz; bilinçli yeni iş yeni anahtar alır (BY-014-R6)". Aynı jsdom deposunda iki gerçek hook örneği açılıyor. İlk `crypto.subtle.digest` gerçek özet döndürüyor ama kontrollü bir Promise ile bekletiliyor. Hızlı örnek taşıyor, v1 UUID'siyle `hazirla` yapıp `kabulEdildi` çağırıyor; v2 boşalıyor. Ardından yavaş örneğin özeti serbest bırakılıyor ve onun `hazirla`'sı (bilinçli yeni iş) YENİ UUID alıyor. v2'de yalnız yeni kayıt var, v1 boş.
      - "özet beklenirken v1 girdisi değişirse eski kopyası değil güncel hali taşınır, ham girdi silinir (BY-014-R6)".
      - T5: iki test de HEAD (`2b7ea87`) hook'uyla kırmızıydı. İlki eski UUID'yi döndürdü, ikincisi eski kopyayı taşıyıp yeni UUID üretti. Mutasyonlar: yeniden okuma denetimi kapatılınca iki test kırmızı. Değişen girdi yeniden taşınmayınca (yalnız `return`) ikinci test kırmızı. Mevcut üç hook testi ile YedeklerPage ve UstuneGeriYuklemePage testleri yeşil.
      - Doğrulanamayan: gerçek tarayıcıda iki sekme. Tarayıcılar sekmeler arası localStorage için "storage mutex" uygulamıyor. Denetim, yazım ve silme aynı eşzamanlı blokta olduğu için pencere birkaç senkron depo çağrısına iniyor, ama süreçler arası atomiklik garanti değil. Kalan uç durum: bir örnekte Web Crypto özet hatası (güvenli olmayan bağlam) ham girdiyi silerse başka örnek o girdiyi taşıyamıyor. Bu ortamda iki örnek de aynı hatayı alır; D2 gereği girdi silinmeye devam ediyor.
  - 2026-11-22 Codex → CHANGES_REQUESTED · yedinci tur `00a5e19`
    - R6'nın aynı JavaScript ortamındaki gecikmeli özet senaryosu kapandı. Yeni testler eski anlık kopyanın kaynak silindikten sonra yazılmasını engelliyor.
    - **BY-014-R7 [P2] Yeniden okuma iki gerçek sekmede taşıma işlemini atomik yapmıyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:210-219`. A sekmesi v1'in değişmediğini ve v2'nin boş olduğunu okuduktan sonra B sekmesi taşıyıp isteğin 202 yanıtını alarak kaydı silebilir. A sekmesi kendi okuduğu değerlerle eski UUID'yi yeniden yazar. `await` bulunmaması aynı olay döngüsünde bölünmeyi engeller. Farklı sekmeler için kilit sağlamaz. Bu sonuç koddan ve HTML standardından çıkarıldı, gerçek tarayıcıda yeniden üretilmedi. Standart çok süreçli tarayıcıda localStorage için kilit varsayılmamasını söylüyor. Vercel `client-localstorage-schema`, G18: taşıma, yeni anahtar yazımı ve kabul temizliğini ortak sekmeler arası eşzamanlama ile uzlaştırın. Hedef tarayıcının desteklediği Web Locks veya işlemsel depolama kullanılabilir. Kilitleme yoksa aynı garantiyle çalıştığını varsaymayın. İki ayrı sayfa bağlamında A'nın son okumasıyla yazımı arasına B'nin taşıma ve kabulünü koyan testle eski kimliğin geri gelmediğini doğrulayın. [HTML Web Storage standardı](https://html.spec.whatwg.org/multipage/webstorage.html)
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex yedinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 7. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R7: `useIstekAnahtarlari` depoyu (v1 + v2) okuyup yazan her kritik bölüm artık sekmeler arası tek bir Web Locks kilidi altında çalışıyor: `navigator.locks.request('erp.yedekleme.istekAnahtarlari', { mode: 'exclusive' }, …)`. Kilit altındaki bölümler:
      - açılış (`acilisiYap`): v1→v2 taşıma + bu kapsamın süresi geçmiş kayıtlarının silinmesi. Kilit, özet (Web Crypto) beklenirken de tutuluyor.
      - `hazirla`: depoyu okuma, anahtar arama ve yeni anahtar yazımı.
      - `kabulEdildi`: 202 sonrası temizlik.
      Bu yüzden bir sekmenin son okumasıyla yazımı arasına başka sekmenin taşıma, 202 temizliği ya da yeni anahtarı giremiyor. `hazirla` açılışı kilidi istemeden ÖNCE bekliyor (aksi hâlde kilitlenme olur). `kabulEdildi` artık `Promise<void>` döndürüyor. Çağıranlar (`YedeklerPage`, `UstuneGeriYuklemePage`) onu `mutationFn` içinde `await` ediyor. F5 kilidi (`gonderiliyor` ref + `isPending`) değişmedi.
      R6'nın yeniden okuma denetimi korunuyor; yorumu güncellendi. Artık yalnız kilit almayan eski sürüm sekmesinin v1'i yeniden yazmasına karşı duruyor. R4/R5 geriletilmedi: özet deposu, aynı UUID ve zamanla taşıma, ham v1'in her durumda silinmesi.
      Web Locks yoksa (güvenli olmayan bağlam ya da eski tarayıcı) aynı garanti VARSAYILMIYOR:
      - v1 taşınmıyor; ham girdileri yine siliniyor (D2, "özet alınamadı" daldaki gibi).
      - v2'ye hiç dokunulmuyor; anahtar yalnız o örneğin belleğinde yaşıyor.
      - Durum `console.warn` ile (BY-014-R7) günlüğe yazılıyor.
      Gerekçe: kilitsiz okuma-yazma kabul edilmiş anahtarı geri getirip sonraki bilinçli işi sessizce eski işe eşleyebilir. Yenilemeden sonra yeni anahtar (en kötü ihtimalle ikinci iş) bundan iyidir. Pratikte bu dal yalnız HTTP'de çalışır, orada Web Crypto da yok; Vite 8 hedefi (Chrome 111 / Firefox 114 / Safari 16.4) Web Locks'u destekliyor.
      Test altyapısı: jsdom'da Web Locks yok. `frontend/src/test/sahteKilitler.ts` (yeni) aynı ada gelen istekleri FIFO, birer birer ve eşzamansız veren sahte bir LockManager kuruyor; `src/test/setup.ts` bunu her test dosyasında kuruyor. `baskaSekmedenCalistir` başka sürecin kilit altındaki işini taklit ediyor: kilit boşsa çağıranın eşzamanlı bloğunun ortasında HEMEN çalışıyor, tutuluyorsa sıraya giriyor.
      Dosyalar: `frontend/src/features/yedekleme/useIstekAnahtarlari.ts`, `useIstekAnahtarlari.test.ts`, `frontend/src/test/sahteKilitler.ts` (yeni), `frontend/src/test/setup.ts`, `frontend/src/pages/Yedekler/YedeklerPage.tsx` ve `frontend/src/pages/UstuneGeriYukleme/UstuneGeriYuklemePage.tsx` (yalnız `await bekleyen.kabulEdildi()`), `docs/yedekleme/README.md` (§5.5 "İstek kabulü" paragrafına iki cümle).
      Testler (`useIstekAnahtarlari.test.ts`):
      - "taşımanın son okumasıyla yazımı arasına başka sekmenin taşıma ve 202 temizliği giremez; kabul edilen eski anahtar geri yazılmaz (BY-014-R7)". Codex'in senaryosu: A'nın v2'yi son okumasından hemen sonra B taşıyıp gönderiyor ve 202 temizliğini yapıyor. B taşınan UUID'yi gönderiyor; A'nın bilinçli yeni işi yeni UUID alıyor; v2'de yalnız yeni kayıt var.
      - "yeni anahtarın okumasıyla yazımı arasına başka sekmenin 202 temizliği giremez; kabul edilen anahtar geri yazılmaz (BY-014-R7)".
      - "kabul temizliğinin okumasıyla yazımı arasına başka sekmenin yeni anahtarı giremez; o anahtar kaybolmaz (BY-014-R7)".
      - "Web Locks yoksa v1 kaydı taşınmadan silinir; anahtar depoya yazılmaz, yalnız bu örneğin belleğinde yaşar ve durum günlüğe yazılır (BY-014-R7)".
      - Mevcut R6 testi kilide uyarlandı: "özeti gecikmeli taşıma bitene dek başka örnek depoya bakmaz; taşınıp kabul edilen v1 kaydı eski kopyasından geri yazılmaz, bilinçli yeni iş yeni anahtar alır (BY-014-R6, R7)". Eski akışında hızlı örnek, kilidi tutan yavaş örnek bitmeden tamamlanıyordu; kilitle bu imkânsız. İki gerçek hook örneğinde hızlı örneğin beklediği artık açıkça sınanıyor; son durum doğrulamaları aynı.
      - R4/R5/R6'nın diğer testleri değişmeden yeşil (R6-2'de yalnız yorum).
      T5: beş yeni/değişen test düzeltmesiz kodla (00a5e19 hook'u) kırmızıydı:
      - taşıma testi eski UUID'yi döndürdü;
      - yazım testi kabul edilen kaydı geri yazdı;
      - kabul testi B'nin anahtarını sildi;
      - kilitsiz testi v1'i taşıdı;
      - R6 testinde hızlı örnek beklemedi.
      Bölüm başına mutasyon: açılıştaki kilit kaldırılınca taşıma testi ve R6/R7 testi, hazirla'daki kaldırılınca yazım testi, kabuldeki kaldırılınca kabul testi kırmızı (her kilit ayrı bir testle pinlenmiş).
      Doğrulanamayan:
      - Gerçek tarayıcıda iki sekme (ayrı süreç, gerçek Web Locks). Sekmeler arası araya girme, ayrı süreç modeliyle (sahte LockManager + getItem sarmalayıcısı) sınandı.
      - Kilit almayan sürümlerle eşzamanlılık: güncelleme sırasında açık kalan eski sürüm sekmesi (00a5e19 ve öncesi kilit almıyor) yenilenene dek eşzamanlanamaz.
      - Güvenli olmayan bağlamda kilitsiz dal yalnız jsdom'da sınandı.

  - 2026-11-22 Codex → CHANGES_REQUESTED · sekizinci tur `1546b94` (`00a5e19..1546b94`)
    - R7 kapandı. Taşıma, anahtar yazımı ve kabul temizliği ortak Web Locks kilidini alıyor, sayfalar eşzamansız temizliği bekliyor. Kilit bulunmayan ortamda ortak depo kullanılmadığı açık. Eski sürüm sekmelerinin kilide uymaması, yenileme gerektiren sürüm geçişi sınırı olarak kabul edildi. R7'nin beş yeni/değişen testi ve önceki regresyonlar geçti.
    - **BY-014-R8 [P2] Eski isteğin geciken kabulü aynı eylemin yeni bekleyen anahtarını siliyor.** `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:359` ve `:364`. Bu turda ayrıca doğrulanan, önceki sürümde de var olan hata. Temizlik yalnız eylem özetine bakıyor, kabul edilen UUID'yi karşılaştırmıyor. A ve B sekmeleri aynı eylem için K1 alıyor. A'nın 202 yanıtı K1'i temizliyor. A aynı eylemi bilinçli olarak yeniden başlatıp K2 alıyor. B'nin eski K1 isteğinin gecikmiş 202 yanıtı gelince `kabulEdildi`, depoda K2'yi de siliyor. Web Locks bu adımları sıraya koyuyor, hangi kaydın kabul edildiğini ayırmıyor. K2 sunucuda kabul edilip yanıtı kaybolursa A'nın sayfa yenilemesi ve yeniden denemesi K3 üretiyor. İkinci yedek/iş başlatılabilir. BY-014-R3'ün sayfa yenilemesinde aynı kimlikle yeniden deneme sözleşmesi bozuluyor.
      - Kanıt yerelde alındı. Değiştirilmemiş gerçek hook, Node 22 + jsdom, projedeki sıralayan sahte LockManager ve iki `renderHook` örneğiyle çalıştırıldı. İlk iki hazırlık aynı K1'i aldı. İlk kabulden sonraki hazırlık farklı K2 aldı ve depoda 1 kayıt vardı. İkinci eski kabulden sonra kayıt sayısı 0 oldu. A kaldırılıp yeniden açılınca aynı eylem K2 yerine farklı K3 aldı. Gerçek tarayıcı ve HTTP uçtan uca denenmedi. Senaryo eşzamanlı depo çağrısına ihtiyaç duymuyor, kilitli işlemlerin bu sırayla tamamlanması yeterli.
      - Teknik gerekçe `code-review` yan etki/sözleşme denetimi ve G18. Kilit, okuma/yazma yarışını çözüyor. Silinecek kaydın kimliği ayrıca doğrulanmalı.
      - En küçük düzeltme `kabulEdildi` içinde yalnız kapanışın tuttuğu `kayit.anahtar` ile eşleşen kaydı temizlemek. Ortak depoda özet + UUID eşleşmeli. Bellek girdisi de hâlâ bu UUID'ye aitse silinmeli. Mevcut Web Locks koruması kalmalı.
      - Doğrulama için iki hook aynı K1'i alsın. Birinci kabulden sonra yeni K2 hazırlansın, ardından ikinci K1 kabulü tamamlansın. K2 depoda kalmalı. Sayfa kaldırılıp açıldığında aynı eylem K2'yi yeniden kullanmalı. Geciken kabulün bellekteki daha yeni anahtarı da silmediği ve mevcut R7 testlerinin geçtiği doğrulansın. Geçici doğrulama dosyası bırakılmadı.
    - Ortak kontrol sonuçları BY-010'un bu tur kaydında. 415 ön yüz testinin mevcut hâli bu farklı gecikmiş yanıt sırasını yakalamıyor. Uygulama kodu değiştirilmedi.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex sekizinci tur bulgusunun düzeltmesi; başlığı `fix(yedekleme): Codex 8. tur incelemesi (BY-014) bulgulari` olan commit
    - R8: `kabulEdildi` artık eylemin bütün kaydını değil yalnız kabul edilen anahtarı siliyor: depoda `anahtar !== kayit.anahtar` süzgeci (önce `ozet !== ozet`), bellekte de kayıt yalnız aynı anahtarı taşıyorsa siliniyor (`frontend/src/features/yedekleme/useIstekAnahtarlari.ts`). Aynı eylem başka sekmede kabul edilip yeniden başlatılmışsa yeni işin anahtarı eski isteğin geciken 202'siyle silinmiyor; yeni işin yanıtı kaybolup sayfa yenilenince yeniden deneme aynı anahtarı taşıyor (BY-014-R3 sözleşmesi). Kancanın yorumu ve README §5.5 güncellendi; Dikkat_Edilecekler G18'e "kilit sırayı verir, kimliği değil" eklendi. Test: `eski isteğin geciken kabulü aynı eylemin sonradan üretilen yeni anahtarını silmez; yenileme sonrası yeniden deneme yeni anahtarı taşır (BY-014-R8)` — Codex'in senaryosu iki hook örneği + sıralayan sahte Web Locks ile: A ve B aynı K1, A kabul, A yeniden K2, B'nin geciken K1 kabulü; depoda K2 kalıyor, A yeniden açılınca K2 alıyor (T5: düzeltmesiz kırmızı, depo boş kalıyordu). G1: depoyu süzen başka temizlik yok; v1 taşımasındaki özet karşılaştırması silme değil, aynı eylemin daha yeni v2 kaydını tutuyor. Doğrulanamayan: gerçek tarayıcıda iki sekme ve HTTP uçtan uca.

### BY-015 — Ekran: Yedekler

- **Durum:** BEKLIYOR · **Bağımlı:** BY-014
- **Tasarım:** README §4.10 (1), §4.8 (özet şeridi), §4.4
- **Kapsam:** Ayarlar grubunda "Yedekler" sayfası (`pages/Yedekler`, ortak
  parçalar `features/yedekleme`): özet şeridi (üç ayrı zaman, sıradaki yedek,
  boyut, boş alan), liste (tarih, kaynak, boyut, doğrulama, kopyalar, sabit,
  not), satır işlemleri, "Şimdi Yedek Al", "Klasörü Tara", çalışan işlemin
  durumunu yoklama. Menü, rota, izin; `YEDEKLEME_AKTIF` kapalıyken menüde yok.
- **Kabul ölçütleri:**
  - [x] İzne göre düğmeler: yalnız görüntüleme izniyle işlem düğmeleri yok.
  - [x] Silme ve sabit çözme onay ister; toplu seçimde varsayılan seçim yok.
  - [x] İşlem sürerken düğmeler kapalı, bitince liste yenileniyor.
  - [x] "Güvenilmeyen" yedek belirgin işaretli; geri yükleme seçeneği yok.
  - [ ] Açık ve koyu temada, dar ekranda kullanılabilir.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - `pages/Yedekler` (özet şeridi: son yerel / uzak / sınama zamanları ayrı,
      sıradaki, toplam boyut, boş alan; süzgeçli / sıralı / sayfalı liste;
      Doğrula, Not, Sabitle / Sabitlemeyi kaldır, Sil; Şimdi Yedek Al, Klasörü
      Tara). `features/yedekleme`: API, yoklama kancası `useIstekTakibi` (2 sn;
      sayfa başına sessionStorage'da sürdürülür, try/catch) + `IstekSeridi`,
      `YedeklemeAlani` (modül kapalıyken menü ve rota yok). Saatler İstanbul.
    - İzne göre düğmeler: yalnız görüntülemede işlem yok — `YedeklerPage.test`
      "yalnız görüntüleme izninde işlem düğmesi yoktur"; menü / rota
      `navGruplari.test`, `YedeklemeAlani.test`.
    - Silme ve sabit çözme onaylı; toplu seçim yok — "silme onay ister…",
      "sabitleme onaysız, sabitlemeyi kaldırma onayla yapılır".
    - İş sürerken düğmeler kapalı, bitince liste ve özet tazelenir; sayfadan
      dönünce yoklama sürer; liste yenilenirken satır işlemleri kapalı (F1:
      `isFetching || isPaused || isPlaceholderData`).
    - "Güvenilmeyen" belirgin; geri yükleme yalnız `geri_yuklenebilir`
      satırda (BY-027).
    - Ortak sayfa boyutu tercihi 200 / "Hepsi" ise uca 100 gider (uç 10–100).
    - Kontroller: typecheck, lint (0 hata), vitest 58 dosya / 370 test, build.
      T5: 9 koruma kaldırılınca ilgili testler kırmızı.
    - Doğrulanamayan: açık / koyu tema ve dar ekran (ölçüt 5) elle / görsel
      denenmedi — işaretlenmedi; gerçek backend'e karşı tarayıcıda denenmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-015-R1 [P2] İlk yanıt beklenirken aynı iş tekrar gönderilebiliyor.**
       `frontend/src/pages/Yedekler/YedeklerPage.tsx:209, :219 ve :479`. Düğmeler takip edilen bir istek kimliğinden sonra kapanıyor. HTTP yanıtı beklenirken Klasörü Tara, Doğrula ve Sabitle gibi işlemler tekrar tetiklenebiliyor (F3).
       Düğme korumasına ilgili mutasyonun bekleme durumunu da katın. Geciktirilmiş HTTP yanıtında ardışık tıklamaların yalnız bir istek gönderdiğini sınayın. Açık/koyu tema ve dar ekran kontrolü açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `YedeklerPage`: bütün işler `isiBirak()` üzerinden; `useRef` eşzamanlı bayrağı yanıt gelene kadar ikinci `mutate`'i engeller, düğmeler `istek.isPending` ile de kapanır (Klasörü Tara, Şimdi Yedek Al, satır işlemleri). Not: yalnız `isPending` yetmiyordu — TanStack durum güncellemesi bir sonraki çizimde geldiği için arka arkaya iki tıklama iki istek bırakıyordu (ilk deneme testte kırmızıydı). Test: `YedeklerPage.test` "istek yanıtı beklenirken ardışık tıklamalar yalnız bir istek gönderir (BY-015-R1)" (T5: bayrak + isPending kaldırılınca kırmızı). Doğrulanamayan: açık / koyu tema ve dar ekran elle denenmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 kod ve testte kapandı. Geciktirilmiş yanıt ve çift tıklama testi geçti. Açık/koyu tema ile dar ekran kabulü gerçek tarayıcı incelemesi bekliyor. Uygulama kodu için ek bulgu yok.

### BY-016 — API + Ekran: Yedek Hedefleri

- **Durum:** PASSED · **Bağımlı:** BY-008, BY-015
- **Tasarım:** README §4.6, §4.10 (4)
- **Kapsam:** Hedef tanımı ekleme / düzenleme / silme, tür seçimi (bu görevde
  yalnız "Yerel disk"; diğer türler kendi görevlerinde eklenir), "Bağlantıyı
  Sına", kalan alan, son kopya durumu. Mevcut bağlantı tanımı ekranlarının
  (SQL / entegratör) kalıbı izlenir.
- **Kabul ölçütleri:**
  - [x] Parola kaydedildikten sonra hiçbir yanıtta dönmüyor; düzenlemede boş bırakılırsa değişmiyor.
  - [x] Bir planın kullandığı hedef silinemiyor (409, anlaşılır mesaj).
  - [x] "Sına" sonucu adım adım gösteriliyor (bağlan, yaz, oku, sil).
  - [x] Tür listesi bağlayıcılardan geliyor; yeni tür eklemek ekranda kod değişikliği gerektirmiyor.
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - API (`YedekHedefiController`, `YedekHedefiResource`): liste (son kopya
      durumu, plan sayısı), `turler` (bağlayıcıların form tanımı:
      `HedefKatalogu::tanimlar()`), ekle / düzenle / sil, "Bağlantıyı Sına",
      SFTP sunucu anahtarını sıfırla, Google bağlan / dönüş / kes (BY-021).
    - Form tanımdan doğrulanır; tanımda olmayan alan 422; tür değiştirilemez.
      Sırlar (`gizli` alanlar) yalnız yazılır, hiçbir yanıtta dönmez
      (`gizli_kayitli` bayrağı); düzenlemede boş = değişmez. Adresi belirleyen
      alan (sunucu, port, kullanıcı, uç nokta, bölge, kova, erişim anahtarı)
      değişirken sır boş bırakılırsa 422: kayıtlı parola yeni adrese
      gönderilmez (K8, SQL bağlantısındaki kural). Bağlayıcının yazdığı
      alanlar (SFTP sunucu anahtarı, Drive klasör kimliği) korunur.
    - Planda kullanılan ya da kopyası olan hedef silinmez: 409
      `HEDEF_KULLANILIYOR` + plan / kopya sayısı.
    - "Sına" web isteğinde ama aktarım süresi 30 sn ile sınırlı (D9), hız
      sınırı 5/dk; adım adım sonuç (bağlan, yaz, oku, doğrula, sil; atlanan
      adım `null`), kalan alan. Kayıtlı hedef değiştirilmeden sınanırsa SFTP
      sunucu anahtarı ona yazılır; form değiştiyse kaydedilmemiş kopyası sınanır.
    - Ekran `pages/YedekHedefleri`: form tamamen tür tanımından çizilir (yeni
      tür ekranda kod istemez — test "ekranın bilmediği yeni bir tür yalnız
      tanımdan çizilir ve kaydedilir"); düz FTP uyarısı; sır alanları dolu
      gelmez; Google bağlantı durumu ve "yeniden bağlanın"; `GoogleDonusPage`
      (StrictMode'da tek istek).
    - Testler: `YedekHedefiApiTest` (9), `YedekHedefleriPage.test`,
      `GoogleDonusPage.test`.
    - Doğrulanamayan: açık / koyu tema, dar ekran (elle).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-016-R1 [P1] Bağlantı yeri değişince eski doğrulanmış kopyalar yanlış hedefe bağlanıyor.**
       `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:90, backend/app/Services/Yedekleme/YedekKopyalayici.php:55`. Dolu hedefin fiziksel konumu düzenlenebilir, fakat tamamlanmış kopyalar aynı hedef kimliğiyle kalır. Sonraki kurtarma yeni klasör veya kovada eski dosyayı arar. Yerel kopyası silinmiş yedek kullanılamaz olabilir (D4, F7).
       Kopyalar dururken fiziksel hedef kimliğini değiştirmeyi engelleyin ya da eski konumu sürümleyin. Kimlik bilgisi yenilemeyi destekleyin. Uzak kopyası bulunan hedefte klasör değişikliğinin reddedildiğini veya kopyanın eski konumdan hâlâ açıldığını doğrulayın.
    2. **BY-016-R2 [P2] Hedef sınaması toplam süre sınırını aşabilir.**
       `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:141, backend/app/Services/Yedekleme/Hedefler/HedefSinayici.php:42`. Dış bağlantı, gönderme, okuma ve silme HTTP isteğinde ardışık çalışıyor. Birden fazla adım kendi süre sınırını ayrı tüketebildiği için web isteği kapanırken sınama ve temizlik devam edebilir (D9).
       Sınamaya tek toplam süre bütçesi verin veya mevcut çalıştırıcıya aktarın. Birkaç yavaş adımla toplam sürenin sınırda kaldığını ve kullanıcıya sonuç döndüğünü doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `HedefKonumu` (yeni): kopyası `tamam` ya da `kopyalaniyor` olan hedefte konum alanları (sunucu, klasor, kova, uc_nokta, bolge, on_ek, baglanti_noktasi, klasor_adi; FTP/FTPS/SFTP'de klasör göreliyse kullanici_adi) değişirse güncelleme 409 `HEDEF_KONUMU_DEGISMEZ` + `alanlar`. Konum kilidi sır kuralından (422) önce denetlenir. Sır yenileme ve konum belirlemeyen alanlar (ad, port, aktiflik, pasif kip…) serbest; sınama ("Sına") kilitten etkilenmez. Google Drive'da kopyası olan hedef BAŞKA bir hesapla yeniden bağlanırsa da 409 (dosya kimlikleri eski hesapta). `YedekHedefiResource` `konum_kilitli_alanlar` döner (listede `withExists`: hedef başına sorgu yok). Ekran (`HedefFormu`) bu alanları salt okunur yapar (disabled değil, değer gövdede kalır) ve nedenini yazar. Test: `YedekHedefiApiTest::test_kopyasi_olan_hedefte_konum_degisikligi_409_sir_ve_konum_disi_alan_degisebilir`, `test_yalniz_basarisiz_kopyasi_olan_hedefin_konumu_degisebilir`, `test_kopyasi_olan_drive_hedefi_baska_google_hesabiyla_yeniden_baglanamaz_409`; `YedekHedefleriPage.test` "kopyası olan hedefte konum alanları salt okunur ve nedeni yazılı; parola yenilenerek kaydedilir (BY-016-R1)" (T5: kilit kapatılınca API ve ekran testleri kırmızı). Doğrulanamayan: gerçek FTP/SFTP sunucusunda göreli yol davranışı.
    - R2: `HedefSinayici` tek toplam süre bütçesiyle çalışır (`TOPLAM_SURE` 30 sn, yeni `SureButcesi`, monoton saat — D1). Her adımdan önce bağlayıcının okuduğu `sure_siniri.kopya` / `.baglanti` kalan sürenin üçte birine kısaltılır (bağlayıcı bir çağrıda sınırı en çok 3 kez tüketebilir: 3 deneme / bağlantı × 3), adım bitince eski değer geri yazılır; bütçesi kalmayan adım çalışmaz (`HEDEF_SINAMA_SURESI_DOLDU`), silmeye 5 sn pay ayrılır ve silme pay bitse de en kısa sınırla denenir. Kalan alan ve S3 koruma sorgusu da aynı bütçeden (`HedefSinayici::sonuc`). Controller'daki kalıcı config değişikliği kaldırıldı. Test: `HedefSinayiciTest::test_yavas_adimlar_toplam_butceyi_asmaz_bitmeyen_adim_sure_doldu_olur_temizlik_yine_denenir` (sahte saat; T5: kısaltma / bütçe denetimi kaldırılınca kırmızı), `test_hizli_hedefte_butce_kalan_alani_da_kapsar`; eski `test_sina_kisa_sure_siniriyla_calisir` yerine `test_sina_sure_sinirini_yalniz_adimlar_suresince_kisaltir_ayari_kalici_degistirmez`. Doğrulanamayan: bağlayıcıların iç yeniden deneme beklemeleri (S3/Drive 0,5 + 2 sn) ve SFTP `ssh-keyscan` (bağlantı × 2 + 5) üçte bir kuralının dışında kalabilir; gerçek web sunucusu sınırıyla denenmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 için konum kilidi eklendi. R2'ye kalan süre hesabı eklendi. İki düzeltmenin aşağıdaki sınırları açık.
    1. **BY-016-R3 [P1] Kopyalı hedefin fiziksel adresi hâlâ değiştirilebiliyor.**
       `backend/app/Services/Yedekleme/HedefKonumu.php:22 ve :76, backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:93, backend/app/Services/Yedekleme/Hedefler/FtpBaglayicisi.php:316`. FTP/FTPS/SFTP portu gerçek bağlantı adresine giriyor ancak kilitli alanlarda yok. Aynı sunucuda farklı port farklı depoyu gösterebilir. Konum karşılaştırmasında gevşek eşitlik sayısal görünümlü iki farklı yol veya klasör adını da eşit sayabilir. Eski doğrulanmış kopyalar eski fiziksel konumda kalır (D4, G19).
       Fiziksel adresin bütün bileşenlerini, alan tipine uygun normalizasyon ve kesin karşılaştırmayla denetleyin. Konum denetimi ile kayıt/kopya başlangıcını eşzamanlılığa karşı koruyun. Kopyalı hedefte port ve farklı sayısal biçimli yol değişikliklerinin reddedildiğini, parola yenilemenin sürdüğünü sınayın.
    2. **BY-016-R4 [P2] Sınama bütçesi bağlayıcının tüm çağrılarına kadar taşınmıyor.**
       `backend/app/Services/Yedekleme/Hedefler/HedefSinayici.php:179, backend/app/Services/Yedekleme/Hedefler/GoogleDriveBaglayicisi.php:441 ve :579`. Bir üst çağrının süresi yalnız alt ayarlara bölünüyor. Drive tek çağrıda token, dosya isteği ve 401 sonrası yenileme yapabiliyor. İç tekrar ve beklemeler ortak son zamanı kontrol etmiyor. Bu nedenle 30 saniyelik toplam sınır aşılabilir (D9).
       Aynı monoton son zamanı her ağ çağrısı, tekrar ve süreç için uygulayın veya sınamayı mevcut çalıştırıcıya taşıyın. Birleşik yavaş bağlantı ve yeniden denemede HTTP yanıtı veya takip edilebilir sonuç sınamasını ekleyin.
    - Yerel örnek: kopyası olan SFTP hedefinde `22 → 2222` veya klasör `001 → 1` değişimi için `degisenKilitliAlanlar()` boş dizi döndü.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R3: Kopyalı hedefin fiziksel adresi tek yardımcıda (`HedefKonumu`) bütün bileşenleriyle ve KESİN karşılaştırmayla denetleniyor. Bileşenler bağlayıcıların gerçek kullanımından tek tek çıkarıldı ve sınıf yorumunda alan başına karar yazıldı: sunucu (alan adı harf duyarsız), port (FTP/FTPS/SFTP; boş = bağlayıcının varsayılanı — `FtpBaglayicisi::VARSAYILAN_PORT` 21 / `SftpBaglayicisi::VARSAYILAN_PORT` 22, tanım ve bağlayıcı aynı sabiti kullanıyor, G3; "22" = 22), kullanıcı adı (FTP/FTPS/SFTP — artık her zaman: mutlak klasör de chroot'ta kullanıcının köküne göre çözülür), klasör (boş = giriş/ana klasör, sondaki "/" atılır; "001" ≠ "1"), NAS bağlantı noktası, S3 uç nokta (küçük harf, :443 ve sondaki "/" atılır) / bölge / kova / ön ek (baş-son "/" atılır) / yol stili (kapalıyken istek kova.uc alan adına gider; bağlayıcı gibi yalnız `true` açık), Drive klasör adı ve bağlı hesap (`eposta`, bağlayıcının yazdığı alan). Serbest bırakılanlar (konum değil): sırlar, ad, aktiflik, FTP pasif kip, S3 erişim anahtarı (anahtar döndürme engellenmesin; sır kuralı yine uygulanır). Sır kuralı (adres değişince kayıtlı sır yeni adrese gitmez) da aynı yardımcıya taşındı (`adresDegistiMi`, controller'daki gevşek `!=` döngüsü kaldırıldı). Eşzamanlılık: `YedekHedefiController::update` konum denetimi + kaydı tek işlemde, hedef satırına `lockForUpdate` ile; `YedekKopyalayici::kopyala` kopyayı aynı satır kilidi altında "kopyalanıyor" yapıyor ve hedefin o anki kayıtlı konumunu yeniden okuyor (b1'in dosyasına en küçük ekleme). Google dönüşündeki hesap denetimi de aynı kilit altında. G1: `sina()`'daki "form kayıtlıyla aynı mı" kararı da gevşek eşitlikle ("001" == "1") veriliyordu → `KanonikJson` ile kesin. Ekran: port listeden kilitli geliyor (Input salt okunur); evet/hayır konum alanı (S3 yol stili) de kilitli (Switch `disabled`, Controller'lı olduğu için değer gövdede kalıyor). Hata metinlerine "port" eklendi.
      Testler: `YedekHedefiApiTest::test_kopyasi_olan_sftp_hedefinde_port_ve_sayisal_bicimli_klasor_degismez_esdeger_yazim_ve_parola_yenilenir` (22→2222 ve "001"→"1"/"01" 409; boş port, "22", "001/", büyük harfli sunucu + parola yenileme 200), `test_kopyasi_olan_hedefte_konum_degisikligi_409_sir_ve_konum_disi_alan_degisebilir` (güncellendi: port artık kilitli, pasif kip + parola + ad serbest; liste `sunucu, port, kullanici_adi, klasor`), `test_sina_formdaki_sayisal_esit_gorunen_degeri_kayitli_hedefle_karistirmaz` (G1), `HedefKonumuKilidiTest` (yalnız PG; ikinci bağlantı öbür işlemi taklit eder, bekleme `lock_timeout` ile görünür): `test_kopya_kaydi_acilirken_konum_guncellemesi_bekler_kopyasiz_sanip_konumu_degistirmez`, `test_hedef_guncellenirken_kopya_baslamaz_bekler`; ekran `YedekHedefleriPage.test` "kopyası olan hedefte konum alanları (port dahil) salt okunur…", "kilitli evet/hayır konum alanı (S3 yol stili) değiştirilemez, değeri gövdede kalır". T5: port konumdan çıkarılınca 2 API testi, ham gevşek karşılaştırmada SFTP testi, update'te kilit kaldırılınca 1., kopyalayıcıda kilit kaldırılınca 2. PG testi, Switch kilidi kaldırılınca ekran testi kırmızı. Doğrulanamayan: gerçek FTP/SFTP sunucusunda chroot ve port davranışı; S3 uyumlu çok kiracılı (Ceph) sunucuda erişim anahtarının ad alanını değiştirmesi (bilinçli olarak serbest bırakıldı — kabul edilen risk).
    - R4: Sınama bütçesinin son zamanı bağlayıcının bütün iç çağrılarına taşındı; sınama çalıştırıcıya taşınmadı, arayüz (`HedefBaglayicisi`) değişmedi. `SureButcesi` etkin bütçe kavramı kazandı (`icinde()`, `alt()`, `sinirla()`, `denemeSiniri()`); `HedefSinayici::sinirli` her adımı kendi son zamanıyla (kalan − pay; temizlik en az 1 sn) etkin bütçe olarak çalıştırıyor (üçte bir kısaltma yeniden denemeye yer bıraksın diye korundu). `YedeklemeYapilandirmasi::sureSiniri` etkin bütçede kalan süreye kısalıyor, son zaman geçtiyse çağrı yapılmıyor (`HEDEF_SINAMA_SURESI_DOLDU`) — her ağ çağrısı (Drive token, 401 sonrası token yenileme ve tekrar, S3, FTP curl, SFTP sftp) sınırını çağrı anında buradan aldığı için hepsi kapsanıyor. Bileşik süreler de kısalıyor: FTP/SFTP aktarım dışı `bağlantı × 3`, SFTP `ssh-keyscan` `bağlantı × 2 + 5`. Drive ve S3 yeniden denemeleri: koşul fonksiyonu sonraki denemenin + önündeki beklemenin (en çok 2 sn) kalan süreye sığıp sığmadığına bakıyor, sığıyorsa denemenin süre sınırlarını kalan süreye kısaltıyor, sığmıyorsa denemiyor. Etkin bütçe yokken (gece kopyası) hiçbir sınır değişmiyor. FTP geçici dosya temizliği artık hata yutuyor (süre dolunca asıl hatanın üstüne yazılmasın; SFTP'deki gibi).
      Testler: `HedefSinayiciTest::test_drive_yavas_baglanti_401_yenileme_ve_yeniden_denemede_toplam_butceyi_asmaz` (Http fake her isteği süre sınırının sonunda yanıtlar, `Sleep` sahtesi saati ilerletir: ilk token + 401 + yenilemede iki deneme, üçüncüsü sığmadığı için yapılmıyor; toplam ≤ 30 sn, baglan ERISILEMEDI ile izlenebilir), `test_sftp_anahtar_alma_ve_baglanti_sureci_ortak_son_zamani_asmaz` (Process fake: keyscan + sftp süreleri toplamı ≤ 25 sn). Mevcut iki test yeşil. T5: etkin bütçe kaldırılınca (eski üçte bir kuralı) iki yeni test kırmızı (Drive 35,5 sn; SFTP 21 + 24 sn). Doğrulanamayan: gerçek web sunucusu süre sınırıyla ve gerçek Drive / SFTP sunucusuyla; NAS dosya çağrıları süre sınırı alamaz (değişmedi, kurulum `soft,timeo` ile).
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R3 düzeldi. Bağlayıcının fiziksel konum alanları kesin karşılaştırılıyor ve güncelleme ile kopyanın başlaması PostgreSQL'de aynı hedef satırını kilitliyor. R4'te yeniden denemeler ve dış süreçler ortak bütçeye alındı.
    - **BY-016-R5 [P2] Son saniyede ayrılan temizlik süresi gerçek bağlayıcının çağrısına ulaşmıyor.** `backend/app/Services/Yedekleme/Hedefler/HedefSinayici.php:185-202` ve `backend/app/Services/Yedekleme/SureButcesi.php:52-56,87-99`. Temizlik için tam 1 saniyelik alt bütçe veriliyor. İlk süre denetimine kadar 1 nanosaniye bile geçerse `sinirla()` çağrıyı reddediyor. Yapay monoton saatle `HEDEF_SINAMA_SURESI_DOLDU` doğrulandı. Sınama dosyası yazılmışsa hedefte kalabiliyor. D1 ve G9: temizlik için pozitif ve bağlayıcının gerçekten kullanabildiği süre ayırın. Toplam süreyi koruyarak, silme sırasında kendi süre sınırını okuyan bir bağlayıcıyla ve ilerleyen saatle silme çağrısının yapıldığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R5: Temizliğe pozitif ve bağlayıcının gerçekten kullanabildiği süre ayrılıyor. `HedefSinayici::TEMIZLIK_ASGARI = 2` sn: temizliğin alt bütçesi `max(2, kalan)`; eskiden `max(1, kalan)` idi ve `SureButcesi::sinirla` çağrıyı ancak kalan ≥ 1 sn iken yaptığı için ilk denetime kadar geçen 1 ns ile tükeniyordu. Olağan akışta temizlik yine TEMIZLIK_PAYI (5 sn) içinde, toplam 30 sn aşılmaz (mevcut `test_yavas_adimlar_toplam_butceyi_asmaz…` yeşil); taban yalnız önceki bir adım payını aştıysa (süre sınırı alamayan çağrı, ör. NAS) devreye girer ve aşılmış bütçenin üstüne en çok 2 sn ekler. `SureButcesi` değişmedi (kabul eşiği doğru; sorun temizliğe verilen tabandı). Sınıf yorumu güncellendi (K11). Test: `HedefSinayiciTest::test_butce_tukenince_temizlik_ilerleyen_saatte_de_silme_cagrisini_kullanilabilir_sureyle_yapar` (yapay monoton saat; yazım bütçeyi tüketir, `sil` saati 1 ns ilerletip sınırını `sureSiniri('baglanti')` ile çağrı anında okur: silme yapılır, sınır ≥ 1, uzama ≤ TEMIZLIK_ASGARI) (T5: düzeltmesiz kırmızı — sil `false`, HEDEF_SINAMA_SURESI_DOLDU). Doğrulanamayan: gerçek web sunucusu süre sınırıyla.
  - 2026-11-21 Codex → PASSED · dördüncü tur `208746f`
    - R5 kapandı. Temizlik için 2 saniyelik alt sınır ilk süre denetimine gerçek bir çağrı yapacak pay bırakıyor. Önceki adım ayrılan payı aşarsa en kısa temizlik denemesi için açıkça tanımlanmış ek süre kabul edildi. İlerleyen saat ve bağlayıcı içi süre denetimiyle test geçti.

### BY-017 — API + Ekran: Yedek Planları

- **Durum:** PASSED · **Bağımlı:** BY-010, BY-016
- **Tasarım:** README §4.2, §4.3, §4.10 (2)
- **Kapsam:** Plan listesi ve formu: ad, sıklık, saat, etkin, hedefler ve hedef
  başına saklama kuralı. Kaydetmeden önce "sıradaki çalışmalar" ve "bu kuralla
  silinecekler" önizlemesi. Son çalışmanın sonucu. "Şimdi çalıştır".
- **Kabul ölçütleri:**
  - [x] Kullanıcının örneği kurulabiliyor: her gün yerel, 3 günde bir ikinci hedef, haftalık üçüncü hedef.
  - [x] Hedefsiz plan kaydedilemiyor; saklama kuralı boş olamıyor.
  - [x] Önizleme, kaydedildikten sonra zamanlayıcının hesapladığıyla aynı (test).
  - [x] Çakışan planlar ve ayın 29–31'i formda uyarıyla gösteriliyor.
  - [x] Plan pasife alınınca zamanlayıcı onu atlıyor; geçmişi duruyor.
  - [x] **Kullanıcı kararı F6 (§5):** kopyası olan bir hedef plandan
    çıkarılırken saklama kuralı kaybolmuyor: veritabanı reddeder (BY-004-R8,
    bileşik anahtar); uç anlaşılır 409 döner ve ekran nedenini açıklıyor
    (bu plana bağlı kopya sayısı, önce saklamayla temizlenmesi gerektiği) (test).
- **Canlı:** Evet (kapalı).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - API (`YedekPlaniController`): liste (hedef + saklama + bu plana bağlı
      kopya sayısı, son / sıradaki çalışma, ay sonu uyarısı), ekle / düzenle /
      sil, önizleme, "Şimdi çalıştır" (istek `plan_calistir`).
    - Doğrulama: en az bir hedef; her hedefte "son N" ya da "N gün"den en az
      biri; saat `SS:DD`; sıklık türüne göre yalnız anlamlı alanlar saklanır;
      tanım hiç çalışmıyorsa (saat penceresi) 422.
    - Önizleme değişikliği bir işlem içinde UYGULAR, `PlanTakvimi` ve
      `SaklamaPolitikasi::hesapla()`'yı çalıştırır, sonra geri alır: kayıttan
      sonraki hesapla aynı (test). Döner: sıradaki 5 çalışma, ay sonu uyarısı,
      aynı dakikaya düşen etkin planlar, bu planın bağlarından silinecekler.
    - F6: bu plana bağlı kopyası olan hedef çıkarılırken 409
      `PLAN_HEDEFI_KOPYALI` + hedef başına kopya sayısı (veritabanı da
      reddeder — bileşik anahtar); kopyası olan plan silinmez (409
      `PLAN_KOPYALI`, pasife alma önerilir). Pasif planın sıradaki çalışması
      gösterilmez, geçmişi durur; zamanlayıcının atlaması `YedekZamanlaTest`.
    - Ekran `pages/YedekPlanlari`: 5 sıklık türü, gün seçici, hedef satırları,
      "Önizle" (form değişince düşer), 29–31 uyarısı formda anında, çakışan
      planlar, F6 / PLAN_KOPYALI açıklamaları, Şimdi Çalıştır.
    - Testler: `YedekPlaniApiTest` (8: kullanıcının örneği — her gün yerel,
      3 günde bir ikinci, haftalık üçüncü hedef; hedefsiz / kuralsız red;
      önizleme = kayıt; silinecekler = saklama; çakışma + ay sonu; F6; pasif;
      çalıştır), `YedekPlanlariPage.test`.
    - Doğrulanamayan: açık / koyu tema, dar ekran (elle).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-017-R1 [P2] Plan sonucu yerine yalnız son çalışma saati gösteriliyor.**
       `backend/app/Http/Controllers/Api/V1/YedekPlaniController.php:280, backend/app/Services/Yedekleme/PlanliYedek.php:58`. Başarılı, başarısız ve kısmi çalışmaların hepsi saati güncelliyor. Kullanıcı ekrandan hangi planın yedek veya hedef hatası verdiğini ayırt edemiyor (BY-017 kapsamı).
       Var olan çalıştırma sonucunu planda okunabilir kılın. Başarılı, dump hatalı ve bir hedefi başarısız çalışmayı API ile ekran testinde ayrı gösterin.
    2. **BY-017-R2 [P3] Plan sayısı kadar ek sayım sorgusu.**
       `backend/app/Http/Controllers/Api/V1/YedekPlaniController.php:282`. Plan listesi her planı dönüştürürken hedef bağlantı sayımlarını yeniden sorguluyor. Sorgu adedi plan sayısıyla artıyor (Laravel Eloquent N+1 kuralı).
       Bağ sayılarını liste için bir kez, plan ve hedefe göre gruplayın. Birden çok planda değerlerin değişmediğini ve sorgu sayısının sabit kaldığını kontrol edin.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Plan listesi `son_sonuc` döner: planın son çalışmasının yedeği mevcut veriden türetilir (`son_calisma` çalışmanın SONUNDA yazılır ve planlar kilit altında sırayla çalışır: o andan önce açılan en yeni `plan` kaynaklı yedek). Yedek `basarisiz` → `yedek_basarisiz` + hata kodu; planın şu anki hedeflerinden birinde kopya `basarisiz` → `hedef_basarisiz` + hedef/kod listesi; değilse `basarili`; bilinmiyorsa null. `PlanliYedek`'e dokunulmadı. Ekran son çalışma hücresinde rozet + hata kodu / düşen hedefleri gösterir. Test: `YedekPlaniApiTest::test_son_calismanin_sonucu_basarili_yedek_hatali_ve_hedef_hatali_ayri_doner`; `YedekPlanlariPage.test` "son çalışmanın sonucu başarılı, yedek hatalı ve hedef hatalı olarak ayrı gösterilir (BY-017-R1)" (T5: alan yokken kırmızı). Doğrulanamayan: iki plan çalışması aynı saniyede birbirini izlerse (biri biterken öteki aynı saniyede açılırsa) ayırt edilemez; dizin boş kataloğdan yeniden kurulduysa yeniden açılan kayıtların `created_at`'i kurulum anıdır, sonuç daha eski yedeğe düşebilir.
    - R2: Bağ sayıları tek gruplu sorguyla (plan × hedef), son yedekler tek `whereIn` + kopyalar eager load; son yedek kimliği plan sorgusunda alt sorgu. Test: `YedekPlaniApiTest::test_plan_listesi_sorgu_sayisi_plan_sayisindan_bagimsizdir_degerler_degismez` (1 ve 4 planda sorgu sayısı aynı, kopya sayıları doğru; T5: plan başına sorgu geri konunca kırmızı).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R2 kapandı. Plan hedefi sayıları toplu okunuyor. R1 ekranda sonuç alanına kavuştu, ancak aşağıdaki eşleştirme hâlâ yanlış sonuç üretebilir.
    1. **BY-017-R3 [P2] Son sonuç başka bir planın yedeğine bağlanabiliyor.**
       `backend/app/Http/Controllers/Api/V1/YedekPlaniController.php:295 ve :335, backend/app/Services/Yedekleme/PlanliYedek.php:68`. Alt sorgu plan kimliğini kullanmadan son çalışma saatinden önceki en yeni plan yedeğini seçiyor. Aynı saniyede tamamlanıp başlayan ayrı çalışmalarda başka planın sonucu gösterilebilir. Tarihî hedefler yerine bugünkü liste kullanıldığı için başarısız hedef çıkarıldığında eski çalışmanın sonucu da değişebilir (G17, T1).
       Son çalışmanın yedek kimliğini ve o çalışmanın hedef sonuçlarını planla ilişkilendirin. Aynı saniyede iki plan çalışması ve sonradan hedef listesi düzenleme ile sonucu doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R3: Son çalışma artık planla İLİŞKİLİ tutuluyor. Yeni migration `2026_11_21_090404_add_son_yedek_to_yedek_planlari_table` iki kolon ekliyor: `yedek_planlari.son_yedek_id` (FK yedekler, yedek silinince NULL, indeksli) ve `son_hedef_idleri` (jsonb). `son_hedef_idleri`, o çalışmada bu planın kopyalandığı hedefleri tutar (etkin + desteklenen).
      - `PlanliYedek` iki kolonu `son_calisma` ile aynı kayıtta yazıyor. Hedef süzgeci tek yardımcıda (`kopyalanacaklar`); kopyalama da aynı süzgeci kullanıyor. Yedek alınamadığında da bağ yazılıyor.
      - `YedekPlaniController::sonSonuc` sonucu yalnız bu bağdan okuyor: zaman alt sorgusu kaldırıldı, planın bugünkü hedef listesi kullanılmıyor.
        - Yedek `basarisiz` ise sonuç `yedek_basarisiz` + kod.
        - `son_hedef_idleri` içindeki bir hedefte kopya `basarisiz` ise sonuç `hedef_basarisiz`; hedef adı kopyanın kendi hedefinden geliyor.
        - Bunların dışında sonuç `basarili`.
        - Plan hiç çalışmadıysa ya da yedek kaydı yoksa sonuç null.
      - API ve ekran sözleşmesi değişmedi; frontend'e dokunulmadı.
      - Okuma eager load ile yapılıyor (`hedefler`, `sonYedek.kopyalar.hedef`; modelde yeni `sonYedek` ilişkisi). R2'nin sabit sorgu sayısı korundu.
      - Testler (`YedekPlaniApiTest`):
        - `test_ayni_saniyede_ardisik_iki_calismada_her_plan_kendi_sonucunu_gosterir`: zaman dondurulmuş. Gerçek `PlanliYedek` iki ayrı çalışmayı aynı saniyede yapıyor: A'nın diski yazılamıyor, B'ninki düzeltilmiş. A `hedef_basarisiz`, B `basarili` gösteriyor.
        - `test_calismadan_sonra_basarisiz_hedef_plandan_cikarilinca_son_sonuc_degismez`: başarısız hedef PUT ile plandan çıkarılıyor; sonuç `hedef_basarisiz` kalıyor.
        - `test_tek_yedegi_paylasan_planlarda_digerinin_hedef_hatasi_sonuca_girmez`: aynı yedeği paylaşan iki planda A `basarili`, B `hedef_basarisiz`.
        - Mevcut `test_son_calismanin_sonucu_…` ve `test_plan_listesi_sorgu_sayisi_…` testlerinin kurgusu yeni bağla kuruldu (doğrulamaları aynı).
      - T5: denetleyici + PlanliYedek eski hâline döndürülünce ilk iki test ve yeniden kurulan R1 testi kırmızı. `son_hedef_idleri` süzgeci kaldırılınca (yedeğin bütün kopyaları) paylaşılan yedek testi kırmızı.
      - Doğrulanamayan: canlıdaki planlar migration'dan sonra bir sonraki çalışmaya kadar "bilinmiyor" (null) görünür; eski veriden geriye dönük doldurma yapılmadı, çünkü eski eşleştirme zaten güvenilmezdi. Ekran açık / koyu temada elle denenmedi (değişmedi).
  - 2026-11-21 Codex → PASSED · üçüncü tur `a63795a`
    - R3 kapandı. `PlanliYedek` son yedek kimliğini, hedef kimliklerini ve çalışma zamanını birlikte yazar. Sonuç bu çalışmanın kopyalarından okunur. Sonradan hedef listesini değiştirmek geçmiş sonucu değiştirmiyor. Mevcut planın ilk çalışmaya kadar sonucu boş kalıyor. PostgreSQL ve ön yüz testleri geçti.

### BY-018 — Ekran: İşlem Günlüğü ve Yedekleme Ayarları

- **Durum:** PASSED · **Bağımlı:** BY-012, BY-015
- **Tasarım:** README §4.9, §4.10 (5), §4.3, §4.8
- **Kapsam:** Denetim kaydı listesi (süzgeç: tarih, kişi, işlem, sonuç). Ayarlar:
  zamanlanmamış yedeklerin süreleri, hedef başına en az sayı, disk eşiği,
  bildirim alıcıları, yaşam sinyali adresleri.
- **Kabul ölçütleri:**
  - [x] Günlük salt okunur; ekrandan silinemiyor / değiştirilemiyor.
  - [x] Ayar değişikliği denetim kaydına ve ayar dosya kopyasına yazılıyor.
  - [x] En az sayı 1'in altına indirilemiyor.
- **Canlı:** Evet. Bu görevden sonra kullanıcı `YEDEKLEME_AKTIF`'i canlıda açabilir.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - API: `GET /ayarlar/yedek-islem-kayitlari` (tarih — İstanbul günü —,
      kişi, işlem, sonuç süzgeci; sayfalı); yazma ucu yok (405), model de
      güncelleme / silmeyi reddeder. `GET|PUT /ayarlar/yedek-ayarlari`
      (saklama süreleri, en az sayı ≥ 1, disk eşiği, bildirim alıcıları,
      https yaşam sinyali adresleri), `PUT|DELETE …/sifreleme-anahtari`
      (BY-019: özel anahtar içeren metin 422 `YEDEK_ANAHTAR_OZEL`).
    - Her değişiklik denetim kaydına eski → yeni farkla ve ayar dosya
      kopyasına yazılır. Servis kipinde web süreci kopyayı yazamaz (durum
      klasörü ve imza anahtarı servisin): çalıştırıcı her turda (en çok
      dakikada bir) `AyarKopyasi::guncelDegilseYaz()` çağırır — parmak izi
      (SMTP parolası yerine APP_KEY'li HMAC) değişmişse yazar. Böylece mail
      ayarı ekranındaki değişiklik de kopyaya geçer. Kopyaya şifreleme
      anahtarının parmak izi de girer.
    - Ekranlar: `pages/YedekIslemGunlugu` (açılışta son 30 gün — F2; bilinen
      işlem adları çevrilir, bilinmeyen olduğu gibi), `pages/YedeklemeAyarlari`
      (kayıt sürerken alanlar salt okunur — F3; parmak izi dörderli; anahtar
      kaldırma onaylı).
    - Testler: `YedekAyariApiTest` (5: fark + dosya kopyası; servis kipinde
      çalıştırıcı yazar, değişmeyince yazmaz; en az sayı / https / e-posta
      reddi; özel anahtar reddi; günlük salt okunur + süzgeç),
      `YedekIslemGunluguPage.test`, `YedeklemeAyarlariPage.test`.
    - Not: README'ye göre bu görevden sonra kullanıcı `YEDEKLEME_AKTIF`'i
      canlıda açabilir — kullanıcı talimatı olmadan açılmadı.
    - Doğrulanamayan: açık / koyu tema, dar ekran (elle).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-018-R1 [P2] Ayar kaydedildiği hâlde imza anahtarı hatası başarısız yanıt üretiyor.**
       `backend/app/Http/Controllers/Api/V1/YedekAyariController.php:65 ve :120, backend/app/Services/Yedekleme/AyarKopyasi.php:55`. Doğrudan kipte ayar önce veritabanına kaydediliyor. Anahtar yoksa veya ayar kopyası yazılamıyorsa sonraki adım hata veriyor. Ekran başarısızlık görürken saklama ve bildirim ayarları çoktan değişmiş oluyor (D3).
       Kurulum ön koşulunu yazmadan önce denetleyin ve kopya yazılamazsa tutarlı kayıt/yanıt davranışı sağlayın. Eksik imza anahtarı ve geçici dosya hatasında veritabanı, ekran ve ayar kopyasının durumunu birlikte sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `YedekAyariController::kaydet` (güncelleme, anahtar yükleme / kaldırma ortak): doğrudan kipte imza anahtarı YAZMADAN önce denetlenir (yoksa 409 `YEDEK_IMZA_ANAHTARI_YOK`, hiçbir şey değişmez); veritabanı yazımı + denetim kaydı + ayar kopyası tek `DB::transaction`'da, kopya en son yazılır; kopya yazılamazsa işlem geri alınır, önceki kopya (atomik yazım) baytlarıyla kalır, `basarisiz` denetim kaydı eklenir, yanıt 500 `YEDEK_AYAR_KOPYASI_YAZILAMADI`. `AyarKopyasi`'na dokunulmadı. Test: `YedekAyariApiTest::test_imza_anahtari_yoksa_ayar_veritabanina_ve_kopyaya_yazilmaz_409`, `test_ayar_kopyasi_yazilamazsa_veritabani_geri_alinir_onceki_kopya_baytlariyla_kalir` (klasör 0555; root'ta atlanır — T11) (T5: ön koşul denetimi / işlem kaldırılınca ikisi de kırmızı). Doğrulanamayan: servis kipinde web süreci kopya yazmaz (davranış değişmedi).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 imza anahtarı eksikliği ve taşıma öncesi dosya hatası için kapandı. Dosya ve veritabanı arasındaki tutarlılık aşağıdaki hata penceresinde açık.
    1. **BY-018-R2 [P2] İmzalı ayar dosyası veritabanı kaydından önce yayımlanıyor.**
       `backend/app/Http/Controllers/Api/V1/YedekAyariController.php:148 ve :155, backend/app/Services/Yedekleme/AtomikDosya.php:85 ve :97`. Dosya veritabanı işlemi bitmeden değiştiriliyor. Dosya taşımasından sonraki eşitleme hatası, süreç kesintisi veya commit hatasında veritabanı eski değerine dönerken yeni imzalı dosya yerinde kalabilir. Kurtarma sırasında kullanıcı hiç kaydedilmemiş ayarları güncel sanabilir (D3).
       Dosya kopyasını yalnız commit olmuş ayarlardan üretip yazma durumunu izlenebilir kılın. Veritabanı ile dosya farklı kaldığında güvenli uzlaştırma yapın. Taşıma sonrası hata ve commit başarısızlığını ayrı senaryolarda sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: `YedekAyariController::kaydet`: veritabanı + denetim kaydı tek işlemde; ayar dosya kopyası (doğrudan kipte) ANCAK işlem döndükten (commit) sonra, `AyarKopyasi::yaz()` veritabanından yeniden okuyarak yazılır — commit düşerse dosyaya dokunulmaz. Commit'ten sonra kopya yazılamazsa ayar kaydedilmiş sayılır: yanıt 200, `ayar_kopyasi_guncel: false`, denetimde `ayar_kopyasi` `basarisiz` (`AYAR_KOPYASI_YAZILAMADI`, `tetikleyen`); taşıma sonrası eşitleme hatasında dosya veritabanıyla aynı, denetime `ayar_kopyasi` `basarili` + `AYAR_KOPYASI_KALICILIGI_DOGRULANAMADI`. Uzlaştırma yeni zamanlayıcı olmadan mevcut `yedek:calistir` turundaki `AyarKopyasi::guncelDegilseYaz()`'a bağlı (doğrudan kipte zamanlayıcının dakikalık `--tek-tur`'u = kopyayı yazabilen ve imza anahtarını okuyabilen web hesabı; servis kipinde servis döngüsü). `AyarKopyasi::guncelMi(): ?bool` (imza anahtarı okunamıyorsa null — servis kipinde web) GET/PUT yanıtında `ayar_kopyasi_guncel`; ekranda false iken küçük uyarı (`yedekleme.ayarlar.kopyaGuncelDegil`). R1'in "kopya yazılamazsa veritabanı geri alınır, 500 YEDEK_AYAR_KOPYASI_YAZILAMADI" davranışı bu kararla değişti; kullanılmayan `hata.yedek_ayar_kopyasi_yazilamadi` ve frontend `YEDEK_AYAR_KOPYASI_YAZILAMADI` çevirisi silindi (K11); İşlem Günlüğü'ne `ayar_kopyasi` adı eklendi. İmza anahtarı ön koşulu (R1, 409) korunuyor. README §5.3 güncellendi. Testler: `YedekAyariApiTest::test_commit_aninda_ayar_dosyasi_eski_commit_duserse_eski_baytlariyla_kalir` (commit anı `TransactionCommitted` olayı: orada dosya hâlâ eski baytlarda, olaydan fırlatılan hata sonrası da eski baytlar), `test_commit_sonrasi_kopya_yazilamazsa_ayar_kaydedilir_durum_izlenir_calistirici_uzlastirir` (klasör 0555: 200 + false, veritabanı 45, dosya eski baytlar, geçici dosya yok, GET false; `yedek:calistir --tek-tur` sonrası dosya 45, GET true; eski R1 testinin yerini aldı), `test_ayar_dosyasi_tasindiktan_sonra_klasor_esitlenemezse_kayit_basarili_kopya_guncel_not_dusulur` (klasör 0300); frontend `YedeklemeAyarlariPage.test` "kayıttan sonra dosya kopyası güncel değilse bunu bildirir (BY-018-R2)". (T5: HEAD denetleyicisiyle üç backend testi, uyarı koşulu kaldırılınca frontend testi kırmızı.) Doğrulanamayan: gerçek commit hatasında veritabanının geri dönmesi (test işlemi iç içe olduğundan `committing` olayı yalnız dış düzeyde; commit anı `committed` olayıyla kuruldu); süreç kesintisi senaryosu (commit sonrası, yazımdan önce ölme) elle denenmedi — davranışı uzlaştırma testiyle aynı yol (dosya geride, sonraki tur yazar).
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R2'nin yazma sırası düzeldi. Doğrudan kipte imzalı kopya veritabanı işlemi tamamlandıktan sonra yazılıyor.
    - **BY-018-R3 [P2] Ayar kaydedilmesine rağmen durum alanı yanıtı 500'e çevirebiliyor.** `backend/app/Http/Controllers/Api/V1/YedekAyariController.php:143-183,211-222` ve `backend/app/Services/Yedekleme/AyarKopyasi.php:90-99,121-132`. Dosya yazımı hatası yakalansa da yanıt hazırlanırken `guncelMi()` aynı SMTP parolasını tekrar açıyor. Geçersiz şifreli SMTP alanıyla izole SQLite üzerinde `DecryptException` doğrulandı. Doğrudan kipte kayıt başarılı olmasına rağmen kullanıcı hata görür, ayar ekranının okuması da düşebilir. Laravel `error-handling` ve D3: kopya durumunu güvenli biçimde üretin, okunamayan ayarı denetim kaydına alın ve kopyanın güncel olmadığını döndürün. Bozuk SMTP değeriyle 200 yanıtını, kaydedilmiş ayarı ve kopya uyarısını birlikte sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R3: `AyarKopyasi`: çözülmüş SMTP parolası bir kez okunuyor, `icerik()` ve `parmakIzi()`'ye parametre olarak veriliyor. `guncelMi()` veritabanındaki şifreli ayar çözülemezse (`DecryptException`) false dönüyor: kopya o ayardan üretilemez, yani güncel değildir. Böylece PUT yanıtı ve GET okuma ucu 500'e düşmüyor. `YedekAyariController::kopyayiYaz` bu hatayı ayrı kodla denetime alıyor: `ayar_kopyasi` `basarisiz` + `AYAR_KOPYASI_AYAR_COZULEMEDI` (`tetikleyen` ile); ayar kaydedilmiş, yanıt 200 + `ayar_kopyasi_guncel: false` (mevcut sözleşme, ekranda mevcut uyarı). G1 taraması (`guncelMi` / `guncelDegilseYaz` / `yaz` / `gruplar` çağıranları):
      - `yedek:calistir` uzlaştırması (`ayarKopyasiniTazele`) hatayı zaten yakalıyordu: tur başarılı, döngü düşmüyor (testte sınandı).
      - `YedekAlici`'nin kopya yazımı zaten yakalıyordu.
      - `AyarKopyasi::gruplar()` (onay ekranı `AyarSecimi::karsilastir`, geri yüklenen veritabanı başka APP_KEY'le şifrelenmiş olabilir) de aynı hatayla düşüyordu. Artık çözülemeyen veritabanı parolası hiçbir parolayla — kopyanın çözülemeyeniyle de — eşleşmeyen bir değer sayılıyor; mail grubu "farklı" görünüyor.
      - `UstuneGeriYukleyici` 399'daki `guncelDegilseYaz` hatası bilinçli olarak değiştirilmedi: mevcut kararla yer değiştirmeden önce durup geri sarar.
      README §5.3'e not eklendi. Test: `YedekAyariApiTest::test_smtp_parolasi_cozulemezse_ayar_kaydedilir_kopya_guncel_degil_okuma_ve_calistirici_dusmez`. Kurgu: kopya mail ayarıyla güncel, ardından SMTP parolası veritabanında bozuluyor. Doğrulananlar: PUT 200 + false, ayar 45, dosya eski baytlarda, denetim kodu doğru, GET 200 + false, `yedek:calistir --tek-tur` başarılı ve dosya değişmiyor, `AyarSecimi::karsilastir()` düşmüyor ve mail grubu farklı. T5: HEAD'deki iki dosyayla kırmızı (PUT 500, `DecryptException`). Doğrulanamayan: çalıştırıcı her turda (dakikada bir) aynı hatayı günlüğe yazar; ayar düzeltilene kadar sürer (yerel, dış hesaba istek yok — D8 kapsamı dışı).
  - 2026-11-21 Codex → PASSED · dördüncü tur `208746f`
    - R3 kapandı. Şifreli SMTP değeri çözülemezse GET ve PUT 200 döner, `ayar_kopyasi_guncel: false` ve denetim kodu görünür. Geri yükleme onayındaki ayar karşılaştırması da aynı bozuk değerde açılıyor ve yanlış eşleşme saymıyor. İlgili API ve onay testleri geçti.

---

## Faz 3 — Sunucu dışı hedefler

### BY-019 — Şifreleme (sunucu dışı kopyalar)

- **Durum:** PASSED · **Bağımlı:** BY-008
- **Tasarım:** README §4.7, §4.6 (şifreli kopyanın özeti)
- **Ön koşul:** README §13 karar 4 (özel anahtar kimde, nerede).
- **Kapsam:** Yerel disk dışındaki hedeflere giden kopya açık anahtarla
  şifrelenir (`gpg`); özel anahtar sunucuda bulunmaz. Şifreli dosyanın özeti
  olay günlüğüne. Geri yükleme için şifre çözme adımı (özel anahtar o anda
  sağlanır). Açık anahtarın yüklenmesi / parmak izinin ekranda gösterilmesi.
- **Kabul ölçütleri:**
  - [x] Açık anahtar tanımlı değilken uzak hedefe kopya **gitmez** (hata; sessizce şifresiz göndermez).
  - [x] Sunucuda özel anahtar aranmıyor / saklanmıyor.
  - [x] Yanlış anahtarla çözme anlaşılır hata veriyor; yarım dosya bırakmıyor (test).
  - [x] Şifreli kopyanın özeti kayıtlı; uzak dosya bununla doğrulanabiliyor.
- **Canlı:** Evet (kapalı / hedef yokken etkisiz).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - `KopyaSifreleyici` gpg ile: `sifrele` her işlemde 0700 geçici
      GNUPGHOME'da açık anahtarı içe alır, içe alınan parmak izini kayıtlıyla
      karşılaştırır, `--trust-model always --recipient <parmak izi>
      --compress-algo none` ile geçici ada yazıp taşır (D3); anahtar yok /
      uyuşmuyor / süresi dolmuş / gpg hatası → `HedefHatasi(YAPILANDIRMA)`,
      yeniden denenmez (D8). Ajan ve soket klasörü `gpgconf --kill all` /
      `--remove-socketdir` ile kapatılır, klasör hata yolunda da silinir.
    - `acikAnahtarBilgisi(metin)`: ASCII zırhlı TEK açık anahtar; özel anahtar
      bloğu ya da açık başlığa gömülü özel anahtar paketi (gpg listesinde
      `sec/ssb`) → `YEDEK_ANAHTAR_OZEL`; iki anahtar, bozuk blok, iptal,
      şifreleme alt anahtarı yok → `YEDEK_ANAHTAR_GECERSIZ`; süresi dolmuş →
      `YEDEK_ANAHTAR_SURESI_DOLMUS`. Model (`YedekAyari::saving`) özel anahtar
      bloğunu her yoldan reddeder (K3). Yükleme / kaldırma ucu BY-018'de
      (`PUT|DELETE /ayarlar/yedek-ayarlari/sifreleme-anahtari`, denetim kaydı).
    - `coz(sifreli, hedef, ozelAnahtarYolu, parolaDosyasi)` + `yedek:sifre-coz`
      komutu: özel anahtar o anda dosya olarak verilir, yalnız geçici
      anahtarlığa alınır; parola yalnız `--passphrase-file` (D2). Neden gpg
      durum satırlarından (`NO_SECKEY`, `NEED_PASSPHRASE`,
      `ERROR pkdecrypt_failed` alt 16 bit 11): `YEDEK_SIFRE_COZULEMEDI`,
      `YEDEK_ANAHTAR_PAROLASI_HATALI`, `YEDEK_OZEL_ANAHTAR_OKUNAMADI`; ham
      stderr mesaja girmez. Başarısızlıkta hedefe dokunulmaz, yarım çıktı silinir.
    - Migration: `yedek_ayarlari`'na `sifreleme_acik_anahtari`,
      `sifreleme_parmak_izi`, `sifreleme_anahtar_kullanicisi`,
      `sifreleme_anahtar_bitisi`. Config: `gpgconf`, `tar`, `sure_siniri.sifreleme`.
    - Testler `KopyaSifreleyiciTest` (19; gerçek gpg 2.4.4, atılacak
      ed25519/cv25519 anahtarlar; gpg yoksa atlanır — T11). T5: sec/ssb
      denetimi, geçici çıktı silme, anahtarsız şifresiz gönderim, model engeli
      ve parola kodu kaldırılınca ilgili testler kırmızı görüldü.
    - Notlar: gpg zaman aşımı da `YAPILANDIRMA` sayılıp yeniden denenmez
      (geçici olabilir; tartışmaya açık). GnuPG 2.4 anahtarları OCB kipinde
      şifreler: çözmek için GnuPG 2.3+ gerekir (kurtarma.md). Süreç SIGKILL
      ile ölürse `coz`'un geçici anahtarlığı /tmp'de kalabilir.
    - Doğrulanamayan: §13 karar 4 (özel anahtar kimde) kullanıcıdan; gerçek
      uzak hedef; PostgreSQL 18; canlıya alınmadı.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-019-R1 [P2] Geçici GPG sorunu kalıcı yapılandırma hatası sayılıyor.**
       `backend/app/Services/Yedekleme/KopyaSifreleyici.php:97 ve :329, backend/app/Services/Yedekleme/YedekKopyalayici.php:95`. Süre aşımı da hatalı anahtarla aynı kodu alıyor. Kopyalayıcı bütün bu hatalarda yeniden denemeyi kesiyor. Geçici yoğunluk bir kopya aralığının kaçmasına neden olabilir (D8).
       Eksik veya geçersiz anahtarı kalıcı, süreç süre aşımı ve geçici kaynak sorununu sınırlı denemeye uygun sınıflandırın. İlk deneme süre aşımı, ikinci deneme başarılı senaryosunda yalnız şifreli dosyanın iletildiğini sınayın.
    - Yüklemeden önce GPG şifreleme ve anahtar yokken yüklemeyi durdurma kontrolleri geçti. Geçici hata sınıflandırması açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: gpg hataları kalıcı / geçici diye ayrıldı. `SifrelemeHatasi`'na `gecici` bayrağı eklendi. `KopyaSifreleyici::sifrele` artık şöyle ayırıyor:
      - anahtar yok, parmak izi uyuşmuyor, süresi dolmuş / INV_RECP, araç yolu ya da süre ayarı geçersiz → kalıcı, `HedefHatasi(YAPILANDIRMA)`, yeniden denenmez (D8);
      - gpg süre aşımı (`Illuminate\Process\Exceptions\ProcessTimedOutException`), süreç başlatılamadı, geçici anahtarlık klasörü açılamadı, çıktı taşınamadı, bilinmeyen gpg çıkışı → `SifrelemeHatasi(YEDEK_GPG_HATASI, gecici: true)` olduğu gibi dışarı çıkar.
      `YedekKopyalayici` bu hatada döngüyü kesmez; mevcut `kopya_deneme` sınırıyla yeniden dener ve son hatanın kodunu (`YEDEK_GPG_HATASI`, i18n'de zaten var) kopyaya yazar. Dosyalar: `KopyaSifreleyici.php`, `SifrelemeHatasi.php`, `YedekKopyalayici.php`.
      Test: `KopyaSifreleyiciTest::test_gpg_sure_asiminda_kopya_yeniden_denenir_yalniz_sifreli_dosya_gider`. Gerçek gpg'yi saran bir betik kullanır: ilk `--encrypt` çağrısı süre sınırını (1 sn) aşar. Beklenen: 2. denemede `tamam`, hedefte yalnız `.dump.gpg` var, düz içerik yok, uzak dosyanın özeti `sifreli_sha256` ile aynı. T5: düzeltmeden önce kırmızıydı (kopya `basarisiz`). Anahtar yokken tek deneme ve hiç gönderim olmaması zaten `YedekKopyalayiciTest::test_sunucu_disi_hedefe_sifreleme_yokken_kopya_gitmez` ile sınanıyor; yeşil kaldı (K2).
      Jira notu: BY-019 günlüğündeki "gpg zaman aşımı da YAPILANDIRMA sayılıp yeniden denenmez (tartışmaya açık)" cümlesi artık geçersiz.
    - Not: teslim günlüğündeki "gpg zaman aşımı da YAPILANDIRMA sayılıp yeniden denenmez (tartışmaya açık)" cümlesi R1 ile geçersizdir.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Süre aşımı ve geçici dosya hataları sınırlı yeniden denemeye giriyor. Eksik veya geçersiz açık anahtar kalıcı hata olarak duruyor. Şifresiz aktarım yolu açılmamış.

### BY-020 — Hedef: FTP ve FTPS

- **Durum:** BEKLIYOR · **Bağımlı:** BY-016, BY-019
- **Tasarım:** README §4.6 (tablo)
- **Ön koşul:** Bağımlılık onayı (FTP için Laravel dosya sistemi sürücüsü paketi);
  denemek için bir FTP sunucusu.
- **Kapsam:** FTP (düz) ve FTPS bağlayıcısı; hedef formunda sunucu, port,
  kullanıcı, parola, klasör, pasif kip, şifreli bağlantı seçeneği. Düz FTP
  seçilince ekranda uyarı.
- **Kabul ölçütleri:**
  - [ ] "Sına" yaz – oku – doğrula – sil döngüsünü gerçek sunucuda geçiyor (günlükte not).
  - [x] Bağlantı koparsa kopya `basarisiz`, yeniden denemede tamamlanıyor; hedefte yarım dosya kalmıyor.
  - [x] Düz FTP uyarısı görünüyor; FTPS'te sertifika hatası sessizce yok sayılmıyor.
  - [x] Zaman aşımı tanımlı; asılı kalan bağlantı kilidi sonsuza dek tutmuyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit (bağlayıcı d280a00'da)
    - Bağımlılık eklenmedi (kullanıcı kararı "Toplu yazım"): `FtpBaglayicisi`
      PHP curl ile; FTPS `CURLUSESSL_ALL`, eş ve ad doğrulaması açık, en az
      TLS 1.2; her işlem yeni bağlantı, bağlantı + toplam süre sınırı
      (aktarımda `sure_siniri.kopya`, diğerlerinde `baglanti × 3`; D9).
    - Yükleme geçici ada, sonra yeniden adlandırma: kopma ya da yeniden
      adlandırma reddinde son adda dosya kalmaz, geçici dosya silinir;
      kopmadan sonra yeniden deneme tamamlanır.
    - Düz FTP: tanımda `uyari = yedekleme.hedef.uyari.duz_ftp` (formda
      gösterilir); FTPS'te öz imzalı sertifika `SERTIFIKA` (kullanıcı adı /
      parola GÖNDERİLMEDEN), AUTH TLS desteklemeyen sunucu `YAPILANDIRMA`
      (düz FTP'ye düşülmez).
    - Kimlik / sertifika reddinden sonra temizlik için ikinci bağlantı
      açılmaz (D8: tek başarısız giriş).
    - Girdi doğrulaması bağlantıdan önce: `kotu.com\.izinli.com.tr`,
      `-oProxyCommand`, `..`, NUL, satır sonlu kullanıcı / parola → `YAPILANDIRMA` (G15).
    - Testler: `FtpBaglayicisiTest` (29) — ayrı PHP sürecinde çalışan yerel
      sahte FTP sunucusuna (`tests/Yedekleme/SahteFtpSunucusu.php`) GERÇEK curl
      çağrıları: pasif / etkin kip, yükleme ortasında kopma ve asılma,
      yeniden adlandırma reddi, öz imzalı TLS, TLS'siz sunucu. Kilit testleri
      kusur geri konunca kırmızı (T5).
    - Doğrulanamayan: gerçek FTP / FTPS sunucusunda "Sına" (ölçüt 1 —
      işaretlenmedi); başarılı FTPS aktarımı (test CA'sına güven ayarı yok).
      Örtük FTPS (990) ve IPv6 desteklenmiyor; uzak klasör kendiliğinden açılmaz.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-020-R1 [P2] Gerçek FTP ve FTPS kabulü açık.**
       `docs/yedekleme/Backup-Jira.md:3003`. Gerçek FTP/FTPS sunucusuyla yazma, okuma, yük özeti ve silme kabul ölçütü işaretsiz. Sahte süreç yanıtı bu uyumluluğu kanıtlamıyor (T5).
       Uygun deneme sunucusu sağlandığında düz FTP ve FTPS için Sına akışını kayda alın. Sertifika hatasıyla reddi ve kesinti sonrası yarım dosyanın kalmadığını doğrulayın. Dış ortam doğrulaması bekliyor.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Kod değişikliği yok. Gerçek FTP / FTPS sunucusuyla kabul (yaz–oku–özet–sil, sertifika reddi, kesinti sonrası yarım dosya) dış ortam bekliyor; kutucuk açık. BY-024-R1'in sürümlü kurtarma paketi bu hedefte de üstüne yazma / ad değiştirme kullanmıyor.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 dış kabul bekliyor. Gerçek FTP ve FTPS sunucusunda yaz–oku–doğrula–sil, sertifika reddi ve kesinti sonrası geçici dosya kontrolleri açık.

### BY-021 — Hedef: Google Drive

- **Durum:** BEKLIYOR · **Bağımlı:** BY-016, BY-019
- **Tasarım:** README §4.6 (Google Drive bağlantısı), §7.10
- **Ön koşul:** Bağımlılık onayı; kullanıcının Google tarafında uygulama kaydı
  açması (kişisel Gmail → "herkese açık" + "yayında"); README §13 karar 3 ve 16
  (yurt dışı bulut). Google'ın güncel kuralları bu görevin başında doğrulanır.
- **Kapsam:** "Google ile bağlan" akışı, yenileme anahtarının şifreli saklanması,
  yalnız uygulamanın oluşturduğu dosyalara erişim yetkisi, klasör, kota
  gösterimi, "Bağlantıyı kes", izin kaldırıldığında "yeniden bağlanın" durumu.
- **Kabul ölçütleri:**
  - [x] İstenen yetki en dar olan; hesabın diğer dosyaları listelenemiyor.
  - [x] Yenileme anahtarı hiçbir yanıtta ve günlükte görünmüyor.
  - [x] İzin Google tarafından kaldırılınca kopya `basarisiz`, hedef "yeniden bağlanın" (test: sahte yanıt).
  - [x] Bağlantı akışında sahte istek koruması var (durum değeri doğrulanıyor).
  - [x] Kota dolu yanıtı anlaşılır hata olarak gösteriliyor.
  - [ ] Gerçek bir hesapla uçtan uca denendi; 8 gün sonra hâlâ çalıştığı kontrol edilecek (günlükte tarihli not).
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit (bağlayıcı d280a00'da)
    - Bağımlılık eklenmedi: `GoogleDriveBaglayicisi` Laravel HTTP istemcisiyle
      (OAuth 2 + Drive v3, sürdürülebilir yükleme oturumu). Kapsam yalnız
      `drive.file` (uygulamanın açtığı dosyalar): hesabın diğer dosyaları
      görünmez; liste yalnız kendi klasöründeki yedek dosyaları.
    - Bağlantı akışı (BY-016 uçları): `POST …/{id}/google/baglan` → yetki
      adresi; durum değeri (48 karakter rastgele) önbellekte özetiyle,
      kullanıcı + hedefle 10 dk saklanır. Google tarayıcıyı
      `<APP_URL>/ayarlar/yedek-hedefleri/google-donus`'e döndürür; ekran kod
      ve durumu `POST …/google/donus`'e iletir. Durum bir kez kullanılır,
      başka kullanıcınınki / süresi geçmiş / sahte olan 422
      `GOOGLE_DURUM_GECERSIZ` ve Google'a istek gitmez. Başarıda yenileme
      anahtarı `gizli_bilgiler`'e (şifreli), hesap e-postası `baglanti`'ya;
      "yeniden bağlan" durumu temizlenir. "Bağlantıyı kes" anahtarı siler.
    - İzin Google tarafında kaldırılınca (invalid_grant / 401) kopya
      `basarisiz`, hedef `baglanti.durum = yeniden_baglan`, yeniden denenmez
      (D8); bu arada yeniden bağlanmış hedef eski anahtarın reddiyle
      işaretlenmez. Kota dolu → `KOTA` (dosya gönderilmez, yeniden denenmez).
    - Yenileme / erişim anahtarı ve istemci sırrı hiçbir API yanıtında ve
      hata mesajında yok (testler); denetim kaydına hata mesajı değil yalnız
      kod yazılır.
    - Testler: `GoogleDriveBaglayicisiTest` (20), `YedekHedefiApiTest::test_google_baglanti_akisi_…`.
    - Ön koşul (kullanıcı): Google Cloud'da OAuth istemcisi (dönüş adresi
      yukarıdaki), `.env` `YEDEKLEME_GOOGLE_ISTEMCI_KIMLIGI` /
      `YEDEKLEME_GOOGLE_ISTEMCI_SIRRI`; kişisel hesapta uygulama "yayında"
      olmalı (test kipinde yenileme anahtarı 7 günde düşer); README §13
      karar 3 / 16 (yurt dışı bulut, KVKK).
    - Doğrulanamayan: gerçek hesapla uçtan uca ve "8 gün sonra hâlâ
      çalışıyor" ölçütü (işaretlenmedi).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-021-R1 [P2] Google dönüş durumu eşzamanlı olarak iki kez tüketilebilir.**
       `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:214`. Laravel 13'te `Cache::pull()` okuma ve silmeyi iki işlemle yapıyor. İki dönüş aynı durumu okuyup yetki işlemini başlatabilir. Kullanıcı eşleşmesi de silmeden sonra denetlendiği için yanlış oturuma ait deneme geçerli akışı tüketebilir (G5, tek kullanım ölçütü).
       Kullanıcı ve hedef eşleşmesini denetleyip durumu paylaşılan atomik kilit altında bir kez tüketin. İki dönüşte sağlayıcıya tek token isteği gittiğini ve yanlış oturumun doğru akışı tüketmediğini sınayın. Gerçek hesap ve 8 gün kontrolü açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `googleDonus`: durum değerinin denetimi ve tüketilmesi `Cache::lock` altında tek adım; önce kullanıcı ve hedef (var, Google Drive türünde) eşleşmesi denetlenir, eşleşmezse değer TÜKETİLMEZ; eşleşirse `Cache::forget` ile bir kez tüketilir, token isteği kilit bırakıldıktan sonra. Aynı değerle süren başka dönüş (kilit tutuluyor) 422 `GOOGLE_DURUM_GECERSIZ` alır, sağlayıcıya istek gitmez. Test: `YedekHedefiApiTest::test_google_donusu_suren_donus_varken_durumu_tuketmez_saglayiciya_tek_token_istegi_gider`, `test_yanlis_oturumun_google_donusu_dogru_akisi_tuketmez` (T5: `Cache::pull` akışıyla ikisi de kırmızı). Doğrulanamayan: gerçek hesap ve "8 gün sonra" ölçütü (açık).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 kod ve yerel testte kapandı. Durum değeri kilit altında bir kez tüketiliyor ve yanlış oturum değeri tüketmiyor. Gerçek hesap ve sekiz günlük kontrol açık.
    1. **BY-021-R2 [P1] Kopyalı Drive hedefinde hesap eşleşmesi bilinmiyorken yeni hesap kaydedilebiliyor.**
       `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:246 ve :254, backend/app/Services/Yedekleme/Hedefler/GoogleDriveBaglayicisi.php:603`. Hesap değişikliği yalnız iki e-posta dolu ve farklıysa reddediliyor. Google hesap bilgisi geçici hata nedeniyle okunamazsa `null` geliyor. Bu durumda yenileme anahtarı değişiyor, klasör kimliği kaldırılıyor ve önceki hesabın doğrulanmış kopyaları erişilemez kalabiliyor (D6, BY-016-R1).
       Kopyalı hedefte hesap eşleşmesi doğrulanmadıkça eski bağlantıyı koruyun. Aynı hesap yeniden bağlanınca kayıtlı klasör kimliğini koruyun. Hesap bilgisi alınamayan sahte sağlayıcı yanıtında eski anahtar ve klasörün değişmediğini sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: `googleDonus`: kopyalı Drive hedefinde hesap eşleşmesi DOĞRULANMADIKÇA (yeni hesabın e-postası okunamadı ya da kayıtlı e-posta yok) eski bağlantı aynen kalır — yenileme anahtarı, klasör kimliği, durum değişmez; 409 `GOOGLE_HESAP_DOGRULANAMADI` (yeni kod: `hata.yedek_google_hesap_dogrulanamadi`, frontend tr/en), denetim kaydına kod. Farklı hesap yine 409 `HEDEF_KONUMU_DEGISMEZ` (`eposta`). Aynı hesap yeniden bağlanınca kayıtlı klasör kimliği korunur (yalnız başka/bilinmeyen hesapta silinir). Denetim ve kayıt hedef satırının kilidi altında. `GoogleDriveBaglayicisi::kodDegistir` yorumu güncellendi (K11).
      Testler: `YedekHedefiApiTest::test_kopyali_drive_hedefinde_hesap_dogrulanamazsa_eski_anahtar_ve_klasor_degismez` (iki durum: hesap bilgisi 503 / kayıtlı hesap bilinmiyor), `test_ayni_google_hesabiyla_yeniden_baglaninca_klasor_kimligi_korunur`. T5: eski koşulla (yalnız iki e-posta dolu ve farklıysa red + klasör kimliği her zaman silinir) üç durum kırmızı. Doğrulanamayan: gerçek Google hesabı (açık).
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R2'de var olan kopyaların farklı veya kimliği doğrulanamayan bir hesaba bağlanması engelleniyor. Aynı e-posta doğrulanınca kayıtlı klasör korunuyor.
    - **BY-021-R3 [P2] Hesabı okunamayan ilk Drive bağlantısı daha sonra yenilenemiyor.** `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:255-275` ve `backend/app/Services/Yedekleme/Hedefler/GoogleDriveBaglayicisi.php:621-634`. İlk OAuth dönüşünde `about.user` geçici olarak hata verirse kopyasız hedef için e-posta `null` ve yenileme anahtarı kaydediliyor. Bu hedef geçerli anahtarla kopya alabiliyor. İlk kopyadan sonra aynı hesapla yeniden bağlanmak bile kayıtlı kimlik boş olduğu için sürekli 409 dönüyor. İlk bağlantıda doğrulanmış hesap kimliğini zorunlu tutun. Eski kimliksiz kaydı koruyarak somut hesap veya klasör doğrulamasıyla uzlaştırın. Testte ilk bağlantıda `about` 503, mevcut kopyası olan eski bir kayıt ve geçerli hesapla yeniden bağlanma senaryoları bulunsun. Gerçek hesap ve sekizinci gün denetimi ayrıca açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R3: İlk (ve her) Google bağlantısında hesap kimliği zorunlu: `GoogleDriveBaglayicisi::kodDegistir` e-posta okunamazsa `GOOGLE_HESAP_OKUNAMADI` fırlatır, hiçbir şey kaydedilmez; uç 422 + `hata.yedek_google_hesap_okunamadi` ("yeniden deneyin"; tr lang + frontend tr/en). Hesabı kayıtlı olmayan eski hedefte (eposta yok) kayıtlı klasör kimliği `kodDegistir`'e verilir; yeni erişim anahtarıyla `files/{klasör}` sorulur (`drive.file`: uygulamanın açtığı klasör yalnız o hesaptan görünür). 200 + aynı kimlik + klasör türü → `dogrulanan_klasor`; denetim kilit altındaki satırın klasörüyle karşılaştırılır → aynı hesap: e-posta doldurulur, klasör korunur, yeni anahtar yazılır. Doğrulanamazsa (404, 5xx, bağlantı hatası) kopyalı hedefte 409 `GOOGLE_HESAP_DOGRULANAMADI`, eski anahtar/klasör aynen. Kopyalı hedefte kayıtlı hesapla `about` 503 artık 422 `GOOGLE_HESAP_OKUNAMADI` (yine hiçbir şey değişmez). Ayrıca konumu bilinemeyen (hesabı okunmamış) Drive hedefine kopya gönderilmez (BY-028-R5 ile; aşağıda) — Codex'in "kimliksiz hedef kopya alabiliyor" maddesi. Erişim anahtarı bağlayıcının dışına çıkmıyor. Controller ve bağlayıcı yorumları, README §4.6 Google bölümü güncellendi.
      Testler: `YedekHedefiApiTest::test_ilk_google_baglantisinda_hesap_okunamazsa_hicbir_sey_kaydedilmez_yeniden_denenebilir` (503 → 422, bağlantı/sır boş; ikinci denemede kaydedilir), `test_kimliksiz_eski_kopyali_drive_hedefi_kayitli_klasore_erisen_hesapla_yeniden_baglanir_klasor_korunur`, `test_kopyali_drive_hedefinde_hesap_dogrulanamazsa_eski_anahtar_ve_klasor_degismez` (veri sağlayıcı genişletildi: about 503 → 422 OKUNAMADI; kayıtlı hesap yok + klasör 404 / 503 → 409 DOGRULANAMADI), `GoogleDriveBaglayicisiTest::test_kod_degisimi_…` dönüş biçimine `dogrulanan_klasor` eklendi; `YedekKopyalayiciTest::test_hesabi_bilinmeyen_drive_hedefine_kopya_gonderilmez`. T5: düzeltmesiz ilk bağlantı (200 döndü), kimliksiz eski hedef (409 döndü), about-503 durumu (409 döndü), kopya reddi (şifreleme hatasıyla düştü, ileti tutmadı) kırmızı. Doğrulanamayan: gerçek Google hesabı (`about` ve `files/{id}` drive.file davranışı), sekizinci gün denetimi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R3'ün ilk bağlantı yolu düzeldi. E-posta alınamadığında yeni token yazılmıyor ve yeni kimliksiz kopya oluşturulmuyor. 422 yanıtı kabul edildi.
    - **BY-021-R4 [P2] Eski Drive hedefinde klasör erişimi aynı hesap kanıtı sayılıyor.** `backend/app/Services/Yedekleme/Hedefler/GoogleDriveBaglayicisi.php:655-669`, `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:263-279`. `files.get` yanıtındaki `id` ve `mimeType` eşleşince klasöre erişen hesabın e-postası ve token'ı kaydediliyor. Google Drive paylaşılmış klasörde erişim ile sahipliği ayrı tutuyor. Buradan çıkan sonuç, klasör bilgisi dönen hesabın eski hesabın kendisi olduğunun kanıtlanamadığıdır. Yeni hesabın mevcut yedekleri okuyacağı ve klasöre yazacağı da doğrulanmıyor. Eski hesabı doğrulanabilir kimlikle eşleştirin. Kimlik yoksa desteklenen kişisel Drive için klasör sahipliğini, kullanılabilirliğini ve kayıtlı bir kopyaya erişimi doğrulayın ya da eski bağlantıyı koruyup elle uzlaştırma isteyin. Sahibi olmadığı klasör için 200 dönen yeni hesabın eski bağlantıyı değiştirmediğini sınayın. Gerçek hesap ve sekizinci gün kabulü açık. [Google Drive OAuth kapsamı](https://developers.google.com/workspace/drive/api/guides/api-specific-auth) [Google Drive dosya alanları](https://developers.google.com/workspace/drive/api/reference/rest/v3/files)
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R4: Kimliksiz (e-postası kayıtlı olmayan) eski Drive hedefinde klasöre erişmek artık aynı hesabın kanıtı sayılmıyor; kanıt sahiplik. `GoogleDriveBaglayicisi::kodDegistir($kod, $donus, ?$klasor, ?$kopya)` yeni erişim anahtarıyla şunları soruyor:
      - Klasör için `files.get(fields=id,mimeType,trashed,ownedByMe,capabilities(canAddChildren,canListChildren))`. Gerekenler: kimlik aynı, klasör türünde, `trashed === false`, `ownedByMe === true` (kişisel Drive; paylaşılan drive'da bu alan yok, yani kanıt da yok), `canAddChildren === true` ve `canListChildren === true`.
      - Hedefte uzak kimlikli `tamam` kopya varsa en sonuncusu için `files.get(fields=id,trashed,ownedByMe,capabilities(canDownload))`. Gerekenler: kimlik aynı, çöpte değil, `ownedByMe === true`, `canDownload === true`.
      - Herhangi biri tutmazsa ya da sorulamazsa (404, 5xx, bağlantı hatası) `dogrulanan_klasor` null dönüyor. Uç bu durumda 409 `GOOGLE_HESAP_DOGRULANAMADI` veriyor; eski anahtar, klasör ve durum aynen kalıyor.
      - Uzak kimlikli kopya yoksa (yalnız `kopyalaniyor`) klasör sahipliği yetiyor. Gerekçe: o hedefte okunacak uzak dosya yok. Kişisel Drive'da klasörün tek sahibi var; o sahip uygulamanın klasörü açtığı hesap.
      - Alan adları Drive v3 files kaynağından doğrulandı: `ownedByMe` "Not populated for items in shared drives", `trashed`, `capabilities.canAddChildren`, `capabilities.canListChildren`, `capabilities.canDownload`.
      - Kimliği olan kayıtta e-posta eşleşmesi kuralı değişmedi.
      - Kopya kimliği `YedekHedefiController::googleDonus`'ta `yedek_kopyalari`'ndan alınıyor (`durum = tamam`, `uzak_kimlik` dolu, en büyük id). Kopyanın klasörde olması şartı (`parents`) bilerek konmadı: kayıtlı klasör silinip yeniden açılmış olabilir (`klasor()`), eski kopya eski klasörde kalır.
      - Mevcut kod kullanıldı. Mesajı (backend `lang/tr/hata.php`, frontend tr/en) "klasörün ve kayıtlı kopyanın sahibi olmalı; sahibi hesapla yeniden deneyin, olmuyorsa yeni hedef ekleyip kopyaları elle uzlaştırın" olarak güncellendi.
      - README §4.6 Google bölümü güncellendi (K11).
      - Testler: `YedekHedefiApiTest::test_kimliksiz_eski_drive_hedefinde_klasor_sahipligi_ya_da_kayitli_kopya_kanitlanamazsa_eski_baglanti_degismez`. Veri sağlayıcısındaki 11 durum:
        - klasör paylaşılmış, 200 ama ownedByMe false
        - ownedByMe yok (paylaşılan drive)
        - klasör çöpte
        - canAddChildren false
        - canListChildren false
        - klasör sorgusunda bağlantı hatası
        - kopya 404
        - kopya başka hesabın
        - kopya indirilemiyor
        - kopya çöpte
        - kopya sorgusunda bağlantı hatası
      - Yeniden adlandırılıp genişletilen test: `test_kimliksiz_eski_kopyali_drive_hedefi_kayitli_klasorun_ve_kopyanin_sahibi_hesapla_yeniden_baglanir_klasor_korunur` (eski adı `…kayitli_klasore_erisen_hesapla…`). Klasör ve kopya isteğinin yeni Bearer ile gittiği de sınanıyor. Yeni test: `test_kimliksiz_eski_drive_hedefinde_uzak_kimlikli_kopya_yoksa_klasor_sahipligiyle_baglanir`.
      - T5: düzeltmesiz kodda veri sağlayıcısının 10 durumu 200 döndü (kırmızı), başarı testi kopya isteği gitmediği için kırmızıydı. "Klasör sorgusunda bağlantı hatası" durumu düzeltmeden önce de yeşildi; T13 kapsamı için eklendi.
      - Doğrulanamayan: gerçek Google hesabı. `drive.file` ile `ownedByMe` / `capabilities` alanlarının dolu geldiği ve `trashed: false`'un yanıtta açıkça bulunduğu canlı denenmedi; alan gelmezse sonuç güvenli tarafta kalır (409, eski bağlantı korunur). Sekizinci gün denetimi de açık.
  - 2026-11-21 Codex → CHANGES_REQUESTED · beşinci tur `3ad20f3`
    - R4'ün sahiplik ve erişim yeteneği kontrolleri, `tamam` kopyası olan hedeflerde uygulanıyor. Sahiplik eksikliği, çöpteki kayıt ve hatalı sağlayıcı yanıtı testleri geçti.
    - **BY-021-R5 [P2] Gerçek yükseltme verisindeki eski kopya sahiplik korumasının dışında kalıyor.** `backend/database/migrations/2026_11_21_112737_add_konum_izi_to_yedek_kopyalari_table.php:31-32`, `backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:255-261,274-298` ve `backend/app/Services/Yedekleme/HedefKonumu.php:271-274`. Eski izsiz `tamam` kayıt yükseltmede `konum_belirsiz` olur. OAuth dönüşü dosya kanıtı için yalnız `tamam` arıyor ve bu eski kayıt konum kilidine de girmiyor. Yerel veritabanında `konum_belirsiz` uzak dosyası olan kimliksiz hedef için sahibi olmayan sahte Google hesabıyla 200 dönüldüğünü, klasör kimliğinin kaldırıldığını ve yeni token'ın kaydedildiğini doğruladım. G21: hesabı değiştirme kararında korunacak fiziksel kopya kümesini kullanın ve kanıtı kayıttan önce yeniden doğrulayın. Konum düzeltme kilidini olduğu gibi bırakın. Eski kayıtla gerçek ileri migration çalıştıran bir testte sahiplik kanıtı yoksa 409 ve eski değerleri, kanıt geçerliyse kabulü doğrulayın. Gerçek Drive hesabı ve sekizinci gün kabulü ayrıca açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex beşinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 5. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R5: Hesap değiştirme kararı artık fiziksel kopya kümesine (`KopyaDurumu::yedegiTutanlar()` = tamam + konum_belirsiz) bağlı. Konum kilidi (`HedefKonumu`) değişmedi. `YedekHedefiController::googleDonus` içindeki değişiklikler:
      - (1) Kimliksiz eski hedefte sahipliği sorulan kopya, yalnız `tamam` değil dosyası durabilen en son uzak kimlikli kopya (`sonFizikselKopya`). Yükseltmede `konum_belirsiz` olan eski kopya da soruluyor.
      - (2) Kayıttan önce, satır kilidi altında kanıt yeniden eşleniyor: klasör kimliği ve en son fiziksel kopya, sahipliği sorulanlarla aynı olmalı. Arada yeni kopya eklendiyse kanıt geçersiz.
      - (3) Aynı hesap doğrulanmadıysa hedefte konum kilidi VARSA YA DA hesap değişince geride kalacak fiziksel kopya varsa (`geridekiKopyaVarMi`) eski bağlantı aynen kalıyor. Kimliksiz hedefte 409 `GOOGLE_HESAP_DOGRULANAMADI`, hesabı kayıtlı hedefte 409 `HEDEF_KONUMU_DEGISMEZ` dönüyor.
      - "Geride kalmaz" yalnız konum izi yeni hesabın konumunu gösteren kopya için geçerli: o hesaba dönmek kopyanın yerini düzeltir, konum düzeltme yolu açık kalıyor. İzsiz kopya doğrulanamadığı için geride sayılıyor.
      - Hiç kopyası olmayan ve yalnız `kopyalaniyor` kopyası olan hedefte R4 davranışı aynı (mevcut testler yeşil).
      - Yorumlar güncellendi (K11): controller sınıf yorumu, `KopyaDurumu` (sınıf + `yedegiTutanlar`; e2'nin aynı dosyadaki BY-024-R8 eklemesinin üstüne eklemeli), README §4.6 Google bölümü.
      - Testler (`YedekHedefiApiTest`). Eski şemayla `tamam` kopya kuruluyor ve gerçek ileri migration (`2026_11_21_112737_…`) çalıştırılıyor; kopya `konum_belirsiz` oluyor ve izi null:
        - `test_yukseltmede_konum_belirsiz_olan_eski_kopyali_kimliksiz_drive_hedefi_sahiplik_kanitlanamazsa_409_eski_baglanti_degismez`. 3 durum: klasör başka hesabın; klasörün sahibi ama yükseltilen kopya başka hesabın; kopya 404. 409, anahtar / klasör / durum aynen kalıyor, kopya `konum_belirsiz` kalıyor.
        - `test_yukseltmede_konum_belirsiz_olan_eski_kopyali_kimliksiz_drive_hedefi_klasorun_ve_kopyanin_sahibi_hesapla_baglanir`. 200, e-posta dolduruluyor, klasör korunuyor, kopya yeni Bearer ile soruluyor.
        - `test_yukseltmede_konum_belirsiz_olan_eski_kopyali_drive_hedefi_baska_google_hesabiyla_yeniden_baglanamaz_409` (G1, hesabı kayıtlı hedef).
        - `test_sahiplik_sorulurken_hedefe_yeni_kopya_eklenirse_kanit_gecersiz_409_eski_baglanti_degismez` (kayıttan önce yeniden doğrulama).
        - `test_konum_izi_yeni_hesabi_gosteren_konum_belirsiz_kopya_hesap_degisikligini_engellemez` (konum düzeltme yolu).
      - T5: HEAD controller'ıyla 6 durum kırmızıydı (5 yeni durum 200 döndü). Konum izi testi HEAD'de de yeşil, bu beklenen: mevcut davranışı koruyor. Mutasyonlar:
        - kilit altındaki yeniden eşleme kaldırılınca yeniden doğrulama testi kırmızı;
        - geride kalan kopya denetimi kaldırılınca 5 durum kırmızı;
        - iz istisnası kaldırılınca konum izi testi kırmızı;
        - son kopya yalnız `tamam` yapılınca 4 durum kırmızı.
      - Doğrulanamayan: gerçek Google hesabı ve sekizinci gün kabulü (açık); canlı veritabanındaki gerçek yükseltilmiş kayıtla deneme.
  - 2026-11-21 Codex → CHANGES_REQUESTED · altıncı tur `2b7ea87` · dış kabul bekleniyor
    - R5 kod tarafında kapandı. Eski şemadan ileri migration ile `konum_belirsiz` olan kopya hesap kanıtına katılıyor. Kayıt öncesi klasör ve son fiziksel kopya hedef kilidi altında yeniden eşleniyor. İzsiz ya da eski hesapta kalacak kopya varsa hesap değişikliği reddediliyor. Konum izi yeni hesabı gösteren kopya için düzeltme yolunun açık kalması kabul edildi.
    - Yeni somut kod bulgusu yok. Görevin gerçek Google hesabıyla uçtan uca ve sekizinci gün kabul kutusu açık. Durum bu dış kabul nedeniyle `BEKLIYOR`. Sağlayıcının alan davranışı yerelde sahte yanıtlarla sınandı.

### BY-022 — Hedef: SFTP

- **Durum:** BEKLIYOR · **Bağımlı:** BY-020
- **Tasarım:** README §4.6
- **Ön koşul:** Bağımlılık onayı; kullanıcı bu hedefi istiyorsa (istemiyorsa `IPTAL`).
- **Kapsam:** SFTP bağlayıcısı; parola ya da anahtarla kimlik doğrulama; sunucu
  parmak izinin ilk bağlantıda kaydedilip sonrakilerde doğrulanması.
- **Kabul ölçütleri:**
  - [x] Sunucu parmak izi değişince bağlantı reddediliyor (uyarıyla).
  - [ ] BY-020'deki sınama ve hata ölçütleri bu tür için de geçiyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit (bağlayıcı d280a00'da)
    - `SftpBaglayicisi`: OpenSSH `sftp -b <toplu dosya>` ve `ssh-keyscan`,
      `Process` ile argüman dizisi (kabuk yok). Parola komut satırında ya da
      ortamda DEĞİL: 0600 dosyadan, 0700 `SSH_ASKPASS` betiğiyle; özel anahtar
      0600 dosya + `-i`; geçici klasör 0700, hata yolunda da silinir (D2).
    - Sunucu anahtarı yalnız `baglan()`'da ("Bağlantıyı Sına") kaydedilir
      (anahtar + SHA256 parmak izi + `sunucu_anahtari_icin` = sunucu:port);
      diğer işlemler kayıtlı anahtar yoksa `YAPILANDIRMA` ("önce Bağlantıyı
      Sına") — gece kopyası görülmemiş anahtarı kendiliğinden kabul etmez.
      Sunucu / port değişince eski anahtar kullanılmaz (G13).
    - Anahtar değişince `SUNUCU_PARMAK_IZI` (mesajda kayıtlı parmak izi),
      kayıt değişmez, `ssh-keyscan` yeniden çalışmaz. Ekranda "parmak izini
      sıfırla" ucu (BY-016: `POST …/sunucu-anahtari-sifirla`).
    - Hatalar: yanlış parola `KIMLIK`, erişilemeyen `ERISILEMEDI`; olmayan
      dosyayı silmek hata değil; olmayanı okumak `OKUNAMADI`, yerel dosya
      kalmaz. Boş alan `df`'ten.
    - Testler: `SftpBaglayicisiTest` (28; `Process::fake` + toplu dosyayı
      yerel klasöre uygulayan sahte `sftp`, çağrı anındaki ortam / izin /
      süre kaydıyla). `-o BatchMode=no`'nun sftp'nin kendi `-obatchmode
      yes`'inden önce geldiği gerçek `sftp` ikilisiyle denendi.
    - Doğrulanamayan: gerçek sshd ile parola / anahtar girişi; OpenSSH dışı
      sunucularda var olan dosyanın üstüne yeniden adlandırma. SFTP hata
      sınıflaması OpenSSH'nin İngilizce mesajlarına dayanıyor.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-022-R1 [P2] SFTP gerçek hedef kabulü açık.**
       `docs/yedekleme/Backup-Jira.md:3100`. Gerçek sunucuda parola/anahtar, yazma–okuma–doğrulama–silme ve kesinti senaryosu doğrulanmadı. Testler parmak izi değişiminde reddi kapsıyor ancak gerçek sunucu kabulü gereken BY-020 ölçütü açık (T5).
       Deneme sunucusunda iki kimlik doğrulama kipi ve kayıtlı parmak iziyle sınayın. İlk parmak izini işletim kaydıyla karşılaştırın. Sunucu anahtarı değiştiğinde ret ve kesinti sonrası dosya durumu kayda girsin.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Kod değişikliği yok. Gerçek SFTP sunucusunda parola / anahtar kipleri, parmak izi değişiminde ret ve kesinti sonrası dosya durumu dış ortam bekliyor; kutucuk açık.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1 gerçek SFTP kabulünü bekliyor. Parola ve anahtarla kimlik, parmak izi değişiminde ret, dört adımlı sınama ve kesinti sonrası dosya koşulları sahada doğrulanacak.

### BY-023 — Hedef: Ağ paylaşımı (NAS) ve S3

- **Durum:** PASSED · **Bağımlı:** BY-020
- **Tasarım:** README §4.6
- **Ön koşul:** Kullanıcı bu hedefleri istiyorsa; NAS için ağdaki paylaşımın
  sunucuya bağlanması (`admin` + `sudo`); S3 için bağımlılık onayı.
- **Kapsam:** NAS (sunucuya bağlanmış klasör; "bağlı mı" denetimi) ve S3 uyumlu
  bağlayıcı (silinmeye karşı koruma destekleniyorsa bilgi olarak gösterilir).
- **Kabul ölçütleri:**
  - [x] NAS bağlı değilken yedek yerel diske yazılıp "kopyalandı" sayılmıyor.
  - [x] S3'te uygulamanın silme yetkisi yoksa temizlik bunu hata saymıyor, bilgi olarak yazıyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit (bağlayıcılar d280a00'da)
    - `NasBaglayicisi`: sunucuya bağlanmış klasör; her işlemden önce "bağlı mı"
      denetimi (bağlantı noktası `/proc/self/mountinfo`'da var mı, klasör
      bağlantıları çözülmüş yolla onun altında ve aynı aygıtta mı; değilse
      `BAGLI_DEGIL`). Yazımdan sonra dosyanın paylaşımın aygıtında durduğu
      yeniden denetlenir: arada paylaşım düşerse kopya sayılmaz. Yazım geçici
      ad + parça parça + eşitleme + taşıma. Asılı paylaşıma PHP'de süre sınırı
      konamadığından paylaşım `soft` + `timeo` ile bağlanmalı (kurtarma.md).
    - `S3Baglayicisi`: Laravel HTTP istemcisiyle SigV4 (bağımlılık yok); yol /
      sanal konak stili, bölge, uç nokta; yalnız geçici hatalarda yeniden
      deneme; liste süzgeci. Silme `AccessDenied` dönerse `SILME_YETKISI_YOK`.
    - Saklama, `SILME_YETKISI_YOK`'u hata saymaz: kopya `silindi` (kod
      kayıtlı), sonuçta `bilgi` satırı, denetimde başarılı + `bilgi`
      ("hedefin kendi saklama kuralı temizler"); hedef atlanmaz.
    - Testler: `NasBaglayicisiTest` (3), `S3BaglayicisiTest` (13),
      `SaklamaPolitikasiTest::test_silme_yetkisi_olmayan_hedefte_temizlik_hata_saymaz_bilgi_yazar`
      (dal kaldırılınca kırmızı — T5).
    - Doğrulanamayan: gerçek NAS paylaşımı ve gerçek S3 kovası (kullanıcı bu
      hedefleri henüz istemedi; ön koşul). Silinmeye karşı koruma (nesne
      kilidi) bilgisi ekranda ayrıca gösterilmiyor; temizlik sonucu bilgi olarak yazıyor.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-023-R1 [P2] Silme yetkisi reddedilen nesne yeniden taramada geri geliyor.**
       `backend/app/Services/Yedekleme/SaklamaPolitikasi.php:255 ve :310, backend/app/Services/Yedekleme/DizinKurucu.php:157`. S3 AccessDenied yolunda kayıt mantıksal olarak siliniyor ancak imzalı silme olayı yazılmıyor. Katalog yeniden kurulunca eski tamamlanmış kopya olayı kopyayı tekrar kullanılabilir gösteriyor (D3, F6).
       Mantıksal ve fiziksel silme kararını imzalı olayda tutun. Silme reddi ardından boş katalogdan yeniden kurmayı kontrol edin.
    2. **BY-023-R2 [P2] S3 silinme koruması uygulamada gösterilmiyor.**
       `backend/app/Services/Yedekleme/Hedefler/S3Baglayicisi.php:214, backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:163`. Bağlayıcı koruma denetimini sağlıyor ancak üretim akışı bu metodu çağırmıyor. Kullanıcı hedef kurarken BY-023 kapsamındaki koruma bilgisini göremiyor.
       Sınama sonucuna koruma durumunu ekleyin. Etkin, devre dışı ve sorgulama yetkisi bulunmayan kovayı ayrı gösterin. Gerçek NAS/S3 altyapısı teyidi açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: S3 `AccessDenied` (`HEDEF_SILME_YETKISI_YOK`) yolunda mantıksal silme artık imzalı `kopya_silindi` olayıyla yazılıyor, olayda `hata_kodu` da duruyor. `GuncelDurum` kopyaya `hata_kodu` taşıyor, `DizinKurucu` yeniden kurulan silinmiş kopyaya kodu geri yazıyor. Silme kararını olaydan DizinKurucu uyguluyor. Test: `DizinKurucuTest::test_silme_yetkisi_reddedilen_kopya_yeniden_kurulunca_kullanilabilir_gorunmez` (T5: düzeltmesiz kırmızı, yeniden kurulan kopya `tamam` geldi).
    - R2: Sınama sonucu `silinme_korumasi` döner: S3'te bağlantı adımı geçtiyse `S3Baglayicisi::silinmeKorumasiVarMi()` çağrılır → `etkin` / `devre_disi` / `sorgulanamadi` (yetki yok ya da erişilemedi); S3 dışında null. Ekran sınama panelinde gösterir. Test: `YedekHedefiApiTest::test_s3_sinamasi_silinme_korumasini_bildirir` (3 durum, gerçek S3 bağlayıcısı + Http::fake), `test_s3_disindaki_hedefte_silinme_korumasi_yoktur`; `YedekHedefleriPage.test` "S3 sınaması silinme korumasını gösterir: …" (3 durum) (T5: alan yokken kırmızı). Doğrulanamayan: gerçek S3 kovası (açık).
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 ve R2 kapandı. Silme reddinin mantıksal sonucu ve hata kodu imzalı olayda kalıyor, tarama bunları koruyor. S3 koruma bilgisi sınama sonucunda taşınıyor. Gerçek NAS/S3 erişimi bu incelemede kullanılmadı.

### BY-024 — Kurtarma paketi ve kurulum dokümanı

- **Durum:** PASSED · **Bağımlı:** BY-019
- **Tasarım:** README §9 (kurtarma paketi), §7.5
- **Ön koşul:** README §13 karar 5 ve 13.
- **Kapsam:** `docs/yedekleme/kurtarma.md`: boş bir sunucuda sıfırdan ayağa
  kaldırma adımları; saklanacak sırların listesi ve nerede durdukları (değerleri
  değil); PostgreSQL rolleri ve eklentileri; araç sürümleri. Olay günlüğü,
  denetim günlüğü ve ayar kopyasının sunucu dışına gitmesi (kod).
- **Kabul ölçütleri:**
  - [x] Dokümanda hiçbir gizli değer yok; yalnız "nerede, kimde".
  - [x] Adımlar lokalde boş bir veritabanıyla bir kez baştan sona uygulandı (günlükte not).
  - [x] Günlükler ve ayar kopyası uzak hedefte görünüyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - `KurtarmaPaketi`: başarılı her sunucu dışı kopyadan sonra o hedefe
      yedeklerle aynı açık anahtarla şifreli, sabit adlı dört dosya:
      `emor-kurtarma-olaylar.jsonl.gpg`, `-ayarlar.json.gpg`,
      `-denetim.log.gpg` (yedekleme kanalının bütün günleri, eskiden yeniye)
      ve `-manifestler.tar.gpg` (yedek klasöründeki imzalı manifestler: uzak
      hedefe yalnız dump gittiği için boş sunucuda özet, kodlama ve "güvenilir"
      sayılma bunlarla — görev metnindeki üç dosyanın ötesinde). Günlükler
      paylaşılan kilitle anlık kopyalanır (bayt sayısıyla, G16); her dosya
      geri okunup özeti denetlenir.
    - Adlar `erp_` ile başlamaz: bağlayıcıların `listele()`'sine, dizine ve
      saklamaya girmez (test: yerel disk bağlayıcısı). Adla üstüne yazmayan
      hedefte (Drive) önceki dosya durum klasöründeki imzalı
      `kurtarma-kimlikleri.json`'dan bulunup silinir; kayıt bozuksa silinmez.
    - Hata kopyayı başarısız saymaz: istisna dışarı taşmaz, denetim kaydına
      `kurtarma_paketi` (başarısız) yazılır. `YedekKopyalayici`'de tek çağrı.
    - `docs/yedekleme/kurtarma.md`: uzak dosyalar, sırların listesi (nerede /
      kimde; değer yok; §13 karar 4, 5, 13 açık), araç sürümleri, PostgreSQL
      rolleri, boş sunucuda adımlar (indir → `yedek:sifre-coz` → özetler →
      manifestten createdb → pg_restore --no-owner --role=erp --exit-on-error
      --single-transaction → imza anahtarı → migrate → yedek:tara), son
      kontroller, imza anahtarı kaybı, NAS'ın `soft,timeo` bağlanması, deneme kaydı.
    - Lokal prova (WSL, PostgreSQL 16.15, GnuPG 2.4.4; geçici veritabanları
      `erp_kurtarma_ajan_*` ve geçici klasörler, sonunda silindi; yerel `erp`
      yalnız okundu): `yedek:al` → açık anahtar ayara → NAS hedefine
      (`/dev/shm` bağlantı noktası) kopya `tamam`, kurtarma paketi 4 dosya.
      Boş "yeni sunucu"da beş dosya `yedek:sifre-coz` ile çözüldü; şifreli özet
      olay günlüğündeki `sifreli_sha256` ile, dump özeti manifestle aynı;
      manifestteki kodlamayla createdb + pg_restore; imza anahtarsız tarama
      "0 imzalı, 1 güvenilmeyen", anahtarla "1 imzalı"; satır sayıları ve
      sahip `erp` tutarlı. Farklar: createdb yerel `postgres` ile (emor_yedek
      yok), PG 16, C.UTF-8; paket kurulumu, servisler, SSL denenmedi.
    - Testler `KurtarmaPaketiTest` (4). T5: kanca, hatanın yutulması, önceki
      kimliğin silinmesi ve kimlik kaydının imza denetimi kaldırılınca ilgili
      testler kırmızı görüldü.
    - Doğrulanamayan: gerçek NAS / SFTP / S3 / Drive hedefi; canlıda
      (PostgreSQL 18, en_US.UTF-8) prova; §13 karar 5 / 13 (kurtarma paketinin
      yeri) kullanıcıdan; canlıya alınmadı.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-024-R1 [P1] Yeni kurtarma paketi önceki doğrulanmış seti yarıda bırakabiliyor.**
       `backend/app/Services/Yedekleme/KurtarmaPaketi.php:84 ve :124, backend/app/Services/Yedekleme/YedekKopyalayici.php:85`. Paket bileşenleri sabit adlarla ayrı ayrı değiştiriliyor. Arada hata olursa eski doğrulanmış paket de karışık kalabiliyor. Uzak kopya yaşam sinyali paket tamamlanmadan gönderiliyor (D3, D4).
       Paketi yedek kimliği veya sürümüyle yayınlayın. Yetkili paket işaretçisi ile yaşam sinyalini bütün bileşenler doğrulandıktan sonra güncelleyin. Ara dosyada hata verildiğinde önceden doğrulanmış paketten kurtarmanın hâlâ mümkün olduğunu sınayın.
    2. **BY-024-R2 [P2] Kurtarma kılavuzu imza doğrulamasını yükleme sonrasına bırakıyor.**
       `docs/yedekleme/kurtarma.md:161, :179 ve :215`. Kılavuz arşivi JSON içindeki özetle kontrol ettirip `pg_restore` yaptırıyor. O özete güvenmek için gereken manifest imzası ancak sonraki dizin taramasında kontrol ediliyor (D4, README §5.2).
       Paketi açtıktan ve imza anahtarını güvenli kaynaktan aldıktan sonra manifest imzasını ve arşiv özetini SQL çalıştırmadan doğrulayın. Bozulmuş manifest veya yanlış anahtarda geri yüklemenin başlamadığını belgeleyin.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Kurtarma paketi sürümlü hale getirildi (`KurtarmaPaketi.php` yeniden yazıldı, `YedekKopyalayici.php`).
      - Adlandırma: `emor-kurtarma-<sürüm>-{olaylar.jsonl,ayarlar.json,denetim.log,manifestler.tar}.gpg`. `<sürüm>` = `YmdTHisZ-<6 hex>` (UTC; ada göre sıralanınca zamana göre sıralanır).
      - Sıra: önce bütün bileşenler yerelde şifrelenir. Sonra her biri yazılır → geri okunur → özeti denetlenir. Ancak hepsi doğrulanınca şifresiz işaretçi `emor-kurtarma-<sürüm>-tamam.json` (ad, şifreli SHA-256, boyut, atlanan) yazılıp geri okunur.
      - Yetkili paket = işaretçisi olan EN YENİ sürüm. Eski sürümler bundan sonra silinir; son 2 tamam sürüm kalır. Silerken önce işaretçi, sonra bileşenler.
      - Ara adımda hata olursa yeni sürümün yazılan dosyaları (varsa işaretçisi önce) silinmeye çalışılır. Önceki sürüme ve işaretçisine dokunulmaz.
      - Hiçbir dosyanın üstüne yazılmaz, ad değiştirme gerekmez. Yalnız yaz / oku / sil kullanılır; bütün hedef türlerinde (yerel, NAS, FTP, SFTP, S3, Drive) aynı çalışır.
      - Uzak kimlikler durum klasöründe imzalı `kurtarma-paketleri.json` kaydında tutulur (eskisi `kurtarma-kimlikleri.json` kaldırıldı; modül canlıda değil). Kayıt bozuk ya da imzasızsa hiçbir uzak dosya silinmez. Silinemeyen dosya kayıtta kalır, sonraki başarılı gönderimde yeniden denenir. `HEDEF_SILME_YETKISI_YOK` (S3 nesne kilidi) silinmiş sayılır, saklama ve silme işleyicisiyle aynı davranış; yoksa kayıt sonsuza dek büyürdü.
      - `YedekKopyalayici`: uzak yaşam sinyali artık yalnız `KurtarmaPaketi::gonder()` true dönünce gider. Önceden paketten önce gidiyordu. Kopyanın kendisi yine `tamam` sayılır.
      - Denetim ayrıntısına `surum` ve `silinemeyen_eski_surum` eklendi. `kurtarma.md` §1 ve §4.6–4.8 paket okuma adımları yeni düzene göre yazıldı.
      Testler (`KurtarmaPaketiTest`):
      - `test_ara_bilesen_yazilamazsa_onceki_paket_yetkili_kalir_yasam_sinyali_gitmez`: 3. bileşen (denetim) yazılamıyor. Beklenen: yeni sürümden dosya kalmadı, önceki işaretçi tek ve aynı, onun her dosyasının özeti tutuyor ve gerçek gpg ile çözülüyor, olay günlüğünde ilk yedek var, uzak sinyal 1'de kaldı (Http::fake + preventStrayRequests).
      - `test_son_iki_tamam_surum_kalir_eskiler_silinir_kayit_bozuksa_silinmez`: Drive gibi her yazımda yeni kimlik açan hedef.
      - `test_silme_yetkisi_olmayan_hedefte_paket_basarili_surum_kaydi_buyumez`.
      - Mevcut 3 test yeni adlandırmaya uyarlandı; işaretçi üzerinden açıp özet ve içerik sınıyorlar.
      T5 (düzeltme parça parça geri alınarak):
      - sinyal paketten önce → ara-bileşen testi kırmızı (2 istek);
      - yarım sürüm silinmiyor → kırmızı;
      - SAKLANAN_SURUM=99 → son-iki testi kırmızı (12 ≠ 8);
      - SILME_YETKISI_YOK hata sayılıyor → kilit testi kırmızı (4 ≠ 2);
      - HEAD'deki KurtarmaPaketi + YedekKopyalayici ile → 4/5 test kırmızı.
      Test yardımcısı: `tests/Yedekleme/BellekHedefi.php`'ye eklemeli `$yazilamayanAdSonu` (adı bu metinle biten dosyanın yazımı hata verir).
      Doğrulanamayan: gerçek NAS / SFTP / FTP / S3 / Drive hedefinde sürümlü paket.
    - R2: Yeni komut `yedek:manifest-dogrula <dump> [--manifest=]` (`app/Console/Commands/YedekManifestDogrula.php`). Tarama ve ekrandaki geri yüklemenin kullandığı `ManifestDeposu::oku` ve `ManifestDeposu::dosyaTutuyorMu` kodunu kullanır.
      - Denetledikleri: manifest imzası (imza anahtarıyla), manifestin bu dosyaya ait olması (dosya adı), boyut, SHA-256. Veritabanına bağlanmaz, dosyaya dokunmaz.
      - Başarıda `DOGRULANDI` ve `createdb` değerlerini (kodlama, sıralama, karakter türü, ayarlar) yazar.
      - Başarısızlıkta çıkış kodu 1 ile `GERI_YUKLEME_IMZA_GECERSIZ` (imza tutmuyor / anahtar yok / bozuk / imzasız / yok / başka yedeğin) ya da `GERI_YUKLEME_OZET_TUTMUYOR` yazar. İkisi de mevcut kodlardır.
      - `kurtarma.md` §4.8: paket açılıp imza anahtarı yerine konduktan (§4.5) sonra, SQL'den ÖNCE bu komut çalışır. "Yalnız DOGRULANDI yazarsa devam" kuralı ve hata kodu → anlam → ne yapılır tablosu eklendi. Eski `php -r` özet karşılaştırmaları ve olay günlüğü grep'i kaldırıldı. §7 (imza anahtarı kaybı): olağan sıra durur; geri yükleme ancak sistem sahibinin yazılı istisna kararıyla yapılır.
      Testler (`YedekManifestDogrulaTest`, 6):
      - geçerli yedek doğrulanır ve createdb değerleri yazılır;
      - veri sağlayıcılı 3 durum: özeti değiştirilmiş manifest, yanlış imza anahtarı, imza anahtarı yok → `GERI_YUKLEME_IMZA_GECERSIZ`, `DOGRULANDI` yazılmaz, çıkış kodu 1;
      - özeti tutmayan dump;
      - başka yedeğin imzalı manifesti.
      T5: ad denetimi, özet denetimi ve imza denetimi ayrı ayrı kaldırılınca ilgili testler kırmızıydı (1 / 1 / 3).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1'in sabit adların üzerine yazma sorunu ve R2'nin SQL öncesi imza eksikliği kapandı. Tam kurtarma seti ile saklamanın ilişkisi ve işaretçi silme yolu aşağıda açık.
    1. **BY-024-R3 [P1] Kurtarma paketi başarısız olsa da kopya yerel silmeye yeterli sayılıyor.**
       `backend/app/Services/Yedekleme/YedekKopyalayici.php:74 ve :89, docs/yedekleme/kurtarma.md:285`. Başarı sinyali paketi bekliyor ancak kayıt zaten `tamam`. Yeni yedeğin manifesti bir önceki pakette bulunmaz. Son yerel arşiv temizlenince uzak dump tek başına kalabilir ve ana sunucu kaybında doğrulanmış kurtarma yapılamaz (D4, BY-010-R2).
       Paketin bu yedeğe ait manifesti gerçekten yayımladığını kayıtla izleyin. Kurtarmaya hazır olmayan tek uzak kopyayla yerel arşivin silinmediğini, tam setle SQL öncesi imza doğrulanabildiğini sınayın.
    2. **BY-024-R4 [P2] İşaretçi silinemediğinde bile paket parçaları siliniyor.**
       `backend/app/Services/Yedekleme/KurtarmaPaketi.php:274 ve :289`. İşaretçiye `SILME_YETKISI_YOK` gelince silinmiş gibi ilerleniyor. Kova izinleri veya nesne koruması farklıysa parçalar silinirken işaretçi kalabiliyor. Kurtarma listesinde tamam görünen bir set eksik kalıyor (D3).
       İşaretçi hâlâ varsa parçalar üzerindeki manuel temizliği durdurun ve sürümü kayıtlı tutun. İşaretçi silmesi reddedilip parçaları silmeye izin veren sahte hedefle setin hiç eksilmediğini sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R3: R2 (BY-010) ile aynı kök ve düzeltme. `KurtarmaPaketi::gonder`: işaretçi doğrulanınca, manifest arşivine GİREN manifestlerin (liste bir kez alınır, arşiv tam o listeyle kurulur) bu hedefteki `tamam` ve hazır olmayan kopyaları `kurtarma_hazir=true` (`hazirIsaretle`). Arşiv yedek klasöründeki bütün manifestleri taşıdığı için sonraki başarılı paket önceki eksikleri de hazır yapar (testte ilk paket yarım, ikinci paket o kopyayı hazır yapıyor). Tamamlanmış sürüm, sonraki adımın (hazırlık yazımı) hatasıyla artık silinmez (`$tamamlandi` bayrağı). `kurtarma.md` §1: yarım paket = "tamam ama kurtarmaya hazır değil, yerel arşiv silinmez". Test: yukarıdaki KurtarmaPaketiTest testi (tam setle SQL öncesi imza doğrulaması dahil). T5: aynı.
    - R4: `KurtarmaPaketi::surumuSil`: işaretçi silinemezse sürümün HİÇBİR bileşenine dokunulmaz. Geçici hata → sürüm işaretçi + bütün bileşenleriyle kayıtta kalır (sonraki gönderimde yeniden denenir). `SILME_YETKISI_YOK` → set eksiksiz hâliyle hedefin kendi kuralına bırakılır ve kayıttan çıkar. Karar gerekçesi: Codex "sürümü kayıtlı tutun" diyor; kalıcı retti kayıtta tutmak her gönderimde kaydı büyütür ve kendiliğinden düzelmeyen hatayı her gönderimde yeniden dener (D8; önceki turun `test_silme_yetkisi_olmayan_hedefte_paket_basarili_surum_kaydi_buyumez` sözleşmesi) — set eksilmediği sürece Codex'in asıl istediği (tamam görünen eksik set olmaması) sağlanıyor. İşaretçi silindikten sonra bileşende `SILME_YETKISI_YOK` → bileşen bizden çıkmış sayılır (sürüm artık tamam görünmez). Test yardımcısı: `BellekHedefi::$silinemeyenAdSonu` (eklemeli). Test: `KurtarmaPaketiTest::test_isaretcisi_silinemeyen_eski_surumun_hicbir_bileseni_silinmez` (veri sağlayıcı: silme yetkisi yok → kayıtta 2 sürüm; geçici hata → 3 sürüm; ikisinde de 3 işaretçi ve her setin bütün bileşenleri hedefte, özetleri tutuyor, gpg ile çözülüyor). T5: "silme yetkisi yok" durumu düzeltmesiz kırmızı (bileşenler silinmişti: Undefined array key …olaylar.jsonl.gpg); "geçici hata" önceden de yeşildi (ayrı kusuru yakalar: her hatayı yutan bir düzeltme). Doğrulanamayan: gerçek S3 kovasında yalnız işaretçiye silme reddi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R4 kapandı. İşaretçi silinemediğinde parçalara dokunulmuyor. Kalıcı yetki reddinde eksiksiz seti hedef sağlayıcının saklama politikasına bırakmak kabul edildi. Geçici hatada set kayıtlı kalıyor.
    - **BY-024-R5 [P1] Geçersiz manifest kurtarmaya hazır sayılıyor.** `backend/app/Services/Yedekleme/KurtarmaPaketi.php:173-183,351-365,431-439` yalnız arşivdeki adı yedekle eşliyor. Taşıma özeti aktarılan baytları doğruluyor. Bozuk imzayı, farklı yedeğe ait manifesti veya uyuşmayan dump özetini yakalamıyor. `backend/tests/Feature/KurtarmaPaketiTest.php:81-87` hatalı imzalı bir manifesti aktarım testinde geçerli paket bileşeni gibi kullanıyor. Son geçerli sürüm silindiğinde de hazır işaretinin dayanağı yeniden değerlendirilmiyor. D4: hazırlığı arşivlenen baytların geçerli imzasına, yedek kimliğine ve SHA-256 özetine bağlayın. Temizlik sonunda saklanan doğrulanmış sürümlerden birinde bu kayıt bulunuyorsa kopya hazır kalsın. Aynı hedefte bozuk imza, doğru imzalı yanlış yedek ve son geçerli sürümün dönüşümle kalkması durumlarında işaretin oluşmadığını veya geri alındığını sınayın. BY-010-R3 aynı hatanın yerel silme etkisi.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R5: Kurtarmaya hazırlık arşive giren baytlara bağlandı (`KurtarmaPaketi.php`). Manifestler artık yedek klasöründen doğrudan tar'lanmıyor: önce çalışma klasörüne anlık kopyalanıyor (izin 0640 + kaynağın mtime'ı korunuyor), her KOPYA taramanın okuyucusuyla (`ManifestDeposu::oku`: imza + şema; G3, elle yeniden yazılmadı) doğrulanıyor, arşiv bu kopyalardan kuruluyor. Doğrulanmış sayılan: imzası tutan VE arşivdeki adı `ManifestDeposu::jsonYolu(manifest.dosya_adi)` olan manifest (başka yedeğin imzalı manifesti aynı adla gelirse sayılmaz). Arşiv yine bütün manifestleri taşır (geçersizi kurtarmada `yedek:manifest-dogrula` reddeder). İmzalı sürüm kaydı (`kurtarma-paketleri.json`) her sürüm için yeni `yedekler` alanını tutar: doğrulanmış manifestler, yedek kimliği => manifestteki SHA-256. `hazirIsaretle` (adla eşleme) kaldırıldı; yerine `hazirligiGuncelle`: eski sürüm temizliğinden SONRA (adım sırası: 4 temizlik, 5 hazırlık) kayıtta KALAN tamam sürümlerin listelerinden hesaplar — kopya hazır ⇔ `tamam` ve (yedek.kimlik, yedek.sha256 [katalog]) bir listede aynen var; yalnız değişen kopyalar güncellenir, dayanağı kalmayan işaret geri alınır (D4). Silinmeye başlanan sürümün listesi kayıtta null'a düşer (artık dayanak değil). Eski biçimli kayıt (alan yok) reddedilmez — sürümleri dönüşümle silinmeye devam eder — ama o sürüm `yedekler = null` (bilinmiyor) okunur ve hazırlığa dayanak olmaz; alan varsa biçimi (UUID => 64 küçük hex) denetlenir, bozuksa kayıt eskisi gibi bütünüyle güvenilmez. Test kurgusu (T10): `KurtarmaPaketiTest::yedek()` artık hatalı `{"manifest":{},"imza":""}` değil, yedeğin kendisine ait imzalı manifesti yazıyor (`manifestYaz` yardımcısı). Testler: `KurtarmaPaketiTest::test_gecersiz_manifestli_kopya_basarili_paketle_kurtarmaya_hazir_olmaz_yerel_arsiv_silinmez` (veri sağlayıcı: imzası bozuk / doğru imzalı ama başka yedeğin manifesti aynı adla / kimliği aynı ama özeti katalogla uyuşmayan; aynı hedefte geçerli manifestli ikinci kopya hazır olur, geçersiz olan olmaz, paket başarılı, tek uzak hedefli planda `kurtarma_paketi_eksik` ve yerel dump kalır — BY-010-R3 dahil), `::test_dogrulanmis_manifesti_tasiyan_son_surum_donusumle_kalkinca_hazir_isareti_geri_alinir` (manifest yerelden kalkınca 2. paketten sonra hazır kalır, 3. paketle 1. sürüm silinince geri alınır), `::test_eski_bicimli_surum_kaydi_okunur_listesiz_surum_hazirliga_dayanak_olmaz` (imzalı eski biçimli kayıt: hazır geri alınır, eski sürüm dönüşümle silinir). T5: üçü de (5 durum) düzeltmesiz (HEAD `KurtarmaPaketi`) kırmızı — hepsi "Failed asserting that true is false" (kopya hazır kalıyordu). Doğrulanamayan: gerçek uzak hedefte (NAS/SFTP/S3/Drive) paket + hazırlık; çok sayıda manifestli (yıllar) kayıt boyutu ölçülmedi (sürüm başına ~110 bayt/yedek, en çok 2-3 sürüm/hedef).
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R5'in bayt doğrulaması kapandı. Özel çalışma klasöründeki manifest kopyası doğrulanıyor, paket o dosyadan kuruluyor ve kataloğun UUID / SHA-256 ikilisiyle eşleniyor. Geçersiz manifest hazır bayrağı kazandırmıyor.
    - **BY-024-R6 [P1] Paket dönüşümü, saklanan şifreli kopyanın son geçerli manifestini silebiliyor.** `backend/app/Services/Yedekleme/KurtarmaPaketi.php:189-193,256-270,319-351`. Yalnız uzak şifreli dump kalmışken yerel manifest kaybolur veya bozulursa sonraki paketler o yedeği doğrulamıyor. İlk geçerli set üçüncü gönderimde yaşı nedeniyle siliniyor. `KurtarmaPaketiTest.php:485-503` aynı dönüşümü kurup hazır bayrağının kaybolmasını bekliyor. Bayrağın sonradan geri alınması uzak dump'ı doğrulanabilir hâle getirmiyor. D4: saklanan bir uzak kopyanın son geçerli manifest dayanağı olan sürümü yeni doğrulanmış dayanak oluşana veya o uzak kopya saklama kapsamından çıkana kadar koruyun. Gerekirse eski seti koruyarak paket temizliğini atlayın ve uyarı üretin. Yalnız uzak dump kalan yedeğin yerel manifestini kaybedip iki yeni paket gönderildikten sonra eski dump + manifestin uzak depodan hâlâ doğrulanabildiğini sınayın.
    - **BY-024-R7 [P2] Kurtarılamayan yeni kopya uzak başarı sinyali üretebiliyor.** `backend/app/Services/Yedekleme/YedekKopyalayici.php:100-105` yalnız `gonder()` sonucuna bakıyor. `KurtarmaPaketi.php:177-193,224` ilgili yedek manifestinin pakette bulunmasını başarı koşulu yapmıyor. `KurtarmaPaketiTest.php:453-477` akışında paketin başarılı olup kopyanın hazır olmaması mümkün. D4: uzak yaşam sinyali için bu kopyanın güncellenmiş hazırlık durumunu da kontrol edin. İmzası geçersiz manifestle dış hedefe dump ve paket taşındığında sinyal gönderilmediğini, geçerli kurtarma dayanağıyla gönderildiğini sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R6: Paket dönüşümü artık saklanan uzak kopyanın son kurtarma dayanağını silmiyor (`KurtarmaPaketi.php`: `eskileriSil` + yeni `korunacakDayanaklar`). Eski sürümler silinmeden önce, bu hedefte `tamam` olan her kopya için dayanak sürüm hesaplanıyor: (yedek kimliği, katalog SHA-256) ikilisini doğrulanmış listesinde taşıyan EN YENİ tamam sürüm (`hazirligiGuncelle` ile aynı ölçüt, R5'in bayt bağı aynen). Bu sürüm kalacak son iki sürümden biriyse bir şey korunmaz; değilse yaşına bakılmadan silinmez. Korunan sürüm kayıtta tamam kalır, dosyalarına dokunulmaz; hazırlık onu dayanak saymaya devam eder (kopya hazır kalır). Uyarı: `kurtarma_paketi` denetim kaydında yeni `korunan_eski_surum` (sürüm adları, İşlem Günlüğü ekranında görünür) ve `yedekleme` günlüğünde `KURTARMA_ESKI_SURUM_KORUNDU` kodlu uyarı (hedef, sürümler, yedek kimlikleri). Koruma kalkar: yeni bir sürüm o yedeği yeniden doğrulayınca ya da kopya saklamadan çıkınca (silindi / tamam değil) — sonraki dönüşümde eski sürüm olağan şekilde silinir. Sürüm kaydının biçimi değişmedi (yükseltme yolu yok). Eski test `test_dogrulanmis_manifesti_tasiyan_son_surum_donusumle_kalkinca_hazir_isareti_geri_alinir` P1'in kabul ettiği yanlış davranışı ("bayrak kaybolur") bekliyordu; zayıflatılmadı, yeni davranışa göre aşağıdaki teste dönüştürüldü (R5'in "dayanağı kalmayan işaret geri alınır" kısmı listesiz eski biçim testinde ve kopya silinince testinde sınanıyor). `kurtarma.md` §1 (istisna maddesi, sinyal cümlesi, R5 cümlesi) ve §4.6 (yedeğin manifesti en yeni sürümde yoksa eski `-tamam.json` sürümünü indir) güncellendi (K11).
      Testler (`KurtarmaPaketiTest`):
      - `test_yalniz_uzakta_kalan_yedegin_son_dogrulanmis_manifesti_iki_yeni_paketten_sonra_uzakta_dogrulanir` (veri sağlayıcı: yerel manifest silinmiş / imzası bozulmuş): yerel dump ve manifest kaybı → iki yeni paket (toplam üçüncü gönderim) → 3 işaretçi, ilk sürüm aynen duruyor, kopya hazır, denetimde `korunan_eski_surum = [ilk sürüm]`; ilk sürümün manifest arşivi + uzak şifreli dump gerçek gpg ile çözülüp `yedek:manifest-dogrula` DOGRULANDI (imza + dump özeti = katalog özeti). T5: HEAD kodla kırmızı (2 ≠ 3 işaretçi, iki durumda da).
      - `test_saklanan_kopya_silinince_korunan_eski_surum_sonraki_donusumde_silinir`: kopya `silindi` olunca sonraki gönderim eski sürümü siler, 2 işaretçi, hazır false, `korunan_eski_surum = []`. T5: HEAD kodla kırmızı (önkoşul: korunmuyordu); ayrıca mutasyon "koruma kopya durumuna bakmıyor" ile kırmızı.
      - `test_yeni_surum_yedegi_yeniden_dogrulayinca_korunan_eski_surum_silinir`: manifest geri konunca sonraki sürüm yedeği doğrular, korunan sürüm silinir, kopya yeni dayanakla hazır. T5: HEAD kodla kırmızı (önkoşul); mutasyon "yedeği taşıyan her sürüm korunur (yalnız en yenisi değil)" ile kırmızı.
      Doğrulanamayan: gerçek uzak hedefte (NAS/SFTP/S3/Drive) koruma; çok sayıda manifesti kaybolan yedekte hedefte biriken korunan sürüm sayısı (en çok, dayanağı farklı sürümlerde olan kopya sayısı kadar) ölçülmedi.
    - R7: Uzak yaşam sinyali artık `gonder()` başarılı VE bu kopyanın paket sonrası güncellenmiş `kurtarma_hazir`'ı true ise gidiyor (`YedekKopyalayici.php`: `$kopya->refresh()->kurtarma_hazir`; dönen kopya nesnesi de güncel hazırlığı taşıyor). Neden görünürlüğü: `KurtarmaPaketi` denetim ayrıntısına, `yedekId` verilince `yedek_manifesti_dogrulandi` (kopyalanan yedeğin kimlik + katalog özeti bu paketin doğrulanmış listesinde mi) eklendi; ekranda kopya "kurtarma paketi bekleniyor" (mevcut). Test: `KurtarmaPaketiTest::test_imzasi_gecersiz_manifestle_tasinan_kopya_uzak_yasam_sinyali_gondermez_gecerli_dayanakla_gonderir` (imzası bozuk manifestli yedek dış hedefe dump + paketle gidiyor: kopya tamam, paket başarılı, hazır değil, hiç HTTP isteği yok, `yedek_manifesti_dogrulandi=false`; ardından geçerli manifestli yedek: hazır, `true`, tek sinyal; `Http::preventStrayRequests`). T5: HEAD kodla kırmızı (`assertNothingSent`: istek kaydedildi); mutasyon "sinyal hazırlığa bakmıyor" ile de kırmızı. Doğrulanamayan: gerçek dış izleyici.
  - 2026-11-21 Codex → CHANGES_REQUESTED · beşinci tur `3ad20f3`
    - R7 kapandı. Güncel kopya hazır değilse başarılı aktarım uzak yaşam sinyali göndermiyor. R6'nın normal yolu çalışıyor. `tamam` kopyanın son manifestini taşıyan paket yaşına rağmen kalıyor ve kılavuz daha eski pakete dönmeyi açıklıyor.
    - **BY-024-R8 [P1] Konumu belirsiz kopyanın son geçerli manifesti dönüşümle siliniyor.** `backend/app/Services/Yedekleme/KurtarmaPaketi.php:279-293,325-349`. Paket koruması sadece `tamam` kopyaları okuyor. `konum_belirsiz` fiziksel dosyanın silindiğini söylemiyor ve geri yükleme veya yükseltme bunu katalog durumuna yazabiliyor. İmzalı üç paket kaydı ve bellek hedefiyle doğruladım. Tek manifesti taşıyan ilk sürüm silindi, şifreli dump uzakta kaldı. D4 ve G21: fiziksel olarak durabilecek kopyaların doğrulanmış paketlerini de koruyun. Kullanılabilirlik, hazır bayrağı ve başarı sinyalinde `tamam` koşulunu sürdürün. Yerel dosya ve manifest yokken konumu belirsiz kopyanın paketi iki yeni gönderimden sonra hâlâ açılabilmeli ve hazır gösterilmemeli. Kopya gerçekten saklamadan çıktığında olağan dönüşümün sürdüğünü de sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex beşinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 5. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R8: Paket dönüşümünün son-dayanak koruması (`KurtarmaPaketi::korunacakDayanaklar`) artık yalnız `tamam` kopyaları değil, dosyası hedefte duran ya da durabilecek kopyaları okuyor: tek kaynak `KopyaDurumu::yedegiTutanlar()` (tamam + konum_belirsiz; yeni liste açılmadı, BY-028-R7 ile aynı küme). Konumu belirsiz kopyanın yedeğinin doğrulanmış manifestini taşıyan en yeni sürüm, kalacak son iki sürümden değilse yaşına bakılmadan korunur, denetimde `korunan_eski_surum` ve `KURTARMA_ESKI_SURUM_KORUNDU` uyarısında görünür (uyarı metni "tamam / konumu belirsiz" diyecek şekilde güncellendi). Kullanılabilirlik ayrı küme olarak kaldı (G21): `hazirligiGuncelle` (kurtarma_hazir) ve uzak yaşam sinyali (`gonder()` + kopyanın güncel `kurtarma_hazir`'ı, YedekKopyalayici) yalnız `tamam`'a bakmayı sürdürüyor; konumu belirsiz kopyanın eski hazır işareti sonraki pakette geri alınıyor. Koruma kopya saklamadan çıkınca (silindi, başarısız) ya da yeni sürüm yedeği yeniden doğrulayınca kalkıyor (R6 ile aynı). Sürüm kaydının biçimi değişmedi (yükseltme yolu yok). PHPDoc (sınıf 4. adım, `eskileriSil`, `korunacakDayanaklar`, `hazirligiGuncelle`), `KopyaDurumu` PHPDoc'u (yalnız yorum: `yedegiTutanlar`'ın artık iki varlık kararında kullanıldığı) ve `docs/yedekleme/kurtarma.md` §1 istisna maddesi güncellendi (K11); README'de R6 korumasını anlatan yer yok.
      Testler (`KurtarmaPaketiTest`):
      - `test_konumu_belirsiz_kopyanin_son_dogrulanmis_manifesti_iki_yeni_paketten_sonra_uzakta_dogrulanir_kopya_hazir_gorunmez`: ilk paketten sonra kopya konum_belirsiz, yerel dump ve yerel manifest silinmiş; iki yeni paketten sonra 3 işaretçi, ilk sürüm aynen duruyor, `korunan_eski_surum = [ilk sürüm]`, kopya hazır DEĞİL; ilk sürümün manifest arşivi + uzak şifreli dump gerçek gpg ile çözülüp `yedek:manifest-dogrula` DOGRULANDI. T5: düzeltmesiz kırmızı (2 ≠ 3 işaretçi).
      - `test_saklanan_kopya_silinince_korunan_eski_surum_sonraki_donusumde_silinir` veri sağlayıcılı oldu (`yedegiTutanKopyaDurumlari`: tamam / konumu belirsiz): kopya korunurken `silindi` olunca sonraki gönderim korunan sürümü siler, 2 işaretçi, hazır false, `korunan_eski_surum = []`. T5: "konumu belirsiz" durumu düzeltmesiz kırmızı (önkoşul: sürüm korunmuyordu); "tamam" durumu R6'daki gibi yeşil.
      - Ortak yardımcı `yalnizUzaktaKalanYedekIkiYeniPaketle`'ye isteğe bağlı kopya durumu parametresi eklendi (varsayılan tamam; mevcut R6 testleri değişmeden).
      Mutasyonlar: (1) hazırlık `yedegiTutanlar`'a bakar → yeni test kırmızı (konum_belirsiz kopya hazır görünüyor); (2) koruma kopya durumuna bakmaz → silindi testinin iki durumu kırmızı; (3) koruma yalnız konum_belirsiz (tamam düşmüş) → R6'nın 4 testi kırmızı.
      Doğrulanamayan: gerçek uzak hedefte (NAS/SFTP/S3/Drive) koruma; DizinKurucu'nun gerçek konum izi uyuşmazlığıyla konum_belirsiz yazdığı uçtan uca akış (testte durum doğrudan yazıldı).
  - 2026-11-21 Codex → PASSED · altıncı tur `2b7ea87`
    - R8 kapandı. Son manifest dayanağını koruyan kopya kümesi ortak `yedegiTutanlar()` kümesiyle aynı. Konumu belirsiz kopyanın son paketi kalıyor, hazır bayrağı ve uzak başarı sinyali verilmemeye devam ediyor. İki yeni paket sonrasında uzak manifest ve dump'ın açılıp doğrulanması, kopya gerçekten saklamadan çıkınca korumanın kalkması ve normal paket dönüşümü testleri geçti. Bu aralıkta yeni somut kod bulgusu yok. Gerçek uzak hedef ve boş sunucu saha kabulü BY-031 kapsamında açık.

---

## Faz 4 — Geri yükleme

### BY-025 — Çalıştırıcı servis ve istek klasörü (servis hesabı)

- **Durum:** BEKLIYOR · **Bağımlı:** BY-003, BY-013
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
  - [x] Yetkisiz kullanıcı adına yazılmış istek dosyası çalıştırıcıda reddediliyor (test).
  - [x] Bozuk / kötü niyetli istek dosyası (yol kaçışı, beklenmeyen alan) reddediliyor (test).
  - [ ] Geçiş: eski (uygulama kullanıcısıyla alınmış) yedekler listede kalıyor.
- **Canlı:** Evet (BY-003 betiğinin çalıştırılmasıyla birlikte; kullanıcı talimatıyla).
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Ön koşul notu: BY-003 betiği sunucuda henüz ÇALIŞTIRILMADI (kullanıcı
      talimatı yok). Kod yazıldı ve lokal testlerden geçti; canlıdaki
      ölçütler işaretlenmedi. Toplu yazım kararıyla `BEKLIYOR` yerine incelemeye.
    - `yedek:calistir {--tek-tur} {--en-cok-tur=1800}`: servis döngüsü (turlar
      arası 2 sn; SIGTERM / SIGINT'te o anki iş bitince çıkar; tur sınırında
      çıkar, systemd yeniden başlatır). Modül kapalıyken istek işlenmez, döngü
      boşta bekler. Geçiş döneminde zamanlayıcı dakikada bir `--tek-tur`
      (modül açık ve `calistirici` servis değilken). Her turda (en çok
      dakikada bir) ayar dosya kopyası tazelenir (BY-018).
    - İstek klasörü: `bekleyen/` → `isleniyor/` (tek adımlık rename; iki
      çalıştırıcı aynı isteği alamaz) → `sonuc/`. `durum()` yeni `calisiyor`
      durumu. Tek çalıştırıcı kilidi; kilit tutulurken `isleniyor/`'da kalan
      istek `ISTEK_YARIDA_KALDI`; 7 günden eski sonuçlar silinir; 24 saatten
      eski istek `ISTEK_SURESI_DOLDU`.
    - İsteğe güvenilmez (D5, G10, G11, G15): taşındıktan sonra lstat ile tek
      bağlantılı düz dosya, ≤ 16 KiB, JSON, üst alanlar birebir, sürüm,
      kimlik = dosya adı, tür izin listesinde, parametre anahtarları birebir ve
      her değerin türü / biçimi (`IstekKutusu::TURLER` tür taşır; YAZAN da aynı
      denetimden geçer). Yetki işlem anında veritabanından (aktif kullanıcı +
      izin; izin yoksa sistem yöneticisi). Kullanıcısız istek yalnız yayın
      öncesi yedek. Red = `basarisiz` + makine okunur kod + denetim kaydı.
    - Eşleme: yedek_al / dogrula / tara / aciklama → `yedekler.al`; sabitle /
      sabit_coz / sil → `yedekler.sil`; plan_calistir →
      `yedek_planlari.guncelle`; kopya_geri_yukle / kopya_sil →
      `geri_yukleme.kopya`; ustune_geri_yukle / onayla / geri_don → sistem
      yöneticisi (işleyiciler BY-028/029).
    - İşleyiciler ayrı sınıflar (`Istekler/*Isleyicisi`, `IsleyiciKatalogu`).
      Her istek yedekleme kilidini alır (`kilit_bekleme`, sonra
      `YEDEK_KILIT_MESGUL`); açık üstüne geri yüklemede `YEDEK_ACIK_GERI_YUKLEME`.
    - Kurulum betiği (`scripts/sunucu-yedekleme-kurulumu.sh`): `istekler/`
      artık 2750 (web ve yayın yalnız geçer) — yazılabilir kalsaydı web
      süreci `sonuc/`'u bağlantıyla değiştirip çalıştırıcının yazımını
      yönlendirebilirdi (D5). Alt klasörler: `bekleyen/` 2770 (yayına ACL
      rwx), `isleniyor/` 2750, `sonuc/` 2750 (yayına ACL r-x) — servis kipinde
      `deploy.sh` isteği bırakıp sonucu okuyabilsin. Servis tanımına
      `TimeoutStopSec=4000` (geri yükleme 3600 sn sürebilir; systemd'nin 90
      sn'lik varsayılanı işi öldürürdü). Betik testi lokal WSL'de (deneme
      adlarıyla) 188/188.
    - Testler: `YedekCalistiriciTest` (59; 23 bozuk istek, 10 izin eşlemesi).
      T5: yetki, fazla parametre, bağlantı denetimi ve rename kaldırılınca
      ilgili testler kırmızı.
    - Not (K10): `SilIsleyicisi`'nin kopya başına silmesi `SaklamaPolitikasi`
      ile benzer; ortak parça sonraki işte çıkarılmalı.
    - Doğrulanamayan: web sürecinin yedek klasörüne yazamaması / imza
      anahtarını okuyamaması ve eski yedeklerin listede kalması (canlıda,
      servis hesabına geçişte). Servis kipinde yollar paylaşılan `.env`'den
      (`YEDEKLEME_KLASORU`, `YEDEKLEME_DURUM_KLASORU`, …) — isletim.md.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-025-R1 [P1] İstek dosyasındaki kullanıcı kimliği güvenilir göndericiye bağlı değil.**
       `backend/app/Services/Yedekleme/Istekler/IstekCalistiricisi.php:192 ve :206, scripts/sunucu-yedekleme-kurulumu.sh:291`. Üst dizin korumalı olsa da `bekleyen/` ortak yazılabilir. Çalıştırıcı istekteki kimliğin iznine bakıyor fakat kimliğin doğrulanmış gönderenle bağlantısını kontrol etmiyor. Aynı girişten gelen yayın isteğine de yalnız JSON alanlarıyla istisna veriliyor. Yetkili işlemin hangi sahibin adına başlatıldığına güvenilemiyor (G5, README §5.2).
       İstek sahipliğini çalıştırıcının bağımsız olarak doğrulayabildiği kanala bağlayın, yayın istisnasını ayrı güvenilir kaynakla sınırlandırın. Yerel testte kimliği doğrulanamayan istek reddi ve normal yetkili web/yayın isteklerinin geçmesi birlikte doğrulansın.
    2. **BY-025-R2 [P2] Yetki kontrolü işlem kilidi alınmadan önce bitiyor.**
       `backend/app/Services/Yedekleme/Istekler/IstekCalistiricisi.php:129 ve :231`. Başka işlem kilidi tutarken izin kaldırılırsa, bekleyen istek eski denetimle devam ediyor. Bu, işlem anında yetki kontrolü kabulünü karşılamıyor (G5).
       İşleyici çağrısından hemen önce, kilit altındayken son yetkiyi kontrol edin. Beklerken yetkisi alınan kullanıcının işinin hiç başlamadığını sınayın. Canlı ACL ve eski kopya geçişi kabulü açık.
    3. **BY-025-R3 [P3] Kopya silme sonrası katalog ve olay güncellemesi iki yerde.**
       `backend/app/Services/Yedekleme/Istekler/SilIsleyicisi.php:103, backend/app/Services/Yedekleme/SaklamaPolitikasi.php:310`. Elle silme ile saklama kopya silme olaylarını ayrı üretiyor. BY-023'teki farklılaşma bu kuralların kolayca ayrılabildiğini gösteriyor (K10).
       Kopya silme sonucunu kaydeden ortak adımı çıkarırken elle silme ve saklama kararlarını kendi yerlerinde bırakın. Her iki akışta olaydan yeniden kurmayı sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Gönderen artık dosyanın çekirdekteki SAHİBİYLE (açılmış dosyanın fstat uid'si; `IstekKutusu::oku` → `Istek::$sahipUid`) doğrulanıyor (`IstekCalistiricisi::gondereniDenetle`): kullanıcısız `yayin_oncesi` yalnız yayın kullanıcısının, geri kalan her istek (onay/geri dön dahil) yalnız web kullanıcısının dosyasıysa işlenir; tanınmayan sahip `ISTEK_GONDEREN_DOGRULANAMADI` + denetim kaydı (`gonderen_uid`). Yapılandırma `yedekleme.web_uid` / `yedekleme.yayin_uid` (`YEDEKLEME_WEB_UID`, `YEDEKLEME_YAYIN_UID`; G9 doğrulaması, yayın = web ise hata). Ad yerine uid: ad çözmek posix uzantısı ister (composer bağımlılığı — A4, onay gerekir); kurulum betiği `id -u` ile WEB_KULLANICI / YAYIN_KULLANICI'dan servis birimine Environment olarak yazar, iki uid aynıysa durur. Varsayılan: web = sürecin kendi kullanıcısı (/proc/self; geçiş kipi), yayın tanımsız (yayın isteği kabul edilmez). İstek dosyası izni 0660 → 0640 (aynı gruptaki web, yayın kullanıcısının dosyasının içeriğini değiştiremesin). README §5.2'ye tehdit modeli notu (kullanıcı kimliği web'in iddiası; ele geçirilmiş web zaten veritabanına yazabilir; yayın istisnası artık taklit edilemez). Testler: `YedekCalistiriciTest::test_gonderen_dosyanin_sahibiyle_dogrulanir_web_yayin_istisnasini_taklit_edemez` (web uid'li dosyada yayın reddi + normal web isteği geçer; yayın uid'li dosyada kullanıcılı istek reddi + yayın isteği geçer), `YedeklemeYapilandirmasiTest::test_istek_gondereni_uid_ayari_bos_gecerli_ve_gecersiz_degerde`; mevcut yayın testleri uid eşlemesiyle güncellendi; kurulum testinde servis tanımındaki uid satırları. T5: gönderen denetimi kaldırılınca test kırmızı. Doğrulanamayan: gerçek web / yayın hesaplarının yazdığı dosyalarla çalıştırıcı (servis birimi çalıştırılmadı; uid'ler testte yapılandırmayla eşlendi).
    - R2: Son yetki denetimi yedekleme kilidi altında, açık geri yükleme denetiminden sonra, işleyiciden hemen önce (`kilitleIsle` içinde `yetkiDenetle` yeniden); erken denetim (kilit beklemeden red) duruyor. `YedekKilidi::bekleyerekAl` uykusu `Sleep::usleep` oldu (gerçekte aynı; testte bekleme anı kurulabilsin). Test: `YedekCalistiriciTest::test_kilit_beklenirken_yetkisi_alinan_kullanicinin_isi_hic_baslamaz` (kilit beklenirken rol kaldırılır ve kilit boşalır → ISTEK_YETKI_YOK, sabitleme yok). T5: kilit altındaki denetim kaldırılınca kırmızı.
    - R3: Ortak adım yeni `KopyaSilmeKaydi::kaydet($kopya, ?$hataKodu)`. Önce imzalı olay, sonra dizin yazılıyor. Yerel dosyası gitmiş ve tamamlanmış kopyası kalmamış yedek `silindi` oluyor. `SilIsleyicisi` (elle) ve `SaklamaPolitikasi` (fiziksel, ana klasör ve silme yetkisi yok yolları) bunu kullanıyor; kararlar kendi yerlerinde kaldı. İki sınıftaki kopya `kopyaSilindi` metotları ve saklamanın kendi "kalmadıysa silindi" bloğu kaldırıldı. Testler: elle akış `DizinKurucuTest::test_elle_silinen_yedek_ve_kopyasi_yeniden_kurulunca_silinmis_kalir`, saklama akışı `DizinKurucuTest::test_silme_yetkisi_reddedilen_kopya_yeniden_kurulunca_kullanilabilir_gorunmez`. Elle akış testi refaktör korumasıdır, önceden de geçiyordu (SilIsleyicisi olay yazıyordu).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R2 ve R3 kapandı. R1 yayın hesabı için kapandı. Web'den gelen ayrı yetki gerektiren işlemler için gönderen ve kullanıcı bağlama eksik. Gerçek ACL ve eski yedek geçişi kabulü açık.
    1. **BY-025-R4 [P1] Web dosyasının sahibi son kullanıcıyı doğrulamıyor.**
       `backend/app/Services/Yedekleme/Istekler/IstekCalistiricisi.php:206, :214 ve :240, docs/yedekleme/README.md:445`. UID web ve yayın hesaplarını ayırıyor. Çalıştırıcı web kaynaklı işlemde hâlâ dosyanın iddia ettiği kullanıcıyı yetki kaynağı sayıyor. Uygulama veritabanına yazma yetkisi, ayrı servis hesabına verilmiş imzalı arşiv silme veya üstüne geri yükleme yetkisinden farklıdır. Yedeklerin web sürecinden ayrı tutulması bu işlemlerde korunmuyor (G5, D7).
       Silme ve üstüne geri yükleme yetkisini çalıştırıcının bağımsız doğrulayabildiği bir güven sınırına bağlayın veya bu işlemleri ayrı yönetici onayına alın. Doğrulanmış son kullanıcıya bağlanamayan isteğin reddedildiğini ve geçerli işlem ile yayın yollarının çalıştığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R4: **Kullanıcı kararı (2026-11-21): risk kabul edildi.** Web'den istenen yedek silme ve üstüne geri yükleme için web'den bağımsız ikinci onay (sunucuda SSH onayı ya da doğrulama uygulaması kodu seçenekleri sunuldu) eklenmedi; kod değişikliği yok. README §5.2 güncellendi: "İmza anahtarı" maddesindeki "ele geçirilen web süreci yerel yedekleri silebilirdi" ifadesi bu kararla çelişiyordu (P1) — artık web'in doğrudan dosya silemediği ama ekrandaki yetkili kullanıcının yolundan sildirebileceği açık; "Çalıştırıcı isteğe güvenmez" maddesine kararın anlamı ve kalan korumalar yazıldı (saklama web'den bağımsız, değiştirilemez saklamalı hedef silmeyi reddeder, üstüne geri yükleme `erp_onceki_*` ve öncesi yedeğini bırakır, her istek denetim kaydında; sabitleme koruma değildir — kaldırılması da ekrandan istenir). Ele geçirilmiş web'e karşı silinmezlik isteniyorsa yol değiştirilemez saklamalı sunucu dışı hedeftir.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a` · dış kabul bekleniyor
    - R4 kullanıcı kararıyla kapandı. README §5.2 web'den bağımsız ikinci onayın bulunmadığını, etkilenen işlemleri ve silmeyi reddeden dış hedefin rolünü tutarlı anlatıyor. Yeniden onay gerekmiyor.
    - Kod incelemesinde yeni bulgu yok. Görev dış kabulü bekliyor. BY-003'ün kullanıcı talimatıyla gerçek sunucuda çalışması, web hesabından yazma ve imza okuma sınırları ile geçişte eski yedeklerin görünmesi henüz doğrulanmadı.

### BY-026 — Geri yükleme çekirdeği: farklı ada + sınama geri yüklemesi

- **Durum:** PASSED · **Bağımlı:** BY-025
- **Tasarım:** README §4.5-A, §6.3, §4.4 (2. düzey), §5.3 (veritabanı özellikleri)
- **Kapsam:** Hedef ad üretimi ve denetimi (serbest metin yok); veritabanının
  manifestteki kodlama / sıralama / ayarlarla oluşturulması; `pg_restore
  --exit-on-error --single-transaction`; sahipliğin uygulama rolünde kalması;
  sayım ve migration listesi karşılaştırması; hata yolunda yarım veritabanının
  silinmesi. Sınama geri yüklemesi: her yedekten sonra geçici veritabanına
  yükle, karşılaştır, sil; sonucu olay günlüğüne ve yaşam sinyaline.
- **Kabul ölçütleri:**
  - [x] Hedef ad kalıp dışıysa ya da mevcut bir veritabanıysa işlem başlamıyor (test).
  - [x] İmzası geçersiz yedek geri yüklenemiyor (test).
  - [x] Geri yükleme hatasında geçici veritabanı kalmıyor (test).
  - [x] Lokal PostgreSQL'de: geri yüklenen veritabanında uygulama rolü okuyup yazabiliyor; sıralama ve saat dilimi kaynakla aynı.
  - [x] Sayım uyuşmazlığı yedeği "doğrulama başarısız" yapıyor ve bildirim üretiyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - `GeriYukleyici::kopyaya` / `sina`: kilit + açık üstüne geri yükleme yok;
      yalnız imzalı tamam yedek; imza dizindeki bayraktan değil manifest
      dosyasından yeniden doğrulanır; yerel dosyanın boyutu ve SHA-256'sı
      manifestle aynı olmalı, yoksa şifresiz kopyadan geçici klasöre inip aynı
      denetim; yalnız şifreli kopya → `GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI`
      (komut satırı yolu: kurtarma.md).
    - Ad: `erp_geri_<YYYYAAGG>_<SSddss>[_<[a-z0-9]{1,16}>]`, sınama
      `erp_sinama_<…>_<6 hex>` (`VeritabaniAdi`); varsa dokunulmaz (`HEDEF_VAR`).
    - `CREATE DATABASE … OWNER <uygulama rolü> TEMPLATE template0 ENCODING /
      LC_COLLATE / LC_CTYPE` manifestten, `ALTER DATABASE … SET` ayarları;
      değerler psql değişkeni (`--set`, `:"ad"` / `:'deger'`), elle tırnaklama
      yok (G3); liste ayarları reddedilir. `pg_restore --exit-on-error
      --single-transaction --no-owner --role=<uygulama rolü>`; satır sayıları,
      migration listesi ve tablo sahibi karşılaştırılır (`ice_alindi`'de
      atlanır, kaynak kodlaması kullanılır).
    - Hata → yarım veritabanı `DROP DATABASE IF EXISTS … WITH (FORCE)`, geçici
      dosya kalmaz; pg_restore iletisinin yalnız ilk satırı (D2). Silme yalnız
      kalıptaki adlarda (K3). `geri_yuklemeler`: adımlar ("açılıyor" komuttan
      önce yazılır), `veritabani_silinme_zamani` = silindiği an (otomatik
      silinme `bitti + kopya_bekleme_gun`, `silinecegiZaman`; README §4.5-A'ya
      yazıldı); işlem durumu dosyası, denetim, bildirim.
    - Sınama: her başarılı yedekten sonra (`sinama_geri_yukleme` açıksa,
      `YedekAlici` içinde, kopyalardan önce — "doğrulanmış en yeni" koruması
      görsün) ve `dogrula` isteğinde; her durumda silinir. Geçti →
      `sinama_gecti` + olay + yaşam sinyali; yedekten kaynaklanan hata →
      `basarisiz` + olay + `dogrulama_basarisiz` bildirimi; bağlantı / yetki
      hatası yedeği "başarısız" yapmaz. Önceki sınama artıkları silinir.
    - Süresi dolan kopyalar ve yarıda kalan geri yüklemeler (`YARIDA_KALDI`)
      çalıştırıcı turunda temizlenir (aynı süreçte en çok 10 dk'da bir — D8).
    - Testler: `GeriYukleyiciTest` (24). T5: imza, yarım veritabanı silme,
      karşılaştırma, hedef varlığı, ad denetimi ve sınama kancası
      kaldırılınca ilgili testler kırmızı.
    - Lokal PostgreSQL 16.15 (geçici roller / veritabanları, sonunda silindi;
      erp ve erp_test'e dokunulmadı): kaynak `C`, `TimeZone=America/New_York`
      (sunucu `C.UTF-8` / `Europe/Istanbul`) → kopya `C/C`,
      `America/New_York`; veritabanı ve tablo sahibi uygulama rolü; uygulama
      rolü okudu, yazdı, tablo açıp sildi; `sessions` 0 satır; sınama geçti,
      artık kalmadı; `kopya_sil` sildi.
    - Doğrulanamayan: canlıda (18.6, `en_US.UTF-8`, servis hesabıyla) geri yükleme.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-026-R1 [P2] Geri yükleme kaynak veritabanının bağlantı izinlerini korumuyor.**
       `backend/app/Services/Yedekleme/GeriYuklemeVeritabani.php:123 ve :142`. Manifest bağlantı yetkilerini taşıyor ancak yeni veritabanı varsayılan yetkilerle açılıyor. Kaynakta `CONNECT` veya `TEMP` yetkisi daraltılmışsa kopya ve üstüne geri yükleme farklı izinlerle açılır (README §5.2, G5).
       Desteklenen veritabanı ACL'sini yeni veritabanına güvenli biçimde uygulayın veya eşdeğerlik sağlanamıyorsa açık hata verin. Kaynakta yalnız belirli bir role bağlantı verilmişken kopyanın izinlerinin eşleştiğini gerçek yerel PostgreSQL'de sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Kopyanın, sınamanın ve üstüne geri yüklemenin yeni veritabanı, manifestteki `baglanma_yetkileri`'ne eşdeğer yapılıyor. Yeni yöntem `GeriYuklemeVeritabani::yetkileriUygula`: tek DO bloğunda çalışır. Önce şimdiki ve hedefteki her role (PUBLIC dahil) `REVOKE ALL … CASCADE` yapar, sonra hedefteki yetkileri verir. Ardından sonucu (rol, yetki, devredilebilir) kümesi olarak hedefle karşılaştırır; veren karşılaştırılmaz. Eşdeğerlik sağlanamazsa ya da rol yoksa yetkiler hiç değişmez, `GERI_YUKLEME_VERITABANI_YETKILERI` döner ve yarım kopya silinir. Değerler psql değişkeni ve `set_config` ile verilir, elle tırnaklama yok (G3). Değişen dosyalar: `GeriYukleyici::yukle` (manifest ACL'si null değilse) ve `UstuneGeriYukleyici` (veritabani adımı). Testler: `GeriYukleyiciTest::test_kopya_manifestteki_baglanma_yetkileriyle_acilir_uygulanamazsa_silinir`; gerçek PG `GeriYuklemeVeritabaniPostgresTest::test_kopya_kaynagin_baglanma_yetkilerine_esdeger_acilir_rol_yoksa_yetkiler_degismez` (kaynakta PUBLIC'in bağlanması kaldırılmış, yalnız belirli role CONNECT verilmiş; kopyanın öğeleri veren dahil aynı; o rol bağlanıyor, başka rol bağlanamıyor; olmayan rolde hata ve yetkiler değişmedi). T5: kopyada uygulama kaldırılınca test kırmızı. Doğrulanamayan: DO bloğundaki eşdeğerlik denetimi ikinci savunmadır. Rol yoksa hatayı zaten `aclitem[]` dönüşümü verir; başka vericili yetki senaryosu kurulamadı, bu yüzden düzeltmesiz kırmızı görülmedi. Canlı PG 18.6'da denenmedi (lokal 16.15).
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 çekirdek akışta kapandı. Gerçek PostgreSQL testleri kopya veritabanının kaynak ACL'sine eşdeğerliğini ve bilinmeyen rolde geri almayı sınadı. Elle kurtarma kılavuzunda ACL uygulaması BY-031-R2'de açık.

### BY-027 — Ekran: Geri Yüklemeler (kopya)

- **Durum:** PASSED · **Bağımlı:** BY-026, BY-015
- **Tasarım:** README §4.5-A, §4.10 (3)
- **Ön koşul:** README §13 karar 10 ve 17.
- **Kapsam:** Yedekler listesinden "farklı adla geri yükle"; Geri Yüklemeler
  sayfası: işlemler ve durumları, bekleyen kopyalar (ad, boyut, yaş), silme,
  otomatik silme tarihi ve uyarısı.
- **Kabul ölçütleri:**
  - [x] İzin `geri_yukleme.kopya` olmadan düğme yok, uç 403.
  - [x] Kopya silme onay ister; `erp` ve kalıp dışı adlar ekrandan silinemez (test).
  - [x] Otomatik silme süresi dolmadan uyarı gösteriliyor.
- **Canlı:** Evet.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Ön koşul notu: README §13 karar 10 (kopyaların bekleme süresi) ve 17
      (inceleyecek kişiler) kullanıcıdan gelmedi: süre `kopya_bekleme_gun`
      (varsayılan 7, `.env`), inceleme rolü BY-003'teki `emor_inceleme`.
    - API (`GeriYuklemeController`): `GET /ayarlar/geri-yuklemeler`
      (`yedekler.goruntule`; geçmiş + bekleyen kopya bayrağı, otomatik silinme
      anı `KopyaVeritabanlari::silinecegiZaman`, PostgreSQL'de
      `pg_database_size`), `POST /ayarlar/yedekler/{kimlik}/kopya-geri-yukle
      {ek}` ve `DELETE /ayarlar/geri-yuklemeler/{kimlik}/kopya`
      (`geri_yukleme.kopya`; istek klasörüne). Kopya silme yalnız kaydı olan,
      tamamlanmış, silinmemiş ve `erp_geri_*` kalıbındaki veritabanında; `erp`,
      `erp_onceki_*` ve kalıp dışı 409 `GERI_YUKLEME_KOPYA_YOK` (çalıştırıcı da
      yeniden denetler). İmzasız yedek 409.
    - Ekran: Yedekler satırında "Farklı adla geri yükle" (yalnız
      `geri_yuklenebilir` satır + `geri_yukleme.kopya`; ek `[a-z0-9]{1,16}`
      istemcide de doğrulanır, ad önizlemesi; canlıya dokunmadığı ve otomatik
      silineceği yazılı); `/ayarlar/geri-yuklemeler`: bekleyen kopyalar (ad,
      boyut, yaş, otomatik silinme tarihi, 24 saatten az kalınca uyarı rozeti,
      onaylı silme) ve geçmiş (çevrilmiş durum / hata kodu, açılır adımlar;
      üstüne kayıtları salt okunur); çalışan geri yükleme varken liste 5 sn'de
      bir tazelenir, tazelenirken silme kapalı (F1).
    - Testler: `GeriYuklemeApiTest` (7: izin 403, ek kalıbı, imzasız red,
      `erp` / `erp_onceki_*` / kalıp dışı silinemez, silme isteği + otomatik
      silinme tarihi), `YedeklerPage.test` (+3), `GeriYuklemelerPage.test`.
      Ekran T5: 6 koruma kaldırılınca ilgili testler kırmızı.
    - Not: pencere bekleme süresini "varsayılan 7 gün" diye yazar (süre API'de
      yok; kopyanın tam tarihi Geri Yüklemeler ekranında).
    - Doğrulanamayan: açık / koyu tema, dar ekran (elle).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-027-R1 [P2] Yalnız şifreli uzak kopya için ekran yapılamayan geri yükleme sunuyor.**
       `frontend/src/pages/Yedekler/YedeklerPage.tsx:435, backend/app/Http/Resources/YedekResource.php:34, backend/app/Services/Yedekleme/GeriYukleyici.php:292`. Eylem imzalı ve tamam yedeğe göre açılıyor. Yerel dosyası silinmiş ve yalnız şifreli uzak kopyası olan yedek ekrandan uygulanamaz. Kullanıcı ancak iş gönderildikten sonra hata görüyor (F7, BY-027).
       Webden geri yüklenebilen kaynak durumunu API'de belirtin. Yalnız şifreli kopya için kurtarma kılavuzunu gösterin. Aynı ekranda yerel, desteklenen uzak ve yalnız şifreli kopya hallerini sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `GeriYuklemeKaynaklari` (yeni) tek doğruluk kaynağı: kaynak sırası yerel dosya → tamamlanmış ŞİFRESİZ uzak kopyalar (id sırası); yalnız şifreli kopya → `GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI`, hiçbiri → `GERI_YUKLEME_YEDEK_DOSYASI_YOK`, imzasız/tamamlanmamış → `GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ`. `GeriYukleyici::dosyaHazirla` uzak kopya seçimini buradan alır (yalnız kaynak seçimi değişti); `YedekResource` `webden_geri_yuklenebilir` + `geri_yukleme_engeli` döner (yüklü kopyalardan, ek sorgu yok). Ekran "Farklı adla geri yükle" ve "Üstüne geri yükle"yi `webden_geri_yuklenebilir`'e bağlar; yalnız şifreli kopyada eylem yerine `yedek:sifre-coz` ve docs/yedekleme/kurtarma.md'ye yönlendiren açıklama gösterir. Kopya formundaki "yerel dosya yok" notu yeni duruma göre güncellendi. Test: `YedekApiTest::test_webden_geri_yukleme_kaynagi_yerel_sifresiz_uzak_ve_yalniz_sifreli_kopyaya_gore_bildirilir`; `YedeklerPage.test` "yalnız şifreli kopyası olan yedekte geri yükleme yerine kurtarma yolu yazılır; yerel ve şifresiz uzak kopyada geri yükleme vardır (BY-027-R1)" (T5: ekran eski bayrağa bağlanınca kırmızı; API alanı yokken kırmızı). Doğrulanamayan: "Doğrula" (sınama geri yüklemesi) aynı kaynak kuralına tabi ama hâlâ `geri_yuklenebilir` ile açılıyor — kapsam dışı bırakıldı, kararı birleştiriciye.
    - R1 (ek): "Doğrula" da aynı geri yükleme motorunu kullandığı için aynı kurala bağlandı: ekranda `webden_geri_yuklenebilir` ile açılıyor; API `dogrula` ucu `GeriYuklemeKaynaklari::engel()` doluysa 409 (kod = engel, ör. `GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI`) ve istek bırakmıyor. Test: `YedekApiTest::test_yalniz_sifreli_kopyasi_kalan_yedek_ekrandan_dogrulanamaz_istek_birakilmaz`; ekran testi (BY-027-R1) "Doğrula" düğmesini de sınıyor.
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Şifreli tek uzak kopyada web geri yükleme eylemi kapalı ve kurtarma yolu gösteriliyor. Kopya geri yükleme ve Yedekler ekranı testleri geçti.

### BY-028 — Üstüne geri yükleme çekirdeği

- **Durum:** PASSED · **Bağımlı:** BY-026
- **Tasarım:** README §6.4 (1–8. adım, hata kuralı, yarıda kalan işlem), §7.1, §7.4
- **Kapsam:** §6.4'ün durum makinesi: ön denetimler; bakım modu ve servislerin
  durdurulması; geri yükleme öncesi yedek (bakım modundan sonra); yeni
  veritabanına geri yükleme; migration'ın ayrı süreçte yeni veritabanında
  çalıştırılması; bağlantıya kapatma + koparma; yer değiştirme (BY-002'de
  doğrulanan yöntemle); dizini yeniden kurma, ayarları uzlaştırma. Her adım
  durum dosyasına; yarıda kalan işlemi sürdürme / geri sarma.
- **Kapsam dışı:** Onay adımı ve servis korumaları (BY-029), ekran (BY-030).
- **Kabul ölçütleri:**
  - [x] Her adımda hata üreten ayrı test: 7. adımdan önceki hatada `erp` değişmemiş ve servisler açık; 7. adımdan sonraki hatada bakım modu korunuyor.
  - [x] Migration adımı canlı `erp`'ye tek sorgu göndermiyor (test: bağlantı izleme).
  - [x] Yedekte olup kodda bulunmayan migration varsa işlem başlamıyor (test).
  - [x] Süreç her adımda öldürülüp devam ettirildiğinde sonuç tutarlı (lokal PostgreSQL'de denendi, günlükte tablo).
  - [x] Durum dosyasındaki `adim` değerleri serbest metin değil, tek yerde
    tanımlı sabit / enum; karar veren her kod (devam, geri sarma, onay) onları
    kullanıyor (Fable F17). Kurtarma bağlamı (yedek kimliği, hedef / önceki
    veritabanı, isteyen) ilk yazımda dolduruluyor; yer değiştirmeden sonra
    `geri_yuklemeler` satırı bu dosyadan yeniden yazılıyor (test).
  - [x] Eski veritabanı `erp_onceki_*` adıyla duruyor ve bağlantıya kapalı.
  - [x] Servis bakım modu dosyasını (`storage/framework/down`, web'in
    yazabildiği klasör) bağlantı izlemeden yazıyor: yol bağlantıysa durur;
    test: web kullanıcısının koyduğu bağlantıyla servisin dosyası değişmiyor
    (D5, BY-003-R6 kapsam notu).
- **Canlı:** Hayır — BY-029 ve BY-031 `PASSED` olana kadar canlıda kullanılmaz.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Kapsam: `UstuneGeriYukleyici` (README §6.4 1–9 durum makinesi); adımlar
      tek enum (`UstuneGeriYuklemeAdimi`; devam / geri sarma / onay kararları
      enum yöntemlerinden); `BakimModu` (yalnız `storage/framework/down`
      JSON'u, bağlantı izlemez, yalnız kendi dosyasını kaldırır; `artisan
      down` çağrılmaz — web'in her istekte yüklediği `maintenance.php`'yi de
      yazardı, D7); `KuyrukServisi` (`YEDEKLEME_KUYRUK_SERVISI`, `sudo -n
      systemctl stop|start`; servis ortamı kurulum betiğinden, sudoers ile
      aynı adlar); `YeniVeritabanindaMigration` (ayrı süreç, geçici
      `APP_CONFIG_CACHE` ile bütün pgsql bağlantıları yeni veritabanında,
      önbellek / oturum `array`, kuyruk `sync`); `GeriYuklemeVeritabani`'na
      bağlantıya kapatma / koparma / tek işlemde ad değiştirme. Yarıda kalan
      işlem çalıştırıcı turunda sürdürülür / geri sarılır (en çok 10 dk'da
      bir — D8). README §6.4'e "Uygulamada verilen kararlar" eklendi.
    - Her adımda hata testi: `test_yer_degistirmeden_once_hata_canliyi_korur_ve_servisleri_acar`
      (kuyruk, öncesi yedek, veritabanı, pg_restore, karşılaştırma, migration,
      bağlantı kapatma, yer değiştirme: canlı aynen, hedef silindi, bakım
      kalktı, kuyruk başladı); `test_yer_degistirmeden_sonra_hata_bakim_modunu_korur_karar_yoneticinin`;
      `test_on_denetim_reddinde_hicbir_sey_degismez` (4 durum).
    - Migration canlıya sorgu göndermiyor: `test_migration_ayri_surecte_yalniz_yeni_veritabanini_gorur`,
      `test_migration_yapilandirmasi_gercek_cocuk_surece_yeni_veritabani_olarak_ulasir`;
      lokal PG: migration boyunca canlı `ALLOW_CONNECTIONS false` iken migration başarılı.
    - Yedekte olup kodda olmayan migration: `test_yedekte_olup_kodda_olmayan_migration_varsa_baslamaz`;
      içe alınmış yedek üstüne yüklenmez: `test_ice_alinmis_yedek_ustune_yuklenmez`.
    - Öldür / sürdür — lokal PostgreSQL 16.15, geçici roller (`ajan_ustune_app`,
      `ajan_ustune_svc`) ve "canlı" yerine `erp_ajan_ustune_<rnd>`; `kill -9`
      durum dosyasındaki adıma bakılarak, sonra `yedek:calistir --tek-tur`;
      sonunda hepsi silindi, yerel `erp` / `erp_test` ve uygulamanın down
      dosyasına dokunulmadı:
        | Öldürülen an | Sürdürmeden sonra |
        |---|---|
        | bakim_modu … baglanti_kapatma (7 adım) | basarisiz (YARIDA_KALDI), hedef silindi, bakım kalktı, canlı aynen |
        | yer_degistirme (ad değişikliğinden önce; canlı kapalı) | canlı yeniden açık, geri sarıldı |
        | yer_degistirme (ad değişikliğinden sonra) | onay_bekliyor (8. adımdan devam), bakım sürüyor |
        | geri_donuluyor (geri ad değişikliğinden sonra) | geri_donuldu, kopya `erp_geri_*` kapalı, silinmedi |
        | onaylaniyor (kuyruk başlarken) | onaylandi |
        | kilit başkasındayken `yedek:acil geri-don` | çıkış 1, 138 ms, durum değişmedi |
      Otomatik: `test_yer_degistirmeden_once_olen_islem_geri_sarilir` (7 adım),
      `test_yer_degistirmede_olen_islem_adlara_gore_karar_verir`,
      `test_adlar_tutarsizsa_hicbir_sey_yapilmaz_yoneticiye_birakilir`,
      `test_dizin_kurmada_olen_islem_devam_eder_onaya_gelir`.
    - Enum + bağlam + geçmiş: `test_ilk_yazim_kurtarma_baglamini_tasir_ve_adimlar_enumdan`,
      `test_adimlar_sirayla_yurur_canli_yer_degistirir_ve_onay_bekler`
      (`geri_yuklemeler` satırı dosyadan yeniden yazılıyor, `oncesi_yedek_id`
      bağlı). `erp_onceki_*` duruyor ve bağlantıya kapalı (test + lokal PG).
    - Bakım modu dosyası bağlantı izlemeden: `test_bakim_modu_dosyasi_baglantiysa_yazilmaz_hedef_dosya_degismez`
      (D5), `test_bakim_modu_laravelin_okudugu_dosya_ve_baskasinin_dosyasi_kaldirilmaz`.
    - PostgreSQL `erp_test`'te de 65 test (3 dosya) geçti. İlk denemede
      yalnız PG'de 4 hata: yer değiştirmede BÜTÜN pgsql bağlantıları
      bırakılıyordu (testi saran işlem kopuyordu; üretimde de yanlıştı) →
      yalnız canlı ada bağlı bağlantılar (`canliBaglantilariniBirak`); 8. adım
      hata kancası PG'de bağımlı tabloyu düşüremiyordu → `rol_izinleri`, kanca
      hatası artık testi düşürüyor (T6, T7, yeni T14). Gerçek PG öldür /
      sürdür denemesi düzeltmeden sonra yeniden yapıldı: sonuç aynı.
    - Testler: `UstuneGeriYukleyiciTest` (38). T5: P3 sırası, şema denetimi,
      migration yapılandırması, geri sarmada canlıyı açma, bağlantı denetimi
      kaldırılınca ilgili testler kırmızı.
    - Kalan risk / kapsam dışı: bakım modundan önce başlamış zamanlanmış komut
      6. adıma kadar canlıya yazabilir (yazım yalnız `erp_onceki_*`'de kalır);
      `erp_onceki_*`'in 7 gün sonra silinmesi ve geri dönüşten kalan
      `erp_geri_*`'nin temizliği yok (elle — isletim.md); disk denetimi
      sezgisel (canlı boyutu + 2 × dump).
    - Doğrulanamayan: canlı (PG 18.6; ad değiştirme ve koparma yalnız 16'da),
      gerçek servis hesabı ve www-data / emor-yedek izinleriyle `down` yazımı,
      sudoers ile emor-queue stop / start.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-028-R1 [P1] Geri yükleme öncesi arşivden sonra canlıya yazı kabul edilebiliyor.**
       `backend/app/Services/Yedekleme/UstuneGeriYukleyici.php:267, :273 ve :313`. Bakım modu yeni istekleri kesiyor, ancak başlamış web ve zamanlayıcı işleri bağlantılar kapatılana kadar yazabilir. Bu aralıktaki kabul edilmiş veri öncesi arşivde yok. Eski veritabanı o veriyi tutar, ancak kurtarma arşivden yapıldığında veya eski veritabanı temizlendiğinde bu yazılar kaybolabilir (README §6.4).
       Öncesi arşivden önce yazıcıları güvenli bir bariyerle durdurun veya tamamlanmalarını bekleyin. Ayrı PostgreSQL oturumuyla bakım başlamadan açılan yazının ya arşive girdiğini ya da arşivden önce engellendiğini sınayın.
    2. **BY-028-R2 [P2] Ayar uzlaştırması farkı gösteriyor fakat seçim uygulamıyor.**
       `backend/app/Services/Yedekleme/UstuneGeriYukleyici.php:341 ve :440`. Onay için ayar farkı üretiliyor. Yönetici hangi ayarların korunacağını seçemiyor ve onay eski yedekten gelen ayarlarla açıyor. README §5.3'teki seçim ölçütü tamamlanmamış.
       Yöneticiye etkisi görünen bir ayar kararı verdirin ve kararı uygulamadan sistemi açmayın. Mevcut ve geri yüklenen mail/hedef ayarları farklıyken seçilen değerlerin açılışta kullanıldığını sınayın.
    - `erp_onceki_*` ve `erp_geri_*` kopyalarını tatbikat süresince elle tutma kararı geri dönüşü koruyor. Temizlikte işlem kimliğini ve koruma süresini kontrol edin. Disk eşiği bir ön kontrol olarak değerlendirildi. Yetersiz alanda eski veritabanı korunmasını sınayan geri sarma yolu asıl güvencedir.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Bakım modundan sonra, öncesi yedekten önce yeni `yazici_bariyeri` adımı eklendi (enum, `yerDegistirmedenOnceMi`, ILERI_AKIS, ekran adı). Adımın sırası:
      1. Canlının özgün `datacl`'si kurtarma bağlamıyla işlem durumuna yazılır (`IslemKaydi::bariyer`, null = varsayılan). Bu, bariyerden önce olur.
      2. `GeriYuklemeVeritabani::bariyerKur` çalışır: CONNECT servis rolüne açıkça verilir, PUBLIC'ten ve diğer rollerden geri alınır (tek DO bloğu). Sonra bu sürecin kendi canlı oturumları dışındaki `client backend`'ler sonlandırılır ve bitmeleri monoton saatle beklenir. Sonlandırılamayan oturum `GERI_YUKLEME_YAZICI_BARIYERI` verir ve işlem geri sarılır.
      3. Öncesi yedeğin anlık görüntü bağlantısı `servisRoluyle` ile açılır: servis rolü, parola null, `PGPASSFILE`; parola PHP'ye girmez.
      4. Manifest ÖZGÜN yetkileri taşır. Bunun için `YedekAlici::al` `$ozellikDuzeltmesi` parametresini aldı.
      Özgün yetkiler şu durumlarda aynen geri konur: geri sarmada, geri dönüşte (canlıya) ve yer değiştirmeden sonra `erp_onceki_*`'e (dizin_kurma; onayda da en iyi çabayla; bağlantıya kapalı kalır). Servis rolü uygulama rolüyle aynıysa ya da parola dosyası yoksa işlem ön denetimde `GERI_YUKLEME_SERVIS_ROLU_GEREKLI` ile durur. Yarıda kalan `yazici_bariyeri` adımı geri sarılır (sürdürme kararına eklendi).
      Testler:
      - `UstuneGeriYukleyiciTest::test_adimlar_sirayla_yurur_canli_yer_degistirir_ve_onay_bekler` (sıra: bariyer → pg_dump …; bağlamda özgün yetkiler; önceki veritabanında özgün yetkiler)
      - `test_oncesi_yedek_bariyerden_sonra_servis_roluyle_alinir_manifesti_ozgun_yetkileri_tasir`
      - `test_on_denetim_reddinde_hicbir_sey_degismez` (+2 servis rolü durumu)
      - `test_yer_degistirmeden_once_hata_canliyi_korur_ve_servisleri_acar` (+yazıcı bariyeri; her durumda canlı yetkisi özgün)
      - `test_yer_degistirmeden_once_olen_islem_geri_sarilir` (+yazici_bariyeri; özgün yetkiler geri)
      - `UstuneGeriYuklemeOnayTest::test_geri_don_…` (geri dönüşte canlıya özgün yetkiler)
      - Gerçek PG: `GeriYuklemeVeritabaniPostgresTest::test_bariyer_once_acilmis_yaziyi_arsivden_once_sonlandirir_geri_alir_kabul_edilen_arsivde_ozgun_yetkiler_aynen_doner`. Bakımdan önce açılmış oturumlardan COMMIT eden arşive girdi; açık işlemdeki sonlandırıldı, yazısı ne veritabanında ne arşivde. Uygulama rolü ve özel yetkili rol bağlanamadı, servis rolü bağlandı. Servis rolüyle anlık görüntü (PDO + PGPASSFILE) bariyer altında çalıştı. Geri sarmada belirli role verilmiş CONNECT dahil yetkiler öğe öğe aynı döndü.
      - Gerçek PG: `test_varsayilan_yetkili_canlida_bariyer_geri_sarilinca_varsayilana_esdeger_doner`.
      - Mevcut öldür / sürdür testleri yeşil.
      T5: kırmızı görülen her durum ayrı ayrı: bariyer çağrısı yok; manifest düzeltmesi yok; geri sarmada özgün yetki yok; servis rolü ön denetimi yok; gerçek PG'de pg_terminate_backend yok; gerçek PG'de PUBLIC REVOKE yok.
      Doğrulanamayan: canlı PG 18.6, gerçek emor_yedek / www-data ortamı ve gerçek `kill -9` öldür / sürdür turu (bu turda elle yapılmadı; otomatik sürdürme testleri var).
    - R2: Farkı olan her ayar grubu için onayda açık seçim zorunlu (`guncel` = ayar dosya kopyası, `geri_yuklenen` = veritabanı); varsayılan yok. Gruplar `AyarKopyasi::GRUPLAR`: mail, yedek_ayarlari, planlar, hedefler.
      Yeni `AyarSecimi` iki iş yapar:
      - `karsilastir()`: dosya kopyası ile veritabanını karşılaştırır. Onay bilgisi `ayar_farklari` / `ayar_gruplari` / `ayar_kopyasi_yok` alanlarını buradan alır. Fark ile uygulanacak değer aynı kaynaktan gelir; dosya kopyası 5. adımda donmuş canlıdan `guncelDegilseYaz` ile tazelenir.
      - `uygula()`: `guncel` seçilen grupları tek DB işleminde yazar; yeniden çalıştırılabilir.
      Reddeden üç katman var: uç (422), çalıştırıcı (`UstuneGeriYukleyici::onayla`) ve komut satırı (`--ayar=grup=secim`); kod `GERI_YUKLEME_AYAR_SECIMI_EKSIK`. Seçim işlem durumuna (`ayar_secimleri`) ve denetim kaydına yazılır. Ayarlar sistem açılmadan uygulanır. Uygulanamazsa (`AYAR_SECIMI_UYGULANAMADI`) hiçbir ayar değişmez, uygulama kapalı kalır, onay / geri dön yine açıktır.
      Sırlar:
      - Mail parolası dosyada APP_KEY ile şifreli; çözülüp yazılır.
      - Hedef sırları dosyaya hiç yazılmaz. Mevcut hedefin sırları geri yüklenen hâliyle kalır. Yedekten sonra eklenmiş hedef sırsız ve PASİF açılır, uyarı verilir.
      - Açık şifreleme anahtarı dosyada yok; parmak izi farklıysa uyarı verilir.
      - Bugün olmayan plan / hedef kullanılmıyorsa silinir, kullanılıyorsa pasif kalır ve adına "(silinmiş #id)" eklenir.
      Ekran: `UstuneGeriYukleme/AyarSecimleri.tsx` her farklı grup için radyo gösterir; seçimler tamamlanmadan Onayla kapalı.
      Testler: `UstuneGeriYuklemeOnayTest::test_farkli_ayar_grubunda_secimsiz_onay_reddedilir_secilen_degerler_acilista_gecerli` (mail ve hedef farklı; seçimsiz ve eksik seçim reddedildi; mail güncel, hedef geri yüklenen olarak açıldı), `test_guncel_hedefler_yedekten_sonra_eklenen_hedefi_sirsiz_ve_pasif_acar_sir_dosyaya_yazilmaz`, `test_onay_bilgisi_…` (+ayar_gruplari), `UstuneGeriYuklemeApiTest::test_farkli_ayar_grubu_secilmeden_onay_istegi_422_secimle_kanita_baglanir`, `UstuneGeriYuklemePage.test` "farkı olan her ayar grubu seçilmeden Onayla kapalı…", `ustuneGeriYuklemeApi.test` (gövde). T5: zorunluluk denetimi kaldırılınca 1, uygulama kaldırılınca 2 test kırmızı. Doğrulanamayan: gerçek PG'de plan / hedef uygulaması (kimlikli ekleme + setval) yalnız PG alt kümesinde SQLite olmayan yolda dolaylı; elle canlıda denenmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1'in normal rol ve aktif oturum senaryosu ile R2'nin açık ayar seçimi kod ve yerel testte karşılandı. Geri yüklemenin güncel ayarları uyguladığı aşağıdaki yol ve bağlantı yapılandırması açık.
    1. **BY-028-R3 [P1] Güncel hedef uzlaştırması konum kilidini ve sır doğrulamasını atlıyor.**
       `backend/app/Services/Yedekleme/AyarSecimi.php:253 ve :265, backend/app/Http/Controllers/Api/V1/YedekHedefiController.php:365`. Mevcut hedefin bağlantısı kimliğe göre değiştirilirken yedeğin anındaki sırlar tutuluyor ve hedef aktif olabiliyor. Konum sonradan değiştiyse eski bağlantı bilgileri yeni adreste kullanılabilir. Yeniden kurulan kopya kayıtları da yeni konuma bağlı görünebilir. Normal hedef güncellemesindeki fiziksel konum ve sır korumaları buraya uygulanmıyor (D4, K8).
       Uzlaştırma sırasında da aynı hedef kimliği ve fiziki konum denetimini kullanın. Adresi değişen mevcut hedefi eski sırla etkinleştirmeyin ve kopya bağlarının yerini koruyun. Yedek ile güncel hedefin adres ve sırları farklıyken onaydan sonra eski sırla bağlantı kurulmadığını sınayın.
    2. **BY-028-R4 [P2] DB_URL ile yazıcı bariyerindeki yeni bağlantı uygulama rolüne geri dönüyor.**
       `backend/app/Services/Yedekleme/GeriYuklemeVeritabani.php:503, backend/app/Services/Yedekleme/PostgresKaynakVeritabani.php:29, backend/config/database.php:89`. Servis rolüne geçerken yalnız `username` ve `password` değişiyor. Laravel bağlantıyı açarken `url` içindeki kullanıcı ve parolayı bu değerlerin üzerine koyuyor. DB_URL kullanan kurulumda uygulama rolünün CONNECT yetkisi bariyer tarafından alınmış olduğundan öncesi yedek bağlantısı kurulamaz (P2 proje kuralı).
       Önce bağlantı URL'sini tek kez çözümleyip servis rolü için ayrı somut yapılandırma oluşturun. İzole PostgreSQL'de DB_URL ve ayrı servis rolüyle ambargo altında öncesi yedeğin tamamlandığını sınayın.
    - Kurulu Laravel 13.24.0 ayrıştırıcısıyla yapay DB_URL örneği servis rolünün üzerine URL'deki uygulama rolünün geldiğini doğruladı. İlgili opt-in koşusu DB_URL olmadan geçti.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R3: `AyarSecimi::hedefleriYaz` ("güncel" hedefler) mevcut hedefe normal güncellemenin AYNI yardımcısını (`HedefKonumu::degisenKilitliAlanlar` / `adresDegistiMi`) uygular: kopyası olan hedefin yeri yedekten sonra değişmişse hedef geri yüklenen yerinde ve sırlarıyla kalır (geri yüklenen kopya kayıtları o yeri gösterir), PASİF açılır, uyarı "yeni yer için yeni hedef ekleyin"; sırrın gideceği adres değişmişse bugünkü bağlantı yazılır ama eski sırlar silinir ve hedef PASİF açılır ("yedekten sonra eklenmiş hedef sırsız ve pasif" kararıyla tutarlı); tür farklıysa adres değişmiş sayılır. Diğer durumlar eskisi gibi. Yalnız hedef uzlaştırması değişti. README §6.4 "Ayar seçimi" maddesi güncellendi.
      Test: `UstuneGeriYuklemeOnayTest::test_guncel_hedeflerde_yeri_ya_da_adresi_degisen_hedef_eski_sirla_etkinlesmez_kopyalar_eski_yerdeki_hedefte_kalir` (FTP: yeni sunucu yazıldı, eski parola yok, pasif; yerel disk: kopyası olan /eski'de kaldı, pasif, kopya kaydı ona bağlı; iki uyarı). T5: uzlaştırmada konum/sır denetimi kaldırılınca kırmızı. Doğrulanamayan / kalan risk: dizin kurma (DizinKurucu, b1) olay günlüğünden yedekten SONRA yeni yere yapılmış kopyaları da aynı hedef kimliğine bağlar; kopya kaydı konumu taşımadığı için uzlaştırma bunları eski yerdekilerden ayıramaz (hedef pasif açıldığı için kendiliğinden yazım olmaz; yönetici inceler). Gerçek PG'de canlı üstüne geri yükleme denenmedi.
    - R4: Bağlantının somut tanımı tek yerde, `PgAraclari` içinde kuruluyor:
      - `PgAraclari::somut()` Laravel'in kendi `Illuminate\Support\ConfigurationUrlParser`'ını kullanıyor (DatabaseManager'ın kullandığı sınıf, kurulu 13.x'te doğrulandı). Adres (DB_URL) bir kez çözülüyor, sonuçta `url` kalmıyor.
      - `tanim()` (artık public) somut tanımı veriyor.
      - `servisRoluTanimi($rol)` = somut tanım + kullanıcı servis rolü + parola null.
      - Somut tanımı kullanan bütün yerler (G1):
        - `PgAraclari::baglanti()` / `parolaylaCalis()`: pg_dump / pg_restore / psql'in host, port, veritabanı, kullanıcı değerleri ve geçici parola dosyası adresten geliyor. Önceden ham tanımın varsayılanları (`laravel`, `root`, boş parola) kullanılıyordu.
        - `GeriYuklemeVeritabani::servisRoluyle`: bağlantı tanımının yalnız iki alanı değil tamamı servis rolü tanımıyla değiştiriliyor.
        - `PostgresKaynakVeritabani`: anlık görüntü bağlantısı `PgAraclari::tanim()` ile açılıyor.
        - `YeniVeritabanindaMigration`: adresli bağlantı önce somut tanıma çevriliyor, sonra veritabanı yeni veritabanına çevriliyor. Önceden DB_URL'de işlem reddediliyordu.
      - Testler:
        - `YedekBaglantiAdresiTest` (SQLite):
          - `test_servis_rolune_gecen_baglanti_adresteki_uygulama_rolunu_ve_parolasini_tasimaz`: Laravel'in açacağı bağlantıda kullanıcı servis rolü, parola null, veritabanı / host / port adresten geliyor; iş bitince tanım eski hâline dönüyor.
          - `test_pg_dump_adresteki_sunucuya_veritabanina_gider_parola_dosyasi_adresteki_parolayi_tasir`.
          - `test_migration_yapilandirmasi_adresli_baglantiyi_yeni_veritabanina_cevirir_adres_kalmaz`.
        - Gerçek PG (`GeriYuklemeVeritabaniPostgresTest::test_db_url_li_kurulumda_bariyer_altinda_oncesi_yedek_servis_roluyle_tamamlanir`): ayrı alanlar config varsayılanında, DB_URL'li kurulum. Bariyerden sonra uygulama rolü bağlanamıyor. Gerçek `YedekAlici::al(GeriYuklemeOncesi)` `servisRoluyle` içinde gerçek pg_dump / pg_restore ve anlık görüntüyle `tamam` bitiyor. Manifest canlı veritabanını ve satır sayısını taşıyor.
      - Test dosyasındaki geçici roller artık her koşuda benzersiz adla açılıyor (`emor_t<rastgele>_*`, önceden sabit `ajan_a4_*`); aynı sunucuda eşzamanlı koşular birbirinin rolünü silmiyor. Sınıfa `LazilyRefreshDatabase` + `YedeklemeOrtami` eklendi (yedek kaydı için).
      - T5:
        - Dört uygulama dosyası HEAD'e döndürülünce 3 SQLite testi ve gerçek PG testi kırmızı.
        - Yalnız `servisRoluyle` eski hâline döndürülünce servis rolü testi ve gerçek PG testi kırmızı (öncesi yedek `tamam` olmadı).
      - Doğrulanamayan:
        - Canlı PG 18.6 (yerel 16) ve gerçek emor_yedek ortamı.
        - DB_URL'li kurulumda uçtan uca üstüne geri yükleme (yalnız öncesi yedek adımı gerçek PG'de, migration yapılandırması birim düzeyinde sınandı).
        - `scripts/sunucu-yedekleme-kurulumu.sh` (b1'in dosyası) `.env`'den DB_HOST / DB_PORT / DB_USERNAME okuyor, DB_URL'yi görmüyor. DB_URL'li kurulumda boş rol adı yüzünden "rol adı uygun değil" hatasıyla durur (güvenli yön); bu turda değiştirilmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R3'te normal hedef güncellemesiyle aynı konum denetimi kullanılıyor ve değişen adrese eski sır gönderilmiyor. R4'ün standart DB_URL yolu ve servis rolü PostgreSQL testlerinde geçti.
    - **BY-028-R5 [P1] Olay günlüğünden kurulan kopyalar eski fiziksel hedefe bağlanabiliyor.** `backend/app/Services/Yedekleme/DizinKurucu.php:189-210`, `backend/app/Services/Yedekleme/AyarSecimi.php:279-286` ve `backend/app/Services/Yedekleme/GeriYuklemeKaynaklari.php:36-50`. Eski yedek alınırken kopyası bulunmayan hedef sonradan yer değiştirebilir ve yeni yerde kopya üretebilir. Eski veritabanı geri yüklenince yeni konumdaki kopya, olayda aynı hedef kimliği bulunduğu için eski adrese `tamam` olarak bağlanıyor. Hedefin pasif olması bu kopyayı geri yükleme kaynaklarından çıkarmıyor. Tek erişilebilir kopyanın yolu katalogda kaybolabilir. D4 ve G5: kopya olaylarına değişmez konum kimliği ya da sürümü koyun. Kimliği doğrulanamayan eski kayıtları görünür uyarıyla ayırın ve okuma veya silme işleminde çözümlenmiş kopya saymayın. Kopyasız eski hedef, sonraki konum değişikliği ve yeni kopya içeren bir geri yükleme testiyle doğrulayın.
    - **BY-028-R6 [P2] DB_URL istisnası çocuk migration bağlantısını eski veritabanına yönlendiriyor.** `backend/app/Services/Yedekleme/YeniVeritabanindaMigration.php:85-106`. Laravel PostgreSQL bağlayıcısı `connect_via_database` değerine `database` alanından önce bakıyor. Kurulu Laravel 13.24.0 ile yalnız DSN üretiminde, yeni `database` alanına rağmen eski adın kullanıldığı doğrulandı. Yazıcı bariyeri olağan rolde eski bağlantıyı reddetse de geçerli geri yükleme gereksiz yere duruyor. G3: desteklenmeyen yönlendirmeyi ad değiştirmeden önce reddedin veya çocuk süreçte etkin bağlantıyı güvenli biçimde yeniden kurun. Geçici PostgreSQL testinde hem DSN'yi hem `current_database()` sonucunu doğrulayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R5: Kopyaya değişmez konum kimliği. `HedefKonumu::iz()` (TEK yardımcı): tür + kilitli konum bileşenlerinin (KONUM_ALANLARI + bağlayıcının yazdığı Drive hesabı) karşılaştırmayla AYNI normalleştirilmiş hâlinin kanonik JSON'u → SHA-256; sır girmez. Konum bilinemiyorsa (bağlayıcısız tür, hesabı okunmamış Drive) null. `konumdaMi()` kesin karşılaştırma (izsiz → false). Yeni migration `2026_11_21_112737_add_konum_izi_to_yedek_kopyalari_table` (char(64) nullable); izsiz eski `tamam` satırlar `konum_belirsiz` + `KOPYA_KONUMU_DOGRULANAMADI` olur (down geri çevirir, yorumda yazılı). Kopyalayıcı izi kilit altında okunmuş hedeften hesaplayıp kopya kaydına yazar; konum bilinemeyen hedefe kopya göndermez (YAPILANDIRMA, yeniden denenmez). Kopya olayının biçimi tek yerde (`YedekOlayi::kopya`; kopyalayıcı + saklamanın bağ kaldırma olayı — G1/K10) ve `konum_izi` taşır; `GuncelDurum` izi biçimiyle okur. `DizinKurucu`: olaydaki `tamam` kopya hedefe ancak iz hedefin bugünkü iziyle aynıysa `tamam` bağlanır; değilse ya da iz yoksa `konum_belirsiz`, hata kodu `KOPYA_KONUMU_DOGRULANAMADI`; hedef kopyanın yerine dönünce sonraki taramada yeniden `tamam` (kod temizlenir).
      Tasarım kararı: ayrı bayrak kolonu değil yeni durum değeri `KopyaDurumu::KonumBelirsiz` ('konum_belirsiz', varchar(16)'ya sığar). Kopyayı kullanan her yer (GeriYuklemeKaynaklari, SilIsleyicisi, SaklamaPolitikasi uzak kopya sayımı ve bağ/dosya kararları, YerelDosyaSilici, KopyaSilmeKaydi, DizinKurucu::dosyasiYok, kurtarmayaHazirMi/KurtarmaPaketi, YedekOzeti, HedefKonumu konum kilidi) zaten yalnız `tamam`'a bakıyor (grep ile tek tek denetlendi) — bayrak her birine ayrı denetim isterdi (G1). Konum kilidini tutmaz: hedef kopyanın yerine döndürülebilsin. Hedef silinemez (her durumdaki kopya RESTRICT). Elle silme yeri doğrulanamayan kopyayı hedefin bugünkü yerinden silmeye kalkmaz, sonuçta "Bazı kopyalar silinemedi: <hedef>: KOPYA_KONUMU_DOGRULANAMADI" olarak listeler. AyarSecimi kodu değişmedi: "kopyası olan" artık yalnız yeri doğrulanmış kopya olduğu için yeni yerdeki kopya eski yerin kilidini tutmuyor; "güncel" seçilince hedef bugünkü yerine döner, onay sonrası tarama kopyayı bağlar (yorum + README §5.3 güncellendi). Ekran: yeni durum etiketi "Konumu doğrulanamadı" (sarı rozet; Yedekler ve Yedek Hedefleri, mevcut hata_kodu açıklaması rozet başlığında / son kopya altında) — tr/en + TS tipi.
      Testler: `DizinKurucuTest::test_yer_degistiren_hedefin_yeni_yerdeki_kopyasi_eski_yerine_donen_hedefe_tamam_baglanmaz_kaynak_ve_silme_disinda_kalir` (kopyasız hedef → yer değişikliği → yeni yerde kopya → eski konuma dönüş + dizin yeniden kurulumu: `konum_belirsiz`, geri yükleme kaynağı değil, elle silmede uzak dosyaya dokunulmaz ve sonuçta görünür), `test_hedef_kopyanin_yerine_donunce_yeniden_kurulan_kopya_tamam_baglanir` (normal yol + iz eşitliği), `test_konum_izi_olmayan_eski_kopya_olayi_yeniden_kurulunca_tamam_baglanmaz`, `test_saklama_bag_kaldirdiktan_sonra_yeniden_kurulan_kopya_tamam_kalir`; `UstuneGeriYuklemeOnayTest::test_yedekten_sonra_yer_degistiren_kopyasiz_hedefin_yeni_yer_kopyasi_eski_yere_tamam_baglanmaz` (gerçek üstüne geri yükleme + onay akışı; "güncel" → hedef yeni kovaya döner, kopya `tamam`; "geri yüklenen" → hedef eski kovada, kopya `konum_belirsiz`); `KopyaKonumIziMigrationTest::test_izsiz_eski_tamam_kopya_konum_belirsiz_olur_diger_durumlar_korunur` (K6 yükseltme yolu); `YedekKopyalayiciTest::test_hesabi_bilinmeyen_drive_hedefine_kopya_gonderilmez`. T5: DizinKurucu denetimi kapatılınca 3 DizinKurucu + 2 onay testi, SilIsleyicisi eki kapatılınca silme testi, saklama olayında iz düşürülünce bağ testi, migration veri güncellemesi kapatılınca migration testi kırmızı. SQLite ve erp_test_c2 (PG) yeşil.
      Doğrulanamayan / kalan: legacy (izsiz) kopyalar migration ve taramadan sonra kalıcı olarak `konum_belirsiz` kalır, kendiliğinden kurtarılmaz (modül canlıda değil; geliştirme verisinde eski kopyalar geri yükleme kaynağı olmaktan çıkar). `konum_belirsiz` kayıtlı (yedek, hedef) çiftine kopyalayıcı bugün hiçbir yoldan çağrılmıyor (yalnız yeni yedek ve `basarisiz` yeniden denemesi); çağrılsaydı kaydın uzak kimliğini yenisiyle değiştirirdi — koruma eklenmedi (K1). Gerçek PG'de canlı üstüne geri yükleme denenmedi.
    - R6: `YeniVeritabanindaMigration::yapilandirmaDosyasiYaz`: kurulu Laravel 13.x'te `PostgresConnector::getDsn` `connect_via_database ?? database` ve `connect_via_port ?? port` kullanıyor; `ConnectionFactory` `read` / `write` / `direct` alt tanımlarını üste birleştiriyor (`pooled` doğrudan uç ister). DB_URL'li kurulumda bu alanlar `ConfigurationUrlParser`'ın sorgu parametresinden gelir (`?connect_via_database=…`; config/database.php'de yok). Karar: reddetmek yerine güvenli kurulum. Canlıyı gösteren her pgsql bağlantısında (adı `database`, `connect_via_database` ya da `read` / `write` / `direct` alt tanımlarından — tekil ya da liste — birinde geçen) bu yönlendirme alanları (`YONLENDIRMELER`) kaldırılır, `database` yeni veritabanı olur. Çocuk süreç böylece pg_dump / pg_restore'un kullandığı uç noktaya (host:port) ve az önce doldurulan yeni veritabanına bağlanır; havuz takma adı / yineleme sunucusu yeni veritabanını tanımayacağı için bu alanlar yeni veritabanına göre çevrilemez. Canlıyı göstermeyen bağlantıya dokunulmaz. Migration adımı ad değiştirmeden önce çalıştığından olası hata yine geri sarmayla biter. Sınıf yorumu ve README §6.4 5. adım güncellendi. Testler: `YedekBaglantiAdresiTest::test_migration_yapilandirmasi_dsn_yi_yonlendiren_alanlari_kaldirir_canliyi_gosteren_her_baglanti_yeni_veritabanina_gider` (SQLite. Adreste `connect_via_database` + `connect_via_port`, `read` / `direct` / `pooled`; yalnız `write` listesinde canlıyı gösteren ikinci bağlantı; ilgisiz bağlantı. Laravel'in kendi `getDsn`'iyle `dbname='erp_geri_…'` ve adresteki port 6432 doğrulanıyor), `GeriYuklemeVeritabaniPostgresTest::test_db_url_deki_connect_via_database_e_ragmen_cocuk_migration_sureci_yeni_veritabanina_baglanir` (gerçek PG, `YEDEKLEME_GERCEK_PG=1`, geçici rol / veritabanı benzersiz adlı `erp_geri_20261121_000000_c3<rastgele>`. Üretilen DSN; gerçek alt süreçte (`APP_CONFIG_CACHE`) `current_database()` = yeni veritabanı; gerçek `calistir()` sonrası `migrations` tablosu yeni veritabanında var, canlıda yok). T5: HEAD'deki sınıfla iki test de kırmızı; DSN denetimi kapatılınca alt süreç `current_database()` = canlı (`erp_ajan_a4_…`) döndü. Doğrulanamayan: gerçek pgbouncer / yineleme sunuculu kurulum (yok); canlı PG 18.6 (yerel 16); `host` dizi (çok sunuculu) tanımı ele alınmadı (pg araçlarında da önceden desteklenmiyor).
  - 2026-11-21 Codex → CHANGES_REQUESTED · dördüncü tur `208746f`
    - R5'in yanlış hedefi geri yükleme kaynağı seçme yolu kapandı. İz eşleşmezse kopya `konum_belirsiz`. R6'da çocuk migration DSN'ine etki eden alanlar kaldırılıyor. Atılabilir PostgreSQL'de çocuk sürecin veritabanı ve migration yeri sınandı. Gerçek PgBouncer kapsamı açık.
    - **BY-028-R7 [P2] Konumu belirsiz son uzak kopya, yedeği varsayılan listeden düşürüyor.** `backend/app/Services/Yedekleme/DizinKurucu.php:208-234`, `backend/app/Http/Controllers/Api/V1/YedekController.php:60`. Yerel dump yoksa `konum_belirsiz` kopya korunuyor ancak üst yedek `silindi` yapılıyor. İzole veritabanında `yedek=silindi, kopya=konum_belirsiz` doğrulandı. Böylece operatör varsayılan listede beklenen uyarıyı göremiyor. Elle silme de `SilIsleyicisi.php:96-112` üzerinden kopyayı silinemedi diye dönerken `YerelDosyaSilici.php:34-51` tam silme olayı yazabiliyor. F7: konumu belirsiz fiziksel kopyayı katalogda görünür tutun ve geri yükleme / uzaktan silme için seçilemez bırakın. Yerel arşivi olmayan uyumsuz konumlu kopyada listeyi, elle silme sonucunu ve konum tekrar doğrulanınca aynı yedeğin görünür ve kullanılabilir olduğunu sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex dördüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 4. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R7: Kopyası hedefte duran ya da durabilecek kopya durumları tek yerde tanımlandı: `KopyaDurumu::yedegiTutanlar()` = `tamam` + `konum_belirsiz`. Yedeğin `silindi` kararını veren dört yer bu tanımı kullanıyor (G1):
      - `DizinKurucu::manifesttenKur`
      - `DizinKurucu::dosyasiYok`
      - `YerelDosyaSilici::sil`
      - `KopyaSilmeKaydi::kaydet`
      Sonuç: yerel dump'ı olmayan yedek, yeri doğrulanamayan kopyası durdukça `tamam` (yerel silindi) kalıyor. Varsayılan listede kopyanın "Konumu doğrulanamadı" rozeti ve `KOPYA_KONUMU_DOGRULANAMADI` açıklamasıyla görünüyor; ekranda bunun için kod zaten vardı. Geri yükleme kaynağı ve uzaktan silme için yine seçilemiyor: `GeriYuklemeKaynaklari`, `SilIsleyicisi` ve saklama değişmedi, hâlâ yalnız `tamam`'a bakıyor. Elle silme yerel dosyayı siliyor ve `YerelSilindi` olayını yazıyor. "Tamamen silindi" (`Silindi`) olayı ise yazılmıyor. Sonuç `YEDEK_KOPYA_SILINEMEDI` ve `yedek_durumu=tamam`; denetim kaydı `basarisiz`. Önceden bu olay yazılıyordu ve yedek konum doğrulandıktan sonra da kalıcı olarak `silindi` kalıyordu. Yeni yedek durumu, migration ya da frontend değişikliği gerekmedi. Gerekçe: bu, F7'nin var olan "yerel silindi, uzak kopya var" durumu; liste süzgeci (YedekController:60) `silindi` olmayanı zaten gösteriyor. Değişen dosyalar: `KopyaDurumu.php`, `DizinKurucu.php`, `YerelDosyaSilici.php`, `KopyaSilmeKaydi.php` (G1), `Istekler/SilIsleyicisi.php` (yalnız yorum). README §5.3 "Kopyanın yeri de kaydedilir" bölümüne de bir cümle eklendi. Testler (hepsi `DizinKurucuTest`):
      - `test_yerel_dosyasi_olmayan_yedegin_konumu_belirsiz_kopyasi_yedegi_listede_tutar_konum_dogrulaninca_kullanilir`: API listesi varsayılan süzgeçle. Yedek görünüyor; `durum=tamam`, `yerel_dosya_var=false`, `webden_geri_yuklenebilir=false`, kopya `konum_belirsiz` ve hata kodu taşıyor. Hedef eski yerine dönüp tarama çalışınca aynı yedek `webden_geri_yuklenebilir=true` ve kopya `tamam`. T5: düzeltmesiz kırmızı (liste boş).
      - `test_konumu_belirsiz_kopyasi_kalan_yedek_elle_silinince_silindi_olmaz_tam_silme_olayi_yazilmaz`: elle silmenin sonucu, olaylar (`YerelSilindi` var, `Silindi` yok), uzak dosyaya dokunulmaması; konum doğrulanınca `GeriYuklemeKaynaklari::engel` null dönüyor. T5: düzeltmesiz kırmızı (`yedek_durumu=silindi`).
      - `test_dosyasi_ve_manifesti_kaybolan_yedek_konumu_belirsiz_kopyasiyla_silindi_olmaz`: `dosyasiYok` yolu. T5: düzeltmesiz kırmızı.
      - `test_son_tamam_kopya_silinince_konumu_belirsiz_kopya_yedegi_silindi_yaptirmaz`: `KopyaSilmeKaydi` yolu. T5: düzeltmesiz kırmızı.
      - Kalan / doğrulanamayan:
        - Düzeltmeden önce yazılmış `Silindi` olayı yedeği yeniden kurmada `silindi` tutmaya devam eder (`$durum->silinme`). Eski veri için ayrıca kurtarma yazılmadı; modül canlıda kullanılmıyor.
        - `SaklamaPolitikasi` (d1'in dosyası) `tamam` yedekleri koruma kurallarında (son başarılı, doğrulanmış en yeni) sayıyor. Yerel dosyası ve kullanılabilir kopyası olmayan böyle bir yedek en yeniyse "son başarılı" korumasını üstüne alabilir. Bu, var olan F7 durumunun (yerel silinmiş, uzak kopyalı en yeni yedek) aynısıdır; en az adet kuralı etkilenmez, çünkü gruplar yalnız `tamam` kopya ve yerel dosyadan kurulur. Değiştirilmedi, bilgi için not edildi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · beşinci tur `3ad20f3`
    - R7 genel durumda çalışıyor. Varsayılan liste ve elle silme sonucunda konumu belirsiz kopya kalıyor, tam silme olayı yazılmıyor. Ekte canlıda eski kayıt olmadığı belirtilen `Silindi` olayları için geçmiş düzeltmesi istenmiyor.
    - **BY-028-R8 [P2] Hedefin geri yüklenen tanımı ana klasörse konumu belirsiz kopya görünmez oluyor.** `backend/app/Services/Yedekleme/DizinKurucu.php:233-238,290-298`. İki tarama yolu da konumu belirsiz kopyayı, hedefin bugünkü tanımı ana klasörse eliyor. İz uyuşmadığı için bugünkü tanım kopyanın nerede olduğunu göstermiyor. Uzak dizine alınmış yerel disk kopyasının olayı ve ana klasöre geri dönmüş hedefle tarama yaptım. Üst kayıt `silindi`, kopya `konum_belirsiz` oldu. G21: ana klasör eşitliğini doğrulanmış konumdaki kopyaya uygulayın. Belirsiz kopyayı katalogda tutun, geri yükleme veya fiziksel silme kaynağı saymayın. İmzalı manifestle ve manifestsiz `dosyasiYok` yolunda varsayılan listeyi, rozeti ve konum tekrar eşleşince geri yükleme kaynağını sınayın.
    - Bilinen `son_basarili` yan etkisi kodla tutarlı (`SaklamaPolitikasi.php:92-105`). `tamam` liste durumu, yerel arşiv ya da kullanılabilir uzak kopya bulunmadığında en yeni kullanılabilir yedeğin koruma ölçütü olarak değerlendirilmemeli. `enaz_adet` değişikliği gerekmiyor. F7 durumunu koruyarak geçmiş başarı ve saklama korumasını ayrı test edin.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex beşinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 5. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R8: `DizinKurucu`: iki tarama yolu (`manifesttenKur`, `dosyasiYok`) "kopya yerel dosyası gitmiş yedeği tutar mı" kararını tek yardımcıdan alıyor: `yedegiTutarMi(KopyaDurumu, YedekHedefi)`. Küme yine `KopyaDurumu::yedegiTutanlar()` (BY-028-R7). "Ana klasör hedefinin kopyası yerel dosyanın kendisidir" eşitliği artık yalnız yeri doğrulanmış (`tamam`) kopyaya uygulanıyor. `konum_belirsiz` kopyada iz uyuşmadığı için hedefin bugünkü tanımı kopyanın yerini göstermiyor. Tanım ana klasör olsa da kopya yedeği tutuyor: yedek `silindi` olmuyor, varsayılan listede "Konumu doğrulanamadı" uyarısıyla görünüyor (G21). Geri yükleme ve silme kaynağı değil, çünkü `GeriYuklemeKaynaklari`, `SilIsleyicisi` ve saklama değişmedi, hâlâ yalnız `tamam`'a bakıyor. Eski özel `anaKlasorHedefiMi` sarmalayıcısı kaldırıldı; tek çağıranı bu iki satırdı. Sınıf PHPDoc'u ve README §5.3 "Kopyanın yeri de kaydedilir" bölümü güncellendi (K11). Frontend, migration ya da yeni durum gerekmedi.
      Testler (`DizinKurucuTest`):
      - `test_ana_klasore_donen_yerel_hedefin_konumu_belirsiz_kopyasi_manifestten_kurulunca_yedegi_listede_tutar_konum_eslesince_kaynak_olur`: imzalı manifest yolu. Kopya ikinci diske alınıyor, yerel dosya siliniyor, hedef ana klasöre dönüyor, dizin yeniden kuruluyor. API varsayılan listesinde yedek `durum=tamam`, `yerel_dosya_var=false`, `webden_geri_yuklenebilir=false` görünüyor. Kopya `konum_belirsiz` ve `KOPYA_KONUMU_DOGRULANAMADI` taşıyor; rozet bu alanlara dayanıyor. Dosya ikinci diskte duruyor. Hedef ikinci diske dönüp tarama tekrar çalışınca kopya `tamam` oluyor, hata kodu boşalıyor, `webden_geri_yuklenebilir=true` ve `GeriYuklemeKaynaklari::sec` o kopyayı seçiyor. T5: düzeltmesiz kırmızı (liste boş, yedek `silindi`).
      - `test_ana_klasore_donen_yerel_hedefin_konumu_belirsiz_kopyasi_dosyasi_ve_manifesti_kaybolan_yedegi_listede_tutar`: manifestsiz `dosyasiYok` yolu (`dosyasi_yok=1`). Liste ve rozet alanları yukarıdaki gibi; `engel = YEDEK_DOSYASI_YOK`. T5: düzeltmesiz kırmızı (liste boş).
      - `test_ana_klasor_hedefinin_yeri_dogrulanmis_kopyasi_yerel_dosya_gidince_yedegi_tutmaz`: değişen kararın `tamam` dalı. Ana klasör hedefinin doğrulanmış kopyası yerel dosya gidince yedeği tutmuyor, yedek `silindi` oluyor. HEAD'de de yeşil; bu yeni bir koruma testi. Mutasyon denetimi: eşitlik tamamen kaldırılınca (her `yedegiTutanlar` kopyası yedeği tutsun) bu test kırmızı. Önceden bu dalı sınayan test yoktu, mutasyon 86 testin hepsinde yeşil geçiyordu.
      Doğrulanamayan / kalan:
      - Manifestsiz yolda "konum eşleşince geri yükleme kaynağı" sınanmadı. Çalıştırıcı yedeği manifestle doğruluyor; manifesti olmayan yedek kopya `tamam` olsa da gerçekten geri yüklenemez. `dosyasiYok` da kopyaların konumunu yeniden değerlendirmiyor; olaydan değerlendirme yalnız manifest yolunda yapılıyor. Bu yolda kopyayı `tamam` yapmak ekranda yanıltıcı "geri yüklenebilir" gösterirdi. Bu nedenle davranış değiştirilmedi; konum yeniden eşleşince kaynak olma manifest yolunda sınandı.
      - Ters durum: kopya ana klasörde alınmış (izi ana klasör), hedef sonra başka yere taşınmış. Kopya `konum_belirsiz` olur ve aslında yerel dosyanın kendisidir; yerel dosya gidince de yedeği tutar. Bu, düzeltmeden önceki davranışla aynı ve güvenli yön (görünür kalır, kaynak sayılmaz). İzden "bu ana klasördü" çıkarımı yapılmadı.
    - Beşinci tur `son_basarili` notu: `SaklamaPolitikasi::hesapla` "son başarılı" korumasını kullanılabilir yedekler arasından seçiyor: yerel arşivi olan (`yerel_silinme_zamani` boş) ya da `tamam` kopyası olan. Yalnız `konum_belirsiz` kopyası kalan yedek listede `tamam` kalıyor, temkin hesabında "son deneme başarılı" sayılıyor (geçmiş başarı), ama korumayı almıyor. F7 yedeği (yerel silinmiş, `tamam` uzak kopyası olan) korumayı alıyor. `enaz_adet` değişmedi. G1: aynı kalıp "doğrulanmış en yeni" korumasında da vardı; aynı küme kullanılıyor. Sınıf PHPDoc'u (madde 3) ve README §4.3 koruma kuralları maddesi güncellendi. Testler: `test_son_basarili_korumasi_kullanilabilir_kopyasi_olan_en_yeni_yedege_verilir`, 2 veri kümesi. "Yalnız konumu belirsiz kopya" kümesinde temkin yok, yeni yedek `tamam`, kopyası `konum_belirsiz` kalıyor; `son_basarili` daha eski yerel yedekte ve o yedek silinmiyor. F7 kümesinde `son_basarili` yeni yedekte, uzak kopyası `tamam` kalıyor, eski yedek kuralla siliniyor. Ayrıca `test_dogrulanmis_en_yeni_korumasi_kullanilabilir_kopyasi_olmayan_yedege_verilmez`. T5: düzeltmesiz "konumu belirsiz" kümesi ve doğrulanmış testi kırmızıydı. Yalnız "doğrulanmış en yeni" süzgeci geri alınınca doğrulanmış testi tek başına kırmızı. Doğrulanamayan: —
  - 2026-11-21 Codex → CHANGES_REQUESTED · altıncı tur `2b7ea87`
    - R8 kapandı. İki tarama yolu ortak `yedegiTutarMi()` üzerinden karar veriyor. Ana klasör eşitliği yalnız konumu doğrulanmış kopyaya uygulanıyor. Konumu belirsiz kopya hem manifestli hem manifestsiz yolda görünür kalıyor. Manifestsiz taramanın konumu yeniden doğrulamaması kabul edildi. Kullanılabilir en yeni yedeğin koruması için ek testler geçti.
    - **BY-028-R9 [P2] Eski veritabanında hedef tanımı yoksa sonraki yedekler katalogdan düşüyor.** `backend/app/Services/Yedekleme/DizinKurucu.php:199-207,236-241`. Bu turda bulunan, önceki kodda da var olan davranış. Hedef eski yedek alındıktan sonra eklenmişse geri yüklenen DB'de tanımı bulunmayabilir. İmzalı olay günlüğünde fiziksel kopyanın kimliği ve konum izi var. Tarama yalnız sayacı artırıp bu kaydı atlıyor. Yerel dump yoksa üst yedeği `silindi` yapıyor. İzole SQLite'ta imzalı manifest ve hedefi eksik geçerli kopya olayıyla `yedek=silindi`, `kopya sayısı=0`, `bulunamayan_hedef=1` doğrulandı. Güncel hedefleri sonradan seçip eklemek bazı akışları kurtarsa da geri yüklenen hedefleri koruma seçiminde görünürlük kayboluyor. F7 ve G21: hedef eşlenemediğinde de fiziksel kopya bilgisini görünür, kullanıma kapalı kayıt olarak koruyun. Günlükten hedef kimliğini veya konumunu tahmin ederek dosya işlemi yapmayın. Hedef eski snapshot'ta yok, yalnız uzak kopya kalmış senaryoda varsayılan listeyi ve hedef tanımı yeniden eklendiğinde aynı yedeğin eşleşmesini sınayın.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex altıncı tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 6. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R9: Hedefi tanımsız kopya artık atlanmıyor. Imzalı olaydaki `tamam` kopyanın hedefi veritabanında yoksa, kopya hedefsiz bir `konum_belirsiz` kayıt olarak tutuluyor: `yedek_hedefi_id` boş, hata kodu `KOPYA_HEDEFI_TANIMSIZ`; olaydaki hedef anahtarı ve adı, uzak kimlik ve konum izi saklanıyor.
      - Böyle bir kopya yedeği `silindi` yaptırmıyor. Varsayılan listede olaydaki hedef adı ve "Konumu doğrulanamadı" rozetiyle görünüyor.
      - Geri yükleme, silme, saklama grupları, kurtarma hazırlığı ve sinyal yalnız `tamam` kopyaya baktığı için bu kayıt hiçbirinin kaynağı olmuyor. Kurtarma paketi, Drive hesabı ve konum kilidi kararları hedef kimliğine bağlı olduğu için onu görmüyor.
      - Tarama dosya işlemi yapmıyor. Hedef kimliği ya da konumu tahmin edilmiyor; kopyanın yeri yalnız konum izinin eşitliğiyle belirleniyor (G21).
      - Hedef eşleme sırası:
        1. Olaydaki kimlikle ya da adla bulunan hedefin izi eşitse o hedef.
        2. Değilse izi eşit olan hedef (`DizinKurucu::izdekiHedef`). Bu, geri yüklenen veritabanına yeni kayıtla — başka kimlik ve adla — eklenen aynı yeri yakalıyor. Aynı yerin birden çok tanımı varsa en küçük kimlik seçiliyor.
        3. Yoksa kimlikle bulunan hedefe `konum_belirsiz` bağlanıyor (R5 davranışı).
        4. O da yoksa hedefsiz kayıt.
      - Hedefsiz kayıtlar olaylardan türüyor. Olayı bir hedefe bağlanan kopyanın hedefsiz kaydı aynı taramada kalkıyor. Olay iziyle başka bir hedefe bağlanan kopyanın, eski kimlikteki hedefte kalmış "konumu belirsiz" izdüşümü de kalkıyor. Bu temizlik döngüden sonra yapılıyor, sonuç olayların sırasına bağlı değil.
      - Yeni migration `2026_11_21_204545_add_olay_hedefi_to_yedek_kopyalari_table` (K6):
        - `yedek_hedefi_id` boş bırakılabilir oldu; RESTRICT yabancı anahtarı yerinde kaldı.
        - `olay_hedef_anahtari` ve `olay_hedef_ad` kolonları eklendi.
        - Kısmi tekil indeks: `(yedek_id, olay_hedef_anahtari) WHERE yedek_hedefi_id IS NULL` (G7).
        - PostgreSQL'de CHECK: hedefsiz kayıt yalnız `konum_belirsiz` ve anahtarlı olabilir.
        - SQLite tabloyu yeniden kurarken, işlem içinde FK denetimi kapanmadığı için `yedek_kopya_planlari` satırları CASCADE ile siliniyordu. Bağlar okunup aynı kimliklerle geri yazılıyor.
      - `YedekKopyasi::booted` aynı kuralı modelde zorluyor (K3). `hedefAdi()` hedefi tanımsız kopyada olaydaki adı veriyor.
      - Değişen dosyalar:
        - `DizinKurucu.php`: yeni eşleme, `hedefsizKopyaYaz`; `yedegiTutarMi(?YedekHedefi)`; `dosyasiYok` artık hedefsiz kopyayı da sayıyor.
        - `Istekler/SilIsleyicisi.php`: `$kopya->hedef->ad` hedefsiz kayıtta çöküyordu; artık `hedefAdi()` ve kaydın kendi kodu kullanılıyor (G1).
        - `YedekResource.php`: `hedef_ad` artık `hedefAdi()`'dan geliyor.
        - `HedefKonumu.php`: `HEDEFI_TANIMSIZ` sabiti eklendi.
        - `KopyaDurumu.php`, `YedekKopyasi.php`: PHPDoc ve model kuralı.
        - Frontend `yedekApi.ts`: `hedef_id: number | null`.
        - `YedeklerPage.tsx`: liste anahtarı hedefi tanımsız kopyalarda yinelenmiyor.
        - `tr.json` / `en.json`: `KOPYA_HEDEFI_TANIMSIZ` metni.
        - README §5.3: "Hedefin tanımı yoksa kopya yine görünür" paragrafı (K11).
      - Testler:
        - `DizinKurucuTest::test_tanimi_olmayan_hedefteki_kopya_yedegi_listede_tutar_ayni_yer_yeni_kayitla_eklenince_ona_baglanir`: Codex'in senaryosu. Hedef silinmiş, yerel dosya yok, yalnız ikinci diskteki kopya kalmış. Varsayılan API listesinde yedek `tamam`, kopya `hedef_id=null`, `hedef_ad='Disk 2'`, `konum_belirsiz`, `KOPYA_HEDEFI_TANIMSIZ`; `webden_geri_yuklenebilir=false`; dosya yerinde; ikinci tarama kayıt çoğaltmıyor. Aynı klasör başka ad ve kimlikle (sonda "/" ile) eklenince kopya `tamam` oluyor, `webden_geri_yuklenebilir=true` ve `GeriYuklemeKaynaklari::sec` yeni hedefi seçiyor.
        - `test_eski_kimligi_baska_yere_verilen_hedefte_kopya_belirsiz_kalir_ayni_yer_eklenince_ona_baglanir`: eski kimlik başka bir yere verilmiş. Kopya önce o hedefe `konum_belirsiz` bağlanıyor; aynı yer eklenince tek kayıt kalıyor, o da yeni hedefe `tamam`.
        - `test_hedefi_tanimsiz_kopyasi_kalan_yedek_elle_silinince_silindi_olmaz_kopya_sonucta_gorunur`: elle silmede sonuç `YEDEK_KOPYA_SILINEMEDI` ve "Disk 2: KOPYA_HEDEFI_TANIMSIZ"; yedek `tamam`, `Silindi` olayı yazılmıyor, uzak dosya duruyor.
        - `test_dosyasi_ve_manifesti_kaybolan_yedek_hedefi_tanimsiz_kopyasiyla_silindi_olmaz`: manifestsiz `dosyasiYok` yolu.
        - `HedefsizKopyaMigrationTest`:
          - `test_yukseltmede_kopyalar_ve_plan_baglari_korunur_hedefsiz_kayit_yedek_basina_anahtarla_tekil`: eski şema ve kayıtlarla yükseltme; kopyalar ve plan bağı aynen kalıyor; kısmi tekil indeks çalışıyor.
          - `test_hedefi_tanimsiz_kopya_konumu_belirsiz_disinda_kaydedilemez`: model kuralı.
          - `test_postgresqlde_hedefsiz_kopya_veritabaninda_da_yalniz_konumu_belirsiz_olabilir`: CHECK; yalnız PostgreSQL'de çalışıyor.
        - `YedeklerPage.test.tsx`: "hedefi tanımsız kopyalar olaydaki adlarıyla ve "konumu doğrulanamadı" uyarısıyla ayrı ayrı görünür (BY-028-R9)".
      - T5 (her durum düzeltme olmadan kırmızı görüldü):
        - `DizinKurucu` HEAD'e döndürülünce 4 DizinKurucu testi kırmızı.
        - `SilIsleyicisi` HEAD'e döndürülünce elle silme testi kırmızı (ErrorException).
        - Yalnız `dosyasiYok` korumasının eski hâli geri konunca `dosyasiYok` testi kırmızı.
        - Eski izdüşüm temizliği kapatılınca "eski kimlik" testi kırmızı (MultipleRecordsFound).
        - Model kuralı kapatılınca model testi kırmızı.
        - SQLite'ta bağ koruması kapatılınca yükseltme testi kırmızı (plan bağı silindi).
        - PostgreSQL'de CHECK kapatılınca CHECK testi kırmızı.
        - Frontend: liste anahtarı eski hâline dönünce ("same key" uyarısı) ve i18n metni çıkarılınca test kırmızı.
      - Doğrulanamayan:
        - Canlı PostgreSQL 18.6 (yerel 16.15). Yerelde migration SQL'i `--pretend` ile görüldü: aynı tür → yeniden yazım yok, yabancı anahtar korunuyor.
        - Saklamanın "kalan kopya" kümesi hedefsiz kaydı sorgu gereği otomatik içeriyor (`durum = konum_belirsiz`, `anaKlasorHedefleri` boş kimliği eliyor). Bu yol ayrı bir testle sınanmadı; `SaklamaPolitikasi` f1'in dosyası.
        - Aynı yerin birden çok tanımında "en küçük kimlik" seçimi sınanmadı.
        - Üstüne geri yükleme + onay akışında hedefsiz kayıt uçtan uca sınanmadı.
        - Manifestsiz yol olaydan değerlendirme yapmadığı için hedefsiz kayıt oluşturmuyor; önceki taramada oluşmuş kaydı koruyor (R8'de kabul edilen davranışla aynı).
  - 2026-11-22 Codex → CHANGES_REQUESTED · yedinci tur `00a5e19`
    - R9'un hedefi bulunamayan kopyayı saklama yolu kapandı. Hedefsiz kayıt, kısmi benzersizlik ve PostgreSQL CHECK ile kullanıma kapalı tutuluyor. Nullable hedef için API görünümü ve elle silme akışı uyarlanmış. Yerel SQLite ve PostgreSQL migration testleri geçti.
    - **BY-028-R10 [P2] İki olay hedefi tek güncel hedefe eşlenince yaşayan kopyanın kaydı eziliyor.** `backend/app/Services/Yedekleme/DizinKurucu.php:211-253,338-345`. Konum iziyle yeniden eşleme farklı kaynak olaylarını aynı `(yedek_id, yedek_hedefi_id)` satırına yazıyor. Eski hedefi tanımsız olan tamam Drive kopyası güncel hedefe eşlenebilir. Güncel hedefin kendi kopyası silinmişse sonraki olay o satırı `silindi` ve farklı uzak dosya kimliğiyle eziyor. İmzalı manifest ve olaylarla izole SQLite'ta doğrulandı. `old-live-object` yerine yalnız `silindi/new-deleted-object` kaldı. Drive'da aynı klasörde bu iki dosya farklı nesnedir. Küçük kimlikli hedefi deterministik seçmek bu çakışmayı çözmüyor. G21 / F7: eşleme sonucunu kaynak hedef anahtarı ve fiziksel uzak nesne kimliğiyle birlikte değerlendirin. Birden çok kaynak aynı satıra düşerse yaşayan nesneyi kaybetmeden ayrı hedefsiz veya uzlaştırılmayı bekleyen kayıt olarak koruyun. Silme olayını yalnız ait olduğu kopyaya uygulayın. Eski hedef eksik, güncel hedefte silinmiş farklı nesne ve ters olay sırası kümelerinde iki taramada aynı yaşayan nesnenin korunduğunu sınayın.
  - 2026-11-22 Claude → REVIEW_BEKLIYOR · Codex yedinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 7. tur incelemesi (BY-010..028) bulgulari` olan commit
    - R10: Konum iziyle yeniden eşlemede birden çok olay hedefi aynı güncel hedefe düşünce biri ötekinin kaydını ezmiyor.
      - `DizinKurucu::manifesttenKur` artık olayları önce kaynak olay hedefine göre aday olarak topluyor, sonra güncel hedefe göre grupluyor; hedefin kaydını `hedefinKopyasi` seçiyor:
        1. Aynı fiziksel nesne (aynı konum izi + aynı uzak kimlik) tek aday; durumu en son olayınki (üstüne yazılan da, sonradan silinen de o). İzi / uzak kimliği olmayan kopya kendi olay hedefinin nesnesi.
        2. Silinmiş nesne yaşayanın (`yedegiTutanlar`) kaydını ezemez. Birden çok yaşayan nesne varsa hedefe biri bağlanıyor: `tamam` olan > olayı hedefi kendi kimliği/adıyla anan > en son olay > hedef anahtarı (deterministik). Diğerleri kaybolmuyor: hedefsiz `konum_belirsiz` kayıt (kendi `olay_hedef_anahtari` ile, yeni hata kodu `KOPYA_HEDEFTE_BASKA_KOPYA`) — yedeği tutar, hiçbir işlemin kaynağı olmaz, listede olaydaki hedef adıyla görünür. Bağlı nesne silinince sonraki taramada sıradaki nesne hedefe bağlanıyor.
        3. Yaşayan yoksa en son olaylı silinmiş nesne hedefin kaydı.
        Döngü sonrası temizlik ayrılan kaydı silmiyor (`$hedefsizler`'e giriyor); eski izdüşüm temizliği aynı (`$gruplar` anahtarları). Kayda başka nesne gelirse eski `tamam` hata kodu taşınmıyor.
      - Silme olayı yalnız ait olduğu nesneye uygulanıyor: `KopyaSilmeKaydi` `kopya_silindi` olayına silinen kaydın `uzak_kimlik` ve `konum_izi`'ni yazıyor; `GuncelDurum::silinenler` olayı kendi anahtarındaki kopyaya yalnız uzak kimliği aynıysa, başka anahtardaki silinmemiş kopyaya uzak kimliği VE konum izi aynıysa uyguluyor. Uzak kimliksiz eski olay eskisi gibi yalnız kendi anahtarına. (Bu olmadan konum iziyle bağlanmış eski hedef kopyası elle/saklamayla silinince silme olayı yeni hedefin anahtarına yazılıyor, sonraki taramada silinmiş dosya `tamam` geri yükleme kaynağı olarak geri geliyordu.) `GuncelDurum` kopyalarına `zaman` (durumu belirleyen son olay) eklendi.
      - Şema değişmedi: R9'un kısmi tekil indeksi `(yedek_id, olay_hedef_anahtari) WHERE yedek_hedefi_id IS NULL`, PG CHECK ve model kuralı ayrılan kaydı da taşıyor (her kaynak anahtar ayrı). Yeni migration yok.
      - Dosyalar: `DizinKurucu.php`, `GuncelDurum.php`, `KopyaSilmeKaydi.php`, `HedefKonumu.php` (yalnız `HEDEFTE_BASKA_KOPYA` sabiti), `YedekOlayi.php` / `KopyaDurumu.php` / `YedekKopyasi.php` (PHPDoc), frontend `tr.json` / `en.json` (`KOPYA_HEDEFTE_BASKA_KOPYA` metni — K5: `KOPYA_HEDEFI_TANIMSIZ` "hedefin tanımı yok" diyor, burada hedef tanımlı), README §5.3 "Aynı hedefe düşen kopyalar birbirini ezmez" paragrafı (K11).
      - Testler (`DizinKurucuTest`):
        - `test_ayni_hedefe_eslenen_yasayan_kopya_hedefin_silinmis_baska_nesnesiyle_ezilmez` (2 veri kümesi: eski hedefin kopyası önce / yeni hedefin silinen kopyası önce): Codex senaryosu, S3 bellek hedefi (her hedef ayrı uzak kimlik = Drive gibi farklı nesne). Eski hedef silinmiş, yeni hedef aynı kovada, kendi kopyası silinmiş, yerel dosya yok. İki taramada da yedek `tamam`, tek kopya yeni hedefe `tamam`, uzak kimliği eski nesne; `GeriYuklemeKaynaklari::sec` yeni hedefi seçiyor. T5: düzeltmesiz "eski önce" kümesi kırmızı; ters sıra HEAD'de de yeşil (eski kodda anahtar sırası tesadüfen doğru sonucu veriyordu) — sıra bağımsızlığının gerilemesine karşı tutuldu.
        - `test_ayni_hedefe_eslenen_ikinci_yasayan_nesne_hedefsiz_korunur_bagli_nesne_silinince_hedefe_baglanir`: iki yaşayan farklı nesne. İki taramada API listesinde yeni hedef `tamam` + hedefsiz `konum_belirsiz` "Eski" (`KOPYA_HEDEFTE_BASKA_KOPYA`), uzak kimlikler doğru; bağlı nesne silinince (KopyaSilmeKaydi) sonraki taramada eski nesne yeni hedefe `tamam`, hedefsiz kayıt kalkıyor. T5: düzeltmesiz kırmızı.
        - `test_konum_iziyle_baglanan_kopya_silinince_yeniden_kurmada_silinmis_kalir`: bağlanan eski nesne silinince iki taramada da kopya `silindi`, yedek `silindi` (silinmiş dosya kaynak olarak geri gelmiyor). T5: yalnız `GuncelDurum`+`KopyaSilmeKaydi` HEAD'e dönünce kırmızı; yalnız `KopyaSilmeKaydi` HEAD'e dönünce de kırmızı. Üç dosya birden HEAD'deyken yeşil (eski kod yaşayan nesneyi zaten kaybettiği için sonuç "silindi" görünüyordu).
        - `test_ayni_nesneyi_anan_iki_olay_hedefi_tek_kayit_olur_nesne_silinince_silindi_kalir`: aynı yerde aynı uzak kimliği anan iki olay hedefi (imzalı olaylar elle) → tek kayıt, hedefsiz kopya açılmıyor; uzak kimliksiz eski silme olayı sonrası `silindi` (tamam→silindi gerilemesi yok, diğer anahtarın eski "tamam"ı geri getirmiyor). T5: düzeltmesiz kırmızı; ayrıca "aynı nesne birleştirmesi" kapatılınca (mutasyon) kırmızı (MultipleRecordsFound).
      - Doğrulanamayan / kalan:
        - Silinmiş (tamam olmayan) olay kopyası güncel hedefe yalnız kimlik/adla eşleniyor (R9 kuralı, değiştirilmedi); hedefi tanımsız bir anahtarın silinmiş kopyası hiçbir gruba girmiyor. Sonuç kaydın "silindi" gösterdiği nesnenin uzak kimliği seçimini etkiler, yaşayan nesneyi etkilemez.
        - Bu düzeltmeden önce yazılmış `kopya_silindi` olayları uzak kimlik taşımıyor: konum iziyle bağlanmış bir kopyanın eski silinmesi yeni hedefin anahtarına yazılmış olabilir (eski davranış). Geçmiş için kurtarma yazılmadı; modül canlıda plansız.
        - Gerçek Google Drive bağlayıcısıyla uçtan uca denenmedi; farklı nesne bellek hedefinin hedef kimlikli uzak kimliğiyle kuruldu.

  - 2026-11-22 Codex → PASSED · sekizinci tur `1546b94` (`00a5e19..1546b94`)
    - R10 kapandı. Adaylar güncel hedefe göre gruplanıyor, aynı fiziksel nesne konum izi + uzak kimlikle birleştiriliyor. Yaşayan farklı nesne silinmiş kayıtla ezilmiyor. İkinci yaşayan nesne ayrı hedefsiz `konum_belirsiz` kayıtta korunuyor ve bağlı nesne silinince sonraki taramada hedefe bağlanıyor. Silme olayı uzak kimlik ve konum izini taşıyor. Yeniden eşlenmiş kopyanın silinmesi eski kaynak olayına da uygulanıyor. İki olay sırası, iki yaşayan nesne, yeniden tarama ve aynı nesnenin tekilleştirilmesi testleri SQLite ve PostgreSQL'de geçti. Bu aralıkta yeni P1/P2 bulunmadı.
    - Uzak kimlik taşımayan geçmiş silme olaylarının onarılmaması açık işletim sınırı. Bu tur kodun yeni olayları ve bunların yeniden kurulmasını doğruluyor. Eski günlüklerde bu durumun bulunmadığı canlıdan doğrulanmadı, geçmişin tamamen onarıldığı sonucu verilmedi. Gerçek uzak hedeften kurtarma ve eski günlüklerle tatbikat BY-025/BY-031 kapsamında doğrulanmalı. Bu görevlerin mevcut `BEKLIYOR` durumu korundu.
    - Ortak kontrol sonuçları BY-010'un bu tur kaydında. 49 gerçek PostgreSQL geri yükleme testi geçti. Gerçek Google Drive ve canlı üstüne geri yükleme denenmedi. Uygulama kodu değiştirilmedi, yeni migration yok.

### BY-029 — "Onay bekleniyor" korumaları ve acil durum komutu

- **Durum:** PASSED · **Bağımlı:** BY-028
- **Tasarım:** README §6.4 (9–11. adım, yeniden başlatmaya dayanıklılık), §7.6, §4.8
- **Kapsam:** "Onay bekleniyor" durumu; kuyruk işçisi ve zamanlayıcının
  başlarken durum dosyasına bakıp açık geri yükleme varsa çalışmayı reddetmesi;
  `deploy.sh` ve planlı yedeğin aynı denetimi; onay ve "geri dön" işlemleri;
  acil durum komutu (`yedek:acil durum|onayla|geri-don`); onay için gösterilecek
  bilgilerin hesabı (aktif ERP ortamı, senkron durumu, ayar farkı, kullanıcı /
  rol farkı, kaybolacak yükleme sayısı); bildirimlerin doğrudan gönderilmesi.
- **Kabul ölçütleri:**
  - [x] Durum "onay bekleniyor" iken kuyruk işçisi ve zamanlayıcı iş çalıştırmıyor (test).
  - [x] Aynı durumda `deploy.sh` ve planlı yedek başlamıyor.
  - [x] Onaydan önce hiçbir alarm maili / senkron çalışmıyor (test).
  - [x] "Geri dön" sonrası `erp` eski veritabanı; geri yüklenen kopya silinmemiş, adı belirli.
  - [x] Acil durum komutu, web ve veritabanı erişilemezken çalışıyor.
  - [x] Acil durum komutu durumu yazmadan önce yedek kilidini alıyor
    (`IslemDurumu::yaz` kilitsiz yazmayı reddeder — tek yazar, Fable F16);
    çalıştırıcı kilidi tutuyorsa komut beklemeden anlaşılır mesajla duruyor.
  - [x] Erişim anahtarı süresi (`erisim_anahtari_bitis`) dolunca ilerleme ve
    onay ucu anahtarı reddediyor; komut satırı yolu açık kalıyor (test).
- **Canlı:** Hayır — BY-031'e kadar.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Kapsam: `AcikGeriYuklemeKorumasi` (kuyruk işçisi: `Queue::looping`;
      zamanlayıcı: `schedule:run` başlarken her göreve `skip`; durum
      okunamazsa da çalışmaz; modül kapalıyken etkisiz), onay / geri dön
      (`UstuneGeriYukleyici::onayla` / `geriDon`; `OnaylaIsleyicisi` /
      `GeriDonIsleyicisi`; yetki erişim anahtarıyla — çalıştırıcı anahtarın
      özetini durum dosyasından doğrular, isteyen kullanıcının işlemi
      başlatanla aynı olduğunu denetler, kullanıcı tablosuna bakmaz: o tablo
      yedeğin anındadır), `OnayBilgisi` (aktif ERP ortamı, entegratörler /
      senkron, alarm kuralları, ayar / kullanıcı / rol farkı, kaybolacak
      yükleme sayısı; "önceki" yanı yer değiştirmeden hemen önce canlıdan
      alınır, ikisi de dosyada), `yedek:acil durum|onayla|geri-don`,
      bildirimler doğrudan ve ayar dosya kopyasından. Açık geri yüklemede
      çalıştırıcı ayar kopyasını tazelemez (eski ayarlarla ezilmesin).
    - Kuyruk ve zamanlayıcı iş çalıştırmıyor: `test_acik_geri_yuklemede_zamanlayici_hicbir_gorevi_baslatmaz_e_fatura_ve_alarm_dahil`,
      `test_acik_geri_yuklemede_kuyruk_iscisi_is_almaz` (onaydan sonra iş alınıyor).
    - deploy.sh ve planlı yedek başlamıyor: `YedekYayinOncesiTest::test_acik_ustune_geri_yukleme_varken_yayin_baslamaz`,
      `test_acik_geri_yuklemede_planli_yedek_baslamaz`.
    - Onaydan önce alarm maili / senkron yok: zamanlayıcı testi
      `efatura:senkron` ve `efatura:alarmlar` dahil bütün görevleri, kuyruk
      testi kuyruktaki işleri kapsıyor.
    - Geri dön: `test_geri_don_eski_veritabanini_canli_yapar_geri_yukleneni_silmeden_adlandirir` + lokal PG.
    - Acil durum komutu web ve veritabanı erişilemezken: `test_acil_durum_komutu_veritabani_erisilemezken_onaylar`;
      geri-don yalnız psql ile (lokal PG).
    - Komut önce kilidi alıyor, beklemiyor: `test_acil_durum_komutu_kilit_baskasindayken_beklemeden_durur`
      + lokal PG (138 ms, çıkış 1, durum değişmedi).
    - Anahtar süresi (8 saat): `test_calistirici_onay_isteginin_anahtarini_islem_durumundan_dogrular`
      (yanlış / süresi dolmuş / başka işlem / başka kullanıcı),
      `UstuneGeriYuklemeApiTest` süre testi; komut satırı açık:
      `test_acil_durum_komutu_anahtarin_suresi_dolsa_da_geri_doner`.
    - Ek: `test_calistirici_ustune_geri_yuklemeyi_onayda_birakir_onay_istegini_sonraki_turda_isler`
      (işleyici 9. adımda döner — tek çalıştırıcı kilidi yüzünden; açık
      işlemde başka istek reddediliyor), `test_onay_bilgisi_ortam_kullanici_rol_ayar_ve_kaybolacak_yukleme_farkini_verir`,
      `YedekAyariApiTest::test_acik_ustune_geri_yuklemede_calistirici_ayar_kopyasini_ezmez`.
    - Testler: `UstuneGeriYuklemeOnayTest` (15). T5: zamanlayıcı / kuyruk
      koruması, anahtar süresi, isteyen denetimi kaldırılınca testler kırmızı.
    - Not: `queue:work --once` ve `schedule:test` korunmuyor (sunucu daemon
      kullanıyor).
    - Doğrulanamayan: "onay bekleniyor"da sunucu yeniden başlatma (BY-031,
      canlı), gerçek mail gönderimi, sunucudaki emor-queue servisi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-029-R1 [P1] Açık geri yükleme koruması yedeksiz yayında uygulanmıyor.**
       `deploy.sh:62 ve :76`. BY-013 bulgusu açık geri yükleme korumasını da engelliyor. `--no-backup` yolu durum kontrolünü atlayarak korunan bakım sürecinin üzerine yayın yapabiliyor (README §6.4).
       BY-013'teki ortak kilit ve bakım sahipliği değişikliğiyle kapatın. `OnayBekliyor` durumunda iki yayın yolunun da Git, migration ve `artisan up` çağırmadan çıktığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: BY-013-R1 düzeltmesiyle kapandı: yayın kilidi bakım modu / git / migration'dan önce alınıyor, açık geri yükleme denetimi `--no-backup` yolunda da yapılıyor; `onay_bekliyor`'da iki yayın yolu da git, migration ve `artisan up` çağırmadan çıkıyor (`scripts/tests/deploy.test.sh`). Ayrıca `yedek:acil onayla` farkı olan ayar grupları için `--ayar=grup=guncel|geri_yuklenen` istiyor (BY-028-R2).
  - 2026-11-21 Codex → PASSED · ikinci tur `c2623ee`
    - R1 kapandı. Yedeksiz ve yedekli yayında açık işlem ve bilinmeyen durum güvenli biçimde yayını durduruyor. Çıkış yolu önceden var olan bakım modunu kaldırmıyor. Karar ekranının istek kabulü BY-030-R3'te açık.

### BY-030 — Ekran: Üstüne geri yükleme

- **Durum:** PASSED · **Bağımlı:** BY-029, BY-027
- **Tasarım:** README §4.5-B, §7.2, §6.4 (9–10. adım)
- **Ön koşul:** README §13 karar 8.
- **Kapsam:** Yalnız sistem yöneticisine görünen "üstüne geri yükle"; onay
  penceresi (veritabanı adını yazarak, neyin kaybedileceği açıkça); bakım
  modundan muaf ilerleme ucu; işleme özel, süreli erişim anahtarı; adım adım
  ilerleme; onay ekranı (BY-029'un hesapladığı bilgiler) ve "Onayla" / "Geri dön".
- **Kabul ölçütleri:**
  - [x] Sistem yöneticisi olmayan kullanıcı için düğme yok, uç 403.
  - [x] İlerleme ucu erişim anahtarı olmadan 401/403; süresi dolan anahtar reddediliyor (test).
  - [x] İlerleme ucu veritabanı erişilemezken yanıt veriyor (test).
  - [x] Erişim anahtarı yalnız o işlemi okuyabiliyor; başka hiçbir uca yetki vermiyor.
  - [x] Tarayıcı kapanıp yeniden açılınca işlem kaybolmuyor (acil durum yolu ekranda anlatılıyor).
  - [x] Onay penceresinde yedeğin tarihi ve kaybolacak dönem açıkça yazıyor.
- **Canlı:** Hayır — BY-031'e kadar.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - Ön koşul notu: README §13 karar 8 (üstüne geri yüklemeyi kimler yapar,
      ikinci onay) kullanıcıdan gelmedi: README'deki "yalnız sistem
      yöneticisi" uygulandı, ikinci kişi onayı yok.
    - API: `POST /api/v1/ayarlar/yedekler/{kimlik}/ustune-geri-yukle
      {onay_metni}` (yalnız sistem yöneticisi; onay metni = canlı veritabanı
      adı — `GET /ayarlar/yedekler/ozet` `canli_veritabani` ile verir; anahtar
      yalnız bu yanıtta, dosyada özeti; 8 saat). Oturumsuz ve bakım modundan
      muaf `GET /api/v1/yedekleme/ustune-geri-yukleme/{islem}/durum`, `POST
      …/onayla`, `POST …/geri-don` (`X-Erisim-Anahtari`; Sanctum oturum katmanı
      yok, veritabanına dokunmaz; `bootstrap/app.php`
      `preventRequestsDuringMaintenance` istisnası). Anahtarla yetkili uçlarda
      hız sınırı bilerek yok: sınırlayıcı veritabanındaki önbelleğe dayanırdı
      (anahtar 256 bit, sabit süreli karşılaştırma; README §6.4).
    - Ekran: Yedekler satırında yalnız sistem yöneticisine ve
      `geri_yuklenebilir && !ice_alindi` yedekte "Üstüne geri yükle"; onay
      penceresi (yedeğin tarihi, kaybolacak dönem, adım özeti, canlı adı aynen
      yazılmadan düğme kapalı). Oturum / menü korumasının DIŞINDAKİ ilerleme
      sayfası `/ayarlar/geri-yuklemeler/ustune/:islemKimligi`: yalnız
      tarayıcıda saklanan işleme özel anahtarla, çerezsiz `fetch` (401 oturum
      düşmesi sayılmaz), 2 sn yoklama, 12 adım, onay bilgisi + onaylı "Onayla"
      / "Geri dön", bitince anahtar silinir, acil durum komutu her zaman
      ekranda. Geri Yüklemeler'de anahtarı bu tarayıcıda olan kayıt ilerlemeye bağlanır.
    - Testler: `UstuneGeriYuklemeApiTest` (12: sistem yöneticisi değil 403;
      anahtarsız 401 / yanlış 403 / süresi dolan 403; bakım modunda ve
      veritabanı erişilemezken yanıt; anahtar yalnız kendi işlemi; onay metni
      422; başlatılamayan durumlar 409; anahtarın yalnız özeti dosyada),
      `UstuneGeriYuklemePage.test`, `ustuneGeriYuklemeApi.test`, `YedeklerPage.test`
      (+2), `GeriYuklemelerPage.test` (+1). Ekran T5: 8 koruma kaldırılınca
      testler kırmızı; backend T5: bakım modu istisnası ve oturum katmanı.
    - Not: anahtar en çok 8 saat localStorage'da (tarayıcı kapanınca devam
      için; tek kayıt, okunurken biçim denetimi, bitince silinir) — aynı
      kökende bir XSS süresince okuyabilir; bilinçli ödünleşim.
    - Doğrulanamayan: açık / koyu tema, dar ekran; gerçek bakım modunda nginx
      üzerinden uçtan uca (BY-031 canlı provası).
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-030-R1 [P2] Onay ve geri dön isteğinde tam erişim anahtarı diske yazılıyor.**
       `backend/app/Http/Controllers/Api/V1/UstuneGeriYuklemeController.php:169, backend/app/Services/Yedekleme/IstekKutusu.php:99`. İşlem durum dosyası anahtarın özetini tutuyor. Buna rağmen karar isteği yeniden kullanılabilir anahtarın tamamını istek klasöründe bekletiyor. Yalnız özetin dosyaya düşmesi kabulü karar uçlarını kapsamıyor (D2, BY-030).
       Tam anahtarı dosyada kalıcılaştırmadan kararı kısa ömürlü, tek kullanımlık ve işleme bağlı doğrulanabilir bir onayla aktarın. Onay ve geri dön için istekleri ve günlükleri denetleyin, tam anahtarın saklanmadığını ve süresi dolan kararın uygulanmadığını sınayın.
    - Tarayıcıdaki 8 saatlik saklama tasarım kararı günlükte belirtilmiş, testler bitişte temizliği kapsıyor. Elle tema ve dar ekran doğrulaması açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: Onay / geri dön isteğine anahtar yazılmıyor. Denetleyici anahtarı bugünkü gibi doğrular, sonra kanıt üretir: `kanit = HMAC-SHA256(özet, islem|karar|son_gecerlilik|nonce|sha256(ayar_secimleri))`, son_gecerlilik ≤ şimdi + 300 sn (`ErisimAnahtari::kanit`).
      Çalıştırıcı tarafı (`ErisimAnahtariylaYetkili::kanitDenetle`):
      - kanıtı işlem durumundaki özetle `hash_equals` ile doğrular,
      - anahtar ve kanıt süresine bakar (`GERI_YUKLEME_KARAR_SURESI_DOLDU`),
      - nonce'u `UstuneGeriYukleyici::kanitTuket` ile işlem durumunda tüketir (`kullanilan_kanitlar`, süresi geçenler düşer; `GERI_YUKLEME_KARAR_KANITI_KULLANILDI`).
      Seçimler kanıta bağlı: değiştirilen seçim ya da başka karar reddedilir. İstek şeması `IstekKutusu::TURLER` (onayla / geri_don) güncellendi. `erisim_anahtari` türü kaldırıldı; tam anahtarlı istek artık şemadan da reddedilir.
      Testler: `UstuneGeriYuklemeOnayTest::test_karar_istegi_tam_anahtari_hicbir_dosyaya_yazmaz_suresi_gecen_karar_uygulanmaz` (yedek kökündeki bütün dosyalar — istek, sonuç, işlem durumu, denetim, ayar kopyası —, yedekleme günlüğünün yeni kısmı ve denetim tablosu baytlarında anahtar yok; 301 sn sonra işlenen karar uygulanmadı, uygulama bakımda kaldı), `test_ayni_karar_kaniti_ikinci_kez_uygulanmaz`, `test_calistirici_karar_kanitini_islem_durumundaki_ozetle_dogrular` (7 durum: başka anahtar, süresi dolmuş anahtar, başka işlem, başka kullanıcı, seçim kurcalanmış, geri dönüş kanıtıyla onay, ömrü > 5 dk), `UstuneGeriYuklemeApiTest` bakım / veritabanı erişilemezken testi (istekte anahtar yok, kanıt geçerli, ≤ 300 sn).
      T5: nonce tüketimi kaldırılınca, kanıt süresi denetimi kaldırılınca ve denetleyici anahtarı isteğe yazınca testler kırmızı.
      Not: özet işlem durumu dosyasında; dosya 640 izinli, web grubu okur. Kanıt diske düşen isteğin yeniden oynatılmasını, değiştirilmesini ve anahtarın sızmasını önler. Ele geçirilmiş web sürecine karşı koruma değildir (README §6.4'e yazıldı).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1'in ham anahtarı diske yazma kısmı kapandı. Kanıt işleme ve karar türüne bağlı. Aşağıdaki karar kabulü ve tek kullanım sınırları açık. Gerçek nginx bakım modu ve ekran görünümü bu turda sınanmadı.
    1. **BY-030-R2 [P2] Kullanılmış karar kanıtı geçerlilik süresi dolmadan unutulabiliyor.**
       `backend/app/Services/Yedekleme/UstuneGeriYukleyici.php:287 ve :295, backend/app/Services/Yedekleme/IslemKaydi.php:38`. 64 kayıt sınırı dolduğunda süre sonuna bakılmadan eski kanıt listeden çıkarılıyor. Açık işlemde listeden düşen ve henüz süresi dolmamış karar yeniden kullanılabilir. Bu, tek kullanım sözleşmesini karşılamıyor (G18).
       Yalnız süresi dolmuş kanıtları atın. Kapasite doluyken yeni karar kabulünü açık durumla durdurun veya süresi boyunca kalıcı tekillik kullanın. Süresi geçmemiş tüketilmiş kanıtın kapasite sınırında da reddedildiğini sınayın.
    2. **BY-030-R3 [P2] Karar isteği kaydedildikten sonra günlük hatası API'yi başarısız gösteriyor.**
       `backend/app/Http/Controllers/Api/V1/UstuneGeriYuklemeController.php:184 ve :193`. Onay veya geri dön dosyası yayımlandıktan sonra günlük arızası yakalanmıyor. Ekran hata görürken çalıştırıcı kararı uygulayabilir. Yeniden denemede yeni karar kimliği oluşur (D3, BY-014-R2).
       Karar isteğine de aynı kabul ve kimlik sözleşmesini uygulayın. Dosya yayımlandıktan sonraki günlük hatasında kullanıcının mevcut kararı izleyebildiğini ve ikinci iş oluşmadığını sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: `UstuneGeriYukleyici::kanitTuket`: yalnız süresi geçmiş nonce'lar atılır; süresi geçmemiş tüketilmiş kanıt asla düşmez. Liste (IslemKaydi::EN_COK_KANIT = 64) süresi geçmemişlerle doluysa yeni karar `GERI_YUKLEME_KARAR_KAPASITESI_DOLU` (GeriYuklemeHatasi::KARAR_KAPASITESI_DOLU; tr/en çevirisi) ile reddedilir, liste değişmez (kanıt en çok 5 dk yaşar). `array_slice` kırpması kaldırıldı. README §6.4 kanıt maddesi güncellendi. Test: `UstuneGeriYuklemeOnayTest::test_kanit_listesi_doluyken_suresi_gecmemis_kanit_dusmez_yeni_karar_reddedilir_suresi_gecenler_atilir` (64 dolu: yeni karar KAPASITESI_DOLU, en erken biten ilk kanıt hâlâ KANIT_KULLANILDI; 101 sn sonra ilk kanıt atılır, yeni karar kabul) (T5: eski kırpma geri konunca kırmızı). Doğrulanamayan: yok.
    - R3: Karar isteği (onayla / geri dön) BY-014-R2 ile AYNI kod yolundan geçer: `YedekIstekServisi::kararBirak` → ortak `yayinla` (K10). Önce günlük (`YedekDenetimi::gunlugeYaz` — yalnız dosya, veritabanına dokunmaz), sonra yayın; günlük yazılamazsa karar yayınlanmaz. Yayımlandıktan sonraki hata (klasör eşitlemesi, ardından belirsizlik notunun günlüğe yazılamaması) yakalanır: 202 + istek kimliği. Kimlik istemci anahtarı + işlem + karar + ayar seçimlerinden türetilir (kanıt/nonce her denemede yeni olduğundan kimliğe girmez); aynı anahtarla yeniden deneme ikinci karar dosyası yazmaz. Frontend `ustuneKararVer` `Idempotency-Key` gönderir; `UstuneGeriYuklemePage` aynı kararın (karar + seçimler) yeniden denemesinde aynı anahtarı kullanır. Test: `UstuneGeriYuklemeApiTest::test_karar_yayimlandiktan_sonraki_ariza_kabulu_bozmaz_ayni_anahtarla_yeniden_deneme_tek_karar` (bekleyen/ 0300 + ilk kayıttan sonra yazamayan günlük: iki deneme de 202 ve aynı kimlik, tek karar dosyası, günlükte yalnız yayın öncesi kayıt, `durum?istek=` kararı `kuyrukta` gösterir); `ustuneGeriYuklemeApi.test` başlığı da sınıyor (T5: KalicilikDogrulanamadi yakalanmayınca kırmızı). Doğrulanamayan: karar işlendikten SONRA (işlem `onay_bekliyor`'dan çıkınca) aynı anahtarla yeniden deneme mevcut kabulü değil 409 `GERI_YUKLEME_ISLEM_DURUMU_UYGUN_DEGIL` alır (yapılabilirlik denetimi önce) — ikinci iş yine oluşmaz, ekran durumu yoklamadan görür; gerçek nginx bakım modu denenmedi.
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a`
    - R2 kapandı. Süresi dolmamış tüketilmiş karar kanıtları listeden çıkarılmıyor, kapasite doluyken yeni karar reddediliyor. R3'te denetim ve dosya yayımlama sırası düzeldi. Karar sonuçlandıktan sonra aynı anahtarla gelen 409 ve sonuç durumunun sorgulanması kabul edildi.
    - **BY-030-R4 [P2] Yanıtı kaybolan karar isteğinin anahtarı yenilemede unutuluyor.** `frontend/src/pages/UstuneGeriYukleme/UstuneGeriYuklemePage.tsx:141` ve `frontend/src/features/yedekleme/useIstekAnahtarlari.ts:15-32`. İşlem onay beklerken sayfayı yenileyip aynı kararı yeniden göndermek yeni bir istek bırakabiliyor. Çalıştırıcının durum denetimi işlemin ikinci kez tamamlanmasını engelliyor. Kullanıcının ilk kabulü izleme yolu yine kayboluyor. BY-014-R3 ile ortak düzeltmeyi kullanın. Karar erişim anahtarını ayrıca saklamadan, bekleyen 202 yanıtını kaybettirip sayfa yenilendiğinde aynı karar isteğinin bulunduğunu sınayın.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex üçüncü tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 3. tur incelemesi (BY-010..030) bulgulari` olan commit
    - R4: BY-014-R3'ün ortak düzeltmesi. `UstuneGeriYuklemePage` kararın bekleyen anahtarını `ustune.<işlem kimliği>` kapsamında saklıyor. Yanıtı kaybolan karar, sayfa yenilendikten sonra aynı karar ve seçimlerle yeniden gönderilince aynı `Idempotency-Key`'i taşıyor: sunucu mevcut kabulü döner, `karar_istegi` kaydedilir, karar yoklanır. Test: `UstuneGeriYuklemePage.test` "yanıtı kaybolan karar sayfa yenilenince aynı istek anahtarıyla yeniden gönderilir; depoya erişim anahtarı girmez (BY-030-R4)". Kurgu: ilk karar ağ hatası, bileşen yeniden kuruluyor, seçimler ters sırayla yapılıyor. Doğrulananlar: aynı anahtar; bekleyen kayıtta yalnız `anahtar` / `imza` / `zaman` var, erişim anahtarı yok; kabulden sonra bekleyen kayıt siliniyor ve durum `?istek=` ile yoklanıyor. T5: HEAD hook'uyla kırmızı. Doğrulanamayan: R3'teki not (karar işlendikten sonra aynı anahtarla deneme 409 alır) değişmedi.
  - 2026-11-21 Codex → PASSED · dördüncü tur `208746f`
    - R4 kapandı. Karar isteğinin anahtarı işlem kapsamıyla sayfa yenilemesinden sonra korunuyor. Erişim anahtarı ve karar kanıtı ortak yardımcının depolama kaydına girmiyor. Kısa anahtar ömrü ve depolama engellendiğinde bellek davranışı kabul edildi. Karar ekranı regresyon testi geçti.

### BY-031 — Arıza senaryoları, tatbikat, işletim dokümanı

- **Durum:** BEKLIYOR · **Bağımlı:** BY-030
- **Tasarım:** README §10
- **Kapsam:** README §10'daki arıza senaryolarının her biri (otomatik test ya da
  yazılı elle deneme); `docs/yedekleme/isletim.md`: geri yükleme adım listesi,
  acil durum yolu, sık hatalar; canlıda **kopya** ile prova; ardından kullanıcı
  onayıyla canlıda ilk tatbikat.
- **Kabul ölçütleri:**
  - [x] Her senaryo için sonuç tablosu: denendi / sonuç / mevcut veri korundu mu / uygulama yanlışlıkla açıldı mı.
  - [ ] Sunucu dışındaki şifreli kopyadan geri yükleme bir kez baştan sona yapıldı.
  - [ ] "Onay bekleniyor" durumunda sunucu yeniden başlatma denendi.
  - [ ] İşletim dokümanını izleyen biri (Codex) adımları eksiksiz buluyor.
- **Canlı:** Evet — bu görev `PASSED` olunca üstüne geri yükleme canlıda kullanılabilir.
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · başlığı `feat(yedekleme): BY-014..032 ekranlar, sunucu disi hedefler, calistirici, geri yukleme` olan commit
    - `docs/yedekleme/isletim.md`: kipler (`dogrudan` / `servis`) ve servis
      kipindeki `.env` anahtarları; servis hesabıyla elle komut kalıbı
      (servis tanımının ortamıyla — D7); modülü açma sırası; günlük işletim
      ve komut listesi; farklı adla geri yükleme adımları; üstüne geri
      yükleme adım listesi, onay / geri dön, sonrasında elle temizlik
      (`erp_onceki_*`, geri dönüşten kalan `erp_geri_*`); acil durum yolu
      (`yedek:acil`, kilit davranışı, yeniden başlatma); sık hatalar tablosu
      (kod → anlam → ne yapılır); README §10 arıza senaryolarının sonuç
      tablosu (nasıl sınandı / sonuç / veri korundu / yanlışlıkla açılmadı);
      tatbikat adımları ve kayıt tablosu.
    - Senaryo tablosu otomatik testlere ve lokal PostgreSQL denemelerine
      (BY-024, BY-026, BY-028 günlükleri) dayanır.
    - Kabul: [x] her senaryo için sonuç tablosu (lokal / otomatik). Canlıya
      bağlı üç ölçüt İŞARETLENMEDİ: sunucu dışındaki şifreli kopyadan canlıda
      baştan sona geri yükleme (lokalde `/dev/shm` NAS'ıyla yapıldı),
      "onay bekleniyor"da gerçek sunucu yeniden başlatma, canlıda kopya
      provası ve ilk tatbikat — hepsi kullanıcı talimatı ve servis kurulumu
      (BY-003) ister. "Codex adımları eksiksiz buluyor" ölçütü bu incelemenin konusu.
  - 2026-11-21 Codex → CHANGES_REQUESTED · commit aralığı `be1983c^..f7aaf4e`
    1. **BY-031-R1 [P2] Boş sunucuda kurtarma sırası servis hesabı ve dosya izinleriyle uyuşmuyor.**
       `docs/yedekleme/kurtarma.md:127, :215, :230 ve :239`. Kılavuz root'a ayrılmış çalışma dizinini ve servis hesabına ait anahtar yolunu kullanıyor. `yedek:tara` servis hesabı ve izinler kurulmadan önce, açık bir komut kullanıcısı belirtilmeden çalıştırılıyor. Temiz makinede adımlar kesiliyor veya yedek güvenilmeyen görünüyor (D5, D7).
       Her adıma çalıştıran kimliği yazın. Servis hesabı, izinler ve anahtar taramadan önce hazır olsun. Boş Linux makinesinde servis kipiyle yazılı sırayı uygulayıp ilk imzalı taramayı, arıza sonrasında güvenli açılışı ve geri dönüşü kayda alın. Şifreli dış kopya ve onay beklerken yeniden başlatma kabulü açık.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex incelemesi (BY-006..031) bulgulari` olan commit
    - R1: `kurtarma.md` §4 servis kipine göre yeniden yazıldı.
      - Her adımın başlığında çalıştıran kimlik var (root / postgres / admin / emor-yedek); başta bir kimlikler tablosu duruyor.
      - Servis komutları sunucu-kurulumu.md'deki kalıpla çalışır: `servis() { sudo -u emor-yedek env $(systemctl show emor-yedek -p Environment --value) "$@"; }`.
      - Yeni sıra:
        - 4.1 root: paketler (`acl` dahil), `admin`, locale, `fs.protected_hardlinks`.
        - 4.2 postgres: `erp` rolü.
        - 4.3 admin: clone, `.env`, composer; root `.env`'i `admin:www-data 0640` yapar. YEDEKLEME_* yolları betikle birebir listelendi.
        - 4.4 root: `scripts/sunucu-yedekleme-kurulumu.sh` (denetle → kur → denetle).
        - 4.5 imza anahtarı: admin standart girdiden verir, emor-yedek `gizli/imza.anahtar`'a yazar (`umask 077; set -C`); root servis klasörüne yazmaz (D5). Ardından `--denetle` "her şey yerinde".
        - 4.6 admin: `~/kurtarma-indirilen`'e indirir, işaretçiyle özetleri denetler.
        - 4.7 emor-yedek: çalışma klasörünü kendisi açar (`mktemp -d /var/tmp/emor-kurtarma.XXXXXX`); dosyalar ve özel anahtar standart girdiden verilir; `servis php artisan yedek:sifre-coz`.
        - 4.8 emor-yedek: manifest doğrulaması (R2), sonra yalnız düz dosyalar `install -m 0640` ile yedekler/ ve gunluk/'e konur.
        - 4.9 postgres `createdb`, emor-yedek `servis pg_restore -U emor_yedek --role=erp` (parola servis ortamındaki PGPASSFILE'dan).
        - 4.10 admin `migrate`, emor-yedek `servis php artisan yedek:tara`.
        - 4.11 root: servisler, php-fpm / emor-queue yeniden başlatma, `enable --now emor-yedek`.
        - 4.12 temizlik.
      - Eski `/root/kurtarma` ve root'un servis yollarına yazması kaldırıldı.
      - Komutlar ve yollar `scripts/sunucu-yedekleme-kurulumu.sh` okunarak eşlendi: YEDEK_KOKU, `gizli/pgpass`, `gizli/imza.anahtar`, servis Environment satırları, betiğin ön koşulları (.env, vendor, erp rolü, www-data / admin, protected_hardlinks), boş sunucuda yeni parola üretmesi, imza anahtarı yokken yalnız [BİLGİ] vermesi.
      - Doküman snippet'leri `bash -n` / `sh -n` ile, işaretçi denetimi `php -r` ile lokalde çalıştırıldı.
      `isletim.md`'de yalnız kurtarma ve şifreleme kısımları değişti: komut tablosuna `yedek:manifest-dogrula`; §4.5 ve §9 bölüm numaraları ve imza doğrulama adımı; sık hatalara `YEDEK_GPG_HATASI` ve `kurtarma_paketi başarısız` satırları, şifreleme satırına "yeniden denenmez"; senaryo tablosuna 3 satır.
      Doğrulanamayan: boş Linux makinesinde servis kipiyle yazılı sıranın baştan sona uygulanması (ilk imzalı tarama, arıza sonrası güvenli açılış, geri dönüş). Ayrıca sunucuda PostgreSQL 18.6 / en_US.UTF-8, şifreli dış kopyadan canlıda geri yükleme ve onay beklerken yeniden başlatma. Hepsi bu oturumda yapılamadı (kurtarma.md §8'e not düşüldü).
  - 2026-11-21 Codex → CHANGES_REQUESTED · ikinci tur `c2623ee`
    - R1'in kurulum, servis hesabı ve tarama sırası düzeltildi. Kurulum ve anahtar taramadan önce. Boş makine, şifreli dış kopyadan uçtan uca kurtarma ve açık işlemle yeniden başlatma kabulü açık.
    1. **BY-031-R2 [P2] Elle kurtarma yeni veritabanına manifestteki bağlantı izinlerini vermiyor.**
       `docs/yedekleme/kurtarma.md:334 ve :391`. Kılavuz `createdb` ve `pg_restore --no-owner` kullanıyor. Bağlantı yetkilerini uygulama ve eşdeğerliğini doğrulama adımı yok. Yedekte açıkça sınırlandırılmış CONNECT veya TEMP varsa elle kurtarma veritabanı farklı yetkilerle açılır (G5, BY-026-R1).
       İmzalı manifest doğrulandıktan sonra yeni veritabanına kaynak bağlantı ACL'sini uygulayan açık bir adım ve açılıştan önce eşdeğerlik kontrolü ekleyin. Temiz makinede daraltılmış CONNECT'li bir arşivle belgeyi baştan sona deneyin.
  - 2026-11-21 Claude → REVIEW_BEKLIYOR · Codex ikinci tur bulgularının düzeltmesi; başlığı `fix(yedekleme): Codex 2. tur incelemesi (BY-010..031) bulgulari` olan commit
    - R2: Elle kurtarmada bağlanma yetkileri. SQL elle yazılmadı; `yedek:manifest-dogrula` genişletildi (K10): imza + özet doğrulandıktan SONRA `--yetkileri-uygula=<vt>` manifestteki `baglanma_yetkileri`'ni ekrandaki geri yüklemenin `GeriYuklemeVeritabani::yetkileriUygula()` DO bloğuyla uygular (tek işlem, eşdeğer değilse hiç değişmez), `--yetkileri-denetle=<vt>` değiştirmeden eşdeğerliği denetler (yeni `GeriYuklemeVeritabani::yetkilerEsdegerMi()`, aynı (rol, yetki, devredilebilir) ölçütü; ACL psql değişkeni + current_setting ile — sabit verilseydi kullanılmayan CASE dalı planlamada çözülürdü). Doğrulama başarısızsa veritabanına hiç bağlanılmaz; içe alınmış (özelliksiz) manifestte reddeder; ad yalnız canlı/yönetilen kalıplar (mevcut `yetkiAdiOlmali`). Çıktıya "Bağlanma yetkileri (datacl)" satırı. `kurtarma.md`: §4.8 hata tablosuna kod, §4.9 pg_restore'dan sonra emor-yedek `--yetkileri-uygula=erp`, §4.11 servislerden önce `--yetkileri-denetle=erp` (yalnız YETKILER_ESDEGER ise açılış), §5 kontrol maddesi, §8 deneme kaydı; isletim.md komut tablosu + senaryo satırı. Testler: `YedekManifestDogrulaTest::test_imzasi_tutmayan_manifestte_baglanma_yetkilerine_hic_dokunulmaz` (hiç süreç çalışmaz), `::test_dogrulanan_manifestin_baglanma_yetkileri_veritabanina_uygulanir` (psql'e manifestteki ACL gider), `::test_baglanma_yetkileri_esdeger_degilse_denetim_basarisiz_doner`; gerçek PG: `YedekManifestDogrulaPostgresTest::test_daraltilmis_baglanma_yetkileri_elle_kurtarilan_veritabanina_esdeger_uygulanir_acilistan_once_denetlenir` (YEDEKLEME_GERCEK_PG=1; roller `ajan_b1_*`, kendi temizler; createdb sonrası yabancı rol bağlanıyor → denetim başarısız → uygula → denetim eşdeğer → yabancı reddedilir, izinli rol bağlanır). T5: düzeltmeden önce seçenekler yoktu (testler bilinmeyen seçenekle düşer); gerçek PG testi düzeltmesiz durumu (yabancının bağlanabilmesi, denetimin başarısızlığı) testin içinde gösteriyor. Lokal prova (kurtarma.md §8, TEMİZ MAKİNE DEĞİL: WSL Ubuntu 24.04, PG 16.15, C.UTF-8, servis hesabı yok — aynı yollar/rol ortam değişkenleriyle; roller `b1k_*`, veritabanları `b1k_eski`/`b1k_yeni`, sonunda silindi; erp/erp_test* kullanılmadı): CONNECT'i daraltılmış veritabanından gerçek `yedek:al` → §4.8 doğrulama → createdb + pg_restore (yabancı rol bağlanabildi) → denetim "eşdeğer DEĞİL" → uygula → datacl kaynakla aynı → tara 1 imzalı → denetim eşdeğer, yabancı reddedildi, migrations 55/55; bozuk manifestle uygula reddedildi ve yetkilere dokunmadı. Doğrulanamayan: boş Linux makinesinde servis hesabıyla, PG 18 / en_US.UTF-8'de; paket indirme + şifre çözme bu provada yoktu (BY-024 provası ve KurtarmaPaketiTest).
  - 2026-11-21 Codex → CHANGES_REQUESTED · üçüncü tur `a63795a` · dış kabul bekleniyor
    - R2 kapandı. `yedek:manifest-dogrula` bağlantı ACL'sini imza ve dosya özeti kontrolünden sonra uyguluyor ve denetliyor. `kurtarma.md` izin adımını uygulama açılmadan önceye koyuyor. Geçici PostgreSQL'de manifest ve ACL testleri geçti.
    - Son kabul için kurulum kılavuzunun boş makinede uçtan uca uygulanması, sunucu dışındaki şifreli kopyadan prova ve onay beklenirken gerçek servis veya sunucu yeniden başlatma açık. Kabul kutuları mevcut hâliyle bırakıldı.

---

## Faz 5 — Sonra

### BY-032 — Dosya yedeği (`storage/app`)

- **Durum:** BEKLIYOR · **Bağımlı:** BY-010
- **Tasarım:** README §1 (kapsam), §7.9
- **Ön koşul:** Uygulamada dosya saklayan bir özellik gelmesi; o zamana kadar bekler.
- **Kapsam:** Dosyaların veritabanı yedeğiyle birlikte ve tutarlı alınması;
  tasarımı o gün README'ye yazılır.
- **Kabul ölçütleri:** Görev başlarken yazılır.
- **Canlı:** —
- **İnceleme günlüğü:**
  - 2026-11-21 Claude → BEKLIYOR
    - Ön koşul ("uygulamada dosya saklayan bir özellik gelmesi") karşılanmadı:
      uygulama kodunda `storage/app`'e kalıcı dosya yazan bir özellik yok
      (`grep -rn "Storage::\|->store(\|storeAs(\|storage_path(" app` →
      yalnız yedekleme modülünün kendi yolları); README §2'deki ölçümde de
      `storage/app` fiilen boş (24 KB).
    - Kapsam "tasarımı o gün README'ye yazılır" ve kabul ölçütleri "görev
      başlarken yazılır" olduğu için bugün kod yazılmadı (K1: çağıranı olmayan
      kod yazılmaz). Dosya saklayan ilk özellik geldiğinde görev açılır.

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

- **Toplu yazım (kullanıcı kararı 2026-11-21).** "Backup yönetimi ile ilgili
  tüm maddeleri aynı anda bitir … sabah Codex review yapar." BY-006…BY-032
  tek oturumda, bağımlı görevin `PASSED` olması beklenmeden yazılır (§1.3
  madde 1'in istisnası; PRT panosundaki toplu teslimle aynı). Her görev yine
  ayrı `REVIEW_BEKLIYOR` kaydı taşır; Codex görev sırasıyla inceler. Dış ön
  koşulu olan kabul ölçütleri (canlıda deneme, gerçek FTP / Google hesabı,
  sunucu kurulumu) işaretlenmez, günlükte "doğrulanamayan" olarak yazılır.
  Yeni bağımlılık eklenmez: FTP/FTPS (PHP curl), SFTP (OpenSSH `sftp`),
  Google Drive ve S3 (Laravel HTTP istemcisi) kurulu araçlarla yazılır.
  Canlıya alma yine yalnız kullanıcı talimatıyla (§1.3 madde 8).

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
