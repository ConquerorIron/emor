# Dikkat Edilecekler — geçmiş inceleme dersleri

Kod incelemelerinde (Codex, kullanıcı) çıkan ve **tekrar edebilecek** hatalardan
süzülen kurallar. Amaç: aynı ya da benzeri hatayı ikinci kez yapmamak.

- **Kod yazan:** işe başlamadan önce ilgili bölümü okur; teslimden önce
  yazdığını bu listeye karşı denetler.
- **İnceleyen:** bulgularını ararken bu listeyi de denetim listesi olarak kullanır.
- **Yeni ders:** bir inceleme bulgusu başka yerde de tekrar edebilecek türdense
  aynı commit'te buraya eklenir (kural + nereden çıktığı + nasıl denetlenir).
  Tek seferlik, yalnız o koda özgü bulgular eklenmez. Dosya kısa tutulur.

Her maddenin sonundaki not, dersin çıktığı yeri gösterir.

## 1. Genel

- **G1 — Bir hatayı düzeltince aynı kalıbı kullanan diğer yerleri de tara.**
  Düzeltme yalnız bildirilen yere yapılırsa hata kardeş ekranda / serviste
  yaşamaya devam eder. Düzeltmeden sonra kalıbı (`grep`) ara ve her kullanım
  için "burada da geçerli mi" kararını yaz.
  *(Pivot'ta düzeltilen "yenilenirken indirme" hatası Kesinti Özeti'nde kaldı;
  ikinci inceleme turunda yakalandı — 2026-11-16.)*
