# Yedekleme — işletim dokümanı (BY-031)

Günlük işletim, geri yükleme adımları, acil durum yolu ve sık hatalar.
Tasarım: [README.md](README.md). Sunucu kurulumu: [sunucu-kurulumu.md](sunucu-kurulumu.md).
Sunucu tamamen kaybolursa: [kurtarma.md](kurtarma.md).

> Bu dokümandaki komutların hiçbiri canlıda kullanıcı talimatı olmadan
> çalıştırılmaz. Değer (parola, anahtar) bu dosyaya yazılmaz.

## 1. Kipler ve ayarlar

Modül iki kipte çalışır (`.env`, `YEDEKLEME_CALISTIRICI`):

| Kip | Yedeği kim alır | İstekleri kim işler | Ne zaman |
|---|---|---|---|
| `dogrudan` (varsayılan, geçiş) | uygulama kullanıcısı (www-data) | zamanlayıcı dakikada bir `yedek:calistir --tek-tur` | servis hesabı kurulana kadar |
| `servis` | servis hesabı (`emor-yedek`) | `emor-yedek.service` (`yedek:calistir` döngüsü) | §2'deki geçişten sonra |

Üstüne geri yükleme yalnız `servis` kipinde açılır (README §5.2).

`.env` anahtarları (servis kipinde web ve servis AYNI `.env`'i okur; yollar
kurulumun klasörleri olur):

| Anahtar | Servis kipinde değer | Not |
|---|---|---|
| `YEDEKLEME_AKTIF` | `true` | Kapalıyken uçlar 409, menü yok, zamanlayıcı ve çalıştırıcı boşta |
| `YEDEKLEME_CALISTIRICI` | `servis` | |
| `YEDEKLEME_KLASORU` | `/var/lib/emor-yedek/yedekler` | |
| `YEDEKLEME_DURUM_KLASORU` | `/var/lib/emor-yedek/durum` | kilit + `islem.json` + bildirim yineleme |
| `YEDEKLEME_ISTEK_KLASORU` | `/var/lib/emor-yedek/istekler` | |
| `YEDEKLEME_AYAR_KOPYASI` | `/var/lib/emor-yedek/gunluk/ayarlar.json` | |
| `YEDEKLEME_IMZA_ANAHTARI` | `/var/lib/emor-yedek/gizli/imza.anahtar` | yalnız servis okur |
| `YEDEKLEME_PAROLA_DOSYASI` | `/var/lib/emor-yedek/gizli/pgpass` | |
| `YEDEKLEME_KALICI_KOK` | `/var/lib/emor-yedek` | klasör eşitlemesi burada durur |
| `YEDEKLEME_ROL` | `emor_yedek` | geri yükleme rolü (uygulama rolünün üyesi) |
| `YEDEKLEME_SINAMA` | `true` | her yedekten sonra sınama geri yüklemesi |
| `YEDEKLEME_KOPYA_BEKLEME_GUN` | `7` | farklı adla geri yüklenen kopyanın ömrü |
| `YEDEKLEME_GOOGLE_ISTEMCI_KIMLIGI` / `_SIRRI` | Google Cloud'dan | yalnız Drive hedefi kullanılacaksa |

Kuyruk servisi adı ve `systemctl` yolu servis tanımından gelir
(`YEDEKLEME_KUYRUK_SERVISI`, `YEDEKLEME_SYSTEMCTL`); sudoers kuralıyla aynıdır.
İstek göndereninin doğrulandığı web ve yayın kullanıcısının uid'leri de
servis tanımındadır (`YEDEKLEME_WEB_UID`, `YEDEKLEME_YAYIN_UID`; kurulum betiği
yazar): çalıştırıcı kullanıcılı isteği yalnız web kullanıcısının, kullanıcısız
yayın öncesi yedeği yalnız yayın kullanıcısının dosyasıysa işler
(`.env`'e yazılmaz; geçiş kipinde web = çalıştırıcının kendi kullanıcısı).

**Servis hesabıyla elle komut çalıştırma** (servis tanımının ortamıyla —
kendi önbellek ve günlük yolları; README §5.5, D7):

```bash
# admin'in kabuğunda bir kez tanımlanır. Servis tanımı okunamazsa (kurulum
# betiği çalışmamış, ad yanlış) DURUR: boş ortamla komut uygulamanın ortak
# önbelleğini ve günlüklerini kullanırdı (YF-71).
servis() {
  local ortam
  ortam="$(systemctl show emor-yedek -p Environment --value)"
  if [ -z "$ortam" ]; then echo "HATA: emor-yedek servis tanımı okunamadı; komut çalıştırılmadı." >&2; return 1; fi
  sudo -u emor-yedek env $ortam "$@"
}
cd /var/www/emor/backend
servis php artisan <komut>
```

Aşağıda "servis olarak" denen her komut bu kalıpla (`servis php artisan …`)
çalıştırılır. Tanım etkinleştirilmemiş olsa da okunur.

## 2. Servis kipine geçiş (bir kez, kullanıcı talimatıyla)

Hangi yol? `backend/storage/app/yedek-imza.key` VARSA modül geçiş kipinde
çalışmıştır (canlı böyle): **§2B**. Yoksa ve hiç yedek yoksa: §2A. Var olan
bir imza anahtarının yanında `yedek:imza-anahtari` ÇALIŞTIRILMAZ: yeni anahtar
eski manifestlerin ve olay günlüğünün imzasını tutmaz, bütün yedekler
"güvenilmeyen" olur (YF-26).

Komutlar admin olarak, `/var/www/emor/backend`'de; "servis olarak" = §1'deki
`servis` kalıbı.

### 2A. Boş kurulum (imza anahtarı ve yedek yok)

1. Kod yayında; `migrate` çalıştı (deploy.sh yapar).
2. Kurulum betiği: `--denetle`, kur, yine kur ("0 değişiklik"), `--denetle`
   (sunucu-kurulumu.md §3). Grup üyelikleri için
   `sudo systemctl restart php8.5-fpm emor-queue` (kısa kesinti; haber verilerek).
3. `.env`: §1'deki servis anahtarları (`YEDEKLEME_CALISTIRICI=servis`, yollar,
   `YEDEKLEME_ROL`, `YEDEKLEME_SINAMA`) ve `YEDEKLEME_AKTIF=true`;
   `php artisan optimize`.
