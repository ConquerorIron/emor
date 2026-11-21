# Kurtarma: boş bir sunucuda sıfırdan ayağa kaldırma (BY-024)

Sunucu (disk, sanal makine) tamamen kaybedildiğinde uygulamayı ve veritabanını
sunucu dışındaki şifreli yedekten geri kurma adımları. Tasarım: README §9
"Kurtarma paketi", §4.6, §4.7, §7.5.

> Bu dokümanda **hiçbir gizli değer yoktur**; yalnız hangi sırrın ne işe
> yaradığı, nerede durduğu ve kimde saklanması gerektiği yazar. Değerleri
> buraya, depoya ya da bilet sistemine yazmayın.

**Son deneme:** 2026-11-21, lokal (WSL, PostgreSQL 16.15) — §8. Sunucuda
(PostgreSQL 18.6) ve boş bir Linux makinesinde servis kipiyle henüz
denenmedi; §4'ün bugünkü sırası (sürümlü paket, imza doğrulaması, çalıştıran
kimlikler) baştan sona uygulanmadı. Bağlanma yetkileri adımları (§4.8 → §4.11)
lokalde, daraltılmış CONNECT'li bir veritabanının yedeğiyle denendi (§8, ilk kayıt).

---

## 1. Sunucu dışında ne var

Her başarılı sunucu dışı kopyadan sonra (NAS, SFTP, FTP, S3, Google Drive)
hedefte şunlar bulunur. `.gpg` dosyaları Ayarlar'da yüklenen **aynı açık
anahtarla** şifrelidir:

| Uzak dosya | İçerik | Ne işe yarar |
|---|---|---|
| `erp_<kaynak>_<zaman>.dump.gpg` | Veritabanı yedeği (pg_dump özel biçim) | Geri yüklenecek veri |
| `emor-kurtarma-<sürüm>-tamam.json` | **Paketin işaretçisi** (şifresiz): sürümün bileşenlerinin adı, şifreli dosyanın SHA-256'sı, boyutu | Hangi sürümün tamam olduğu; indirilen paket dosyalarının bozulmadığı |
| `emor-kurtarma-<sürüm>-manifestler.tar.gpg` | Yedek klasöründeki bütün imzalı manifestler (`erp_….json`) | Dump'ın özeti, kodlama / sıralama kuralı, satır sayıları; yedeğin "güvenilir" sayılması |
| `emor-kurtarma-<sürüm>-olaylar.jsonl.gpg` | Olay günlüğü (`olaylar.jsonl`, imzalı satırlar) | Sabitleme, doğrulama, kopya bilgisi (şifreli kopyanın özeti `sifreli_sha256`) |
| `emor-kurtarma-<sürüm>-ayarlar.json.gpg` | Ayar kopyası (planlar, hedefler, mail ayarı; sırlar hariç) | Planları / hedefleri yeniden tanımlamak; veritabanı eskiyse güncel ayarlar |
| `emor-kurtarma-<sürüm>-denetim.log.gpg` | `yedekleme` denetim günlüğünün bütün günleri, birleşik | Kim ne zaman ne yaptı |

**Kurtarma paketi sürümlüdür.** `<sürüm>` UTC zamanla başlar
(`20261121T101500Z-1a2b3c`): adlar ada göre sıralanınca zamana göre sıralanır.
Her gönderim yeni adlarla yazar, hiçbir dosyanın üstüne yazmaz. Bileşenlerin
hepsi yazılıp hedeften geri okunarak doğrulandıktan **sonra** o sürümün
`-tamam.json` işaretçisi yazılır; ancak ondan sonra eski sürümler silinir ve
uzak yaşam sinyali gider.

- **Yetkili paket:** `-tamam.json` işaretçisi olan EN YENİ sürüm. İşaretçisi
  olmayan `emor-kurtarma-<sürüm>-…` dosyaları yarım kalmış bir gönderimdir;
  kullanılmaz (sonraki başarılı gönderim siler).
- Hedefte son **iki** tamam sürüm durur: yenisinin bir dosyası bozuk çıkarsa
  bir öncekiyle kurtarılır.
