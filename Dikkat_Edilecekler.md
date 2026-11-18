# Dikkat Edilecekler — geçmiş inceleme dersleri

Kod incelemelerinde (Codex, kullanıcı) çıkan ve **tekrar edebilecek** hatalardan
süzülen kurallar. Amaç: aynı ya da benzeri hatayı ikinci kez yapmamak.

- **Kod yazan:** işe başlamadan önce ilgili bölümü okur; teslimden önce
  yazdığını bu listeye karşı denetler.
- **İnceleyen:** bulgularını ararken bu listeyi de denetim listesi olarak kullanır.
- **Yeni ders:** bir inceleme bulgusu başka yerde de tekrar edebilecek türdense
  aynı commit'te buraya eklenir (kural + nereden çıktığı + nasıl denetlenir).
  Tek seferlik, yalnız o koda özgü bulgular eklenmez. Dosya kısa tutulur.
- **Kapsam:** yalnız davranış hataları değil; **kod kalitesi ve skill kuralı
  ihlalleri** de (projeye / skill'e aykırı yapı, gereksiz soyutlama, mevcut
  bileşeni aramadan yenisini yazma, yanlış katmana konan iş…). İnceleyen skill
  kuralına aykırı bir şey bulursa kural adıyla birlikte §7'ye işlenir.

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
  Çerçevenin fonksiyonunu çağırınca onun **bütün kaynaklarını** da devralırsın:
  `Env::get` yalnız verdiğin diziye değil sürecin ortam değişkenlerine de bakar.
  "Dosyada yoksa boş döner" gibi bir sözleşme yazdıysan, ortamda aynı adlı
  değişken varken de sına.
  *(Fable incelemesi F8: `gecici-env-oku.php` `.env`'de olmayan anahtar için
  süreç ortamındaki değeri döndürüyordu.)*
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
  **Okuma gibi görünen ama durum alanı taşıyan uç da yazmadır:** isteğinde
  `setStatus`, `setErpStatus`, okundu gibi alan zorunluysa o uç izin listesine
  girmez; "mevcut değeri geri göndermek" de yazma isteğidir. Böyle bir uç
  gerekiyorsa önce karşı taraftan alanların yok sayıldığını yazılı olarak al.
  *(İzibiz istemcisi 2026-11-18: yasak listesinin dışındaki yeni ve sahte
  uçlara istek çıkabiliyordu. Protel / Süper Entegratör: PDF metodu
  `setErpStatus` zorunlu tutuyor; "mevcut değeri geri gönder" önerisi
  kullanıcı tarafından reddedildi — en küçük hata otelin işleyişini bozar.)*
