# EFAT-16 — Mevcut SQL Bağlantıları yetki açığı

- **Durum:** Bitti (lokal) — sunucuya yayın bekliyor
- **Bağımlılık:** — (eFatura işinden bağımsız; EFAT-03/05 bu görevin yetki altyapısını kullanır)
- **Öncelik:** Yüksek — canlıda açık olan güvenlik hatası
- **Skill:** code-review, laravel-best-practices, testing-best-practices, supabase-postgres-best-practices, vercel-react-best-practices

## Bulgu ve etki

- `backend/routes/api.php:91`: SQL uçları yalnız `auth:sanctum` grubunda.
- `backend/app/Http/Requests/Ayar/SqlBaglantiGuncelleRequest.php:11`:
  `authorize()` her kullanıcı için true.
- `backend/app/Http/Controllers/Api/V1/SqlBaglantiController.php:26`:
  listeleme, güncelleme, sınama ve aktif ortam değiştirmede yönetici kontrolü yok.
- `frontend/src/layouts/AppLayout.tsx:44`: menü herkese açık; doğrudan URL ile de açılıyor.

Oturum açan standart kullanıcı bağlantı bilgilerini görebilir/değiştirebilir,
ERP işlemlerinin hedef ortamını değiştirebilir ve bağlantı sınayabilir.
Teknik gerekçe: Laravel security kuralı `Authorize Protected Actions`;
kimlik doğrulama yönetici yetkilendirmesi yerine geçmez.

### Ek bulgu 1 — Kayıtlı şifrenin başka sunucuya gönderilmesi

`SqlBaglantiController::sina()` gövdedeki `sunucu`/`port`/`kullanici_adi`
değerlerini kayıtlı tanımın üstüne yazıyordu; şifre boş gelirse **kayıtlı şifre**
kullanılıyordu. Bağlantı `trust_server_certificate=true` ile kuruluyor. Bu yüzden
kullanıcı kendi kontrolündeki bir sunucuyu yazıp şifreyi boş bırakırsa uygulama
MSSQL giriş bilgisini o sunucuya gönderirdi. `guncelle` de hedef değişse bile
boş şifreyle kayıtlı şifreyi koruyordu.

### Ek bulgu 2 — Lokal admin kilitlenir

`AdminKullaniciSeeder` lokal admini `sistem_yoneticisi` alanı olmadan oluşturuyordu
(migration varsayılanı `false`). Lokal admin, ERP/MSSQL bağlantısı yokken ya da
bozukken girişin tek yolu; SQL ekranı yalnız `sistem_yoneticisi`ne kapatılırsa
bağlantıyı düzeltebilecek hesap kalmazdı.

## Düzeltme

- [x] **Tek yetki tanımı:** `AppServiceProvider`'da `Gate::define('sistem-yonetimi')`.
      SQL rotaları `can:sistem-yonetimi` middleware'li alt grupta; FormRequest
      `authorize()` aynı Gate'i kullanır. Ekran Tasarımı'nın elle yazılmış kontrolü
      de bu Gate'e bağlandı. Yetkisiz yanıt `403 / ERISIM_ENGELLI`.
- [x] **Lokal admin:** seeder `sistem_yoneticisi = true` yazar; mevcut kayıt için
      `2026_09_23_103738_lokal_admini_sistem_yoneticisi_yap` migration'ı yalnız
      `ERP_ADMIN_KULLANICI` adlı lokal hesabın bayrağını açar (deploy.sh migrate'i
      her yayında çalıştırır; seed yalnız `--with-seed` ile).
- [x] **Kayıtlı şifre yalnız kayıtlı hedefle:** `SqlBaglanti::hedefFarkli()`
      (sunucu büyük/küçük harf duyarsız, port, kullanıcı adı). `sina` ve `guncelle`
      hedef değişip şifre boşsa `hata.sql_sifre_hedef_degisti` ile 422 döner;
      bağlantı denenmez. **Karar:** veritabanı adı hedef sayılmadı — aynı sunucuya
      aynı kullanıcıyla gidildiği için şifre dışarı çıkmaz.
- [x] **Ortam rozeti:** oturum açmış her kullanıcıya açık `GET /api/v1/aktif-ortam` yalnız
      `{aktif_ortam}` döner. Sorgu anahtarı `['ayarlar','sqlBaglantilari','aktifOrtam']`;
      SQL sayfasının mevcut invalidation'ı rozeti de tazeler.
