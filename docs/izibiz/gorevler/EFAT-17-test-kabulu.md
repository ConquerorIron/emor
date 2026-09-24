# EFAT-17 — Test ortamında uçtan uca kabul

- **Durum:** Devam — faz 1 otomatik kısmı bitti; elle kabul (portal karşılaştırması, SMTP, muhasebe) ve ERP senaryoları bekliyor
- **Bağımlılık:** EFAT-01…13, EFAT-15, EFAT-16
- **Skill:** testing-best-practices, laravel-best-practices, supabase-postgres-best-practices, code-review

## Neden

Token almak veya tek sayfalık listeyi görmek mutabakatın doğruluğunu kanıtlamaz.
Kabul senaryoları sahte yokluk ve tekrarlanan alarm risklerini kapsamalı.
Testler ilgili geliştirme görevlerinde yazılır; burada gereksiz yere çoğaltılmaz.

## Yapılacaklar

- [ ] Her iki tarafta var, yalnız ERP, yalnız entegratör, alan farkı,
      eksik/çelişen ETTN, mükerrer kimlik, iptal/red ve gelen/giden iade
      örnekleri — **ERP fazı** (EFAT-08/09).
- [x] Birden çok sayfa, sayfa ortasında hata, bozuk/eksik yanıt, boş ama
      başarılı liste, erişilemeyen kaynak, sayfalar arası kayma
      (`IzibizFaturaKaynagiTest`, `IzibizIstemcisiTest`). 429 ile 5xx aynı
      yeniden deneme yolundan geçer; 429'a özel test yok (503 test edildi).
- [~] Tarih sınırı (İstanbul günü, dahil uçlar), geç ulaşan eski fatura
      (DELIVERY senkronu), farklı para birimi (ayrı toplamlar) test edildi.
      Parasal tolerans ve "son N gün dışındaki açık fark" ERP fazında.
- [x] Test/canlı ayrımı: aynı kaynak kimliği iki hesapta karışmıyor; liste
      ve PDF başka ortamın faturasını göstermiyor; elle senkron işi ortam
      değişince çalışmıyor; alarm bildirimi ortam değişince atlanıyor.
- [x] Eşzamanlı senkron (kilit), yarım iş, token yenileme (kilit + önbellek),
      tekrar bildirim, çözülme/yeniden açılma, mail işinin tekrar denenmesi
      testlerle; gerçek eşzamanlı yük testi yapılmadı.
- [x] Yetki matrisi Gate düzeyinde (`YetkiTest`), her uç için bir red testi
      (`EFaturaEkranTest`, `AlarmTest`, `RolTest`, `KullaniciYonetimTest`);
      güncellik uyarısı ve güncel olmayan veride alarmın durması test edildi.
- [x] PostgreSQL'e özgü davranış ayrı test DB'sinde (`erp_test`) doğrulandı:
      `DB_CONNECTION=pgsql DB_DATABASE=erp_test php artisan test` → 271/271.
      Operasyonel `erp` DB'si kullanılmadı. Bu çalıştırma **gerçek bir hata**
      yakaladı (aşağıda).
- [x] Ağ testleri fake (`Http::preventStrayRequests`). Gerçek test hesabıyla
      yalnız GET: ilk tarama (EFAT-10), artımlı senkron ve PDF okuma.
