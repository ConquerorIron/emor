# ERP Web

Şirketteki exe tabanlı ERP uygulamasına (Meyer — MSSQL) modern bir web arayüzü.
ERP verisi BİZDE TUTULMAZ: kayıtlar ERP'nin kendi store proc'ları çalıştırılarak
(ör. `SOHOM_SIPARIS_KAYDET`) doğrudan MSSQL'e yapılır, listeler ERP view'larından
okunur. PostgreSQL yalnızca uygulamanın kendi tanımlarını tutar (kullanıcılar,
SQL bağlantı tanımları vb.).

## Yapı

- `backend/` — Laravel 13 + Sanctum (SPA session auth), PostgreSQL (`erp` / `erp_test` DB)
- `frontend/` — React 19 + Vite + TS + Tailwind 4 + TanStack Query + react-hook-form/zod + react-select + sonner + i18next (tr/en)
- `ornek/puantaj/` — REFERANS proje: tüm frontend/backend pattern'leri buradan alınır
  (sidebar layout, component kütüphanesi, API hata sözleşmesi, Türkçe adlandırma).
  Değiştirilmez, sadece örnek alınır.

## Kritik mimari kararlar

- **Test/Canlı ortamlar**: MSSQL bağlantı tanımları (test + canli) PostgreSQL'de
  `sql_baglantilari` tablosunda, şifreler encrypted cast ile. GLOBAL tek aktif
  ortam vardır (kısmi unique indeks garantiler). Store proc çalıştıracak HER modül
  MSSQL'e `App\Services\MssqlBaglantiServisi::baglan()` üzerinden erişir —
  aktif ortamın bağlantısını çalışma zamanında kurar (Laravel bağlantı adı: `erp`).
- **Auth**: Kullanıcılar ERP kullanıcı adı/şifresiyle girer. Doğrulama:
  `VOHOM_ARAMA_KULLANICI` view'ı (KULLANICI_ADI benzersiz, SIFRE düz metin) —
  karşılaştırma PHP'de hash_equals ile case-sensitive yapılır
  (`App\Services\ErpKimlikDogrulama`; testler `ErpKimlikDogrulayici` interface'ine
  sahte bağlar). Başarıda kullanıcı `users` tablosuna yansıtılır
  (kaynak=erp, erp_kullanici_id = ERP KULLANICI_ID — proc parametrelerinde
  "kaydı yapan" olarak kullanılacak). Fallback: `kaynak=lokal` admin (seeder, .env:
  ERP_ADMIN_KULLANICI/ERP_ADMIN_SIFRE).
- **Test MSSQL**: 10.2.30.41:1433, veritabanı FD_hom (SQL Server 2019) —
  Ayarlar ekranında "test" ortamı olarak kayıtlı ve aktif. Canlı tanımı henüz girilmedi.
- **API hata sözleşmesi**: her hata gövdesi makine okunur `kod` alanı taşır
  (bootstrap/app.php); frontend `api/errors.ts` ile i18n'e çevirir.
- **PHP MSSQL sürücüsü**: WSL'de pecl sqlsrv/pdo_sqlsrv 5.13.2 (PHP 8.5 için
  phpize8.5 ile derlendi) + msodbcsql18. Bağlantı config'inde
  `trust_server_certificate=true` (şirket içi self-signed sertifikalar).

## Komutlar (WSL Ubuntu-24.04 içinde çalıştırılır)

```bash
# Backend (backend/ içinde)
php artisan serve            # http://localhost:8000
php artisan test             # testler (sqlite :memory:)
./vendor/bin/pint --dirty    # format

# Frontend (frontend/ içinde)
npm run dev                  # http://localhost:5173 (API'yi 8000'e proxy'ler)
npm run typecheck && npm run lint
npm run test                 # vitest
npm run build
```

## Ekran tasarım motoru (form düzenleri veriyle sürülür)

Form ekranları koda gömülü DEĞİL; iki katman var:

- **Katalog (kod)** — `backend/app/Ekranlar/*Katalogu.php`: alanın NE olduğu (giriş
  tipi, veri anahtarları, proc parametresi, kaldırılamaz/salt-okunur kilitleri).
  Tek doğruluk kaynağı; frontend API'den okur.
- **Tasarım (veri)** — `ekran_tasarimlari` tablosu (JSONB `duzen`): alanın NASIL
  göründüğü (bölüm, sıra, 12'lik ızgarada genişlik, zorunlu, textarea, varsayılan).

Bir "alan" tek form anahtarı değildir (personel → `personel_id` + `personel_adi`);
katalogdaki `veri_anahtarlari` bunu tanımlar.

Akış: ekran başına tek `taslak` + tek `yayinda` sürüm (kısmi unique indeks),
yayınlanan eskiler `arsiv` olur ve geri alınabilir. Yayında sürüm yoksa katalogun
`varsayilanDuzen()`'i kullanılır — motor devreye girdiğinde ekran bugünkü haliyle açılır.

Frontend: `features/ekranTasarim/` (tipler + API), `features/satinalma/girisKaydi.tsx`,
sayfa tasarımdan çizer. Zod şeması zorunlu alanlar tasarımdan geldiği için çalışma
zamanında üretilir (`talepSemasiUret`); backend kayıt sırasında aynı tasarımla
tekrar doğrular.

**Alan çizim sözleşmesi (ÖNEMLİ).** Tasarım kuralları TEK yerde uygulanır:
`AlanGirisi` sarmalayıcısı etiketi (+ zorunluluk yıldızı), doğrulama mesajının
ÇEVİRİSİNİ ve salt-okunur kilidini hesaplayıp `ortak` nesnesinde verir.
`GIRIS_TANIMLARI` kayıtları yalnız kendine özgü kısmı yazar ve `{...ortak}`
olarak geçer — bu yüzden yeni bir giriş tipinde ortak kuralları bağlamak
unutulamaz. (Daha önce her tip bunları tek tek bağlıyordu ve düzenli olarak
atlanıyordu.) Sözleşme `girisKaydi.test.tsx` ile her tip için doğrulanır.

Yetki: `users.sistem_yoneticisi` (ERP `TOHOM_KULLANICI.SISTEM_YONETICISI`
yansıması, her girişte tazelenir) — tasarım düzenleme uçlarını bu bayrak açar.

## Yayınlama (production)

- **Repo**: git@github.com:ConquerorIron/emor.git (ornek'teki puantaj repo'suna ASLA dokunma)
- **Sunucu**: erp.tersane-istanbul.com = 10.2.30.67 (iç ağ, Ubuntu 26.04, ssh: admin) — uygulama `/var/www/emor`
- **Akış**: lokalde `./publish.sh --message "..."` (testler + push) → sunucuda `cd /var/www/emor && ./deploy.sh` (pull + build + migrate)
- Sunucu bileşenleri: nginx (SSL: /etc/ssl/emor, Sectigo wildcard 2027-01'e kadar) + php8.5-fpm + pdo_sqlsrv (pecl) + PostgreSQL 17 (db: erp, şifre: /home/admin/.erp_db_pw) + emor-queue systemd servisi + /etc/cron.d/emor scheduler
- Sunucudaki git, GitHub'a `~admin/.ssh/id_ed25519` deploy key'i ile erişir (repo Settings → Deploy keys'e ekli olmalı)
- MSSQL sürücü notu: Microsoft'un ubuntu/26.04 apt deposu boş — 24.04 (noble) deposu kullanılıyor (/etc/apt/sources.list.d/mssql-release.list)

## Sıradaki iş

Satınalma Talebi ekranı: form alanları kullanıcıdan gelecek;
`SOHOM_SIPARIS_KAYDET` store proc'unun parametre eşleştirmeleri tanımlanacak,
seçim listeleri ERP view'larından okunacak.
