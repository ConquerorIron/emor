# Keşif sonuçları (BY-002)

Tasarımın ([README.md](README.md)) dayandığı PostgreSQL davranışlarının
denenmesi. Görev: [Backup-Jira.md](Backup-Jira.md) → BY-002.

## Nasıl denendi

- **Lokal (2026-11-18):** WSL'deki PostgreSQL **16.15**, araçlar 16.15.
- Deneyler, bu iş için açılıp sonunda silinen geçici nesnelerle yapıldı:
  - `by002_app`: uygulama rolünü temsil eder; yönetici değil, veritabanı açamaz.
  - `by002_svc`: geri yükleme servis hesabını temsil eder; veritabanı açabilir
    (`CREATEDB`), `by002_app`'in üyesidir.
  - Geçici veritabanları (`by002_*`).
- Kaynak veri: lokal `erp` veritabanının yedeği. `erp` ve `erp_test`
  veritabanlarına yazılmadı. Deney sonunda geride rol ve veritabanı kalmadığı
  denetlendi.
- **Canlı, 1. oturum (2026-11-18, kullanıcı onayıyla):** yalnız okuma.
  Sorgular uygulamanın kendi PostgreSQL kullanıcısıyla, `.env`'deki bilgilerle
  çalıştı (parola komut satırına girmedi); betik sunucuya geçici kopyalanıp
  sonra silindi. Geri yükleme, uygulama kullanıcısının veritabanı açma yetkisi
  olmadığı için bu oturumda yapılamadı.
- **Canlı, 2. oturum (2026-11-18, kullanıcı `admin` şifresini verip onayladı):**
  `sudo` ile PostgreSQL'de kalıcı **yedekleme kullanıcısı** `emor_yedek` açıldı
  (veritabanı açabilir, uygulama kullanıcısına üye; README §9). Bu kullanıcıyla
  en yeni yayın öncesi yedek **geçici** `erp_kesif` veritabanına geri yüklendi,
  doğrulandı ve `erp_kesif` silindi. Canlı uygulama veritabanına yazılmadı.
  `sudo` şifresi sunucuya SSH girdisiyle verildi, hiçbir dosyaya yazılmadı.

## Özet

| # | Soru | Sonuç |
|---|---|---|
| 1 | Canlı sunucu sürümü; araç (18.6) ile uyum | **Doğrulandı:** sunucu 18.6, araçlar 18.6 — eşleşiyor |
| 2 | Mevcut bir yedek gerçekten geri yükleniyor mu | **Doğrulandı** — lokalde ve canlıda (canlı yedek, canlı sunucuda geçici veritabanına) |
| 3 | Uygulama kullanıcısının yetkileri | **Doğrulandı:** yetkisiz (veritabanı açamaz); veritabanının sahibi |
| 4 | Nesnelerin sahibi uygulama rolü olabiliyor mu | **Doğrulandı** — yöntemle birlikte |
| 5 | İki ad değişikliği tek işlemde | **Doğrulandı** — atomik |
| 6 | Bağlantıya kapatma + koparma | **Doğrulandı** |
| 7 | Veritabanı özellikleri yedeğe giriyor mu | **Doğrulandı: girmiyor** — manifest şart |
| 8 | Aynı andan sayım (`--snapshot`) | **Doğrulandı** |
| 9 | Bozuk yedek yakalanıyor mu | **Doğrulandı** — yalnız tam okuma yakalıyor |

## Ayrıntı

### 1. Sürüm (canlı)

- Sunucu: **PostgreSQL 18.6** (`server_version_num` 180006). Proje notlarında
  yazan "17" eskiydi; CLAUDE.md düzeltildi.
- Araçlar: `pg_dump` / `pg_restore` 18.6 — sunucuyla aynı.
- En yeni yayın öncesi yedekte `transaction_timeout` ayarı var (18 araçları
  yazar); sunucu da 18 olduğu için sorun değil.

Sonuç: README §7.7'deki sürüm riski canlıda bugün yok. Kalıcı kural olarak
kalmalı: sunucu yükseltilirse ya da araçlar ayrı güncellenirse sürümler
yeniden eşleştirilir. Lokal geliştirme ortamı 16.15'tir; lokal deneylerin
18'de aynı davrandığı varsayıldı (aşağıdaki "Sınırlar").

### 2. Geri yükleme ve sayım karşılaştırması

Kaynak veritabanının yedeği yeni bir veritabanına geri yüklendi
(`pg_restore --no-owner --role=<uygulama> --exit-on-error --single-transaction`).
29 tablonun tamamında satır sayıları kaynakla aynı çıktı.

**Canlı:** en yeni yayın öncesi yedek (`erp_predeploy_2026-11-18_063445.dump`,
1,6 MB, 38 tablonun verisi) önce **baştan sona okundu** (`pg_restore
--file=/dev/null`, çıkış 0). Sonra yedekleme kullanıcısı `emor_yedek` ile
canlı sunucuda **gerçekten geri yüklendi**:

| Adım | Sonuç |
|---|---|
| `CREATE DATABASE erp_kesif OWNER erp TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE/LC_CTYPE 'en_US.UTF-8'` | başarılı |
| `pg_restore --no-owner --role=erp --exit-on-error --single-transaction` | başarılı, **1 saniye** |
| Tablo sahipleri / veritabanı sahibi | hepsi `erp` (38 tablo) / `erp` |
| Uygulama kullanıcısı okuyabiliyor mu | evet (`migrations`: 43 kayıt) |
| Uygulama kullanıcısı tablo açıp silebiliyor mu (migration) | evet |
| Satır sayıları, canlıyla | 38 tablonun 36'sı aynı |
| Farklı olan 2 tablo | `efatura_faturalari` (10.049 → 10.052), `efatura_senkron_calismalari` (11.584 → 11.594): yedek 06:34'te alındı, e-Fatura senkronu dakikada bir çalışıyor — beklenen fark |
| `erp_kesif` | silindi; sunucuda geride dosya kalmadı |

Lokaldeki (PostgreSQL 16) sahiplik yöntemi canlıda (18.6) da aynı sonucu verdi.

### 3. Uygulama kullanıcısının yetkileri

| | Lokal | Canlı |
|---|---|---|
| Yönetici | hayır | hayır |
| Veritabanı açma (`CREATEDB`) | hayır | **hayır** |
| Rol açma | hayır | hayır |
| Başka role üyelik | yok | yok |
| `pg_signal_backend` üyeliği | — | hayır |
| `erp` veritabanının sahibi | evet | **evet** |
| `postgres` veritabanına bağlanma | — | evet |

Canlı durum README §9'daki "ayrı servis hesabı" ihtiyacını doğruluyor:
uygulama kullanıcısıyla veritabanı açılamaz, dolayısıyla geri yükleme yapılamaz.
`erp`'nin sahibi uygulama kullanıcısı olduğu için, servis hesabı ona üye
yapılınca ad değiştirme ve bağlantı koparma da mümkün olur (§4–§6).

**Yedekleme kullanıcısı (kuruldu, 2026-11-18):** `emor_yedek` — yönetici değil,
veritabanı açabilir (`CREATEDB`), rol açamaz; uygulama kullanıcısına üye
(`inherit=true set=true admin=false`). Parolası rastgele üretildi ve yalnız
`/home/admin/.emor_yedek_pgpass` dosyasında (izin 600) duruyor; repoda ve
dokümanlarda yok. Bu, servis hesabının **veritabanı tarafıdır**; Linux
kullanıcısı ve parola dosyasının ona taşınması BY-025'te yapılır.

### 4. Nesnelerin sahibi

| Yöntem | Tablo sahibi | Veritabanı sahibi | Uygulama rolü |
|---|---|---|---|
| Servis hesabı, `--no-owner`, `--role` yok | servis hesabı | servis hesabı | **okuyamıyor** (`permission denied`) |
| Servis hesabı: `CREATE DATABASE … OWNER <uygulama>` + `pg_restore --no-owner --role=<uygulama>` | uygulama | uygulama | okuyor, yazıyor, **tablo açabiliyor** |

İkinci yöntem doğru. "Tablo açabiliyor" önemli: geri yüklenen veritabanında
migration çalışacak. PostgreSQL 15'ten beri `public` şemasının sahibi
veritabanının sahibidir; veritabanı servis hesabının olsaydı migration
`public`'e tablo açamazdı.

**Sonuç — tasarıma eklenecek koşul:** servis hesabı, uygulama rolünün
**üyesi** olmalı. `CREATE DATABASE … OWNER` ve `--role` bunu gerektirir. Bunun
anlamı: servis hesabı, uygulama rolünün yapabildiği her şeyi yapabilir (canlı
veriyi okuyup yazabilir). Ayrı hesabın değeri, uygulamanın kendisinin
veritabanı açma / ad değiştirme / bağlantı koparma yetkisi taşımamasıdır.

### 5. İki ad değişikliği tek işlemde

`BEGIN; ALTER DATABASE a RENAME TO b; ALTER DATABASE c RENAME TO a; COMMIT;`
servis hesabıyla (veritabanlarının sahibi olan rolün üyesi olarak) çalıştı.

İkinci ad değişikliği hata verdiğinde (hedef ad zaten var) **birincisi de geri
alındı**; adlar değişmedi. Yani yer değiştirme atomik; README §6.4 7. adımdaki
"yarıda kalırsa geri çevir" dalına gerek yok.

Sınırlama: bağlı olunan veritabanının adı değiştirilemez
(`current database cannot be renamed`). Ad değiştirme, başka bir veritabanına
(ör. `postgres`) bağlanılarak yapılır; servis hesabının oraya bağlanma hakkı olmalı.

### 6. Bağlantıya kapatma ve koparma

- `ALTER DATABASE … WITH ALLOW_CONNECTIONS false` servis hesabıyla çalıştı.
- Sonrasında **yöneticinin de** yeni bağlantısı reddedildi.
- Açık olan eski bağlantı kendiliğinden kopmadı; `pg_terminate_backend` ile
  koparılması gerekti.