4. İmza anahtarı — `.env`'den SONRA (önce çalışırsa anahtar web'in yazabildiği
   `storage/app/yedek-imza.key`'e üretilir): servis olarak
   `php artisan yedek:imza-anahtari`. `sudo ../scripts/sunucu-yedekleme-kurulumu.sh --denetle`
   artık "imza anahtarı henüz yok" demez, anahtarı 0600 `emor-yedek` görür.
   Anahtarın ayrı saklanan kopyası alınır (kurtarma.md §2).
5. `sudo systemctl enable --now emor-yedek`; `systemctl status emor-yedek`.
6. Ortak son adımlar (aşağıda).

### 2B. Geçiş kipinden servis kipine (kipte çalışmış kurulum)

Geçiş kipinde her şey `backend/storage/app` altındadır: `yedekler/` (döküm,
manifest, `olaylar.jsonl`), `yedek-imza.key`, `yedek-durum/` (`islem.json`,
kurtarma paketi sürüm kaydı, bildirimler, `ayarlar.json`, kilitler),
`yedek-istekler/`; denetim günlüğü `storage/logs/yedekleme-*.log`. Bunlar yeni
yere **servis hesabı** tarafından kopyalanır (root servisin klasörüne yazmaz —
D5); eskiler ancak yeni yer doğrulandıktan sonra silinir (D4).

```bash
S=/var/www/emor/backend/storage/app
Y=/var/lib/emor-yedek

# 1. Süren iş yok, sonra modülü kapat
sudo ls -A $S/yedek-istekler/bekleyen $S/yedek-istekler/isleniyor   # boş olana kadar bekle
#    .env: YEDEKLEME_AKTIF=false
php artisan config:cache && php artisan queue:restart

# 2. Kurulum betiği (denetle / kur / kur / denetle — sunucu-kurulumu.md §3);
#    denetimde yalnız "[BİLGİ] imza anahtarı henüz yok" kalmalı. Sonra:
sudo systemctl restart php8.5-fpm emor-queue     # grup üyelikleri (kısa kesinti)

# 3. İmza anahtarı: dosyanın sahibi okur, servis hesabı standart girdiden yazar
#    (set -C: var olanın üstüne yazmaz). Bağlantıysa DUR.
A=$S/yedek-imza.key
[ -f "$A" ] && [ ! -L "$A" ] && echo "düz dosya: tamam"
sudo -u "$(stat -c %U "$A")" cat "$A" | sudo -u emor-yedek sh -c 'umask 077; set -C; cat > /var/lib/emor-yedek/gizli/imza.anahtar'
sudo -u "$(stat -c %U "$A")" sha256sum "$A"; sudo -u emor-yedek sha256sum $Y/gizli/imza.anahtar   # özetler AYNI olmalı

# 4. Yedekler ve durum dosyaları (servis hesabı web grubundan okur).
#    Depo işareti ve çalışma klasörü kopyalanmaz (kurulum yeni yerde kendi işaretini açtı);
#    kilit dosyaları kopyalanmaz (kurulum açtı).
sudo -u emor-yedek find $S/yedekler -mindepth 1 -maxdepth 1 -type f ! -name .emor-yedek-deposu \
  -exec cp --preserve=mode,timestamps -t $Y/yedekler/ {} +
sudo find $S/yedekler -mindepth 1 -maxdepth 1 -type f ! -name .emor-yedek-deposu | wc -l
sudo -u emor-yedek find $Y/yedekler -mindepth 1 -maxdepth 1 -type f ! -name .emor-yedek-deposu | wc -l   # sayılar AYNI
sudo -u emor-yedek find $S/yedek-durum -maxdepth 1 -type f ! -name kilit ! -name yayin.kilit \
  ! -name calistirici ! -name ayarlar.json -exec cp --preserve=timestamps -t $Y/durum/ {} +
sudo -u emor-yedek cp --preserve=timestamps $S/yedek-durum/ayarlar.json $Y/gunluk/ayarlar.json   # varsa
#    Denetim günlüğü (önerilir): eski günlükler servisin günlük klasörüne
sudo -u emor-yedek install -d -m 2750 $Y/gunluk/gecis-kipi
sudo -u emor-yedek sh -c 'cp --preserve=timestamps "$@" /var/lib/emor-yedek/gunluk/gecis-kipi/' _ /var/www/emor/backend/storage/logs/yedekleme-*.log
```