- Gönderim yarıda kalırsa önceki tamam sürüm ve işaretçisi değişmez; yedeğin
  kopyası "tamam" (aktarım doğrulandı) ama **kurtarmaya hazır değil**
  sayılır: o yedeğin imzalı manifesti henüz o hedefteki hiçbir pakette
  yoktur. Hata İşlem Günlüğü'nde `kurtarma_paketi` olarak görünür, uzak yaşam
  sinyali gitmez (izleyici uyarır), Yedekler ekranında kopyanın yanında
  "kurtarma paketi bekleniyor" yazar ve saklama o yedeğin **yerel arşivini
  silmez**. Paketin manifest arşivi yedek klasöründeki bütün manifestleri
  taşıdığı için o hedefe giden bir sonraki başarılı paket, eksik kalan
  kopyaları da kurtarmaya hazır yapar (BY-010-R2, BY-024-R3). Hazır sayılmak
  için arşivdeki manifestin imzası geçerli, kimliği o yedeğin ve özeti
  yedeğinkiyle aynı olmalı; o manifesti taşıyan son tamam sürüm hedeften
  silinince kopya yeniden "hazır değil" olur (BY-024-R5).
- Eski sürüm silinirken işaretçi silinemezse (ör. kovada yalnız işaretçiye
  silme izni yok) sürümün hiçbir dosyasına dokunulmaz: hedefte "tamam"
  görünen her set eksiksizdir (BY-024-R4).
- Adlar `erp_` ile başlamadığı için yedek listesine ve temizliğe girmezler.

İşaretçi imzalı değildir: yalnız indirmenin bozulmadığını gösterir. Yedeğin bu
sistemden çıktığı manifest imzasıyla doğrulanır (§4.8).

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

Sıra servis kipine göredir (`YEDEKLEME_CALISTIRICI=servis`, isletim.md §1):
yedek klasörü, imza anahtarı ve `emor_yedek` parolası servis hesabındadır
(README §5.2, §5.5). Yollar kurulum betiğinin varsayılanlarıdır
(`scripts/sunucu-yedekleme-kurulumu.sh`): uygulama `/var/www/emor`, yedek kökü
`/var/lib/emor-yedek`.

**Her adımın başında onu çalıştıran kimlik yazar:**

| Kimlik | Kim | Bu belgede ne yapar |
|---|---|---|
| **root** | `sudo` ile | Paketler, kurulum betiği, servisler. Servis hesabının klasörlerine **yazmaz**, oradaki yolları izlemez (D5) |
| **postgres** | `sudo -u postgres` | PostgreSQL rolü ve veritabanı açma |
| **admin** | yayın kullanıcısı | Uygulama klasörü (`git`, `composer`, `.env`), uzak hedeften indirme, `migrate`, `optimize` |
| **emor-yedek** | servis hesabı, `sudo -u emor-yedek` | Kurtarma çalışma klasörü, şifre çözme, imza doğrulaması, yedek klasörüne yazma, geri yükleme, tarama |
| www-data | web | Komut çalıştırmaz |

