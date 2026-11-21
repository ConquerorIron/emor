# Kurtarma: boş bir sunucuda sıfırdan ayağa kaldırma (BY-024)

Sunucu (disk, sanal makine) tamamen kaybedildiğinde uygulamayı ve veritabanını
sunucu dışındaki şifreli yedekten geri kurma adımları. Tasarım: README §9
"Kurtarma paketi", §4.6, §4.7, §7.5.

> Bu dokümanda **hiçbir gizli değer yoktur**; yalnız hangi sırrın ne işe
> yaradığı, nerede durduğu ve kimde saklanması gerektiği yazar. Değerleri
> buraya, depoya ya da bilet sistemine yazmayın.

**Son deneme:** 2026-11-21, lokal (WSL, PostgreSQL 16.15) — §8. Sunucuda
(PostgreSQL 18.6) henüz denenmedi.

---

## 1. Sunucu dışında ne var

Her başarılı sunucu dışı kopyadan sonra (NAS, SFTP, FTP, S3, Google Drive)
hedefte şunlar bulunur; hepsi Ayarlar'da yüklenen **aynı açık anahtarla**
şifrelidir:

| Uzak dosya | İçerik | Ne işe yarar |
|---|---|---|
| `erp_<kaynak>_<zaman>.dump.gpg` | Veritabanı yedeği (pg_dump özel biçim) | Geri yüklenecek veri |
| `emor-kurtarma-manifestler.tar.gpg` | Yedek klasöründeki bütün imzalı manifestler (`erp_….json`) | Dump'ın özeti, kodlama / sıralama kuralı, satır sayıları; yedeğin "güvenilir" sayılması |
| `emor-kurtarma-olaylar.jsonl.gpg` | Olay günlüğü (`olaylar.jsonl`, imzalı satırlar) | Sabitleme, doğrulama, kopya bilgisi; **şifreli kopyanın özeti** (`sifreli_sha256`) |
| `emor-kurtarma-ayarlar.json.gpg` | Ayar kopyası (planlar, hedefler, mail ayarı; sırlar hariç) | Planları / hedefleri yeniden tanımlamak; veritabanı eskiyse güncel ayarlar |
| `emor-kurtarma-denetim.log.gpg` | `yedekleme` denetim günlüğünün bütün günleri, birleşik | Kim ne zaman ne yaptı |