`cp` bir dosyayı okuyamazsa (grubu web grubu değil) durun: o dosyanın grubunu
`www-data` yapıp (`sudo chgrp www-data <dosya>`) adımı yineleyin. Bekleyen
istekler taşınmaz (24 saatten eskisi zaten işlenmez); kullanıcı yeniden ister.

5. `.env`: §1'deki servis anahtarları (yollar, `YEDEKLEME_CALISTIRICI=servis`,
   `YEDEKLEME_ROL`, `YEDEKLEME_SINAMA`); `YEDEKLEME_AKTIF` hâlâ `false`.
   `php artisan optimize`.
6. Doğrulama — servis olarak:
   `php artisan yedek:tara` → "Tarama: N imzalı, 0 güvenilmeyen …, 0 dosyası
   yok" (N = kopyalanan yedek sayısı). `0 güvenilmeyen` değilse ya da dosyası
   olmayan varsa DURUN: anahtar ya da kopya eksiktir; eski kopyalara
   dokunmayın, `.env`'i geri alın. `php artisan yedek:acil durum` → "Üstüne
   geri yükleme işlemi yok."
7. Modülü aç, servisi aç: `.env`: `YEDEKLEME_AKTIF=true`;
   `php artisan config:cache && php artisan queue:restart`;
   `sudo systemctl enable --now emor-yedek` (servis `.env`'i açılışta okur).
8. Ancak 6. adım doğrulandıktan sonra eski kopyalar silinir (önce anahtarın
   ayrı saklanan kopyasının güncel olduğu görülür — kurtarma.md §2):
   ```bash
   sudo rm -f $S/yedek-imza.key
   sudo rm -rf $S/yedekler $S/yedek-durum $S/yedek-istekler
   ```
9. Ortak son adımlar (aşağıda).

### Ortak son adımlar

1. `sudo ../scripts/sunucu-yedekleme-kurulumu.sh --denetle` → "her şey yerinde".
2. Eski yayın yedeklerini içeri al (henüz alınmadıysa; okuma izniyle —
   sunucu-kurulumu.md §3): servis olarak
   `php artisan yedek:eski-yedekleri-al --klasor=/var/backups/emor`.
3. Ekrandan: Yedekleme Ayarları (alıcılar, sinyal adresleri, açık anahtar —
   kurtarma.md §6), Yedek Hedefleri (+ "Bağlantıyı Sına"), Yedek Planları
   (önizleyerek).
4. "Şimdi Yedek Al" → listede "tamam", doğrulama "sınama geçti" görülür.
5. Bir yedek "Farklı adla geri yükle" ile açılır, `emor_inceleme` rolüyle
   bakılır, ekrandan silinir (§4).

## 3. Günlük işletim

- **Nereden izlenir:** Yedekler ekranının özet şeridi (son yerel / uzak /
  sınama zamanları ayrı; sessizlik süresi), hata mailleri (doğrudan, kuyruksuz,
  aynı hata saatte bir), dış izleyicideki üç yaşam sinyali.
- **Zamanlayıcı** (`routes/console.php`), geçiş kipinde: `yedek:zamanla`
  dakikada bir (bakım modunda da), `yedek:temizle` her gün 04:10 İstanbul,
  `yedek:calistir --tek-tur` dakikada bir. **Servis kipinde** bu üçü
  zamanlayıcıda çalışmaz; `emor-yedek.service` döngüsü yürütür: istekler her
  turda, planlı yedek dakikada bir, günlük temizlik 04:10 İstanbul'dan sonra
  günde bir kez (son deneme anı `durum/temizlik-son-calisma`; servis o saatte
  kapalıysa açılınca bir kez yapar). Açık üstüne geri yüklemede temizlik yapılmaz.
- **Komutlar:**

  | Komut | Ne yapar |
  |---|---|
  | `yedek:al [--kaynak=elle] [--aciklama=]` | yedek alır (kilitle; meşgulse çıkış 75) |
  | `yedek:temizle [--onizleme]` | saklama kuralları; önizleme silmez |
  | `yedek:tara` | dizini manifestlerden yeniden kurar |
  | `yedek:yayin-oncesi` | deploy.sh'ın yedeği (başarısızsa yayın durur) |
  | `yedek:yayin-korumasi kilit-yolu\|denetle` | deploy.sh'ın yayın kilidi yolu ve açık geri yükleme denetimi |
  | `yedek:eski-yedekleri-al` | eski imzasız yedekleri bir kez içeri alır |
  | `yedek:imza-anahtari` | imza anahtarını üretir (varsa dokunmaz) |
  | `yedek:calistir [--tek-tur]` | istek klasörünü işler |
  | `yedek:sifre-coz <dosya> <cikis> --anahtar=` | sunucu dışı kopyayı çözer (kurtarma) |
  | `yedek:manifest-dogrula <dump> [--manifest=] [--yetkileri-uygula=<vt> \| --yetkileri-denetle=<vt>]` | dump'ı imzalı manifestine karşı doğrular (imza, boyut, SHA-256); elle geri yüklemeden önce (kurtarma.md §4.8). Doğrulanınca manifestteki bağlanma yetkilerini veritabanına uygular ya da (değiştirmeden) eşdeğerliğini denetler (kurtarma.md §4.9, §4.11) |
  | `yedek:acil durum\|onayla\|geri-don` | üstüne geri yüklemenin acil durum yolu (§6) |

- **Yayın:** modül açıksa `deploy.sh` önce **yayın kilidini**
  (`durum/yayin.kilit`) alır ve betik bitene kadar tutar; kilit altında açık
  üstüne geri yükleme varsa (ya da durum okunamazsa) yedekli de `--no-backup`
  da yayın bakım modu, kod çekme, migration ve `artisan up` olmadan DURUR.
  Kilit 60 sn'de boşalmazsa (başka yayın ya da başlayan geri yükleme; süre
  `EMOR_YAYIN_KILIT_BEKLEME`) yine durur. Sonra yedeği `yedek:yayin-oncesi`
  ile alır. Uygulama zaten bakım modundaysa yayın `down` / `up` çağırmaz
  (yalnız kendi açtığını kapatır). Yayın sürerken üstüne geri yükleme
  başlamaz (`GERI_YUKLEME_YAYIN_SURUYOR`). Yedeksiz yayın yalnız bilerek:
  `./deploy.sh --no-backup`. Uygulamanın komutu çalışmıyorsa (bozuk
  bağımlılık) açık geri yükleme bilinemez, yayın durur: önce durum elle
  denetlenir (`yedek:acil durum`), kod elle çekilir.
  Servis kipinde yayın öncesi yedek isteğini çalıştırıcı 90 sn içinde
  üstlenmezse (servis durmuş ya da başka bir iş sürüyor) istek geri çekilir,
  yayın beklemeden durur (YF-68); üstlenilen yedek için 30 dk beklenir.
