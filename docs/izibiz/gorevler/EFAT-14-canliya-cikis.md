# EFAT-14 — Canlıya çıkış

- **Durum:** Bekliyor
- **Bağımlılık:** EFAT-01…13, EFAT-15…17
- **Skill:** code-review (+ ilgili teknoloji skill'leri)

## Yapılacaklar

- [ ] Sunucudan (10.2.30.67) `apitest.izibiz.com.tr` ve `api.izibiz.com.tr`
      adreslerine HTTPS çıkışını doğrula (S8; gerekiyorsa proxy ayarı).
- [ ] Canlı İzibiz tanımını ekrandan gir, sına (şifre repoya girmez).
- [ ] Canlı SQL ortamı tanımlı mı kontrol et (CLAUDE.md: henüz girilmedi).
- [ ] Scheduler (`/etc/cron.d/emor`) ve `emor-queue` servisinin çalıştığını doğrula.
- [ ] EFAT-17 sonuçlarını ve muhasebe kabulünü gözden geçir. Hedef şirket,
      SQL/entegratör ortam çifti ve test/canlı veri ayrımı doğrulansın.
- [ ] PostgreSQL yedeği ve şifreleri açacak anahtarlar korunmuş olsun;
      geri yükleme yolu doğrulansın. Test verileri canlıya dönüştürülmesin.
- [ ] Önce yalnız listeleme/mutabakat çalışsın; sonuç doğrulandıktan sonra
      alarmlar açılsın. Test SMTP yönlendirmesi ve canlı alıcılar ayrı doğrulansın.
- [ ] İlk çalıştırmada alarmlar kısıtlı alıcıyla başlar; eski farklar
      toplu mail yağmuruna yol açmasın (başlangıç tarihi / sessiz ilk çalışma).
- [ ] Değişikliklerin code-review skill'iyle son incelemesi.
- [ ] Geri dönüş: scheduler/job/mail üretimini durdurma, bekleyen canlı
      işleri ayırma ve önceki uygulama sürümüne dönme adımları hazır olsun.
      Mail kapatma zaten gönderilmiş maili geri almaz; kayıtlar körlemesine silinmez.
- [ ] `./publish.sh` tüm dosyaları stage ettiği için EFAT-01 kontrolü ve
      görev dışı yerel değişiklikler yayın öncesi gözden geçirilsin. Yayın
      aşamasındaki kullanıcı talimatıyla `./publish.sh` → sunucuda `./deploy.sh`.

### Faz 1 yayın notları (2026-09-23, Claude — lokal değişikliklerden çıkan)

- [ ] **PHP eklentileri:** `phpoffice/phpspreadsheet` için sunucuda
      `php8.5-gd`, `php8.5-zip`, `php8.5-xml`, `php8.5-mbstring` kurulu
      olmalı; yoksa `composer install` platform denetiminde durur.
- [ ] **Migration'lar:** roller/izinler (EFAT-18), alarm tabloları
      (EFAT-13), e-Fatura tabloları (EFAT-10) — `deploy.sh` migrate eder.
- [ ] **PostgreSQL saat dilimi:** `SHOW timezone` bak. Artık bağlantı her
      zaman UTC kullanır; önceden yazılmış `created_at` değerleri 3 saat erken
      kalabilir (EFAT-17). Veri düzeltmesi gerekip gerekmediğine karar verilsin.
- [ ] **`APP_URL`** = `https://erp.tersane-istanbul.com` (alarm mailindeki
      ekran bağlantısı buradan üretilir).
- [ ] **Kuyruk:** `emor-queue` çalışıyor olmalı. `DB_QUEUE_RETRY_AFTER`
      tanımlıysa ≥ 360 (elle senkron işi 300 sn).
- [ ] **Tek seferlik işler:** tanım aktarma (EFAT-06), ilk tarama
      `efatura:senkron --baslangic=2026-01-01 --tetikleyen=ilk_tarama`,
      `deploy.sh --with-seed` (MailAyarSeeder).
- [ ] **Ekrandan girilecekler:** SMTP şifresi ve test maili, roller ve
      kullanıcı atamaları, alarm kuralları. Alarm kuralları varsayılan
      kapalıdır; önce test alıcısı/yönlendirme adresiyle açılmalı.
- [ ] **Canlıya geçiş:** canlı İzibiz tanımı aktif yapılınca TEST yönlendirme
      koruması kalkar ve alarmlar gerçek alıcılara gider.

## Kabul kriterleri

- [ ] Canlıda bir günlük mutabakat sonucu elle kontrolle tutuyor.
- [ ] Alarm mailleri doğru alıcı/kapsama gidiyor; EFAT-13 tekrar gönderim
      politikası ve belirsiz teslimat sınırı kabul edilmiş.
- [ ] Son başarılı senkron, hata/kuyruk takibi ve geri dönüş sorumlusu belirli.

## Sonuç

—