- [x] **Frontend:** SQL menü öğesi `yoneticiye: true`; `YoneticiAlani` rota
      koruması SQL Bağlantıları ve Ekran Tasarımı'nı sarar, yetkisiz kullanıcıyı
      anasayfaya döndürür. Şifre alanı notu (tr/en) yeni kuralı anlatır.
- [x] **Değişiklik kaydı:** güncelleme ve aktif ortam değişimi `Log::info` ile
      ortam, kullanıcı ID, sunucu ve `sifre_degisti` bilgisiyle yazılır; şifre yazılmaz.
- [ ] Entegratör ekranına (EFAT-03/05) aynı açıklar kopyalanmaz — o görevlerde uygulanacak.

## Operasyon (kullanıcı kararı)

- [x] MSSQL şifresi yenilenmeyecek. Kullanıcı kararı (2026-09-23): bağlantı
      aktif kullanılan bir şey değildi.

## Kabul kriterleri / doğrulama

- [x] Oturumsuz 401; standart kullanıcı dört SQL ucunda 403 ve tanım değişmiyor;
      yönetici tüm işlemleri yapabiliyor. Lokal admin bayrağı seeder ve migration
      testleriyle doğrulandı.
- [x] Farklı sunucu/port/kullanıcı + boş şifreyle `sina` ve `guncelle` şifre
      hatası veriyor, bağlantı denenmiyor; aynı hedef (yalnız veritabanı değişimi,
      büyük harfli sunucu adı) + boş şifre kayıtlı şifreyi koruyor.
- [x] `SqlBaglantiTest` yetki ve hedef değişimi senaryolarıyla güncellendi;
      mevcut başarılı senaryolar `yonetici()` factory state'iyle çalışıyor.
- [x] Standart kullanıcı ortam rozetini görüyor (`/aktif-ortam` testi); SQL
      bilgilerini göremiyor.
- [x] `php artisan test`, pint, `npm run typecheck`, `npm run lint`, vitest, `npm run build` yeşil.

## Sonuç

2026-09-23: Kod incelemesiyle doğrulandı; bu görevde düzeltme uygulanmadı.
2026-09-23 (Claude): ek bulgu 1 (şifre başka hedefe) ve ek bulgu 2 (lokal admin
kilitlenmesi) kodla doğrulanıp göreve eklendi.

2026-09-23 (Claude) — uygulandı:

- Backend: `AppServiceProvider` (Gate), `routes/api.php` (yönetici grubu +
  `aktif-ortam`), `SqlBaglantiController` (`aktifOrtam`, `sina` hedef kontrolü,
  log), `MssqlBaglantiServisi::guncelle` (hedef kontrolü), `SqlBaglanti::hedefFarkli`,
  `SqlBaglantiGuncelleRequest::authorize`, `EkranTasarimController::yoneticiOlmali`,
  `AdminKullaniciSeeder`, yeni veri migration'ı, `lang/tr/hata.php`.
- Frontend: `sqlApi.ts` (`aktifOrtamGetir`), `queryKeys.ts`, `AppLayout.tsx`,
  `router.tsx`, yeni `components/YoneticiAlani.tsx` + testi, `i18n/tr.json`, `en.json`.
- Testler: `SqlBaglantiTest` (403 matrisi uç başına, hedef değişimi, aktif-ortam,
  log), yeni `SistemYonetimiYetkisiTest` (Gate matrisi), `AdminKullaniciSeederTest`,
  `LokalAdminiSistemYoneticisiYapMigrationTest`. Davranış değişikliği: eski
  `test_guncellemede_bos_sifre_kayitli_sifreyi_korur` sunucu değişirken boş şifreyi
  kabul ediyordu; bu kapatılan açık olduğu için test "aynı hedef" ve "farklı hedef"
  olarak ikiye ayrıldı.
- Kontroller: backend 44 → 64 test yeşil; pint geçti; frontend typecheck, lint
  (dokunulmayan dosyalarda önceden var olan 4 uyarı), prettier, 133 vitest testi
  ve build yeşil. Lokal `erp` veritabanında migration çalıştı; `admin` lokal
  hesabı `sistem_yoneticisi = true`.
- Doğrulanmayan: tarayıcıda elle deneme yapılmadı. `sina`'nın gerçek MSSQL'e
  bağlanan başarılı yolu otomatik testte yok (`MssqlBaglantiServisi` final; test
  ağa çıkmamalı). Sunucuya yayın yapılmadı — `./publish.sh` → `./deploy.sh` sonrası
  migration sunucudaki lokal admini düzeltir.

### 2026-09-23 — Codex incelemesi ve düzeltmeleri