- **Yayın ve çalıştırıcı (YF-24):** başarılı yayının sonunda `deploy.sh` proje
  kökünde `.yayin-damgasi` yazar (sürüm + zaman; `.gitignore`'da). Servis
  döngüsü her boş turun başında damgaya bakar, değiştiyse çıkar; systemd yeni
  kodla başlatır (iş sürüyorsa iş bitince). Damga yalnız başarılı yayında
  yazılır: kod çekildikten sonra yarıda kalan bir yayından sonra
  `sudo systemctl restart emor-yedek`.
- **Yayın ve uygulama önbelleği (YF-03):** `deploy.sh` ilk `php` çağrısından
  önce `backend/bootstrap/cache`'i web'e yazılamaz yapar ve içindeki bütün
  `*.php`'yi siler (web'in yazmış olabileceği dosya admin olarak yüklenmez);
  önbellekler yayının sonunda `optimize` ile yeniden üretilir. Klasörün sahibi
  yayın kullanıcısı (`admin`) olmalı, değilse yayın durur
  (`sudo chown admin backend/bootstrap/cache`, bir kez). Yayın boyunca admin'in
  artisan çağrıları derlenmiş görünümleri özel bir geçici klasörde tutar.
- **Yayından önce modülü kapat, sonra aç** (bugünkü uygulama; plan tanımlama,
  otomatik yedek ya da geri yükleme başlatılmaz): önce açık işlem yoktur
  (`yedek:acil durum`, servis kipinde servis olarak), sonra
  ```bash
  cd /var/www/emor/backend
  # .env: YEDEKLEME_AKTIF=false
  php artisan config:clear
  cd .. && ./deploy.sh         # modül kapalı: yayın kilidi alınmaz, yedek eski davranışla
                               # /var/backups/emor/<vt>_predeploy_<zaman>.dump (imzasız)
  cd backend
  # .env: YEDEKLEME_AKTIF=true
  php artisan config:cache && php artisan queue:restart
  sudo systemctl restart emor-yedek   # yalnız servis kipinde: servis .env'i açılışta okur
  ```
  Bu yolla alınan imzasız yedekler sonradan `yedek:eski-yedekleri-al` ile içeri
  alınabilir (sunucu-kurulumu.md §3).

