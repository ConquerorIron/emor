# EFAT-18 — Kullanıcı oluşturma ve yetkilendirme

- **Durum:** Bitti (lokal, yayınlanmadı)
- **Bağımlılık:** EFAT-16 (`sistem-yonetimi` Gate altyapısı)
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices, vercel-react-best-practices

## Neden

S7 kararı (2026-09-23): eFatura ekranlarını kimin göreceği bir kullanıcı
oluşturma ve yetkilendirme mekanizmasıyla belirlenecek. Bugün yalnız iki seviye
var: oturum açmış kullanıcı ve `sistem_yoneticisi` (ERP'den gelen bayrak).

## Mevcut durum (başlangıçta)

- Kullanıcılar ERP kullanıcı adı/şifresiyle girer ve ilk girişte `users`
  tablosuna yansıtılır (`kaynak = erp`); ayrıca `.env`'den tek bir lokal admin var.
- Yetki: `sistem-yonetimi` Gate'i (`sistem_yoneticisi` bayrağı). Rol/izin tablosu yok.

## Kararlar (2026-09-23, kullanıcı)

- **Rol tabanlı:** roller tanımlanır, rollere izin verilir, kullanıcıya rol atanır.
- **ERP kullanıcıları + lokal kullanıcılar:** ERP kullanıcıları girişte oluşmaya
  devam eder; ERP'de olmayan kişiler için ekrandan lokal kullanıcı (kullanıcı
  adı + şifre) açılabilir.

## Ayrıntılar (uygulamada verilen kararlar)

- [x] İlk izin listesi yalnız eFatura faz 1 için: `efatura.goruntule`,
      `efatura.pdf`, `efatura.disari_aktar`, `efatura.senkron`. Katalog kodda
      (`App\Yetki\Izin` enum); veritabanında olmayan bir kod izin sayılmaz.
      Ayar ekranları ve kullanıcı/rol yönetimi `sistem-yonetimi` Gate'inde kaldı
      (ayrı izne bölmek ileride gerekirse enum'a eklenir). Alarm kuralı izni EFAT-13'te.
- [x] `sistem_yoneticisi` bayrağı tüm izinleri kapsar. Bayrak ekrandan
      verilemez; ERP'den (her girişte) ve yedek lokal admin için migration'dan gelir.
- [x] Lokal kullanıcı şifresi: en az 10 karakter, harf + rakam. Şifre
      sıfırlama = yöneticinin düzenleme ekranından yeni şifre girmesi.
      Kullanıcı silinmez, pasif yapılır (`aktif_mi`); pasif kullanıcının her
      isteği 403 `HESAP_PASIF` döner ve frontend oturumu kapatır.
- [x] Lokal kullanıcı adı `users` içinde tekil ve ERP'de (büyük/küçük harf
      duyarsız) bulunmamalı. ERP'ye ulaşılamazsa lokal kullanıcı açılmaz
      (denetlenemeyen ad kabul edilmez).
- [x] Kişi kendini ve `.env` yedek admini pasif yapamaz (kilitlenme önlemi).
      ERP kullanıcısının ad/e-posta/şifresi ekrandan değişmez (ERP'den gelir).

## Yapılacaklar

- [x] Şema: `roller`, `rol_izinleri`, `kullanici_rolleri` (yeni paket yok;
      Laravel Gate ile).
- [x] Her izin için Gate, `sistem-yonetimi` korundu; izinler istek başına
      okunur (önbellek yok → değişiklik bir sonraki istekte geçerli).
- [x] Uçlar (yalnız sistem yöneticisi): `GET /ayarlar/izinler`,
      `GET|POST /ayarlar/roller`, `PUT|DELETE /ayarlar/roller/{rol}`,
      `GET|POST /ayarlar/kullanicilar`, `PUT /ayarlar/kullanicilar/{kullanici}`.
      `/me` yanıtı `izinler` listesini taşır.
- [x] Ekranlar: Ayarlar → Kullanıcılar, Ayarlar → Roller.
- [x] Frontend rota/menü koruması: `IzinAlani` + `useIzin`; menü öğesi `izin`
      alanı alır, boş kalan menü grubu gizlenir. (eFatura rotaları EFAT-11'de
      bununla bağlanacak.)
- [x] Testler: `YetkiTest`, `RolTest`, `KullaniciYonetimTest` (backend);
      `IzinAlani`, `RollerPage`, `KullanicilarPage` (frontend).

## Kabul kriterleri

- [x] Yetkisi olmayan kullanıcı izinli uçları göremiyor (403) — Gate düzeyinde
      test edildi; eFatura uçlarına bağlanması EFAT-11'de.
- [x] Yetki değişikliği kullanıcının bir sonraki isteğinde geçerli oluyor (test).

## Sonuç

2026-09-23 — lokalde bitti, yayınlanmadı. Backend 223 test, frontend 163 test
geçiyor; typecheck, lint (yalnız önceden var olan 4 uyarı) ve build temiz.
Migration lokal PostgreSQL'e uygulandı.

Notlar:

- Laravel'in şifre kuralı mesajları Türkçe çeviri dosyası olmadığı için
  İngilizce döner; frontend aynı kuralı Türkçe mesajla önceden denetler.
- Sunucuda deploy (EFAT-14) sırasında `migrate` bu tabloları oluşturur;
  roller ekrandan tanımlanır, seeder yok.