Claude uygulaması incelendi; aşağıdaki üç bulgu regresyon testleriyle
doğrulanıp düzeltildi. Yukarıdaki Claude sonuçları ilk uygulama anını anlatır;
bu bölüm sonraki incelemenin sonucudur.

1. **P1 — Bağlantı dizesine seçenek ekleyerek kayıtlı şifreyi başka hedefe gönderme.**
   `backend/app/Services/MssqlBaglantiServisi.php:214`: Laravel sunucu ve
   veritabanı adını DSN'e doğrudan ekliyor. `ERPTEST;Server=baska.example`
   gibi bir veritabanı adı, sunucu alanı değişmeden bağlantı hedefini
   değiştirebiliyordu. Yönetici yetkisi gerekiyor; ancak kayıtlı şifreyi
   farklı hedefte kullanmayı engelleyen yeni koruma aşılabiliyordu.
   Teknik gerekçe: Laravel girdi doğrulama ve sırların korunması kuralları;
   [PDO_SQLSRV 5.13.2 ayrıştırıcısı](https://raw.githubusercontent.com/microsoft/msphpsql/v5.13.2/source/pdo_sqlsrv/pdo_parser.cpp)
   yinelenen seçeneği son değerle güncelliyor.
   **Düzeltme:** sunucu/veritabanında noktalı virgül, süslü parantez ve kontrol
   karakterleri hem kayıt hem bağlantı kurulmadan önce reddediliyor.
   **Doğrulama:** güncelleme ve sınama uçlarında 422, bağlantı denenmemesi ve
   kayıtlı tanımın değişmemesi test edildi. Normal veritabanı değişimi korunuyor.

2. **P2 — Port temizlenince sınama eski portu kullanıyordu.**
   `backend/app/Http/Controllers/Api/V1/SqlBaglantiController.php:95`:
   `??`, açıkça gönderilen `null` değerini alanın gönderilmemesiyle aynı
   sayıyordu. Böylece ekrandaki tanımdan farklı hedef sınanıyordu.
   Teknik gerekçe: istekteki boş değer ile eksik alanın farklı anlamlarını koruma.
   **Düzeltme:** `array_key_exists` ile ayrım yapıldı; port temizlemek hedef
   değişimi sayılıyor ve şifre yeniden isteniyor.
   **Doğrulama:** boş şifreyle bağlantı denenmeden 422; yeni şifreyle portsuz
   bağlantı; port gönderilmediğinde kayıtlı portun korunması test edildi.

3. **P2 — Migration geri alınırken mevcut yönetici yetkisi siliniyordu.**
   `backend/database/migrations/2026_09_23_103738_lokal_admini_sistem_yoneticisi_yap.php:27`:
   `down()`, migration öncesinde de yönetici olan hesabı yetkisiz bırakabiliyordu.
   Teknik gerekçe: Laravel `Make Rollbacks Honest` kuralı; eski değer bilinmeden
   `false` yazmak önceki durumu geri getirmez.
   **Düzeltme:** geri alma artık yetkiyi değiştirmiyor; önceki değer
   saklanmadığından yetki iptali ayrı, hedefi belli bir işlem gerektiriyor.
   **Doğrulama:** önceden yönetici olan hesabın `up()` ve `down()` sonrasında
   yetkisini koruduğu regresyon testiyle doğrulandı.

Başarılı `sina` yolu da gerçek controller/service üzerinden, Laravel SQL
connector/PDO sınırında taklit kullanılarak test edildi. Servisin `final`
olması bu testi engellemiyor; test ağa çıkmıyor ve kayıtlı tanımı değiştirmiyor.

Kontroller:

- Backend: `vendor/bin/phpunit` — **74 test, 253 assertion geçti**.
  Eklenen regresyonlar düzeltme öncesinde hata verdi, düzeltme sonrasında geçti.
- `vendor/bin/pint --dirty --format agent` ve `git diff --check` geçti.
- Frontend: `YoneticiAlani.test.tsx` — **2 test geçti**; typecheck, lint
  (Prettier dahil) ve build geçti. Dokunulmayan dosyalardaki dört lint uyarısı
  sürüyor; build ayrıca 500 kB üzerindeki ana paket için boyut uyarısı veriyor.
  Frontend testlerinin tamamı bu incelemede yeniden çalıştırılmadı.
- Backend testleri SQLite üzerinde çalıştı. Gerçek MSSQL/PostgreSQL bağlantısı
  ve tarayıcıda elle deneme yapılmadı. Bu incelemede uygulama veritabanında
  migration çalıştırılmadı ve sunucuya yayın yapılmadı.