## 4. Farklı adla geri yükleme (canlıya dokunmaz)

1. Yedekler → satırda "Farklı adla geri yükle" (izin `geri_yukleme.kopya`;
   yalnız imzalı yedekler). İsteğe bağlı kısa ek (`[a-z0-9]{1,16}`).
2. İş kuyruğa girer; ekran yoklar. Bitince Geri Yüklemeler → "Bekleyen
   kopyalar"da `erp_geri_<tarih>_<saat>[_ek]` görünür.
3. İnceleme yalnız salt okunur `emor_inceleme` rolüyle (veritabanı aracıyla);
   uygulama kopyaya bağlanmaz.
4. İş bitince ekrandan silinir; silinmezse `YEDEKLEME_KOPYA_BEKLEME_GUN` gün
   sonra kendiliğinden silinir (ekranda son gün uyarısı).
5. Yerel dosyası silinmiş, yalnız **şifreli** uzak kopyası olan yedek ekrandan
   açılamaz (`GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI`): kurtarma.md §4.6–4.9.

## 5. Üstüne geri yükleme (yıkıcı — yalnız sistem yöneticisi)

Önce mutlaka §4 ile aynı yedeğin kopyası açılıp bakılır.

1. Yedekler → satırda "Üstüne geri yükle". Pencerede yedeğin tarihi ve
   kaybolacak dönem yazar; canlı veritabanının adı (`erp`) aynen yazılınca
   düğme açılır. İçe alınmış eski yedekler üstüne yüklenemez (migration
   listesi yok).
2. Ekran işlem kimliğini ve **erişim anahtarını** tarayıcıda saklar
   (8 saat geçerli). Tarayıcı kapansa da aynı adresten devam edilir.
3. Adımlar (ekranda canlı izlenir; her biri durum dosyasına yazılır):
   ön denetim → bakım modu + kuyruk durur → geri yükleme öncesi yedek →
   yeni veritabanı (`erp_geri_*`) → pg_restore → migration (yalnız yeni
   veritabanında, ayrı süreç) → doğrulama → canlıya bağlantılar kapatılır →
   ad değiştirme (`erp` → `erp_onceki_*`, yeni → `erp`) → dizin → ayar
   uzlaştırma → **onay bekleniyor** (bakım modu sürer).
4. Onay ekranında: aktif ERP ortamı, entegratörler, alarm kuralları, ayar /
   kullanıcı / rol farkları, kaybolacak yükleme sayısı. Uygulamaya bakım
   modundayken yalnız ilerleme ekranından erişilir.
5. **Onayla:** bakım modu kalkar, kuyruk başlar, işlem kapanır.
   **Geri dön:** `erp` yine eski veritabanı olur; geri yüklenen kopya
   silinmez, `erp_geri_*` adıyla kapalı kalır.
6. Yer değiştirmeden ÖNCEKİ bir hatada canlıya dokunulmamıştır: yarım
   veritabanı silinir, bakım modu kalkar. SONRAKİ bir hatada bakım modu sürer;
   karar yöneticinindir (onayla / geri dön / §6).
7. Sonra elle (bugün otomatik değil): `erp_onceki_*` en az 7 gün bekletilir,
   sonra servis rolüyle `DROP DATABASE`; geri dönüldüyse kalan `erp_geri_*`
   aynı şekilde.

Açık bir üstüne geri yükleme varken: zamanlayıcı ve kuyruk işçisi iş
çalıştırmaz (e-Fatura senkronu, alarm mailleri dahil), planlı yedek ve yayın
başlamaz.

## 6. Acil durum yolu (ekran açılmıyorsa)

Web, veritabanı ya da erişim anahtarı yoksa / süresi dolduysa:

```bash
cd /var/www/emor/backend      # servis(): §1
servis php artisan yedek:acil durum
servis php artisan yedek:acil onayla
servis php artisan yedek:acil geri-don
```

