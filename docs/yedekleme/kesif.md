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
- **Canlı:** henüz denenmedi; kullanıcı onayı bekleniyor (aşağıda "Açık").

## Özet

| # | Soru | Sonuç |
|---|---|---|
| 1 | Canlı sunucu sürümü; araç (18.6) ile uyum | **Açık** (canlı) — lokal 16.15 |
| 2 | Mevcut bir yedek gerçekten geri yükleniyor mu | **Lokalde doğrulandı**; canlı yedekle **açık** |
| 3 | Uygulama kullanıcısının yetkileri | Lokalde: yetkisiz. Canlı **açık** |
| 4 | Nesnelerin sahibi uygulama rolü olabiliyor mu | **Doğrulandı** — yöntemle birlikte |
| 5 | İki ad değişikliği tek işlemde | **Doğrulandı** — atomik |
| 6 | Bağlantıya kapatma + koparma | **Doğrulandı** |
| 7 | Veritabanı özellikleri yedeğe giriyor mu | **Doğrulandı: girmiyor** — manifest şart |
| 8 | Aynı andan sayım (`--snapshot`) | **Doğrulandı** |
| 9 | Bozuk yedek yakalanıyor mu | **Doğrulandı** — yalnız tam okuma yakalıyor |

## Ayrıntı

### 2. Geri yükleme ve sayım karşılaştırması

Kaynak veritabanının yedeği yeni bir veritabanına geri yüklendi
(`pg_restore --no-owner --role=<uygulama> --exit-on-error --single-transaction`).
29 tablonun tamamında satır sayıları kaynakla aynı çıktı.

Canlıdaki bir `erp_predeploy_*` yedeğiyle **denenmedi**. Bu, canlı verinin
lokale kopyalanmasını ya da canlıda veritabanı açma yetkisini gerektiriyor;
kullanıcıya soruldu.

### 3. Uygulama kullanıcısının yetkileri

Lokal `erp` rolü: yönetici değil, veritabanı açamaz, rol açamaz, başka bir
role üye değil. Canlıdaki rolün de böyle olduğu varsayılmamalı; canlıda
okunacak. Lokal durum, README §9'daki "ayrı servis hesabı" ihtiyacını doğruluyor:
uygulama rolüyle geri yükleme yapılamaz.

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
verilir. Lokal: `UTF8`, `C.UTF-8`, sağlayıcı libc. Canlı değerler okunacak.

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

README'ye işlenenler (bu görevin commit'inde):

1. §5.2: servis hesabının uygulama rolüne **üye** olması koşulu ve anlamı;
   doğru sahiplik yöntemi (`CREATE DATABASE … OWNER` + `--role`).
2. §6.4 6. ve 7. adım: doğrulanan sıra; ad değiştirme atomik, `postgres`
   veritabanından yapılır; "yarıda kalırsa geri çevir" dalı kaldırıldı.
3. §9: yetki listesine üyelik ve `postgres` veritabanına bağlanma hakkı.

## Açık (canlı, onay bekliyor)

1. **Sürüm.** Canlı PostgreSQL sunucu sürümü. Araçlar 18.6. Sunucu daha eskiyse
   risk somut: yeni sürüm `pg_dump`'ın ürettiği yedek, eski sunucunun tanımadığı
   ayarlar içerebilir (bilinen örnek: PostgreSQL 17 araçları `transaction_timeout`
   ayarını yazar; 16 sunucusu bunu tanımaz). `--exit-on-error` ile geri yükleme
   bu durumda başarısız olur. Sürümler eşleşmeli ya da canlıda denenmeli.
2. **Canlı uygulama rolünün yetkileri**, kodlama / sıralama kuralı ve veritabanı
   düzeyi ayarlar.
3. **Canlı yedekle geri yükleme.** En az: bir `erp_predeploy_*` dosyasında
   `pg_restore --file=/dev/null` (veritabanı gerektirmez, salt okuma). Tam geri
   yükleme için ya veri lokale kopyalanır ya da canlıda veritabanı açma yetkisi gerekir.
