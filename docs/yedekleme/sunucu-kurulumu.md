# Sunucu kurulumu: yedekleme servis hesabı (BY-003)

Betik: [`scripts/sunucu-yedekleme-kurulumu.sh`](../../scripts/sunucu-yedekleme-kurulumu.sh)
Tasarım: [README](README.md) §5.2, §5.5, §9.

Betik yedekleme / geri yükleme işlerini yapacak **servis hesabını** ve onun
çevresini kurar. Tekrar çalıştırılabilir: ikinci çalıştırma hiçbir şeyi
değiştirmez. `--denetle` kipi hiçbir şeyi değiştirmeden durumu raporlar.

**Sunucuda ne zaman çalıştırılır:** yalnız kullanıcı talimatıyla (Backup-Jira
§1.3 madde 8). Canlıda ilk kez 2026-11-22'de çalıştı (servis etkinleştirilmedi,
modül geçiş kipinde). Betik değiştiğinde (ör. servis tanımına `KillMode=mixed`,
sudo kuralından `is-active` çıkarıldı — YF-23, YF-71) yeniden çalıştırılır;
`--denetle` önce farkı gösterir. Servis kipine geçiş: isletim.md §2.

## 1. README §9 maddelerinin karşılığı

| §9 maddesi | Betikte | Not |
|---|---|---|
| 1. Ayrı servis hesabı: işletim sistemi kullanıcısı | Bölüm 1 | `emor-yedek`, sistem hesabı, giriş kabuğu yok |
| 1. … veritabanı rolü, `CREATEDB` | Bölüm 5 | `emor_yedek` (BY-002'de açıldı; betik denetler, eksikse düzeltir) |
| 1. … uygulama rolüne üyelik | Bölüm 5 | `INHERIT`, `SET` evet; `ADMIN` hayır |
| 1. … `postgres` veritabanına bağlanma | Bölüm 5 | Yetki yoksa verilir; parola dosyasıyla gerçek bağlantı denenir |
| 1. … kuyruk ve zamanlayıcıyı durdurup başlatma | Bölüm 7 | Kuyruk: dar `sudo` kuralı (`systemctl stop/start emor-queue`, başka hiçbir şey). Zamanlayıcı: yetki gerekmez, bakım modunda zamanlanmış işler çalışmaz (README §6.4) |
| 2. Web dışı yedek klasörü | Bölüm 3 | `/var/lib/emor-yedek` |
| 3. Ayrı servis tanımı | Bölüm 6 | `emor-yedek.service` (`yedek:calistir`) kurulur, **etkinleştirilmez**; servis kipine geçişte etkinleştirilir (isletim.md §2) |
| 4. Sunucu dışı hedef ve erişim | — | Betikte değil: hedefler ve erişim bilgileri ekrandan tanımlanır (BY-016, BY-020…BY-023) |
| 5. Uygulama dışı sessizlik izleyicisi | — | Betikte değil: yaşam sinyali BY-012; izleyicinin kim / nerede olacağı açık karar (README §13, madde 11) |
| 6. Salt okunur inceleme rolü | Bölüm 5 | `emor_inceleme`, `NOLOGIN`; geri yüklenen kopyalarda okuma yetkisini çalıştırıcı verir. `NOLOGIN` salt okunur yapmaz: yönetici / veritabanı açma / rol açma / çoğaltma / RLS atlama açıksa denetim bildirir, kurulum kapatır; başka bir role üyeliği bildirilir |
| 7. §5.5 dosya yetkileri | Bölüm 2, 3, 4 | Aşağıdaki tablo |
| 8. Sanal makine yedeği var mı | — | Betikle öğrenilemez; açık soru (README §13, madde 9) |

Ayrıca: `emor_yedek` parola dosyası `/home/admin`'den servis hesabına taşınır
(bölüm 4); imza anahtarının yeri hazırlanır ama betik anahtarı **üretmez ve
taşımaz**: geçiş kipinde çalışmış kurulumda anahtarı servis hesabı uygulamadan
kopyalar, boş kurulumda `.env`'den sonra üretir (isletim.md §2). Var olan bir
anahtarın yerine yenisini üretmek eski yedeklerin imzasını geçersiz kılar.

## 2. Dosya yetkileri (README §5.5)

Sahip:grup `emor-yedek:emor-yedek`; yalnız kök klasör `root:emor-yedek`.
Web kullanıcısı (`www-data`) `emor-yedek` grubuna alınır; yayın kullanıcısı
(`admin`) alınmaz, ona ACL ile yalnız gereken yerde izin verilir.

| Yol | İzin | Servis | Web (grup) | Yayın (ACL) |
|---|---|---|---|---|
| `/var/lib/emor-yedek` (sahibi root) | 0750 | geçer | geçer | yalnız geçer |
| `yedekler/` — yedek, manifest, olay günlüğü | 2750 | okur, yazar | okur | erişmez |
| `istekler/` (kendisi web'e / yayına yazılamaz: D5) | 2750 | okur, yazar | geçer | yalnız geçer |
| `istekler/bekleyen/` — yeni istekler | 2770 | okur, taşır | yazar | yazar |
| `istekler/isleniyor/` — çalıştırıcının aldıkları | 2750 | okur, yazar | okur | erişmez |
| `istekler/sonuc/` — sonuçlar | 2750 | okur, yazar | okur | okur (`deploy.sh` sonucu bekler) |
| `durum/` — işlem durumu | 2750 | okur, yazar | okur | okur (sonradan açılan dosyalar dahil: varsayılan ACL) |
| `durum/kilit` | 0640 | alır | alır | alır (salt okunur açıp `flock`) |
| `durum/yayin.kilit` — yayın kilidi (BY-013-R1) | 0640 | alır | alır | alır (`deploy.sh` yayın boyunca tutar) |
| `gunluk/` — denetim günlüğü, ayar kopyası | 2750 | okur, yazar | okur | erişmez |
| `gizli/` — `pgpass`, `imza.anahtar` | 0700 / 0600 | okur | erişmez | erişmez |
| `onbellek/` — servisin Laravel önbellekleri | 0700 | okur, yazar | erişmez | erişmez |

Servis hesabı da web grubuna alınır: geri yüklemede uygulamayı bakım moduna
almak ve önbelleği temizlemek, uygulamanın `storage/` klasörüne yazmayı ister.

**Grup üyelikleri tam kümeyle denetlenir (YF-27).** `emor-yedek` grubunda
yalnız web kullanıcısı (birincil grubu `emor-yedek` olan başka hesap da yok),
servis hesabı yalnız `emor-yedek` ve web grubunda olmalı. Fazlası (ör.
`usermod -aG emor-yedek <hesap>` → o hesap bütün dökümleri okur; servis
hesabında `sudo`, `adm`, `docker`…) denetimde `[EKSİK]` çıkar; betik
çıkarmaz, elle bakılır. PostgreSQL'de `emor_yedek` / `emor_inceleme` rollerine
ÜYE olan roller de bildirilir (onların yetkisini devralırlar).

**Servis durdurulunca iş yarıda kalmaz (YF-23).** Servis tanımında
`KillMode=mixed`: `systemctl stop|restart` SIGTERM'ü yalnız PHP sürecine
gönderir; çalıştırıcı o anki işi bitirip çıkar, `pg_dump` / `pg_restore` /
`gpg` / `sftp` alt süreçleri sinyal almaz. Durdurma `TimeoutStopSec` (4000 sn)
kadar bekleyebilir; iş bu süreyi aşarsa bütün süreçler yine öldürülür.

**Depo işareti (BY-011-R3).** Betik `yedekler/.emor-yedek-deposu` dosyasını
açar (servis hesabı yazar, 0640; içerik tek satır `emor-yedek-deposu <uuid>`,
uygulamanın `YedekDeposu` biçimi). Yedek klasörü ayrı bir diske bağlanmışsa
disk ayrılınca bağlama noktası boş ve okunabilir kalır; işaret diskin
kendisinde durduğu için o zaman görünmez. Uygulama işaretsiz klasörü depo
saymaz: `yedek:tara` dizine dokunmadan durur, yedek alma bağlama noktasına
(kök diske) yazmaz (`YEDEK_DEPO_ISARETI_YOK`). İşaretsiz klasörü uygulama
yalnız şu durumda kendisi işaretler: katalogda hiç yedek kaydı yoksa (ilk
kurulum; yarıda kalmış ilk yedek de kayıttır) ya da katalogdaki bir kaydın
imzalı, kimliği tutan manifesti o klasördeyse (işaretten önce kurulmuş ya da
işareti silinmiş depo).

- Var olan işarete betik dokunmaz (kimliği değişmez); biçimi tanınmıyorsa
  denetim "eksik" yazar, kurulum elle bakılmasını ister. Bağlantı ya da sabit
  bağlantıysa betik durur (aşağıdaki bağlantı güvenliği).
- **Disk ayrı bağlanacaksa önce bağlanır, sonra betik çalışır.** Betik disk
  bağlanmadan çalışırsa işaret bağlama noktasının altındaki klasörde kalır:
  disk bağlıyken görünmez, disk ayrılınca görünür ve korumayı boşa çıkarır.
  Böyle olduysa diski ayırıp alttaki klasördeki işareti silin, diski bağlayıp
  betiği yeniden çalıştırın. Yedekleri başka bir diske taşırken de alttaki
  klasörde dosya bırakmayın.

**Servis web kullanıcısının yazabildiği PHP dosyasını yüklemez.** Uygulamanın
`bootstrap/cache/*.php` dosyalarını ve derlenmiş görünümlerini (`storage/framework/views`)
web kullanıcısı yazabilir (sunucuda `admin:www-data`, g+w). Servis bunları
yükleseydi ele geçirilmiş bir web süreci servis hesabıyla kod çalıştırır, imza
anahtarına ve parola dosyasına ulaşırdı. Servis tanımı bu yüzden Laravel
önbelleklerini `onbellek/`'e yönlendirir (`APP_CONFIG_CACHE`,
`APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_ROUTES_CACHE`,
`APP_EVENTS_CACHE`, `VIEW_COMPILED_PATH`). `storage/` yolu bilerek değişmez:
bakım modu dosyası uygulamanın `storage/framework/` klasöründe olmalıdır; oradan
PHP dosyası yüklenmez (Fable incelemesi F1).

**Servis web'in yazabildiği günlük klasörüne yazmaz.** `LOG_KLASORU`
bütün dosya kanallarını (acil durum, `denetim`, çerçeveden gelen `monthly`
dahil) servisin `gunluk/` klasörüne yönlendirir. `storage/logs`'taki bir dosyayı
web süreci bağlantıyla değiştirip servisin yazımını servisin kendi dosyalarına
yönlendirebilirdi (BY-003-R6). Bakım modu dosyası ortak kalır; onu yazan kod
bağlantı izlememelidir (BY-028).

**İstek göndereni dosyanın sahibiyle doğrulanır (BY-025-R1).** Servis tanımı
web ve yayın kullanıcısının uid'lerini taşır (`YEDEKLEME_WEB_UID`,
`YEDEKLEME_YAYIN_UID`; betiğin `EMOR_WEB_KULLANICI` / `EMOR_YAYIN_KULLANICI`
adlarından, sudoers'taki gibi tek kaynak). İkisi aynı uid ise betik durur.
Kullanıcı yeniden açılıp uid'si değişirse denetim "içeriği farklı" der,
kurulum tanımı günceller. Planlı yedek ve günlük temizlik de bu serviste yürür
(BY-009-R1); servis etkinleşmeden modül servis kipine alınmaz.

**Bağlantı denetimi temiz ortamda.** Servisin parola dosyasıyla bağlanıp
bağlanamadığı `env -i` ile sınanır: kabuktan devralınan `PGPASSWORD` dosyanın
önüne geçer ve geçersiz dosyayı geçerli gösterebilirdi (BY-001-R6).

**Parola role SCRAM doğrulayıcısı olarak yazılır.** `alter role … password
'<düz parola>'` metni sunucu günlüğüne düşebilir; betik parolanın SCRAM-SHA-256
özetini gönderir (Fable incelemesi F3).

**Yetkiler tam kümeyle karşılaştırılır.** Gerekenin bulunması yetmez, fazlası
da sorundur. İzin bitleri doğru görünse de ek bir ACL girdisi (ör. `yedekler/`
üzerinde `u:admin:r-x`) yasak erişimi açar. Bu yüzden:

- Tablodaki her yolun ACL'si (varsayılan ACL dahil) tablonun tam karşılığıyla
  karşılaştırılır; fark "fazla" / "eksik" girdi olarak raporlanır, kurulum
  ACL'yi tam kümeyle değiştirir (`setfacl --set`).
- Rol özelliklerinin hepsi (giriş, veritabanı açma, yönetici, rol açma,
  çoğaltma, RLS atlama) denetlenir; rollerin beklenmeyen üyelikleri bildirilir
  (betik vermediği üyeliği geri almaz, elle bakılır).
- Servis tanımı `root:root 644`, sudo kuralı `root:root 440` olmalı; içerik
  aynı olsa da sahip / izin farklıysa dosya yeniden yazılır. İkisi de geçici
  adla yazılıp tek adımda yerine taşınır.

**Bağlantı güvenliği (BY-003-R5).** Kurulum root olarak çalışır; servis hesabı
ise kendi klasörlerindeki bir dosyayı başka bir yere giden sembolik bağlantıyla
değiştirebilir. Root o yolu izleyip sahibini / iznini değiştirseydi, servis
hesabı yönetilen alanın dışındaki bir root dosyasına erişim kazanırdı. Önlemler:

- Kök klasörün sahibi root: servis hesabı `yedekler/`, `gizli/` gibi alt
  klasörleri silemez, yerine bağlantı koyamaz. Kurulum kökü alt klasörlerden
  önce düzeltir.
- Kökün üst klasörleri, systemd ve sudoers klasörleri root'un olmalı ve
  başkası yazamamalı (yapışkan bitli `/tmp` hariç); değilse betik başta durur.
  `fs.protected_hardlinks=1` istenir (root dosyasına sabit bağlantıyla ikinci
  ad verilmesini çekirdek önler; sunucuda açık, 2026-11-18).
- Yönetilen her yol dokunulmadan önce denetlenir: sembolik bağlantı, beklenmeyen
  tür ya da birden çok adı olan dosya görülürse kurulum **o yola dokunmadan
  durur**; denetim eksik yazar.
- Servis hesabına ait nesnelerde root yalnız sahibi değiştirir (`chown -h`,
  bağlantıyı izlemez). İzni, ACL'yi ve dosya içeriğini (kilit dosyası, parola
  dosyası) nesnenin sahibi olan servis hesabı verir / yazar. Denetimle
  değişiklik arasında yol bağlantıya çevrilse bile servis hesabı kendi
  yetkisinin dışına çıkamaz.