- [ ] Portal ile uygulama sayılarının elle karşılaştırılması (aynı tarih
      aralığı, örnek ETTN'ler) — kullanıcı tarafından yapılacak.
- [ ] Mutabakat muhasebe onayı — ERP fazı. Mail denemesi: SMTP şifresi
      ekrandan girildikten sonra yönlendirme adresine (test alıcısı).

## Bulunan ve düzeltilen hata (2026-09-23)

PostgreSQL oturumu sunucu varsayılan saat diliminde (Europe/Istanbul)
açılıyordu. Laravel ise zamanı ekisiz, UTC olarak yazar. Bu yüzden her
`timestamptz` değeri 3 saat kayık saklanıyordu. SQLite testleri bunu
göremiyordu; `erp_test` çalıştırmasında 10 test kırıldı.

Etkisi: güncellik ("veri zamanı") ve alarm eşikleri 3 saat kayıyordu.

Düzeltme: `config/database.php` pgsql bağlantısına `'timezone' => 'UTC'`
eklendi (`config/app.php` ile aynı).

Geçmiş kayıtlar:

- Lokal DB'de ve sunucuda (EFAT-14) bu düzeltmeden ÖNCE yazılmış zaman
  damgaları 3 saat erken kalır. Okuma davranışı değişmez; yalnız yeni yazımlar
  doğru olur.
- e-Fatura tablolarında sonraki senkronlar `son_gorulme`/`olusturma_zamani`
  alanlarını yeniden yazar. `ilk_gorulme` ve eski çalışma kayıtları geçmiş
  olarak kalır.
- Sunucuda e-Fatura tabloları henüz yok; diğer tablolardaki (users vb.)
  eski `created_at` değerleri kayık kalır.

## Kod incelemesi (2026-09-23, Claude — code-review skill'i, 3 paralel inceleme)

Düzeltilen gerçek bulgular (her biri için regresyon testi var):

- **Güvenlik:** `aktif_mi: 0` / `"0"` ile kendini ve yedek admini pasife
  alma kilidi aşılıyordu (katı `=== false`). Değer normalize ediliyor.
- **Güvenlik:** SMTP şifrelemesi "yok"a çekilince kayıtlı şifre açık metinle
  gidebiliyordu. Şifreleme artık şifre hedefinin parçası. Ayrıca metin port
  değeri 500 veriyordu, düzeltildi.
- **Yetki:** PDF, Excel ve senkron uçları ayrıca `efatura.goruntule` istiyor.
- **Alarm:** ERP okumadı, eşiği geçmiş ve yeniden okunmamış satırın bayat
  bayrağıyla olay açabiliyor ya da çözebiliyordu; artık `son_gorulme` ≤ 26 saat şartı var.
- **Alarm:** olay ve bildirim tek transaction'da (savepoint'li).
  6 saatlik yeniden deneme penceresi eklendi. Aynı bildirimi iki worker
  gönderemiyor (kilit). Açılışı gönderilmemiş sorunun "çözüldü" maili gitmiyor.
  İlk tarama günü özette belirtiliyor. Gün sonu İstanbul takviminden.
- **Senkron:** beklenmeyen istisna çalışmayı `calisiyor`da bırakıyordu,
  düzeltildi. Elle senkron kilit doluyken sessizce kayboluyordu; artık 60 sn
  bekliyor, olmazsa log yazıyor. Kilit ömrü iş zaman aşımına bağlandı (330 sn).
  Bayrak istek kimliğiyle; eski iş yeni isteğin bayrağını silemiyor.
- **Excel:** bellek sınırı yalnız yükseltiliyor, düşürülmüyor.
- **Frontend — PDF:** StrictMode'da PDF bırakılmış blob adresine düşüyordu.
- **Frontend — liste:**
  - Filtre değişirken eski sonuç ve toplam gösterilmiyor.
  - Ortam başka sekmede değişince ya da yeni senkron bitince liste tazeleniyor.
  - Durum sorgusu hatası görünür oluyor.
  - Seçili filtre değeri kaybolmuyor.
- **Frontend — formlar:** "Tekrar dene" iki sorguyu da yeniliyor; Roller ve
  Kullanıcılar'da uzunluk hataları Türkçe mesajla gösteriliyor.
- **Frontend — alarm kuralları:** 30 sn'lik yenileme kaydedilmemiş girdiyi silmiyor.

Bilinçli bırakılanlar:

- Entegratör ayar ekranı türetilmiş API adresini gösteriyor. Adres sır değil,
  yalnız yönetici görüyor.
- Excel ve mailde tutar `float` ile yazılıyor; bu yalnız gösterim,
  karşılaştırma değil.
- Senkron çalışma tablosu temizlenmiyor (günde ~200 satır); saklama süresi kararı bekliyor.
- 45 günden eski ama okunmamış faturanın ERP okumadı olayı, gece senkronu
  onu yeniden okumadığı için açık kalabilir.

Sonuç: backend 288 test (SQLite ve PostgreSQL `erp_test`), frontend 180 test,
typecheck, lint (önceden var olan 4 uyarı) ve build yeşil.

## Kabul kriterleri

- [~] İlgili otomatik testler başarılı (SQLite ve PostgreSQL: backend 271,
      frontend 177); sınanamayanlar bu dosyada kayıtlı. Elle kabul örnekleri bekliyor.
- [x] Eksik okuma yokluk maili üretmiyor; yeniden çalışma mükerrer veri
      oluşturmuyor (idempotent upsert + tekil bildirim anahtarı).
- [ ] Test sonucu, muhasebe kontrolü ve kabul edilen sınırlamalar EFAT-14'e aktarılmış.

## Sonuç

2026-09-23 (Claude) — faz 1'in otomatik kabulü tamamlandı; yukarıdaki elle
yapılacak kontroller ve ERP fazı senaryoları açık.