- **G2 — "Mevcut kod da böyle yapıyor" doğruluk gerekçesi değildir.** Bir
  kalıbı kopyalamadan önce davranışının doğru olduğunu doğrula; yanlışsa
  kopyalama, kaynağını da not et.
  *(BY-001: `.env` okuma `deploy.sh`'tan kopyalandı; ikisi de hatalıydı.)*
- **G3 — Bir biçimi elle ayrıştırma; projenin kullandığı ayrıştırıcıyı kullan.**
  `.env`, tarih, sayı, CSV, JSON… `grep | cut | tr` ya da el yazımı düzenli
  ifade, tırnak / kaçış / yorum kurallarını kaçırır. Bu, ayrıştırıcının
  **üstündeki dönüşüm katmanı** için de geçerlidir: çerçevenin yaptığı
  dönüşümü elle yeniden yazma, çerçevenin fonksiyonunu çağır.
  *(BY-001-R1: tırnak içeren ve satır sonu yorumlu parola yanlış okundu.
  BY-001-R3: ayrıştırıcı doğru kullanıldı ama Laravel'in `env()` dönüşümü elle
  yazıldı; `true` / `false` dalları eksik kaldı.)*
- **G4 — Değişken uzunluktaki veriyi sabit alana yazan kodu en uzun geçerli
  değerle dene.** Alan sınırı 200 karakterse 200 karakterle; kısa örnekle
  geçmesi kanıt değildir.
  *(Kesinti ekstresi PDF'i: 182 karakterlik proje adı tarih, para birimi ve
  işveren alanlarını sayfa dışına itti.)*
- **G5 — Yetki denetimi izin verilen TAM kümeyle karşılaştırsın.** "Gereken
  girdi var mı" yetmez; fazlası da yok olmalı. İzin bitleri doğru görünürken
  ek ACL girdisi, NOLOGIN rolde SUPERUSER, beklenmeyen rol üyeliği, içeriği
  aynı ama herkesin yazabildiği sistem dosyası erişimi açar. Denetleyen her
  kodu "buraya fazladan ne eklenirse yakalanmaz?" sorusuyla dene.
  *(BY-003-R2…R4: üç ayrı denetim aynı nedenle "her şey yerinde" dedi.)*
- **G6 — `env('X', varsayılan)` boş değerde varsayılanı kullanmaz.** `.env`'de
  `X=` yazılıysa Laravel boş metin döndürür; yol boş, `(int)` ile süre 0 olur.
  Boş metni ve `null`'ı varsayılana çevir, ama **geçerli değeri koru**:
  `?:` yalnız boşun asla geçerli olmadığı metinlerde (yol, ad) uygundur;
  `false` / `0` geçerli olabilen ayarda (aç/kapa, sayı) `?:` açıkça verilmiş
  `false` / `0`'ı da varsayılana çevirir — orada `null` ve `''`'yi ayrıca
  denetle. "Tanımsız / boş / verilmiş (false, 0 dahil)" durumlarını sına.
  *(BY-004-R2: `.env.example`'daki satırı açan kurulumda yedek klasörü boştu.
  BY-004-R4: kalıp `EFATURA_SENKRON_AKTIF`'e körce uygulansa kapatılmış
  senkronu açardı.)*
- **G7 — Benzersizlik kuralında NULL olabilen kolon tekrarı engellemez.**
  PostgreSQL ve SQLite NULL'ları birbirinden farklı sayar: `unique(a, b, c)`
  `c` boşken aynı `(a, b)`'yi tekrar kabul eder. Boş durum için koşullu
  benzersiz indeks ekle (`… WHERE c IS NULL`) ve iki veritabanında sına.
  *(BY-004-R1: plansız yedek kopyası aynı hedefe iki kez yazılabiliyordu.)*
- **G8 — Dış sistemde "yalnız okuma" kuralı izin listesiyle uygulanır.**
  Yasak listesi (`read-flag`, `/send`…) bugün bilinen yazma uçlarını tutar;
  karşı taraf yeni bir uç eklediğinde o uç açık kalır. İstek çıkaran TEK yerde
  yöntem + yol için izin listesi tut, gerisini istek göndermeden reddet; testte
  bilinmeyen bir uç için "hiç istek gitmedi"yi sına.
  *(İzibiz istemcisi 2026-11-18: yasak listesinin dışındaki yeni ve sahte
  uçlara istek çıkabiliyordu; Protel / Süper Entegratör de aynı kuralla yazılacak.)*

## 2. Ön yüz

- **F1 — Eski veri ekrandayken yeni parametreyle iş yapılmasın.** Önceki
  veriyi göstermeye devam eden listelerde (`keepPreviousData`), yenileme
  sürerken indirme / Excel / ekstre gibi düğmeler kapalı olmalı; yoksa görülen
  ile inen farklı olur. Soluk göstermek tıklamayı engellemez.
  *(Firma Özeti ve Kesinti Özeti — iki ayrı bulgu.)*

## 3. Testler

- **T1 — Varlığı değil davranışı sına.** "Düğme var" yetmez; tıklanınca doğru
  çağrının doğru parametrelerle yapıldığını doğrula.
- **T2 — Test adı sınanan davranışı söylesin;** davranış değişince ad da değişsin.
- **T3 — Dosya üreten kodda içeriği sına.** Yanıt türü ve dosya imzası, içeriğin
  doğru / okunur olduğunu göstermez (PDF, Excel).
- **T4 — Toplama testlerinde birden çok sıfır olmayan, ondalıklı değer kullan.**
  Tek değerli ya da sıfırlı örnek toplama ve yuvarlama hatasını gizler.
- **T5 — Regresyon testinin düzeltmeden önce kırmızı olduğunu gör.** Hiç
  kırmızı görülmemiş test, hatayı yakaladığını kanıtlamaz.
- **T6 — Sıraya bağlı doğrulamada veritabanı farkını hesaba kat.** Testler
  SQLite, canlı PostgreSQL; Türkçe harflerde sıra farklıdır. Sıra davranışın
  parçası değilse sırasız karşılaştır.
- **T7 — Testin varsayımını da doğrula.** "Kütüphane bu girdiyi reddeder"
  diye yazmadan önce gerçekten reddettiğini dene.
  *(BY-001: kapanmayan tırnak ayrıştırıcıda hata sanıldı, oysa kabul ediliyordu.)*
- **T9 — "Yok" sonucunu tek bir gözlemden çıkarma.** Bir aracın çıktısında
  görünmemesi, verinin orada olmadığı anlamına gelmez; listeleme komutları bazı
  kayıtları göstermez. "X yok / X yapamaz" demeden önce ikinci bir yoldan dene
  ya da resmî belgeye bak; özellikle yetki ve davranış iddialarında.
  *(BY-002-R1: `pg_restore --list` göstermediği için veritabanı ayarları
  "yedekte yok" sanıldı; arşivdeydi. BY-002-R2: uygulama rolünün kendi
  bağlantılarını koparamadığı yazıldı; PostgreSQL buna izin verir.)*
- **T8 — Başarı ölçütü "hata verdi" değildir.** Hata yolunda mevcut verinin
  korunduğunu ve geride artık (geçici dosya, yarım kayıt) kalmadığını da sına.

## 4. Betikler ve dış komutlar

- **D1 — Dış komuta ve ağ çağrısına süre sınırı koy.** Asılı kalan süreç
  kilidi tutar ve sonraki çalışmaları sessizce engeller. Süre aşımı günlüğe
  hata olarak yazılmalı, alt süreçler sonlanmalı.
  *(BY-001-R2.)*
- **D2 — Sır hiçbir yere sızmasın.** Parola komut satırında, ortam
  değişkeninde ya da günlükte olmaz. Hata mesajına ham girdiyi / hatalı
  satırı yazma; kütüphanenin hata metni de sır içerebilir.
- **D3 — Önce geçici ada yaz, doğrula, sonra taşı.** Yarım çıktı hiçbir zaman
  bitmiş gibi görünmemeli; hata ve sinyal yolunda geçici dosya silinmeli.
- **D4 — Eskiyi, yenisi gerçekten çalıştığı doğrulanmadan silme.** Taşıma /
  değiştirme işinde yeni kopyanın var olması doğru olduğunu göstermez (T9);
  eski kopya son çalışan kopya olabilir. Silme, yeninin gerçek kullanımla
  (bağlantı, okuma, açılış) doğrulanmasından sonra; iki kopya farklıysa hiçbiri
  silinmez, elle bakılır.
  *(BY-003-R1: hedefte bozuk parola dosyası varken geçerli eski kopya silindi.)*
- **D5 — Root, başkasının değiştirebildiği yolda bağlantı izleyerek işlem
  yapmasın.** `chown`, `chmod`, `setfacl`, `cp`, `>` hedefteki sembolik
  bağlantıyı izler; yolu yazabilen hesap root'u yönetilen alanın dışına
  yönlendirir (CWE-59). Yolun üst klasörleri yalnız root'un olsun; dokunmadan
  önce türü denetle (bağlantı / sabit bağlantı → dur); root yalnız `chown -h`
  yapsın, izni ve içeriği nesnenin sahibi yazsın. Testte, o hesabın koyduğu
  bağlantıyla root dosyasının değişmediğini sına.
  *(BY-003-R5: servis hesabının `gizli/pgpass` ve `yedekler/` yerine koyduğu
  bağlantılar, root'a ait dosya ve klasörü servise geçirdi.)*

## 5. Tasarım dokümanları

- **P1 — Aynı kural iki bölümde çelişmesin.** Bir bölümde "onaysız
  başlatılmaz" yazıp akışta otomatik başlatma; dokümanı teslim etmeden önce
  kuralları akışlarla karşılaştır.
  *(Yedekleme tasarımı §6.4 ↔ §7.6.)*
- **P2 — Bir işlem, değiştirdiği şeye dayanmasın.** Geri yüklenen
  veritabanında duran kilit, kuyruk, oturum, mail ayarı işlem sırasında kaybolur.
  "Bu adım çalışırken neye ihtiyaç duyuyor ve o şey o anda yerinde mi" diye sor.
- **P3 — Adımların sırasını "arada ne olur" sorusuyla denetle.** İki adım
  arasında girilen kayıt, kopan bağlantı, yeniden başlayan sunucu.
  *(Geri yükleme öncesi yedek bakım modundan önce alınıyordu.)*

## 6. Araçlar

- **A1 — `pint --dirty` yeniden adlandırılan dosyaları atlayabilir.** Dosya
  taşıdıysan dosya adıyla çalıştır ya da `vendor/bin/pint --test` ile tümünü denetle.
  *(Pivot yeniden adlandırması: yayın, biçim hatasıyla durdu.)*
- **A2 — PHP'de ok fonksiyonu dış değişkeni tanımlandığı andaki değeriyle
  yakalar.** Sonradan değişen değişkeni görmez; değişecekse parametre olarak ver.