## 3. Sunucuda çalıştırma (BY-025, kullanıcı talimatıyla)

Gerekenler: `acl` (setfacl/getfacl), `visudo`, `openssl`, `runuser`, `psql`,
`php`, uygulamanın `backend/vendor`'ı. Sunucuda hepsi var (2026-11-18).

```bash
cd /var/www/emor
sudo scripts/sunucu-yedekleme-kurulumu.sh --denetle   # önce: ne eksik
sudo scripts/sunucu-yedekleme-kurulumu.sh             # kur
sudo scripts/sunucu-yedekleme-kurulumu.sh             # tekrar: "0 değişiklik" beklenir
sudo scripts/sunucu-yedekleme-kurulumu.sh --denetle   # "her şey yerinde" beklenir
```

Betiğin **yapmadıkları** (sonunda hatırlatır):

- Grup üyelikleri süreç yeniden başlayınca etkinleşir:
  `sudo systemctl restart php8.5-fpm emor-queue` — kısa kesinti; kullanıcıya
  haber verilmeden yapılmaz.
- Servisi etkinleştirmek: `sudo systemctl enable --now emor-yedek` — servis
  kipine geçişin bir adımıdır, sırası isletim.md §2'de.

**Servis kipine geçtikten sonra bir kez** (BY-013): `deploy.sh`'ın modül
kapalıyken `/var/backups/emor`'a aldığı imzasız yedekler içeri alınır. Komut
yedek klasörüne ve imza anahtarına yazdığı için servis hesabıyla ve servis
tanımının ortamıyla (kendi önbellek / günlük yolları, parola dosyası — README
§5.5, D7) çalışır; iki kez çalıştırmak yedeği çoğaltmaz, kaynak dosyalara
dokunmaz. Klasör `admin`'in olduğu için servis hesabına önce okuma izni verilir
(okuyamadığı klasörde komut çıkış 1 ile durur — YF-67):