- **G9 — Ayarın geçersiz değeri sessizce dönüştürülmesin.** `(int) 'abc'` = 0,
  `(int) '-10'` = −10; süre sınırında 0 "sınırsız" demektir (Symfony Process).
  Ayarı tüketen kod yalnız geçerli aralığı kabul etsin (ör. pozitif tam sayı),
  geçersizde açık hatayla dursun; boş değer G6'ya göre varsayılana döner.
  "Boş / geçerli / geçersiz" üç durumu sına.
  Aç/kapa ayarında `(bool)` kullanma: `(bool) 'off'`, `'no'`, `'hayir'`,
  `' false'` hepsi **true**'dur. `filter_var(…, FILTER_VALIDATE_BOOLEAN,
  FILTER_NULL_ON_FAILURE)` ile oku; tanınmayan değeri kapalı say ya da hata ver.
  *(BY-004-R5: geçersiz süre ayarı sınırsız çalışmaya dönüşebiliyordu.
  Fable incelemesi F9: `YEDEKLEME_AKTIF=off` modülü açıyordu.)*
- **G10 — Yazan da okuyanın doğrulamasından geçsin.** Okurken denetlenen bir
  biçim (şema, dosya, kayıt) yazılırken de AYNI denetimden geçmeli; yoksa tek
  hatalı çağrı son geçerli veriyi okunamaz bir veriyle değiştirir. Geçersiz
  kayıt dosyaya dokunmadan reddedilir; testte önceki içeriğin baytlarıyla
  korunduğunu sına.
  *(BY-005-R2: boş adımlı kayıt yazıldı, önceki geçerli durum kayboldu.)*
- **G11 — Biçim doğrulaması metnin tamamını ve geçerli değeri denetlesin.**
  PCRE'de `$` sondaki satır sonunu da kabul eder: `/…$/D` ya da `\z` kullan.
  Tarih ayrıştırıcılar taşan değeri sessizce kaydırır (30 Şubat → 2 Mart) ve
  eksik alanı tamamlar: ayrıştırdığını aynı biçime geri çevirip girdiyle
  birebir karşılaştır; saat dilimi şartını ayrıca denetle.
  *(BY-005-R4: 30 Şubat, `+03:00`, kısa mikrosaniye, sonunda satır sonu olan
  UUID / özet kabul ediliyordu.)*

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
- **T10 — Factory varsayılanı tasarımdaki gerçek kurguyu üretsin.** İlişkili
  kayıtlar birbirine tutarlı bağlansın (kopyanın planı varsa hedef o plana
  bağlı, yedeğin kaynağı `plan`; dosya adı öneki kaynakla aynı). Tutarsız
  örnek bugünkü testi geçirir, sonraki görevde tasarımın tersini "doğru" diye
  sınatır. Farklı kurgu gerekiyorsa adlı durum (`plansiz()`) kullan.
  *(BY-004-R3: planlı kopyanın yedeği `elle`, hedefi plana bağlı değildi.
  BY-004-R6: `plan` kaynaklı yedeğin dosya adı `erp_elle_` ile başlıyordu.)*
- **T11 — Testin ortam varsayımını koru.** İzin kaldırarak sınayan test
  (`chmod 0000`) root altında anlamsızdır; `SIGKILL` sabiti `pcntl` ister;
  `flock` gibi dış komut her yerde yoktur. Varsayım tutmuyorsa testi nedeniyle
  birlikte atla (`markTestSkipped`), yanlış kırmızı verme. Testin başlattığı
  alt süreci `try / finally` ile kapat; doğrulama düşerse yetim süreç kalmasın.
  *(Fable incelemesi F13: `YedekKilidiTest` / `IslemDurumuTest`.)*

## 4. Betikler ve dış komutlar

- **D1 — Dış komuta ve ağ çağrısına süre sınırı koy.** Asılı kalan süreç
  kilidi tutar ve sonraki çalışmaları sessizce engeller. Süre aşımı günlüğe
  hata olarak yazılmalı, alt süreçler sonlanmalı. Süreyi **monoton saatle**
  ölç (`hrtime`), sistem saatiyle (`microtime`, `time`) değil — saat
  ayarlanınca bekleme uzar ya da erken biter; sonlu olmayan / negatif süreyi
  baştan reddet.
  *(BY-001-R2. BY-005-R5: kilit beklemesi `microtime` ile ölçülüyordu.)*
- **D2 — Sır hiçbir yere sızmasın.** Parola komut satırında, ortam
  değişkeninde ya da günlükte olmaz. Hata mesajına ham girdiyi / hatalı
  satırı yazma; kütüphanenin hata metni de sır içerebilir. **Karşı tarafın
  günlüğünü de say:** SQL metnine yazılan parola (`ALTER ROLE … PASSWORD '…'`)
  sunucunun günlüğüne düşebilir (`log_statement`, hata günlüğü); aynı oturumda
  günlüğü kapat ya da özetlenmiş (SCRAM) değeri gönder.
  *(Fable incelemesi F3: kurulum betiği yeni parolayı düz metin SQL ile yazıyordu.)*
- **D3 — Önce geçici ada yaz, doğrula, sonra taşı.** Yarım çıktı hiçbir zaman
  bitmiş gibi görünmemeli; hata ve sinyal yolunda geçici dosya silinmeli.
  Elektrik kesintisine dayanması gereken dosyada: geçici dosyayı `fsync`,
  taşıdıktan sonra **klasörü de `fsync`** (taşıma klasör girdisidir), yeni
  açılan klasörün üst klasörünü de; eşitleme hatası başarı sayılmaz. Geçici
  dosyayı `tempnam` ile açma — klasör yazılamazsa sistem geçici klasörüne düşer.
  *(BY-005-R3: taşıma sonrası klasör eşitlenmiyordu.)*
- **D6 — "Yok" ile "erişemiyorum"u ayır.** `file_exists`, `is_file` ve
  benzerleri üst klasöre girilemediğinde de false döner. Yokluk bir karar
  dayanağıysa (ör. "açık işlem yok → yayına devam") yalnız doğrulanmış
  yoklukta "yok" de; erişim hatası istisnadır. Testte erişimi kesilmiş klasörü sına.
  Aynı ayrım "meşgul" için de geçerli: bir çağrı başarısız olunca nedenini
  sor (`flock`'ta `$would_block`); desteklenmeyen dosya sistemi ya da izin
  hatası "başka işlem çalışıyor" diye raporlanırsa iş sonsuza dek sessizce atlanır.
  *(BY-005-R1: erişilemeyen durum dosyası "açık geri yükleme yok" sayılıyordu.
  Fable incelemesi F4: `YedekKilidi` her `flock` hatasını "meşgul" sayıyordu.)*
- **D7 — Ayrı hesap, daha az güvenilen hesabın yazabildiği kodu çalıştırmasın.**
  Bir işi "ele geçirilse bile X'e ulaşamasın" diye ayrı hesaba veriyorsan, o
  hesabın çalıştırdığı / yüklediği her dosyayı say: PHP önbellekleri
  (`bootstrap/cache/*.php`), derlenmiş view'lar, `storage/` — web kullanıcısı
  bunları yazabiliyorsa sınır yoktur. Ayrı hesap kendi önbellek ve depolama
  yolunu kullanır (`APP_*_CACHE`, `VIEW_COMPILED_PATH`, `LARAVEL_STORAGE_PATH`).
  Paylaşılan klasöre farklı `umask` ile yazan ikinci hesap da sorundur: açtığı
  günlük dosyasını diğeri yazamaz. Sunucudaki gerçek izinlere bakarak doğrula.
  *(Fable incelemesi F1, F2: servis hesabı web grubuna alınmış, tanımı
  `UMask=0027` ile uygulamanın `storage/` ve `bootstrap/cache`'ini kullanacaktı.)*
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
- **P4 — Kalıcı bir biçimi dondurmadan önce onu okuyacak en zor akışı yürüt.**
  Tablo ya da dosya şeması yazarken görev metnindeki alan listesiyle yetinme;
  tasarımdaki en zor tüketiciyi (çökmeden sonra kurtarma, dizini yeniden kurma,
  saklama temizliği) kâğıt üstünde o şemayla çalıştır: "bu adımda neyi bilmem
  gerekiyor, şemada var mı?" Bir fiziksel nesneyi (dosya) birden çok kayıt
  temsil edebiliyorsa "hangisi silince dosya gider" kuralını şemayla birlikte yaz.
  *(Fable incelemesi F5–F7: durum dosyasında anahtarın bitişi ve kurtarma
  bağlamı yok; aynı hedefi paylaşan iki planın kopyası; yerel dosya silinince
  yedeğin durumu.)*

## 6. Araçlar

- **A1 — `pint --dirty` yeniden adlandırılan dosyaları atlayabilir.** Dosya
  taşıdıysan dosya adıyla çalıştır ya da `vendor/bin/pint --test` ile tümünü denetle.
  *(Pivot yeniden adlandırması: yayın, biçim hatasıyla durdu.)*
- **A2 — PHP'de ok fonksiyonu dış değişkeni tanımlandığı andaki değeriyle
  yakalar.** Sonradan değişen değişkeni görmez; değişecekse parametre olarak ver.

## 7. Kod kalitesi ve skill kuralları

- **K1 — Bugün çağrılmayan kodu yazma.** "İleride lazım olur" diye eklenen
  metot / helper / prop test edilmez, eskir ve okuyanı yanıltır
  (laravel-best-practices: *avoid speculative abstractions*). Teslimden önce
  her yeni public metodun bir çağıranı olduğunu `grep` ile doğrula.
  *(BY-004 öz-incelemesi: hiçbir yerde kullanılmayan `HedefTuru::sunucuDisi()`
  kaldırıldı.)*
- **K2 — Aynı hatayı yakalayan ikinci testi yazma.** Yeni test, mevcut bir
  testin yakalamadığı bir hatayı yakalamalı; değilse kaldır ya da mevcut
  testi genişlet (testing-best-practices: *each test detects a distinct
  defect*). Katalog / kablolama testi çoğu zaman yeter.
  *(BY-004 öz-incelemesi: "ek izin görüntülemeyi getirir" zaten genel testte
  vardı; yedekleme için yazılan kopyası kaldırıldı.)*
- **K3 — Yorumda yazan değişmez kodda zorlanmalı.** "Salt eklemeli", "tek
  satır", "yalnız X yazar" gibi bir kural yalnız PHPDoc'ta duruyorsa kural
  değildir. Modelde (güncelleme / silme engeli), veritabanında (kısıt) ya da
  tek giriş noktasında zorla ve ihlalini sına; zorlanmayacaksa öyle yazma.
  *(Fable incelemesi F10: "salt eklemeli" denetim kaydı `update()` ile değişiyordu.)*
- **K4 — Veritabanı varsayılanı yeni model nesnesinde yoktur.** `create()` /
  `firstOrCreate()` sonrası, migration'daki `default(…)` kolonları nesnede
  `null`'dır (kayıt doğrudur). Varsayılana hemen ihtiyaç varsa modelde
  `$attributes` ile yansıt ya da `refresh()` et; `null`'ı sayı gibi kullanan
  hesap (ör. "en az N yedek tut") sessizce 0 ile çalışır
  (laravel-best-practices: *Mirror Defaults Only When Unsaved Models Need Them*).
  *(Fable incelemesi F11: `YedekAyari`.)*
- **K5 — Ortak altyapıyı yeniden kullanırken kullanıcıya giden metni de oku.**
  Ortak bir yardımcı (hata kodu, bileşen) ilk yazıldığı ekranın diliyle
  konuşuyor olabilir; yeni yerde yanlış bilgi verir.
  *(Fable incelemesi F12: `KAYIT_KULLANILIYOR` mesajı "kesinti satırlarında
  kullanıldığı için" diyor; yedek hedefi silinirken de bu çıkacaktı.)*