Servis hesabının komutları **servis tanımının ortamıyla** çalışır (kendi
önbellek ve günlük yolları, `PGPASSFILE`; README §5.5, D7 —
sunucu-kurulumu.md §3). Ortam, kurulum betiğinin yazdığı
`emor-yedek.service` tanımından gelir; tanım etkinleştirilmemiş olsa da okunur.
Bu belgede kısaltma olarak (admin'in kabuğunda bir kez tanımlanır):

```bash
servis() { sudo -u emor-yedek env $(systemctl show emor-yedek -p Environment --value) "$@"; }
```

### 4.1 Sunucu bileşenleri — root

1. Paketler: PostgreSQL 18, PHP 8.5 + uzantılar, msodbcsql18 + pecl
   `sqlsrv` / `pdo_sqlsrv`, nginx, gnupg, Composer, Node.js, `acl`
   (kurulum betiği `setfacl` / `getfacl` ister).
2. `admin` kullanıcısı (yayın kullanıcısı; `sudo` yetkili). `www-data`
   nginx / php-fpm paketleriyle gelir.
3. `en_US.UTF-8` yerel ayarı (§3).
4. `fs.protected_hardlinks=1` (Ubuntu'da varsayılan; kurulum betiği yoksa durur).
5. SSL sertifikası `/etc/ssl/emor` altına (§2).
6. NAS hedefi kullanılıyorsa paylaşım `soft,timeo=…` seçenekleriyle bağlanır
   (`/etc/fstab`): asılı bir ağ paylaşımında PHP dosya çağrılarına süre sınırı
   konamaz; `hard` bağlamada kopya ve kilit süresiz bekleyebilir (BY-023).

### 4.2 PostgreSQL rolleri — postgres

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

`emor_yedek` ve `emor_inceleme`'yi kurulum betiği açar (§4.4); betik `erp`
rolünün var olmasını ister.

### 4.3 Uygulama — root, sonra admin

```bash
# root
sudo install -d -o admin -g admin -m 0755 /var/www/emor

# admin
git clone git@github.com:ConquerorIron/emor.git /var/www/emor   # deploy anahtarı (§2)
cd /var/www/emor
# backend/.env: güvenli yerdeki kopyadan (APP_KEY, DB_PASSWORD, ERP_ADMIN_*,
# servis kipinin YEDEKLEME_* yolları — isletim.md §1 tablosu)
(cd backend && composer install --no-dev --optimize-autoloader --no-interaction)
(cd frontend && npm ci && npm run build)

# root: .env'i web ve (www-data grubundaki) servis hesabı okur, başkası okumaz
sudo chown admin:www-data backend/.env && sudo chmod 0640 backend/.env
```

`.env`'deki yollar kurulum betiğinin yollarıyla **birebir** aynı olmalıdır:
`YEDEKLEME_KLASORU=/var/lib/emor-yedek/yedekler`,
`YEDEKLEME_IMZA_ANAHTARI=/var/lib/emor-yedek/gizli/imza.anahtar`,
`YEDEKLEME_PAROLA_DOSYASI=/var/lib/emor-yedek/gizli/pgpass`,
`YEDEKLEME_AYAR_KOPYASI=/var/lib/emor-yedek/gunluk/ayarlar.json`,
`YEDEKLEME_DURUM_KLASORU=/var/lib/emor-yedek/durum`,
`YEDEKLEME_ISTEK_KLASORU=/var/lib/emor-yedek/istekler`,
`YEDEKLEME_KALICI_KOK=/var/lib/emor-yedek`, `YEDEKLEME_ROL=emor_yedek`.

Bu aşamada `php artisan migrate` **çalıştırılmaz**: veritabanı daha yok ve boş
şema geri yüklemenin önüne geçer.

### 4.4 Yedekleme servis hesabı ve izinler — root

Kurulum betiği servis hesabını (`emor-yedek`), grup üyeliklerini, yedek kökünü
ve izinlerini (`/var/lib/emor-yedek/{yedekler,istekler,durum,gunluk,gizli,onbellek}`),
`emor_yedek` / `emor_inceleme` rollerini, servis tanımını (etkinleştirmeden)
ve dar sudo kuralını kurar (sunucu-kurulumu.md). Boş sunucuda parola dosyası
olmadığı için `emor_yedek` rolüne yeni parola üretir ve
`gizli/pgpass`'e yazar; parola hiçbir yerde görünmez.

```bash
cd /var/www/emor
sudo scripts/sunucu-yedekleme-kurulumu.sh --denetle   # ne eksik
sudo scripts/sunucu-yedekleme-kurulumu.sh             # kur
sudo scripts/sunucu-yedekleme-kurulumu.sh --denetle   # yalnız "[BİLGİ] imza anahtarı henüz yok" kalmalı
```

Betik yedek klasörüne depo işaretini de (`yedekler/.emor-yedek-deposu`)
koyar: tarama ve yedek alma, işareti olmayan klasörü (ör. diski bağlanmamış
bağlama noktası) depo saymaz (sunucu-kurulumu.md §2 "Depo işareti"). Yedek klasörü ayrı bir
diske bağlanacaksa disk **betikten önce** bağlanır.

Betik `backend/.env`'i, `backend/vendor`'ı (§4.3) ve `erp` rolünü (§4.2) ister.
Servis **etkinleştirilmez** (§4.11'e kadar): kurtarma sürerken çalıştırıcı
iş almaz.

### 4.5 İmza anahtarı — admin girdisiyle emor-yedek yazar

Manifest imza anahtarı yedeklerden ayrı saklanan kopyadan (§2) gelir.
**Taramadan ve imza doğrulamasından önce** yerinde olmalıdır (yoksa yedekler
"güvenilmeyen" görünür, §4.8 geçmez). Dosyayı servis hesabı kendi klasörüne
yazar; root oraya yazmaz (D5). `set -C`: var olan anahtarın üstüne yazılmaz.

```bash
# admin — anahtarın kopyası admin'in elinde, ör. ~/kurtarma-sir/imza.anahtar (0600)
sudo -u emor-yedek sh -c 'umask 077; set -C; cat > /var/lib/emor-yedek/gizli/imza.anahtar' \
  < ~/kurtarma-sir/imza.anahtar
sudo /var/www/emor/scripts/sunucu-yedekleme-kurulumu.sh --denetle   # "DENETİM: her şey yerinde."
```

Denetim anahtarın sahibini (`emor-yedek`), iznini (0600) ve ACL'sini doğrular.

### 4.6 Uzak hedeften indirme — admin

Hedefin kendi aracıyla (NAS: bağlayıp `cp`; SFTP: `sftp get`; S3: konsol ya da
`aws s3 cp`; Google Drive: web arayüzü; FTP: istemci) admin'in klasörüne
indirin:

```bash
install -d -m 0700 ~/kurtarma-indirilen
```

1. En yeni `emor-kurtarma-<sürüm>-tamam.json` (adlar ada göre sıralanınca
   sonuncusu, §1) ve içinde `dosyalar` altında adı geçen dosyalar.
2. Geri yüklenecek `erp_….dump.gpg` (genellikle en yenisi).

Paket dosyalarının indirmede bozulmadığını işaretçiyle denetleyin:

```bash
cd ~/kurtarma-indirilen
php -r '$i = json_decode(file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR); $ok = true;
  foreach ($i["dosyalar"] as $d) { $h = @hash_file("sha256", $d["ad"]); $ok = $ok && $h === $d["sha256"];
    echo ($h === $d["sha256"] ? "TAMAM  " : "FARKLI "), $d["ad"], "\n"; } exit($ok ? 0 : 1);' \
  emor-kurtarma-<sürüm>-tamam.json
```

Bir dosya `FARKLI` ya da yoksa bir önceki sürümü (diğer `-tamam.json`) indirin.
Dump'ın kendisi §4.8'de manifestle doğrulanır.

### 4.7 Çalışma klasörü ve şifre çözme — emor-yedek

Çalışma klasörünü servis hesabı açar (0700, sahibi `emor-yedek`); admin'in
indirdiği dosyaları ve özel anahtarı servis hesabına **standart girdiden**
verir: root bu klasöre hiç yazmaz, admin içini göremez. Özel anahtarı (ve
parolalıysa parola dosyasını) yalnız bu adım için sunucuya getirin; uygulama
klasörlerine koymayın, §4.12'de silinir. Parola hiçbir komutun satırına
yazılmaz.

```bash
# admin'in kabuğunda; komutlar emor-yedek olarak çalışır
cd /var/www/emor/backend
K=$(sudo -u emor-yedek mktemp -d /var/tmp/emor-kurtarma.XXXXXX)
ver() { sudo -u emor-yedek sh -c 'umask 077; set -C; cat > "$1"' _ "$K/$2" < "$1"; }   # $1 kaynak, $2 ad
for f in ~/kurtarma-indirilen/*; do ver "$f" "$(basename "$f")"; done
ver ~/kurtarma-sir/ozel.asc ozel.asc          # parolalıysa: ver ~/kurtarma-sir/parola parola
for f in ~/kurtarma-indirilen/*.gpg; do
  b=$(basename "$f" .gpg)
  servis php artisan yedek:sifre-coz "$K/$b.gpg" "$K/$b" --anahtar="$K/ozel.asc"   # parolalıysa: --parola-dosyasi="$K/parola"
done
```

Komut veritabanı olmadan çalışır; her işlem kendi geçici anahtarlığında yapılır
ve anahtarlık iş bitince silinir. Yanlış anahtar, eksik / yanlış parola ve
bozuk dosya ayrı hata koduyla durur (`YEDEK_SIFRE_COZULEMEDI`,
`YEDEK_ANAHTAR_PAROLASI_HATALI`); yarım çıktı bırakmaz.

Uygulama henüz kurulamıyorsa yalnız **çözme** başka bir güvenilen makinede düz
gpg ile yapılabilir (imza doğrulaması §4.8 yine bu uygulamayla yapılır):

```bash
G=$(mktemp -d); chmod 700 $G
gpg --homedir $G --batch --import ozel.asc
gpg --homedir $G --batch --pinentry-mode loopback --passphrase-file parola \
    --output <ad>.dump --decrypt <ad>.dump.gpg
gpgconf --homedir $G --kill all; rm -rf $G
```

### 4.8 İmza ve özet doğrulaması — emor-yedek (SQL'den ÖNCE)

Manifestleri çalışma klasörüne açın ve geri yüklenecek dump'ı imzalı
manifestine karşı doğrulayın. `yedek:manifest-dogrula`, tarama ve ekrandaki
geri yüklemenin kullandığı kodla manifest imzasını (imza anahtarıyla, §4.5),
manifestin bu dosyaya ait olduğunu, boyutu ve SHA-256'yı denetler; veritabanına
bağlanmaz, dosyalara dokunmaz.

```bash
sudo -u emor-yedek install -d -m 0700 "$K/m"
sudo -u emor-yedek tar -xf "$K/emor-kurtarma-<sürüm>-manifestler.tar" -C "$K/m"
servis php artisan yedek:manifest-dogrula "$K/<ad>.dump" --manifest="$K/m/<ad>.json"
```

**Yalnız `DOGRULANDI` yazarsa devam edin.** Komut `createdb` için kodlama ve
sıralama değerlerini de yazar (§4.9). Başarısızsa (çıkış kodu 1) **geri
yükleme yapılmaz**, hiçbir SQL çalıştırılmaz:

| Çıktı | Anlamı | Ne yapılır |
|---|---|---|
| `GERI_YUKLEME_IMZA_GECERSIZ: … imzası tutmuyor` | Manifest değiştirilmiş ya da imza anahtarı yanlış | Doğru imza anahtarını bulun (§2); bulunamıyorsa §7 |
| `GERI_YUKLEME_IMZA_GECERSIZ: imza anahtarı yok` | §4.5 yapılmadı / `.env` yolu farklı | §4.5, §4.3'teki yol |
| `GERI_YUKLEME_IMZA_GECERSIZ: manifest bozuk / imzasız / yok / başka bir yedeğin` | Arşivde bu yedeğin geçerli manifesti yok | Başka bir yedek ya da önceki paket sürümü |
| `GERI_YUKLEME_OZET_TUTMUYOR` | Dump bozuk, eksik ya da başka dosya | Dump'ı yeniden indirip çözün; olmazsa başka yedek |
| `GERI_YUKLEME_VERITABANI_YETKILERI` (yalnız `--yetkileri-…` ile, §4.9 / §4.11) | Bağlanma yetkileri manifestle eşdeğer uygulanamadı / eşdeğer değil (ör. yetkideki rol bu sunucuda yok); uygulama yetkileri hiç değiştirmez | Eksik rolü açın (§4.2) ve adımı yineleyin; olmazsa uygulamayı AÇMAYIN |

Doğrulanınca dosyaları servis hesabı yerlerine koyar (yalnız düz dosyalar;
arşivdeki bağlantı vb. alınmaz):

```bash
sudo -u emor-yedek sh -c '
  set -e; K="$1"; Y=/var/lib/emor-yedek/yedekler; G=/var/lib/emor-yedek/gunluk; S="$2"
  find "$K/m" -maxdepth 1 -type f -name "erp_*.json" -exec install -m 0640 -t "$Y" {} +
  install -m 0640 "$K/$3" "$Y/$3"
  install -m 0640 "$K/emor-kurtarma-$S-olaylar.jsonl" "$Y/olaylar.jsonl"
  install -m 0640 "$K/emor-kurtarma-$S-ayarlar.json" "$G/ayarlar.json"
  install -m 0640 "$K/emor-kurtarma-$S-denetim.log" "$G/kurtarilan-denetim.log"' \
  _ "$K" "<sürüm>" "<ad>.dump"
```

Olay günlüğü yedek klasörüne (`YEDEKLEME_KLASORU/olaylar.jsonl`), ayar kopyası
`YEDEKLEME_AYAR_KOPYASI` yoluna, denetim günlüğü arşiv olarak günlük klasörüne
günlük dosya adlarıyla karışmayan bir adla gider. İşaretçide `atlanan` altında
geçen bileşen (gönderim anında kaynağı yoktu) pakette yoktur: onun satırını
çıkarın.

### 4.9 Veritabanını açma ve geri yükleme — postgres, sonra emor-yedek

Değerler §4.8'in çıktısından (canlıda bugün `UTF8` / `en_US.UTF-8`).
`--create` kullanılmaz: veritabanı adı, kodlaması ve sahibi elle verilir
(`kesif.md` §7).

```bash
# postgres
sudo -u postgres createdb --owner=erp --template=template0 \
     --encoding=UTF8 --lc-collate=en_US.UTF-8 --lc-ctype=en_US.UTF-8 erp
# Manifestte veritabanı düzeyi ayar varsa (bugün yok): ALTER DATABASE erp SET …;

# emor-yedek — parola servis ortamındaki PGPASSFILE'dan (gizli/pgpass); -h .env'deki DB_HOST
servis pg_restore -h 127.0.0.1 -U emor_yedek -d erp \
    --no-owner --role=erp --exit-on-error --single-transaction \
    /var/lib/emor-yedek/yedekler/<ad>.dump
```

Nesnelerin sahibi `erp` olur (`--role=erp`; `emor_yedek` `erp`'nin üyesi).
Parola komut satırına yazılmaz, sorulmaz.

**Bağlanma yetkileri — emor-yedek (BY-031-R2).** `createdb` veritabanını
varsayılan yetkilerle açar (PUBLIC herkes bağlanır, geçici tablo açar);
`pg_restore --no-owner` veritabanının kendi ACL'sini (CONNECT / TEMP)
getirmez. Kaynakta bağlanma daraltılmışsa (ör. yalnız belirli rollere
CONNECT) yeni veritabanı farklı yetkilerle açılırdı. İmzalı manifestteki
`baglanma_yetkileri` (`yedek:manifest-dogrula` çıktısında "Bağlanma
yetkileri (datacl)") yeni veritabanına, ekrandaki geri yüklemenin kullandığı
kodla uygulanır: imza ve özet YENİDEN doğrulanır, sonra yetkiler tek işlemde
eşdeğer yapılır (sonuç (rol, yetki, devredilebilir) kümesi olarak
karşılaştırılır; tutmazsa yetkiler hiç değişmez, çıkış kodu 1):

```bash
# emor-yedek — manifest dump'ın yanında (§4.8'de yedekler/'e kondu)
servis php artisan yedek:manifest-dogrula /var/lib/emor-yedek/yedekler/<ad>.dump --yetkileri-uygula=erp
# beklenen son satır: "YETKILER_UYGULANDI: erp veritabanının bağlanma yetkileri manifestle eşdeğer."
```

`GERI_YUKLEME_VERITABANI_YETKILERI` yazarsa (genellikle yetkide adı geçen bir
rol bu sunucuda yok) rolü açın (§4.2) ve yineleyin; uygulamayı açmayın.
SQL elle yazılmaz: aynı iş tek yerde, `GeriYuklemeVeritabani` içindedir (K10).

Oturum, önbellek ve kuyruk tablolarının (`sessions`, `cache`, `jobs` …) yalnız
şeması gelir, verisi boştur (README §7.1) — beklenen durumdur.

### 4.10 Şema ve dizin — admin, sonra emor-yedek

```bash
# admin
cd /var/www/emor/backend
php artisan migrate --force      # kod yedekten yeniyse eksik migration'lar

# emor-yedek
servis php artisan yedek:tara    # beklenen: "… imzalı, 0 güvenilmeyen …"

# admin
php artisan storage:link
php artisan optimize
```

§4.8'den geçen yedek taramada "güvenilmeyen" görünüyorsa komutlar farklı bir
anahtar ya da klasör görüyordur: `servis` kalıbıyla çalıştırıldığını, imza
anahtarını (§4.5) ve `.env`'deki yolları (§4.3) denetleyin.

### 4.11 Servisler — önce emor-yedek denetimi, sonra root

**Açılıştan önce eşdeğerlik denetimi (emor-yedek).** Servisler başlamadan
veritabanının bağlanma yetkileri imzalı manifestle eşdeğer mi, DEĞİŞTİRMEDEN
denetlenir (§4.9'dan sonra `migrate` ya da elle bir işlem yetkileri
değiştirmiş olabilir):

```bash
servis php artisan yedek:manifest-dogrula /var/lib/emor-yedek/yedekler/<ad>.dump --yetkileri-denetle=erp
# yalnız "YETKILER_ESDEGER: …" yazarsa devam; "eşdeğer DEĞİL" yazarsa §4.9'daki
# --yetkileri-uygula adımını yineleyin, denetimi tekrarlayın
```

1. nginx sitesi, php8.5-fpm, `emor-queue` systemd servisi, `/etc/cron.d/emor`
   zamanlayıcısı (`deploy.sh` ve sunucu notları).
2. Grup üyelikleri süreçler yeniden başlayınca etkinleşir:
   `sudo systemctl restart php8.5-fpm emor-queue`.
3. Yedekleme çalıştırıcısı: `sudo systemctl enable --now emor-yedek`.

### 4.12 Temizlik

```bash
sudo -u emor-yedek rm -rf -- "$K"      # emor-yedek: çözülmüş dosyalar, özel anahtar, parola dosyası
rm -rf ~/kurtarma-indirilen            # admin
```

Özel anahtarın ve imza anahtarının admin'deki kopyası (`~/kurtarma-sir`) de
silinir; asılları §2'deki yerlerinde kalır.

## 5. Son kontroller

- [ ] Giriş yapılabiliyor (ERP kullanıcısıyla; ERP'ye ulaşılamıyorsa yerel yönetici).
- [ ] Ayarlar → SQL bağlantıları "Sına" başarılı (APP_KEY doğru: şifreler okunuyor).
- [ ] Veritabanı: kodlama / sıralama manifestle aynı, sahibi `erp`:
      `select pg_encoding_to_char(encoding), datcollate, pg_get_userbyid(datdba) from pg_database where datname = 'erp';`
- [ ] Bağlanma yetkileri manifestle eşdeğer: §4.11'deki `--yetkileri-denetle=erp`
      `YETKILER_ESDEGER` yazdı.
- [ ] Sahibi `erp` olmayan tablo yok:
      `select count(*) from pg_tables where schemaname = 'public' and tableowner <> 'erp';` → 0
- [ ] Birkaç tablonun satır sayısı manifestteki `satir_sayilari` ile aynı.
- [ ] Yedekler ekranında geri yüklenen yedek imzalı görünüyor.
- [ ] Yeni bir elle yedek alınıyor, sunucu dışı hedefe kopyası "tamam", hedefte
      yeni bir `emor-kurtarma-<sürüm>-tamam.json` göründü, İşlem Günlüğü'nde
      `kurtarma_paketi` başarılı, dış izleyiciye uzak yaşam sinyali geldi.
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
- Bu dokümandaki elle geri yükleme de durur: `yedek:manifest-dogrula` (§4.8)
  imzayı doğrulayamaz, `DOGRULANDI` yazmaz ve olağan sırada geri yükleme
  yapılmaz. Dosyanın bu sistemden çıktığı artık kanıtlanamaz: açık anahtar
  gizli değildir, onunla herkes şifreli dosya üretebilir.
- Yine de geri yüklemek **istisnadır** ve sistem sahibinin yazılı kararını
  ister: dump'ın SHA-256'sı imzadan bağımsız bir kayıtla (bildirim maili,
  İşlem Günlüğü'nün başka bir kopyası, hedefteki dosya tarihi) karşılaştırılır;
  karar, kim verdiği ve karşılaştırılan kayıt §8'e yazılır.
- Bu yüzden imza anahtarı yedeklerden ayrı saklanır (§2).

## 8. Deneme kaydı

**2026-11-21 — bağlanma yetkileri, lokal prova (Claude, BY-031-R2).** TEMİZ
MAKİNE DEĞİL: WSL Ubuntu 24.04, PostgreSQL 16.15, `C.UTF-8`; servis hesabı ve
`servis` kalıbı yok (aynı yollar ve rol ortam değişkenleriyle verildi);
geçici roller `b1k_app` (sahip), `b1k_svc` (CREATEDB, `b1k_app` üyesi),
`b1k_diger`, `b1k_yabanci`; geçici veritabanları ve `/tmp` klasörleri sonunda
silindi; `erp` / `erp_test*` kullanılmadı.

1. "Eski sunucu": `b1k_eski` (`REVOKE ALL … FROM PUBLIC; GRANT CONNECT … TO
   b1k_diger`; datacl `{b1k_app=CTc/b1k_app,b1k_diger=c/b1k_app}`), `migrate`,
   `yedek:imza-anahtari`, gerçek `yedek:al` → imzalı manifest bu ACL'yi taşıdı.
2. "Yeni sunucu" (§4.8): dump + manifest yedek klasörüne; `yedek:manifest-dogrula`
   `DOGRULANDI` ve "Bağlanma yetkileri (datacl)" satırı.
3. §4.9: `createdb` + `pg_restore --no-owner --role` → datacl boş (varsayılan),
   `b1k_yabanci` yeni veritabanına BAĞLANABİLDİ (bulgunun kendisi);
   `--yetkileri-denetle` "eşdeğer DEĞİL", çıkış 1. `--yetkileri-uygula`
   `YETKILER_UYGULANDI`; datacl kaynakla aynı.
4. §4.10 `yedek:tara`: 1 imzalı. §4.11 `--yetkileri-denetle` `YETKILER_ESDEGER`;
   `b1k_diger` bağlanıyor, `b1k_yabanci` "permission denied for database";
   `migrations` satır sayısı kaynakla aynı (55).
5. İmzası bozulmuş manifestle `--yetkileri-uygula`: `GERI_YUKLEME_IMZA_GECERSIZ`,
   veritabanının yetkilerine dokunulmadı.

Denenmeyenler: paket indirme ve şifre çözme (BY-024 provası ve
`KurtarmaPaketiTest`), servis hesabıyla çalıştırma, PostgreSQL 18 /
`en_US.UTF-8`. Otomatik test: `YedekManifestDogrulaPostgresTest`
(`YEDEKLEME_GERCEK_PG=1`), `YedekManifestDogrulaTest`.

**2026-11-21 — sıra değişti (BY-024-R1, R2; BY-031-R1), uygulanmadı.**
Kurtarma paketi sürümlü oldu (`-tamam.json` işaretçisi), SQL'den önce
`yedek:manifest-dogrula` ile imza + özet doğrulaması eklendi, her adıma
çalıştıran kimlik yazıldı; servis hesabı, izinler ve imza anahtarı taramadan
önce kurulur. Bu sıra boş bir Linux makinesinde servis kipiyle henüz baştan
sona uygulanmadı (ilk imzalı tarama, arıza sonrası güvenli açılış ve geri
dönüş kayda alınmadı). Otomatik testler: `KurtarmaPaketiTest`,
`YedekManifestDogrulaTest`. Aşağıdaki prova eski sırayla yapıldı.

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
`/dev/shm`. Paket kurulumu (§4.1), servisler (bugün §4.11) ve SSL denenmedi.