- Yalnız durum dosyası ve PostgreSQL araçlarıyla çalışır.
- Önce yedekleme kilidini alır; çalıştırıcı tutuyorsa BEKLEMEDEN anlaşılır
  mesajla çıkar (çıkış 1) — birkaç saniye sonra tekrar denenir. Çalıştırıcıyı
  durdurmak gerekirse `sudo systemctl stop emor-yedek`: `KillMode=mixed` ile
  SIGTERM yalnız PHP sürecine gider, o anki iş (`pg_dump` / `pg_restore`
  dahil) bitince çıkar. Durdurma `TimeoutStopSec` (4000 sn) kadar sürebilir;
  iş bu süreyi aşarsa bütün süreçler yine öldürülür (YF-23). İş gerçekten
  ilerlemiyorsa son çare `sudo systemctl kill --signal=SIGKILL emor-yedek`:
  süren iş yarıda kalır (yedek `basarisiz`; üstüne geri yükleme açılışta
  sürdürülür / geri sarılır — §5).
- `onayla`: farkı olan her ayar grubu (mail, yedek ayarları, planlar,
  hedefler) için seçim ister: `--ayar=mail=guncel --ayar=planlar=geri_yuklenen`
  gibi (`guncel` = geri yüklemeden önceki dosya kopyası, `geri_yuklenen` =
  yedekteki hâli). Varsayılan seçim yoktur; `yedek:acil durum` hangi grupların
  seçim istediğini yazar (BY-028-R2).
- `--force` onay sorusunu atlar (yalnız betik içinde).
- Sunucu "onay bekleniyor"dayken yeniden başlarsa: kuyruk ve zamanlayıcı
  açılsa da iş çalıştırmaz; karar bu komutla ya da ekrandan verilir.

## 7. Sık hatalar

| Kod | Anlamı | Ne yapılır |
|---|---|---|
| `YEDEKLEME_KAPALI` | `YEDEKLEME_AKTIF` kapalı | bilerek mi? `.env` |
| `YEDEK_KILIT_MESGUL` | başka yedek / geri yükleme sürüyor | bitmesini bekle |
| `YEDEK_ACIK_GERI_YUKLEME` | üstüne geri yükleme onay bekliyor | §5.5 / §6 |
| `YEDEK_ISLEM_DURUMU_OKUNAMADI` | `durum/islem.json` okunamıyor / bozuk | izinler; bozuksa elle bakılır, silinmez |
| `ISTEK_YETKI_YOK` | isteyen kullanıcının izni işlem anında yok | rolü |
| `ISTEK_GONDEREN_DOGRULANAMADI` | istek dosyasının sahibi web / yayın kullanıcısı değil | servis tanımındaki `YEDEKLEME_WEB_UID` / `_YAYIN_UID` (kurulum betiği); değilse saldırı şüphesi — denetim kaydındaki `gonderen_uid` |
| `GERI_YUKLEME_YAYIN_SURUYOR` | üstüne geri yükleme istenirken yayın sürüyordu | yayın bitince yeniden iste |
| `ISTEK_SURESI_DOLDU` | istek 24 saatten eski | yeniden iste; çalıştırıcı çalışıyor mu? |
| `ISTEK_YARIDA_KALDI` | çalıştırıcı iş sırasında öldü | günlük; yeniden iste |
| "Çalıştırıcı isteği üstlenmedi" (deploy) | servis kipinde yayın öncesi yedek isteği 90 sn kuyrukta kaldı (servis durmuş ya da başka iş sürüyor); istek geri çekildi | `systemctl status emor-yedek`; iş sürüyorsa bitince yayını yinele |
| "isteği zamanında işlenmedi" (deploy) | servis kipinde çalıştırıcı üstlendiği yedeği 30 dk'da bitirmedi | `systemctl status emor-yedek`, `gunluk/yedekleme-*.log` |
| "bootstrap/cache'in sahibi …" (deploy) | uygulama önbellek klasörü yayın kullanıcısının değil (YF-03) | `sudo chown admin backend/bootstrap/cache`, yayını yinele |
| `HEDEF_KIMLIK_HATALI` / `HEDEF_SERTIFIKA_HATASI` | uzak hedef reddetti | yeniden denenmez (D8); hedefi düzelt, "Sına" |
| `HEDEF_SUNUCU_PARMAK_IZI_DEGISTI` | SFTP sunucu anahtarı değişti | sunucu gerçekten değiştiyse "parmak izini sıfırla" + "Sına"; değilse saldırı şüphesi |
| `HEDEF_YENIDEN_BAGLAN` | Google izni kaldırılmış | Hedefler → "Google ile bağlan" |
| `HEDEF_KOTA_DOLU` | uzak hedef dolu | yer aç; saklama kuralı |
| `HEDEF_BAGLI_DEGIL` | NAS paylaşımı bağlı değil | `mount`; yerel diske yazılmadı |
| `HEDEF_YAPILANDIRMA_HATASI` (şifreleme) | açık anahtar yok / kayıtlı parmak iziyle uyuşmuyor / süresi dolmuş | yeniden denenmez (D8); Ayarlar → açık anahtar; sunucu dışına şifresiz gitmez |
| `YEDEK_GPG_HATASI` (kopyada) | gpg süre sınırını aştı, çalıştırılamadı ya da geçici klasör / disk sorunu | geçici sayılır: kopya `kopya_deneme` kez denenir; sürerse disk / yük ve `YEDEKLEME_SIFRELEME_SURESI` |
| İşlem Günlüğü'nde `kurtarma_paketi` başarısız | kurtarma paketinin yeni sürümü yarıda kaldı; yazılan dosyaları silindi, önceki tamam sürüm yetkili kaldı; uzak yaşam sinyali gitmedi. Kopya "tamam" ama **kurtarmaya hazır değil** (ekranda "kurtarma paketi bekleniyor"): yerel arşiv silinmez | hata mesajındaki koda göre hedefi / günlük dosyasını düzelt; sonraki sunucu dışı kopyada yeniden gönderilir, başarılı paket bekleyen kopyaları da hazır yapar (kurtarma.md §1) |
| `YEDEK_DEPO_ISARETI_YOK` | yedek klasöründe depo işareti (`.emor-yedek-deposu`) yok ve katalogdaki yedeklerin hiçbirinin imzalı manifesti orada değil: yedek diski bağlı değil (boş bağlama noktası) ya da klasör yanlış | yedek alınmadı, tarama dizine dokunmadı. Diski bağla (`mount`, `findmnt <klasör>`); disk bağlı ve klasör doğruysa kurulum betiğini çalıştır (işareti açar; sunucu-kurulumu.md §2 "Depo işareti") |
| `HEDEF_SILME_YETKISI_YOK` | hedef silmeye izin vermiyor (S3 kilidi) | hata değil; hedefin kendi kuralı temizler |
| `GERI_YUKLEME_SEMA_UYUMSUZ` | yedekte olup kodda olmayan migration | doğru kod sürümünü yayınla |
| `GERI_YUKLEME_KARSILASTIRMA_TUTMADI` | satır sayısı / migration listesi tutmadı | yedek "doğrulama başarısız"; başka yedek |
| `GERI_YUKLEME_ADLAR_TUTARSIZ` | yer değiştirmede adlar beklenmedik | dokunulmaz; elle bakılır (`\l`) |
| `GERI_YUKLEME_ERISIM_ANAHTARI_SURESI_DOLDU` | 8 saat geçti | §6 |

