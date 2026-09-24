# EFAT-13 — Alarm kuralları ve bildirimler

- **Durum:** Faz 1 bitti (lokal, yayınlanmadı) — ERP'ye bağlı kurallar ERP fazında
- **Bağımlılık:** EFAT-10, EFAT-12
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices, vercel-react-best-practices

## Neden

"İhtiyaca yönelik kurallar dahilinde" mail alarmı.

## Faz 1 kararı (2026-09-23, kullanıcı)

ERP'siz fazda üç kural: **senkron arızası**, **günlük yeni fatura özeti**,
**ERP okumadı uyarısı**. ERP'de var/entegratörde yok gibi kurallar ERP fazında.

## Kural adayları (ERP fazı)

- Giden fatura ERP'de var, **N saat/gün** geçti hâlâ entegratörde yok.
- Gelen fatura entegratörde var, **N gün** geçti hâlâ ERP'ye işlenmedi
  (faz 1'de `erpReadFlag` ile yaklaşık karşılığı var).
- Ticari gelen faturada yanıt süresi yaklaşması: mali sorumlu doğrulamadan
  sabit "8 gün" kuralı uygulanmaz.
- Tutar/tarih uyuşmazlığı (Farklı kategorisi).

## Uygulanan kurallar

| Kural | Koşul | Sıklık | Mail |
|---|---|---|---|
| Senkron arızası | Yön başına art arda N (3) başarısız/eksik çalışma **veya** veri N (2) saattir güncellenmedi. Senkron `.env` ile kapalıysa üretilmez. | Her 15 dk (senkrondan sonra) | Açıldı + düzelince Çözüldü |
| Günlük özet | Önceki gün (İstanbul) **ilk kez görülen** gelen/giden faturaların adet ve para birimi bazında tutarı | Günde bir, ayarlanan saatten (08:00) sonra | Özet; senkron güncel değilse "sayılar eksik olabilir" uyarısı |
| ERP okumadı | Gelen fatura İzibiz'e ulaşalı N (2) gün, `erp_okundu = false`, belge tarihi ≥ 01.01.2026, **fatura son 26 saatte yeniden okunmuş** (`son_gorulme`; bayat bayrak ne olay açar ne çözer). **Gelen verisi güncel değilse değerlendirilmez.** | Günde bir, ayarlanan saatten (09:00) sonra | Yalnız YENİ açılan olay varsa; okununca olay çözülür, hatırlatma yok |

## Yapılacaklar

- [x] Kural modeli: tür, parametre (eşik), alıcılar, aktif. Tür başına tek
      kural; ilk okumada kapalı oluşturulur (seeder yok).
- [x] Eşik başlangıcı: senkron için son başarılı güncelleme / art arda
      hata; ERP okumadı için İzibiz'e ulaşma zamanı (`olusturma_zamani`).
      Saat dilimi İstanbul (gönderim saati ve gün sınırı). Hatırlatma yok;
      çözülme bildirimi yalnız senkron arızasında.
- [x] Değerlendirici yalnız güncel veriyi kullanır (ERP okumadı); eksik
      okuma yokluk alarmı üretmez. İlk açılışta eski okunmamışlar tek özet
      mailde toplanır (adet), fatura başına mail yok.
- [x] Olay anahtarı: kural + hesap (entegratör tanımı) + anahtar; açık olay
      kısmi unique indeksle tekil. Çözülüp yeniden açılan olay yeni satır.
      Bildirim `anahtar` unique (olay:ID:acildi, gunluk_ozet:HESAP:GÜN, …).
- [x] Bildirim durumu: bekliyor / gonderildi / basarisiz / atlandi (+ neden
      kodu). "gönderildi" yalnız SMTP kabulünden sonra işaretlenir; kuyruğa
      girmeden kalan `bekliyor` kayıt 15 dk sonra komut tarafından yeniden
      kuyruğa verilir (iş tekil).
- [x] SMTP belirsizliği: kabul ile işaret arasında süreç ölürse yeniden
      deneme ikinci mail gönderebilir — **en az bir kez** teslim; tam bir kez
      garanti edilmez. 6 saat boyunca artan aralıkla (1, 5, 15, 30 dk) denenir; SMTP tanımı eksikse denenmez.
- [x] Göndermeden önce: kural hâlâ aktif mi, hesap hâlâ aktif ortam mı,
      açılış bildiriminin olayı hâlâ açık mı, alıcı var mı, TEST hesabıysa
      yönlendirme adresi var mı — değilse `atlandi`.
- [x] Teknik alarm iş alarmından ayrı. Son başarı/arıza e-Fatura ekranında,
      gönderim sonuçları (başarısız/atlanan dahil) Alarm Kuralları ekranında.
- [x] Kural yönetim ekranı (sistem yöneticisi): Ayarlar → Alarm Kuralları.
- [x] Testler: eşik sınırları (sabit zamanla), tekrar bildirmeme, çözülme/
      yeniden açılma, günlük özet gün sınırı, güncel olmayan veride sessizlik,
      gönderim engelleri, SMTP hatası ve son deneme, komutun yeniden kuyruğa alması.

## Kabul kriterleri

- [x] Aynı açık olay için tek bildirim; çözülme/yeniden açılma ve retry
      politikası testlerle doğrulandı. Eşzamanlılık DB kısıtlarıyla
      (kısmi unique + unique anahtar) korunuyor; gerçek eşzamanlı yük testi yapılmadı.
- [x] Eksik okuma ve güncel olmayan veri yokluk alarmı üretmiyor; test
      ortamı yönlendirme adresi olmadan gerçek alıcılara mail göndermiyor.

## Sonuç

2026-09-23 (Claude) — faz 1 lokalde bitti, yayınlanmadı.

- Şema: `alarm_kurallari`, `alarm_olaylari` (kısmi unique `alarm_olaylari_tek_acik`),
  `alarm_bildirimleri` (`anahtar` unique). Lokal PostgreSQL'e uygulandı.
- Kod: `AlarmKurali`, `AlarmOlayi`, `AlarmBildirimi` modelleri;
  `Services/Alarm/AlarmDegerlendirici`, `AlarmKuraliServisi`;
  `Jobs/AlarmBildirimiGonder` (ShouldBeUnique, afterCommit, yük yalnız kimlik);
  `Mail/AlarmMaili` + `resources/views/mail/alarm.blade.php` (fatura
  satırı/unvan/VKN yok; yalnız sayı, tarih, kod ve ekran bağlantısı;
  test hesabında konu `[TEST]`); `efatura:alarmlar` komutu (15 dk'da bir,
  senkrondan sonra); `AlarmKuraliController` (`GET /ayarlar/alarm-kurallari`,
  `PUT /ayarlar/alarm-kurallari/{tur}`, `can:sistem-yonetimi`).
- Frontend: `pages/AlarmKurallari/` (kural kartları + son 50 bildirim ve
  neden kodları), `features/ayarlar/alarmApi.ts`, menüde "Alarm Kuralları".
- Testler: backend `AlarmTest` (19), frontend `AlarmKurallariPage.test.tsx` (3).
- Lokal durum: kurallar kapalı; test hesabında okunmamış gelen fatura yok.
- Doğrulanmayan: gerçek SMTP ile alarm maili (şifre ekrandan girilecek);
  mail bağlantısı `APP_URL`'den üretilir — sunucuda doğru adres olmalı (EFAT-14).