```bash
sudo setfacl -m u:emor-yedek:r-x /var/backups/emor   # dosyalar başkalarına okunur değilse: -R … r-X
servis php artisan yedek:eski-yedekleri-al --klasor=/var/backups/emor   # servis(): isletim.md §1
sudo setfacl -x u:emor-yedek /var/backups/emor        # iş bitince izin geri alınır
```

Servis hesabıyla elle çalıştırılan her yedekleme komutu (`yedek:tara`,
`yedek:acil` …) aynı kalıpla (`servis` — isletim.md §1) çalıştırılır. Kalıp
servis tanımını okuyamazsa (boş ortam) komutu çalıştırmadan durur: ortam
olmadan komut uygulamanın ortak önbelleğini ve günlüklerini kullanırdı.

Parola hiçbir komut satırına, ortam değişkenine ya da çıktıya yazılmaz; yeni
parola gerekirse psql'e standart girdiden verilir, parola dosyası rol
güncellendikten sonra geçici addan taşınarak yazılır.

**Parola dosyasının taşınması** (`/home/admin/.emor_yedek_pgpass` →
`gizli/pgpass`): kopya geçici ada yazılıp tek adımda yerine konur. Eski kopya
ancak yeni yerdeki aynı içerikli dosyayla servis hesabı gerçekten
bağlanabildiyse silinir. İki dosya farklıysa ya da bağlantı kurulamazsa hiçbiri
silinmez; betik "elle bakılacak madde" ile çıkar. O durumda hangi dosyayla
bağlanılabildiğine bakılıp yanlış olan kaldırılır, betik yeniden çalıştırılır.