Ayrıntı: Ayarlar → İşlem Günlüğü (veritabanı) ve `gunluk/yedekleme-*.log`
(dosya; veritabanı geri yüklense de kalır).

## 8. Arıza senaryoları (README §10) — sonuç tablosu

"Veri korundu" = mevcut veri yerinde; "açılmadı" = uygulama yanlışlıkla
bakım modundan çıkmadı / iş çalıştırmadı.

| Senaryo | Nasıl sınandı | Sonuç | Veri korundu | Yanlışlıkla açılmadı |
|---|---|---|---|---|
| Disk dolması / eşik | `YedekAliciTest::test_bos_disk_esigin_altindaysa_yedek_baslamaz`, `UstuneGeriYukleyiciTest` (DISK_YETERSIZ ön denetimi) | yedek başlamaz, bildirim | evet | evet |
| Yarım kalan uzak kopya | `FtpBaglayicisiTest` (kopma, asılma), `NasBaglayicisiTest`, `YedekKopyalayiciTest` | kopya `basarisiz`, son adda dosya yok, yeniden denemede tamam | evet | — |
| Sürecin öldürülmesi (yedek) | `YedekAliciTest` (yarım dosya kalmaz), `YedekCalistiriciTest` (`ISTEK_YARIDA_KALDI`) | yarım dosya / kayıt yok | evet | — |
| Sürecin öldürülmesi (üstüne geri yükleme) | lokal PG, her adımda `kill -9` + sürdürme (BY-028 günlüğü) | 6. adımdan önce geri sarıldı; sonra onaya geldi | evet | evet |
| Bozuk / eksik / imzası tutmayan manifest | `YedekManifestiTest`, `DizinKurucuTest`, `GeriYukleyiciTest` | "güvenilmeyen", geri yüklenemez; tarama durmaz | evet | — |
| Yanlış şifreleme anahtarı | `KopyaSifreleyiciTest` (gerçek gpg) | anlaşılır kod, yarım dosya yok | evet | — |
| gpg süre aşımı (geçici) | `KopyaSifreleyiciTest::test_gpg_sure_asiminda_kopya_yeniden_denenir_yalniz_sifreli_dosya_gider` | ikinci denemede tamam; hedefe yalnız şifreli dosya | evet | — |
| Kurtarma paketinin ara dosyası yazılamaz | `KurtarmaPaketiTest::test_ara_bilesen_yazilamazsa_onceki_paket_yetkili_kalir_yasam_sinyali_gitmez` | yeni sürüm silindi; önceki sürüm ve işaretçisi aynen, ondan çözülüyor; uzak sinyal gitmedi | evet | — |
| Bozuk manifest / yanlış imza anahtarıyla elle geri yükleme | `YedekManifestDogrulaTest` | `yedek:manifest-dogrula` başarısız, geri yükleme başlamaz | evet | — |
| Kurtarma paketi gönderilemeden yerel arşivin temizlenmesi | `KurtarmaPaketiTest::test_paketi_eksik_sifreli_kopya_yerel_arsivi_tutar_paket_dogrulaninca_temizlik_siler_set_imzayi_dogrular` | yerel dump kalır; sonraki paketle kopya hazır, temizlik siler; uzaktaki setle imza SQL'den önce doğrulanır | evet | — |
| Eski paket sürümünün işaretçisi silinemez | `KurtarmaPaketiTest::test_isaretcisi_silinemeyen_eski_surumun_hicbir_bileseni_silinmez` | sürümün hiçbir bileşeni silinmez; "tamam" görünen set eksiksiz | evet | — |
| Yedek diski ayrılmış (boş, okunabilir bağlama noktası) | `DizinKurucuTest` (erişilemeyen klasör senaryoları), `YedekAliciTest::test_depo_dogrulanamazsa_yedek_alinmaz_klasore_yazilmaz` | tarama dizine dokunmaz; yedek alınmaz, bağlama noktasına yazılmaz (`YEDEK_DEPO_ISARETI_YOK`) | evet | — |
| Elle kurtarmada bağlanma yetkileri (daraltılmış CONNECT) | `YedekManifestDogrulaPostgresTest` (gerçek PG), lokal prova (kurtarma.md §8) | manifestteki ACL eşdeğer uygulanır; açılıştan önce denetim | evet | — |
| Migration hatası | `UstuneGeriYukleyiciTest` | yeni veritabanı silindi, canlı aynen | evet | evet |
| Ad değiştirme sırasında kesinti | lokal PG (öncesi / sonrası öldürme), `test_yer_degistirmede_olen_islem_adlara_gore_karar_verir` | adlara göre geri sar / devam | evet | evet |
| Veritabanına erişimin kesilmesi | `UstuneGeriYuklemeApiTest` (ilerleme ucu DB yokken), `test_acil_durum_komutu_veritabani_erisilemezken_onaylar` | kilit dosyada; yetkili okur, anahtarsız 401/403 | evet | evet |
| Zamanlayıcı / kuyruk durdurulur, dış izleyici uyarır | `YedekBildirimTest` (sinyal); izleyicinin uyarısı | sinyal gelmezse izleyici uyarır | — | — |
| "Onay bekleniyor"da sunucu yeniden başlatma | `UstuneGeriYuklemeOnayTest` (kuyruk / zamanlayıcı koruması), lokal PG | iş çalışmıyor, komut satırından onay / geri dön | evet | evet |
| Geri yükleme sonrası rol / sıralama / saat dilimi | lokal PG (BY-026, BY-024) | rol okuyup yazıyor; `C` / `America/New_York` taşındı | — | — |
| Sunucu dışı şifreli kopyadan baştan sona | lokal prova (BY-024: NAS yerine `/dev/shm`) | çözüldü, özetler tuttu, geri yüklendi | — | — |

**Canlıda henüz denenmedi** (kullanıcı talimatıyla, BY-031'in kalan
ölçütleri): servis kurulumu sonrası gerçek sunucuda (PostgreSQL 18.6,
`en_US.UTF-8`) kopya provası; gerçek uzak hedeften şifreli geri yükleme;
"onay bekleniyor"da gerçek sunucu yeniden başlatma; ilk tatbikat.

## 9. Tatbikat (öneri 3 ayda bir)

1. Sunucu dışındaki **şifreli** kopyadan bir yedek seçilir (yerel dosyası
   değil): kurtarma.md §4.6–4.8 ile indirilir, özel anahtarla çözülür, imza
   ve özet `yedek:manifest-dogrula` ile doğrulanır (çalıştıran kimlikler
   oradaki gibi).
2. Ayrı bir veritabanına geri yüklenir (kurtarma.md §4.9, ad
   `erp_geri_tatbikat_*`); satır sayıları ve uygulama rolü denetlenir.
3. Sonuç ve süre bu dosyanın sonuna tarihli satır olarak yazılır; veritabanı silinir.

## Tatbikat kayıtları

| Tarih | Kopya | Kim | Sonuç | Süre |
|---|---|---|---|---|
| — | — | — | (henüz yapılmadı) | — |
