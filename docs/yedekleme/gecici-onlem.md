# Geçici önlem: günlük yedek ve elle sunucu dışı kopya (BY-001)

Asıl yedekleme sistemi gelene kadar iki açığı kapatır:

1. Yayın yapılmayan günlerde yedek alınmıyor → günlük otomatik yedek.
2. Yedekler ve `.env` yalnız sunucuda duruyor → bir kez elle sunucu dışına kopya.

Bu önlem **geçicidir**. BY-013 canlıya alınınca "Kaldırma" bölümü uygulanır.

## 1. Günlük yedek

Betik: [`scripts/gecici-gunluk-yedek.sh`](../../scripts/gecici-gunluk-yedek.sh)

- Veritabanı bilgilerini `backend/.env`'den, uygulamanın kendi ayrıştırıcısıyla
  okur (`scripts/gecici-env-oku.php`; `php` ve `backend/vendor` gerekir).
  Parolayı komut satırına ve ortam değişkenine koymaz.
- Her dış komutun süre sınırı vardır (varsayılan 30 dakika, bağlantı için
  15 saniye). Asılı kalan bir yedek sonlandırılır; sonraki günün yedeğini engellemez.
- `/var/backups/emor/erp_gunluk_<tarih>_<saat>.dump` üretir (yalnız sahibi okur).
- Önce geçici ada yazar, yedeği doğrular (`pg_restore` ile bütün veriyi okur),
  sonra asıl adına taşır.
- Yalnız kendi dosyalarını temizler: 30 günden eski `erp_gunluk_*` silinir, en
  yeni 7 her durumda kalır. `erp_predeploy_*` dosyalarına dokunmaz.
- Sonucu `/var/backups/emor/gecici-gunluk-yedek.log` dosyasına yazar
  (`TAMAM` / `HATA` / `ATLANDI`).

### Kurulum (sunucuda, yayın kullanıcısıyla; `sudo` gerekmez)

Betik sunucuya yayınla (`./deploy.sh`) gelir. Sonra bir kez elle denenir:

```bash
cd /var/www/emor
scripts/gecici-gunluk-yedek.sh
tail -n 3 /var/backups/emor/gecici-gunluk-yedek.log
```

Zamanlayıcıya satır eklenir (`crontab -e`). Sunucu saati UTC'dir; 23:15 UTC,
İstanbul saatiyle 02:15'tir:

```
# eMOR geçici günlük yedek (BY-001) — BY-013 canlıya alınınca kaldırılacak
15 23 * * * /var/www/emor/scripts/gecici-gunluk-yedek.sh >> /var/backups/emor/gecici-gunluk-yedek.cron.log 2>&1
```

### Kontrol

Ertesi sabah:

```bash
ls -la /var/backups/emor/erp_gunluk_*
tail -n 5 /var/backups/emor/gecici-gunluk-yedek.log
```

Günlükte o geceye ait bir `TAMAM` satırı yoksa yedek alınmamıştır;
`gecici-gunluk-yedek.cron.log` hata çıktısını gösterir. **Bu önlemde otomatik
uyarı yoktur:** yedeğin alındığını birinin ara sıra kontrol etmesi gerekir.

## 2. Sunucu dışına elle kopya (bir kez, sonra ayda bir)

İki şey kopyalanır; ikisi de çok hassastır:

| Dosya | İçeriği | Kaybedilirse |
|---|---|---|
| En yeni yedek (`erp_gunluk_*.dump` ya da `erp_predeploy_*.dump`) | Bütün veri | Sunucu / disk kaybında veri gider |
| `backend/.env` | `APP_KEY`, veritabanı parolası | Yedek geri yüklenir ama kayıtlı bağlantı parolaları okunamaz |

Kopyanın konacağı yeri kullanıcı belirler. **`.env` ile yedek aynı yerde
durmamalıdır;** ikisini birlikte ele geçiren, verinin tamamını okur.

Kendi bilgisayarından (sunucu adresi yerine gerçek adres yazılır):

```bash
# En yeni yedeğin adını öğren
ssh admin@<sunucu> 'ls -t /var/backups/emor/*.dump | head -n1'

# Yedeği ve .env'i ayrı ayrı klasörlere indir
scp admin@<sunucu>:/var/backups/emor/<dosya>.dump  <yedek-klasoru>/
scp admin@<sunucu>:/var/www/emor/backend/.env      <sir-klasoru>/emor.env
```

İndirilen yedeğin bozulmadığı, sunucudaki ile özetleri karşılaştırılarak görülür:

```bash
ssh admin@<sunucu> 'sha256sum /var/backups/emor/<dosya>.dump'
sha256sum <yedek-klasoru>/<dosya>.dump
```

## 3. Kaldırma (BY-013 canlıya alınınca)

1. `crontab -e` ile zamanlayıcı satırı silinir.
2. `scripts/gecici-gunluk-yedek.sh`, `scripts/gecici-env-oku.php`, testi ve bu
   doküman repodan kaldırılır.
3. Sunucudaki `erp_gunluk_*` dosyaları silinmez; asıl sistem onları "eski
   yedek" olarak içeri alır ve kendi saklama kuralıyla temizler.