- Servis hesabı, uygulama rolünün bağlantılarını koparabildi (üyelik sayesinde;
  ayrıca yetki gerekmedi).

README §6.4 6. adımdaki sıra (önce kapat, sonra kopar) doğrulandı.

### 7. Veritabanı özellikleri

Kaynakta tanımlı veritabanı düzeyi ayarlar (`TimeZone`, `statement_timeout`)
yedekte **yok**: `pg_restore --list` çıktısında `DATABASE PROPERTIES` girdisi
çıkmadı. Geri yüklenen veritabanında bu ayarlar boş kaldı. (`pg_dump` bunları
yalnız `--create` ile yazar; tasarımda veritabanını biz açtığımız için
`--create` kullanılmıyor.)

Kodlama ve sıralama kuralı da yedekten gelmez; `CREATE DATABASE` sırasında
verilir.

| | Lokal | Canlı |
|---|---|---|
| Kodlama | UTF8 | UTF8 |
| Sıralama / karakter türü | `C.UTF-8` | **`en_US.UTF-8`** |
| Sağlayıcı | libc | libc |
| Veritabanı düzeyi ayarlar | yok | **yok** |
| Sunucu saat dilimi | Europe/Istanbul | **Etc/UTC** |
| `public` şemasının sahibi | — | `pg_database_owner` (yani veritabanı sahibi) |
| Eklentiler | plpgsql | plpgsql |
| Tablolar | 28 (lokal) | 38, hepsi `erp`'nin |

Dikkat çekenler:

- Canlıda sıralama kuralı Türkçe değil, `en_US.UTF-8`. Geri yüklenen
  veritabanı da `en_US.UTF-8` ile açılmalı; yoksa `ORDER BY` sonuçları sessizce
  değişir. Lokal ortam (`C.UTF-8`) canlıdan farklı sıralar
  (`Dikkat_Edilecekler.md` T6).
- Canlıda veritabanı düzeyi ayar yok; sunucunun saat dilimi UTC. Manifest
  mekanizması yine gerekli (ileride ayar eklenirse kaybolmasın), ama bugün
  taşınacak bir ayar yok.

**Sonuç:** README §5.3'teki "manifest bu özellikleri kaydeder, yeni veritabanı
aynı değerlerle açılır" kararı zorunlu. Okunacak yerler: `pg_database`
(kodlama, `datcollate`, `datctype`, `datlocprovider`) ve `pg_db_role_setting`
(veritabanı düzeyi ayarlar).

### 8. Aynı andan sayım

Bir oturumda `pg_export_snapshot()` alındı ve açık tutuldu. Sonra tabloya 3
kayıt eklendi. Görüntüden yapılan sayım **5**, canlı sayım **8** çıktı.
`pg_dump --snapshot=<görüntü>` başarıyla çalıştı. Geri yüklenen veritabanında
sayım 5'ti; yani yedek ve sayım aynı andandı.

### 9. Bozuk yedek

| Dosya | `pg_restore --list` | `pg_restore --file=/dev/null` |
|---|---|---|
| Üçte biri kesilmiş | geçti (0) | **yakaladı** — `end of file` |
| Ortasına 16 bayt yazılmış | geçti (0) | **yakaladı** — `incorrect data check` |

`--list` yalnız içindekiler tablosunu okur; iki bozulmayı da göremedi. README
§4.4 1. düzeydeki "her yedekte `--file=/dev/null` ile tam okuma" kararı doğrulandı.

## Tasarıma etkisi

README'ye işlenenler (bu görevin commit'lerinde):

1. §5.2: servis hesabının uygulama rolüne **üye** olması koşulu ve anlamı;
   doğru sahiplik yöntemi (`CREATE DATABASE … OWNER` + `--role`).
2. §6.4 6. ve 7. adım: doğrulanan sıra; ad değiştirme atomik, `postgres`
   veritabanından yapılır; "yarıda kalırsa geri çevir" dalı kaldırıldı.
3. §7.7: canlı sürüm 18.6, araçlarla eşleşiyor; kalıcı kural olarak kaldı.
4. §9: yetki listesine üyelik ve `postgres` veritabanına bağlanma hakkı;
   canlı uygulama kullanıcısının yetkileri; kurulan `emor_yedek`; "bilgi
   işlemden istenecekler" yerine "kurulum betiğiyle yapılacaklar" (kullanıcı kararı).

## Sınırlar ve açık kalanlar

1. **Ad değiştirme ve bağlantı koparma deneyleri yalnız PostgreSQL 16'da
   yapıldı**, canlı 18.6. Canlı `erp`'ye dokunmamak için canlıda denenmedi.
   Bu davranışların 18'de değişmediği varsayıldı; BY-028 geçici
   veritabanlarıyla canlıda yeniden doğrular. (Sahiplik yöntemi canlıda da
   denendi, aynı sonucu verdi.)
2. Lokal geliştirme ortamı canlıdan farklı: PostgreSQL 16 ve `C.UTF-8`
   (canlı 18 ve `en_US.UTF-8`). Gerçek araçlarla yapılan uçtan uca testlerde
   bu fark akılda tutulmalı.