## 4. Lokal deneme

Bütün ad ve yollar ortam değişkeniyle değiştirilebilir; lokal denemede gerçek
adlara dokunulmaz:

```bash
sudo EMOR_UYGULAMA_KOKU=$PWD EMOR_YEDEK_KOKU=/tmp/ey/kok \
  EMOR_YEDEK_KULLANICI=emor-yedek-deneme EMOR_YAYIN_KULLANICI=$USER \
  EMOR_VT_YEDEK_ROLU=emor_yedek_deneme EMOR_VT_INCELEME_ROLU=emor_inceleme_deneme \
  EMOR_ESKI_PGPASS=/tmp/ey/eski_pgpass EMOR_SYSTEMD_DIZINI=/tmp/ey/systemd \
  EMOR_SUDOERS_DOSYASI=/tmp/ey/sudoers-emor-yedek \
  scripts/sunucu-yedekleme-kurulumu.sh --denetle
```

`EMOR_SYSTEMD_DIZINI` gerçek dizin değilse `systemctl` çağrılmaz.

## 5. Geri alma (kurulumu kaldırma)

Sırası önemlidir (YF-25): önce açık işlem olmadığı görülür, modül kapanır,
sonra servis, yetkiler, dosyalar, en son hesap. **Yedekler, imza anahtarı ve
denetim günlüğü birlikte taşınır**: anahtar sunucudaki tek kopyadır; o
gidince taşınan bütün yedekler kalıcı olarak "güvenilmeyen" olur, ekrandan
geri yüklenemez. Hiçbir adımda klasör toptan silinmez (`rm -rf` yok); yalnız
boşaldığı görülen klasörler kaldırılır.

