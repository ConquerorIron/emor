# EFAT-12 — Mail altyapısı (SMTP, kuyruk, şablon) + SMTP tanımlama ekranı

- **Durum:** Bitti (lokal) — gerçek SMTP teslimatı şifre ekrandan girilince doğrulanacak
- **Bağımlılık:** —
- **Skill:** laravel-best-practices, testing-best-practices

## Neden

Repo varsayılanı ve `.env.example` `MAIL_MAILER=log`; canlı sunucunun
gerçek ayarı bu incelemede doğrulanmadı. Alarmlar için gerçek mail taşıyıcısı
ve kuyruk gerekir (`emor-queue` varlığı CLAUDE.md'de belirtilmiş).
SMTP beklenirken şablon/kuyruk davranışı fake ile geliştirilebilir.

## Kararlar (2026-09-23, kullanıcı)

- SMTP ayarları **ayar ekranından** tanımlanır (Ayarlar → Mail/SMTP, yalnız
  sistem yöneticisi); şifre `encrypted` cast'le veritabanında, API'ye dönmez.
- İlk değerler seeder ile: gönderen `emor@tersane-istanbul.com`,
  `smtp.office365.com:587` (STARTTLS), kullanıcı `emor@tersane-istanbul.com`.
- **Şifre seeder'a yazılmaz** (seeder repoya girer; EFAT-01). Karar: seeder
  şifresiz kayıt açar; şifre SMTP tanımlama ekranından girilir (lokal ve sunucuda
  birer kez). Şifre boşken gönderim ve test maili anlaşılır hata verir.
- Görünen ad: **eMOR ERP**.

## Yapılacaklar

- [x] SMTP tanım tablosu + ekran: sunucu, port, şifreleme (STARTTLS/SSL/yok),
      kullanıcı, şifre (maskeli), gönderen adres, görünen ad, test alıcısı;
      "Test maili gönder" düğmesi. Laravel mailer'ı çalışma zamanında bu
      tanımla kurulur (`MssqlBaglantiServisi` kalıbı).
- [x] Ortak mail şablonu: Markdown `AlarmMaili` + `mail/alarm.blade.php`
      (Türkçe). Fatura tablosu bilinçli yok (kişisel/fatura verisi maile girmez).
- [x] Gönderim kuyrukta: mailable değil `AlarmBildirimiGonder` işi kuyrukta
      (SMTP kabulü işte gözlenir, kayda yazılır); 6 saat boyunca artan aralıkla deneme.
- [x] `afterCommit`; başarısız/atlanan gönderim Alarm Kuralları ekranında
      neden koduyla görünür. Gönderimi durdurma: kuralı kapatmak (kuyruktaki
      iş göndermeden önce denetler) ya da acil durumda `EFATURA_SENKRON_AKTIF=false`.
- [x] Test ortamında alıcı yönlendirme (tüm mailler tek test adresine) — yanlış kişiye gitmesin.
- [x] Yönlendirme: uygulama maillerinde yalnız To kullanılıyor (Cc/Bcc yok),
      yönlendirme To'ya uygulanıyor. Entegratör ortamı TEST ise yönlendirme
      adresi yoksa alarm hiç gönderilmez (`TEST_YONLENDIRME_YOK`); konu `[TEST]`
      ile başlar ve gövdede ortam yazar. Tek şirket (S9) olduğu için şirket adı yok.
- [x] Mail/failed-job içeriği: iş yükü yalnız bildirim kimliği; mailde fatura
      satırı, unvan, VKN yok — yalnız adet, tutar toplamı, tarih, kod ve ekran
      bağlantısı. SMTP hata mesajı 300 karaktere kısaltılır.
- [ ] Mail bağlantısının base URL'si (`APP_URL`) sunucuda doğrulansın (EFAT-14).
      Ekran erişimi izinle korunur; alıcının izni yoksa bağlantı anasayfaya döner.
- [x] Testler: `Mail::fake()`; SMTP hatası kapalı yerel porta gerçek bağlantıyla.

## Kabul kriterleri

- [ ] Lokal ve sunucuda test maili ulaşıyor.
- [ ] Kuyruğa alma ile SMTP kabulü ayrı gözleniyor; fake testi gerçek
      teslimat testi sayılmıyor. SMTP kabulü alıcı kutusuna teslim garantisi değildir.

## Sonuç


2026-09-23 (Claude) — SMTP tanımlama ekranı uygulandı (lokal):

- Backend: `mail_ayarlari` tablosu (tek satır, `anahtar` unique; şifre
  `encrypted`, nullable), `MailAyari` modeli (`hedefFarkli`), `MailAyarServisi`
  (`guncelle`, `gonder`), `MailAyarController` (`GET/PUT /ayarlar/mail`,
  `POST /ayarlar/mail/test` — `can:sistem-yonetimi`, `throttle:mail-test` 5/dk),
  `MailAyarGuncelleRequest` (sunucu adı yalnız `[A-Za-z0-9.-]`), `MailAyarResource`
  (`sifre_dolu`), `TestMaili` (Markdown, `resources/views/mail/test.blade.php`,
  `lang/tr/mail.php`), `MailAyarSeeder` (şifresiz; var olan tanımı ezmez;
  `DatabaseSeeder`'a eklendi), `lang/tr/hata.php`.
- Gönderim: tüm mailler `MailAyarServisi::gonder()` üzerinden. `uygulama` adlı
  mailer çalışma zamanında tanımdan kurulur ve her gönderimde `purge` edilir
  (kuyruk worker'ı eski tanımı taşımaz). Şifreleme: `tls` → STARTTLS **zorunlu**
  (`require_tls`), `ssl` → `smtps`, `yok` → `auto_tls=false`. Gönderen adres/ad
  mailer'ın `from` ayarından.
- Yönlendirme: `yonlendirme_adresi` doluysa tüm alıcılar yerine yalnız o adrese
  gider; asıl alıcılar `X-Emor-Asil-Alicilar` başlığına yazılır. Ekranda uyarı görünür.
- Şifre kuralı: sunucu/port/kullanıcı değişirse kayıtlı şifre yeniden istenir
  (EFAT-16 kuralı); kullanıcı adı boşaltılırsa şifre silinir. Şifre yokken gönderim
  anlaşılır 422 verir.
- Test maili senkron; SMTP hatası (ör. `535 Authentication unsuccessful`) 422 ile
  gösterilir, şifre içermez.
- Frontend: `/ayarlar/mail` (yalnız yönetici, menüde "Mail (SMTP)"),
  `features/ayarlar/mailApi.ts`, `pages/MailAyarlari/MailAyarlariPage.tsx` + test.
- Testler: backend `MailAyarTest` (21) — 401/403 matrisi, kaydetme/şifreli saklama,
  şifre koruma ve hedef değişimi, sunucu adı doğrulaması, test maili mailer/alıcı,
  yönlendirme, TLS seçeneklerinin mailer ayarına yansıması, şifresiz/tanımsız 422,
  kapalı yerel porta bağlanamama (dış ağa çıkmadan), mail içeriği, seeder.
  Frontend 6 test. Backend 151, frontend 148 test; typecheck, lint (önceden var
  olan 4 uyarı), Prettier, Pint, build yeşil. Lokal DB'ye migration + seeder uygulandı.
- **Kullanıcının yapacağı:** ekrandan SMTP şifresini gir → "Test Maili Gönder".
  Office 365'te mailbox için **SMTP AUTH** açık olmalı; kapalıysa `535 5.7.139`
  döner (Exchange yöneticisi açar).
- Açık (EFAT-13 ile yapılacak): ortak alarm şablonu, `ShouldQueue` + `afterCommit`,
  deneme/başarısız iş görünürlüğü, Cc/Bcc yönlendirmesi, ortam/şirket başlığı.
- Doğrulanmayan: gerçek Office 365 gönderimi (şifre ekrandan girilecek); ekranın
  tarayıcıda görünümü. Sunucuda seeder `deploy.sh --with-seed` ile çalışır.

2026-09-23 (Claude) — kuyruklu alarm gönderimi EFAT-13 ile tamamlandı (bkz.
EFAT-13 Sonuç). Kabul kriterlerindeki gerçek teslimat, şifre ekrandan
girildikten sonra "Test Maili Gönder" ve bir alarm kuralının test alıcısıyla
açılmasıyla doğrulanacak.