`emor-kurtarma-*` dosyaları her kopyada üstüne yazılır (adla üstüne yazmayan
Google Drive'da bir önceki silinir); `erp_` ile başlamadıkları için yedek
listesine ve temizliğe girmezler. Gönderim başarısız olursa yedeğin kopyası
yine "tamam" sayılır, hata İşlem Günlüğü'nde `kurtarma_paketi` olarak görünür.

## 2. Saklanacak sırlar — nerede, kimde

Sunucuyla birlikte kaybolanlar sunucu dışında, **yedeklerden ayrı** bir yerde
saklanmalıdır (README §7.5, §9). "Kimde" sütunu README §13 karar 4, 5 ve 13'e
bağlıdır; karar verilene kadar **açık** olarak işaretlidir.

| Sır | Ne işe yarar | Sunucuda nerede | Kurtarma kopyası (kimde) | Kaybolursa |
|---|---|---|---|---|
| **gpg özel anahtarı** (+ varsa parolası) | Sunucu dışı yedekleri ve kurtarma dosyalarını çözer | **Hiçbir yerde** (sunucu yalnız açık anahtarı tutar) | Açık — §13 karar 4. Öneri: iki ayrı kişide / kasada, parolası ayrı yerde | **Bütün sunucu dışı yedekler açılamaz = yedek kaybı** |
| `APP_KEY` (+ `APP_PREVIOUS_KEYS`) | Veritabanındaki şifreli alanlar: SQL bağlantı şifreleri, Protel / İzibiz şifreleri, SMTP şifresi, yedek hedefi sırları; ayar kopyasındaki SMTP şifresi | `backend/.env` | Açık — §13 karar 5 | Veri geri yüklenir ama bu şifreler okunamaz; hepsi ekrandan yeniden girilir. Manifestteki `app_key_parmak_izi` hangi anahtarın gerektiğini gösterir |
| Manifest imza anahtarı | Manifestleri ve olay günlüğünü imzalar; "güvenilir yedek" ayrımı | `YEDEKLEME_IMZA_ANAHTARI` (geçiş: `storage/app/yedek-imza.key`; servis düzeni: `/var/lib/emor-yedek/gizli/imza.anahtar`) | Açık — §13 karar 13 | Bütün yedekler "güvenilmeyen" görünür, ekrandan geri yüklenemez (§7) |
| Uygulama veritabanı rolü (`erp`) parolası | Uygulamanın PostgreSQL bağlantısı | `backend/.env` (`DB_PASSWORD`); kurulumda `/home/admin/.erp_db_pw` | Yeniden verilebilir | Yeni parola verilir (§4.2), `.env`'e yazılır |
| `emor_yedek` rolü parolası | Yedekleme / geri yükleme bağlantısı | `/home/admin/.emor_yedek_pgpass` → BY-025 sonrası servis hesabının `gizli/pgpass` dosyası | Yeniden üretilebilir | Kurulum betiği yeniden üretir |
| Yedek hedefinin erişim bilgisi (FTP / SFTP parolası ya da anahtarı, S3 erişim anahtarı, Google hesabı) | Boş sunucuda yedeği **indirebilmek** | Veritabanında `yedek_hedefleri.gizli_bilgiler` (APP_KEY ile şifreli) — boş sunucuda daha yok | Açık — §13 karar 13 (hedefin yöneticisi / hesap sahibi) | Hedefin kendi yöneticisinden yeni erişim alınır |
| `ERP_ADMIN_KULLANICI` / `ERP_ADMIN_SIFRE` | ERP'ye ulaşılamazken yerel yönetici girişi | `backend/.env` | `.env` ile birlikte | Yeni değer yazılıp seeder çalıştırılır |
| `YEDEKLEME_GOOGLE_ISTEMCI_SIRRI` | Google Drive hedefinin uygulama kaydı | `backend/.env` | Google tarafındaki uygulama kaydının sahibi | Google konsolundan yeni sır alınır |
| SSL sertifikası ve özel anahtarı | `erp.tersane-istanbul.com` HTTPS | `/etc/ssl/emor` | Sertifikayı alan birim (Sectigo wildcard, 2027-01'e kadar) | Sertifika yeniden alınır |
| GitHub deploy anahtarı | Sunucunun depoyu çekmesi | `~admin/.ssh/id_ed25519` | Gerekmez | Yeni anahtar üretilip depo ayarlarına eklenir |

`.env` dosyasının bütünü (yukarıdaki değerler + sunucuya özgü yollar) tek
parça olarak saklanabilir; yedek dosyasının **yanında değil**.

MSSQL (ERP) bağlantı şifreleri, Protel / İzibiz şifreleri ve SMTP şifresi ayrı
saklanmaz: veritabanı yedeğindedir, `APP_KEY` ile açılır.

## 3. Araçlar ve sürümler

| Bileşen | Canlıdaki sürüm | Not |
|---|---|---|
| İşletim sistemi | Ubuntu 26.04 | |
| PostgreSQL sunucusu + istemci araçları | **18.6** | `pg_restore` sunucuyla aynı ana sürüm olmalı (README §7.7). Eklenti: yalnız `plpgsql` |
| Yerel ayar (locale) | `en_US.UTF-8` | Canlı veritabanının sıralama kuralı; yeni sunucuda `locale -a` ile var olduğunu doğrulayın (`locale-gen en_US.UTF-8`). Yoksa `createdb` hata verir |
| PostgreSQL saat dilimi | `Etc/UTC` | |
| PHP | 8.5 + php8.5-fpm | Uzantılar: `pdo_pgsql`, `pdo_sqlsrv` / `sqlsrv` 5.13.2 (pecl), `bcmath` (`composer.json`), Laravel'in istedikleri (`composer install` eksikleri söyler) |
| MSSQL sürücüsü | msodbcsql18 | Microsoft'un 26.04 deposu boş: 24.04 (noble) deposu (`/etc/apt/sources.list.d/mssql-release.list`) |
| GnuPG | 2.4.x | Şifre çözmek için. Anahtar GnuPG 2.4 ile üretildiyse kopyalar OCB kipindedir: çözen tarafta da GnuPG 2.3+ gerekir |
| tar, sha256sum | işletim sistemiyle | |
| nginx, Composer, Node.js / npm | | `deploy.sh` ile aynı |

## 4. Adımlar

Komutlar `admin` kullanıcısıyla, gerekiyorsa `sudo` ile çalıştırılır. Yollar
canlı düzene göredir (`/var/www/emor`); geçiş döneminde yedek klasörü
`backend/storage/app/yedekler`'dir (`YEDEKLEME_KLASORU`).

### 4.1 Sunucu bileşenleri

1. Paketler: PostgreSQL 18, PHP 8.5 + uzantılar, msodbcsql18 + pecl
   `sqlsrv` / `pdo_sqlsrv`, nginx, gnupg, Composer, Node.js.
2. `en_US.UTF-8` yerel ayarı (§3).
3. SSL sertifikası `/etc/ssl/emor` altına (§2).
4. NAS hedefi kullanılıyorsa paylaşım `soft,timeo=…` seçenekleriyle bağlanır
   (`/etc/fstab`): asılı bir ağ paylaşımında PHP dosya çağrılarına süre sınırı
   konamaz; `hard` bağlamada kopya ve kilit süresiz bekleyebilir (BY-023).

### 4.2 PostgreSQL rolleri

`pg_dump` rolleri içermez; yeniden açılır (README §9; `docs/yedekleme/kesif.md` §3–§6):

| Rol | Özellik | Görev |
|---|---|---|
| `erp` | LOGIN; yönetici değil, veritabanı / rol açamaz | Uygulamanın rolü; `erp` veritabanının ve bütün nesnelerin sahibi |
| `emor_yedek` | LOGIN CREATEDB; `erp`'ye üye (INHERIT + SET, ADMIN değil) | Yedek alma, geri yükleme, veritabanı açma |
| `emor_inceleme` | NOLOGIN | Geri yüklenen kopyalarda salt okuma |

```bash
# Parola komut satırına yazılmaz: --pwprompt sorar
sudo -u postgres createuser --login --pwprompt erp
```

`emor_yedek` ve `emor_inceleme`'yi kurulum betiği açar ve yetkilerini
denetler (§4.10; `docs/yedekleme/sunucu-kurulumu.md`). Betik çalışmadan önce
geri yükleme yönetici rolüyle yapılabilir (§4.6).

### 4.3 Uygulama

```bash
sudo mkdir -p /var/www/emor && sudo chown admin: /var/www/emor
git clone git@github.com:ConquerorIron/emor.git /var/www/emor   # deploy anahtarı (§2)
cd /var/www/emor
# backend/.env: kurtarma paketindeki kopyadan (APP_KEY, DB_PASSWORD, ERP_ADMIN_*, YEDEKLEME_* …)
(cd backend && composer install --no-dev --optimize-autoloader --no-interaction)
(cd frontend && npm ci && npm run build)
```

Bu aşamada `php artisan migrate` **çalıştırılmaz**: veritabanı daha yok ve boş
şema geri yüklemenin önüne geçer.

### 4.4 Uzak hedeften indirme

Hedefin kendi aracıyla (NAS: bağlayıp `cp`; SFTP: `sftp get`; S3: konsol ya da
`aws s3 cp`; Google Drive: web arayüzü; FTP: istemci) bir çalışma klasörüne
indirin, ör. `/root/kurtarma` (0700):

- geri yüklenecek `erp_….dump.gpg` (genellikle en yenisi),
- dört `emor-kurtarma-*.gpg` dosyası.

### 4.5 Özel anahtarla çözme

Özel anahtar dosyasını (ve parolalıysa parolanın bulunduğu dosyayı) yalnız bu
adım için sunucuya getirin; uygulama klasörlerine koymayın, iş bitince silin.
Parola hiçbir komutun satırına yazılmaz.

```bash
cd /var/www/emor/backend
K=/root/kurtarma
for f in $K/emor-kurtarma-*.gpg $K/erp_*.dump.gpg; do
  php artisan yedek:sifre-coz "$f" "${f%.gpg}" --anahtar=$K/ozel.asc   # parolalıysa: --parola-dosyasi=$K/parola
done
```

Komut veritabanı olmadan çalışır; her işlem kendi geçici anahtarlığında yapılır
ve anahtarlık iş bitince silinir. Yanlış anahtar, eksik / yanlış parola ve
bozuk dosya ayrı hata koduyla durur (`YEDEK_SIFRE_COZULEMEDI`,
`YEDEK_ANAHTAR_PAROLASI_HATALI`); yarım çıktı bırakmaz.

Uygulama henüz kurulamıyorsa aynı iş düz gpg ile yapılır:

```bash
G=$(mktemp -d); chmod 700 $G
gpg --homedir $G --batch --import $K/ozel.asc
gpg --homedir $G --batch --pinentry-mode loopback --passphrase-file $K/parola \
    --output $K/<ad>.dump --decrypt $K/<ad>.dump.gpg
gpgconf --homedir $G --kill all; rm -rf $G
```

### 4.6 Doğrulama

1. **Şifreli dosya** olay günlüğündeki özetle aynı mı (indirme bozulmadı mı):

   ```bash
   # 1 ya da daha çok satır bulunmalı (kopya olayının sifreli_sha256 alanı)
   grep -c "\"sifreli_sha256\":\"$(sha256sum $K/<ad>.dump.gpg | cut -c1-64)\"" $K/emor-kurtarma-olaylar.jsonl
   ```

2. Manifestleri yedek klasörüne açın, çözülmüş dump'ı yanına taşıyın:

   ```bash
   Y=<YEDEKLEME_KLASORU>   # .env'deki değer; geçiş döneminde /var/www/emor/backend/storage/app/yedekler
   mkdir -p $Y
   tar -xf $K/emor-kurtarma-manifestler.tar -C $Y
   mv $K/<ad>.dump $Y/
   ```

3. **Çözülmüş dump** manifestteki özetle aynı mı:

   ```bash
   php -r '$m = json_decode(file_get_contents($argv[1]), true)["manifest"]; echo $m["sha256"] === hash_file("sha256", $argv[2]) ? "AYNI\n" : "FARKLI\n";' $Y/<ad>.json $Y/<ad>.dump
   ```

   Farklıysa geri yüklemeyin; başka bir yedekle tekrar deneyin.

4. Kodlama ve sıralama kuralını manifestten okuyun:

   ```bash
   php -r 'print_r(json_decode(file_get_contents($argv[1]), true)["manifest"]["veritabani_ozellikleri"]);' $Y/<ad>.json
   ```

### 4.7 Veritabanını açma ve geri yükleme

Değerler bir önceki adımdaki manifestten (canlıda bugün `UTF8` /
`en_US.UTF-8`). `--create` kullanılmaz: veritabanı adı, kodlaması ve sahibi
elle verilir (`kesif.md` §7).

```bash
sudo -u postgres createdb --owner=erp --template=template0 \
     --encoding=UTF8 --lc-collate=en_US.UTF-8 --lc-ctype=en_US.UTF-8 erp
# Manifestte veritabanı düzeyi ayar varsa (bugün yok): ALTER DATABASE erp SET …;

pg_restore -h 127.0.0.1 -U erp -d erp \
    --no-owner --role=erp --exit-on-error --single-transaction $Y/<ad>.dump
```

`-U erp` parolayı sorar (ya da `PGPASSFILE` ile 0600 parola dosyası); parola
komut satırına yazılmaz. `emor_yedek` kurulduysa aynı komut `-U emor_yedek`
ile çalışır, nesnelerin sahibi yine `erp` olur (`--role=erp`).

Oturum, önbellek ve kuyruk tablolarının (`sessions`, `cache`, `jobs` …) yalnız
şeması gelir, verisi boştur (README §7.1) — beklenen durumdur.

### 4.8 İmza anahtarı, olay günlüğü, ayar kopyası

1. İmza anahtarını kurtarma paketinden `YEDEKLEME_IMZA_ANAHTARI` yoluna koyun
   (0600, uygulamanın / servis hesabının kullanıcısı). **Taramadan önce**
   yapılmalı; yoksa yedek "güvenilmeyen" taranır.
2. `$K/emor-kurtarma-olaylar.jsonl` → `$Y/olaylar.jsonl` (0640).
3. `$K/emor-kurtarma-ayarlar.json` → `YEDEKLEME_AYAR_KOPYASI` yolu (0640).
4. `$K/emor-kurtarma-denetim.log` → arşiv olarak `LOG_KLASORU` altına, günlük
   dosya adlarıyla karışmayan bir adla (ör. `kurtarilan-denetim.log`).

### 4.9 Şema ve dizin

```bash
cd /var/www/emor/backend
php artisan migrate --force      # kod yedekten yeniyse eksik migration'lar
php artisan yedek:tara           # beklenen: "1 imzalı, 0 güvenilmeyen …"
php artisan storage:link
php artisan optimize
```

### 4.10 Servisler

1. nginx sitesi, php8.5-fpm, `emor-queue` systemd servisi, `/etc/cron.d/emor`
   zamanlayıcısı (`deploy.sh` ve sunucu notları).
2. Yedekleme servis hesabı ve rolleri: `scripts/sunucu-yedekleme-kurulumu.sh`
   (`docs/yedekleme/sunucu-kurulumu.md`).
3. `php artisan up`.

## 5. Son kontroller

- [ ] Giriş yapılabiliyor (ERP kullanıcısıyla; ERP'ye ulaşılamıyorsa yerel yönetici).
- [ ] Ayarlar → SQL bağlantıları "Sına" başarılı (APP_KEY doğru: şifreler okunuyor).
- [ ] Veritabanı: kodlama / sıralama manifestle aynı, sahibi `erp`:
      `select pg_encoding_to_char(encoding), datcollate, pg_get_userbyid(datdba) from pg_database where datname = 'erp';`
- [ ] Sahibi `erp` olmayan tablo yok:
      `select count(*) from pg_tables where schemaname = 'public' and tableowner <> 'erp';` → 0
- [ ] Birkaç tablonun satır sayısı manifestteki `satir_sayilari` ile aynı.
- [ ] Yedekler ekranında geri yüklenen yedek imzalı görünüyor.
- [ ] Yeni bir elle yedek alınıyor, sunucu dışı hedefe kopyası "tamam", hedefte
      `emor-kurtarma-*` dosyaları yenilendi.
- [ ] Yedek hedefleri "Bağlantıyı Sına" başarılı (Google Drive "yeniden bağlanın"
      diyorsa yeniden bağlanın).
- [ ] Özel anahtar ve parola dosyası sunucudan silindi.

## 6. Açık anahtarın üretilmesi (bir kez, sunucu dışında)

Güvenilen bir makinede:

```bash
gpg --quick-gen-key 'eMOR Yedek <yedek@ornek.invalid>' future-default default never
gpg --armor --export <parmak izi> > emor-yedek-acik.asc               # Ayarlar ekranına yüklenir
gpg --armor --export-secret-keys <parmak izi> > emor-yedek-ozel.asc   # §2: iki ayrı yerde saklanır
```

Ekran yalnız açık anahtarı kabul eder: özel anahtar içeren metin, birden çok
anahtar ve süresi dolmuş anahtar reddedilir. Süreli anahtar kullanılırsa süresi
dolunca sunucu dışı kopyalar durur (`YEDEK_ANAHTAR_SURESI_DOLMUS`); bitiş tarihi
ekranda görünür, öncesinde yenisi yüklenmelidir. Anahtar değişince eski
kopyalar ESKİ özel anahtarla açılır: eski özel anahtar, eski kopyalar
saklandıkça tutulur.

## 7. İmza anahtarı kaybolduysa

Manifestler ve olay günlüğü imza anahtarıyla imzalıdır. Anahtar kaybolursa:

- Yeni anahtar üretilebilir (`php artisan yedek:imza-anahtari`), ama eski
  manifestlerin imzası tutmaz: bütün eski yedekler "güvenilmeyen" görünür ve
  **ekrandan geri yüklenemez** (README §4.5). Olay günlüğünün eski satırları da
  okunmaz (sabitleme, kopya bilgisi kaybolur).
- Bu dokümandaki elle geri yükleme (§4.7) imzaya bakmaz; yedek yine geri
  yüklenebilir. Ama dosyanın bu sistemden çıktığı artık kanıtlanamaz: açık
  anahtar gizli değildir, onunla herkes şifreli dosya üretebilir. Karar
  operatöründür: özeti başka bir kayıtla (bildirim maili, hedefteki dosya
  tarihi, İşlem Günlüğü) karşılaştırın.
- Bu yüzden imza anahtarı kurtarma paketinde, yedeklerden ayrı saklanır.

## 8. Deneme kaydı

**2026-11-21 — lokal prova (Claude, BY-024).** WSL Ubuntu 24.04,
PostgreSQL 16.15, GnuPG 2.4.4. Yerel `erp` veritabanına yalnız okuma
(`pg_dump`) yapıldı; her şey geçici veritabanlarında (`erp_kurtarma_ajan_*`)
ve geçici klasörlerde, sonunda silindi.

1. "Eski sunucu": yerel `erp`'nin kopyası geçici veritabanına; `migrate`,
   `yedek:imza-anahtari`, `yedek:al` → imzalı yedek.
2. Açık anahtar `acikAnahtarBilgisi` ile doğrulanıp ayarlara yazıldı; NAS
   hedefi (`/dev/shm` bağlantı noktası) tanımlanıp `YedekKopyalayici` ile
   gerçek sunucu dışı kopya: kopya `tamam`, kurtarma paketi 4 dosya `basarili`.
3. "Yeni sunucu": veritabanı ve yedek klasörü boş. Beş dosya indirildi,
   `yedek:sifre-coz` ile (veritabanı yokken) çözüldü; şifreli dosyanın özeti
   olay günlüğündeki `sifreli_sha256` ile, çözülen dump'ın özeti manifestle aynı.
4. Manifestteki kodlama / sıralamayla (`UTF8` / `C.UTF-8`) `createdb`,
   `pg_restore --no-owner --role=erp --exit-on-error --single-transaction`.
5. İmza anahtarı yokken `yedek:tara`: 0 imzalı, 1 güvenilmeyen. Anahtar
   konduktan sonra: 1 imzalı. `migrate`: "Nothing to migrate".
6. Satır sayıları (users, migrations) kaynakla aynı; veritabanı sahibi `erp`,
   sahibi `erp` olmayan tablo 0.

Lokalde farklı olanlar: `createdb` `emor_yedek` yerine yerel `postgres`
yöneticisiyle yapıldı (lokalde `emor_yedek` yok); PostgreSQL 18 değil 16;
sıralama kuralı `en_US.UTF-8` değil `C.UTF-8`; uzak hedef gerçek bir NAS değil
`/dev/shm`. Paket kurulumu (§4.1), servisler (§4.10) ve SSL denenmedi.