```bash
cd /var/www/emor/backend

# 0. Açık işlem yok (servis kipinde; servis(): isletim.md §1)
servis php artisan yedek:acil durum          # "Üstüne geri yükleme işlemi yok." olmalı
sudo ls -A /var/lib/emor-yedek/istekler/bekleyen /var/lib/emor-yedek/istekler/isleniyor   # boş olmalı

# 1. Modülü kapat — .env servis yollarında kalıp o klasörler kaldırılırsa durum
#    "bilinmiyor" sayılır: kuyruk ve zamanlayıcı HİÇBİR iş çalıştırmaz (e-Fatura,
#    alarm mailleri dahil), deploy.sh yayın korumasında durur.
#    .env: YEDEKLEME_AKTIF=false (ya da YEDEKLEME_* servis satırlarını silip geçiş yollarına dön)
#    umask 022: önbellek dosyası web'e yazılabilir açılmasın (isletim.md §3)
( umask 022 && php artisan config:cache ) && php artisan queue:restart

# 2. Servis (o anki iş biter: KillMode=mixed, en çok TimeoutStopSec)
sudo systemctl disable --now emor-yedek
sudo rm /etc/systemd/system/emor-yedek.service && sudo systemctl daemon-reload

# 3. sudo kuralı (ardından sudo yapılandırmasını doğrula)
sudo rm /etc/sudoers.d/emor-yedek && sudo visudo -c

# 4. Veritabanı rolleri
#    Önce emor_inceleme'ye geri yüklenen kopyalarda verilmiş yetkiler kaldırılır
#    (ya da o kopyalar silinir): her biri için  DROP OWNED BY emor_inceleme;
sudo -u postgres psql -c "revoke connect on database postgres from emor_yedek" \
  -c "revoke erp from emor_yedek" -c "drop role emor_yedek" -c "drop role emor_inceleme"

# 5. Dosyalar — servis hesabı bağlantı koymuş olabilir: root bağlantı izlemesin (D5)
sudo find /var/lib/emor-yedek -type l            # çıktı BOŞ olmalı; değilse dur, elle incele
H=/var/backups/emor-yedek-eski
sudo install -d -o root -g root -m 0700 "$H" "$H/gizli"
sudo mv /var/lib/emor-yedek/yedekler /var/lib/emor-yedek/gunluk /var/lib/emor-yedek/durum "$H/"
sudo mv /var/lib/emor-yedek/gizli/imza.anahtar "$H/gizli/"   # yedeklerle birlikte saklanır
sudo chown -hR root:root "$H" && sudo chmod 0600 "$H/gizli/imza.anahtar"
#    Kalanlar: geçersiz parola dosyası (rol silindi), servisin önbelleği, istek klasörleri
sudo find /var/lib/emor-yedek -mindepth 1 -ls    # yalnız gizli/pgpass, onbellek/…, istekler/… beklenir
sudo rm -f /var/lib/emor-yedek/gizli/pgpass
sudo find /var/lib/emor-yedek/onbellek /var/lib/emor-yedek/istekler -type f -delete
sudo find /var/lib/emor-yedek -mindepth 1 -depth -type d -empty -delete
sudo rmdir /var/lib/emor-yedek                   # boş değilse hata verir: kalanı elle incele

# 6. Hesap ve grup
sudo gpasswd -d www-data emor-yedek
sudo userdel emor-yedek          # emor-yedek www-data grubundan da çıkar
sudo groupdel emor-yedek 2>/dev/null || true   # userdel silmediyse
sudo systemctl restart php8.5-fpm emor-queue   # üyelik değişikliği (kısa kesinti)
```

Taşınan yedekler geçiş kipinde yeniden kullanılacaksa yedek dosyaları
`backend/storage/app/yedekler/`'e, imza anahtarı `backend/storage/app/yedek-imza.key`'e
(sahibi `www-data`, 0600) konur, sonra `php artisan yedek:tara` (web kullanıcısıyla)
"N imzalı, 0 güvenilmeyen" demelidir. Anahtarın ayrı saklanan kopyası
(kurtarma.md §2) da güncel kalmalıdır.

Kaldırmanın sonucu `--denetle` ile görülür: bütün maddeler `[EKSİK]` olmalı.
