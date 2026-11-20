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
  Ayrıştırıcının çıktısını bir alan için düzeltirken **diğer alanların türünü
  ve kabul edilen biçimleri koru.** Bütün sayıları metne çeviren bir çözüm,
  sayısal fatura numarasını "metin", tırnaklı `"0"`'ı "tutar" yapar; daha katı
  bir okuyucu dünkü geçerli girdiyi (`1.5e3`) reddeder. Kaynak türü ayırt
  edilebilir kalsın (ayrı değer türü), dönüşüm yalnız ona ihtiyaç duyan alana
  uygulansın; eski kodun kabul ettiği biçimleri listeleyip testte sına.
  *(PRT-R12, PRT-R13: R8'in hassas JSON okuması.)*
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
  **Kilit, isteğe hazırlık için yapılan yan istekten de önce çalışır:** yasak
  çağrı için token almak bile karşı tarafa istek demektir. Denetimi istek
  çıkaran yardımcının içine koyup token'ı ondan önce alırsan yasak çağrıda giriş
  isteği gider. Testte "hiç istek gitmedi" (`assertNothingSent`) sına; "asıl uca
  gitmedi" yetmez.
  *(PRT-04, 2026-11-19: `post()` önce `token()` çağırıyordu; 32 kilit testi
  bu yüzden kırmızıydı.)*
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
- **G12 — Devralınan ortam, sınanan ya da kullanılan ayarı gölgelemesin.**
  Kütüphaneler ortam değişkenine dosyadan önce bakar: libpq'da `PGPASSWORD`
  parola dosyasının, Laravel'de süreç ortamı `.env`'in önüne geçer. "Bu
  dosyayla bağlanılabiliyor mu" diye soran denetim temiz ortamda çalışsın
  (`env -i PATH=… PGPASSFILE=…`), dosyayı kullanan betik devralınanı
  temizlesin. Tek değişkeni değil kütüphanenin okuduğu **bütün aileyi**
  temizle: libpq `PGPASSWORD` kadar `PGSERVICE` (profilde eski parola),
  `PGHOST`, `PGOPTIONS`… okur — hepsi `PG` ile başlar (`compgen -e | grep
  '^PG'`). Testte aynı adlı yanlış ya da DOĞRU bir
  ortam değişkeniyle de sına: doğrusu, geçersiz dosyayı geçerli gösterebilir.
  *(Fable F8: `.env` okuyucu süreç ortamından okuyordu. BY-001-R6: devralınan
  PGPASSWORD yedeği bozuyor, kurulumda geçersiz dosyayla bağlantıyı başarılı
  gösterip eski parola kopyasını sildiriyordu.)*
- **G13 — Önbellek anahtarı (ya da sürümü) sonucu belirleyen HER girdiyi
  kapsasın.** Token, oturum, hesaplanmış sonuç önbelleğe alınıyorsa, değişince
  sonucun anlamı değişen her alan (kullanıcı, adres, şifre — ve firmayı seçen
  VKN) anahtarı ya da sürümü değiştirmeli; yalnız ilk alımda yapılan bir
  doğrulama (firma eşleşmesi) önbellekten dönen değere uygulanmaz. Aynı alan
  veri kapsamını da belirliyorsa (aynı bağlantı kimliğine yazılan faturalar),
  geçmiş varken değişikliği reddet ya da kapsamı ayır.
  *(PRT-R1: Protel VKN'si değişince eski firmanın token'ı ve faturaları yeni
  tanımda kalıyordu.)*
- **G14 — Doğrulama sınırı saklama kolonunun gerçek sınırıyla aynı olsun.**
  Eşleyicideki üst sınır migration'daki türle birebir: Laravel
  `unsignedInteger` PostgreSQL'de işaretli `integer`'dır (en çok
  2.147.483.647); `decimal(20,4)` en çok 16 tam basamaktır ve sınır
  yuvarlamadan SONRA denetlenir. Fazla gevşek sınır taşan satırı toplu
  `upsert`'e kadar taşır ve sağlam satırları da düşürür; fazla sıkı sınır geçerli
  veriyi reddeder. Sınırın iki yanını PostgreSQL'de sına.
  *(PRT-R5: Protel ve İzibiz okuyucuları `lineCount`'u 4.294.967.295'e kadar
  kabul ediyordu — G1.)*
  Sınırı genişletirken **temsil hassasiyetini** de sına: PHP `json_decode`
  ondalıklı sayıyı float yapar, 15 anlamlı basamaktan sonrası değişir
  (`1234567890123456.23` → `…56.25`). Para tutarını float'tan geçirme; sınır
  testinde en büyük geçerli değeri sıfır olmayan kuruşla ve ham gövdeyle kur.
  *(PRT-R8: R5 düzeltmesi 16 tam basamağı kabul etti, kuruşlar bozuldu.)*
  Dış yanıtın tamamına düzenli ifade uygularken en büyük, en çok kaçışlı
  gerçekçi gövdeyle sına: PCRE `backtrack_limit` aşılınca `preg_*` `null`
  döner, geçerli yanıt "bozuk" sayılır. Doğrusal iş için `strcspn` / `strspn`.
  *(PRT-R8 öz-incelemesi: base64 PDF'teki unicode kaçışları sınırı aştırdı.)*
  Sınırı, sonucu belirleyen girdilerden birine **tek başına** uygulama: üslü
  sayıda virgülün yeri üs ile katsayının basamaklarından birlikte çıkar
  (`0.000…1e10000` = 1). "Üs 5 haneli → sınır dışı" kısayolu geçerli değeri
  sıfır, taşanı geçerli yaptı. Önce bütün girdilerle sonucu (keyfi büyüklükte,
  bcmath ile) hesapla, sonra sınırla karşılaştır; birbirini dengeleyen uç
  girdilerle sına.
  *(PRT-R15: R13'ün üs kısayolu.)*

## 2. Ön yüz

- **F1 — Eski veri ekrandayken yeni parametreyle iş yapılmasın.** Önceki
  veriyi göstermeye devam eden listelerde (`keepPreviousData`), yenileme
  sürerken indirme / Excel / ekstre gibi düğmeler kapalı olmalı; yoksa görülen
  ile inen farklı olur. Soluk göstermek tıklamayı engellemez.
  *(Firma Özeti ve Kesinti Özeti — iki ayrı bulgu.)*
  Sorgu anahtarına girmeyen sunucu durumu (aktif ortam, aktif hesap) değişince
  aynı anahtar yer tutucu olmadan tazelenir: `isPlaceholderData` false kalır.
  Düğmeyi `isFetching`'e bağla ve isteğe ekranda görünen kapsamı (ör.
  `kapsam.ortam`) ekle; sunucu uyuşmazlıkta reddetsin.
  *(PRT-R3: ortam değişirken Excel yeni ortamın faturalarını indirebiliyordu.)*
  Bağlantı yokken sorgu **beklemeye** alınır (`fetchStatus = 'paused'`):
  `isFetching` false olur, eski veri durur. Düğmeyi `isFetching || isPaused ||
  isPlaceholderData`'ya bağla; testte `onlineManager.setOnline(false)` ile sına.
  *(PRT-R9: R3'te `isPlaceholderData` yerine yalnız `isFetching` kaldı.)*
- **F3 — Yanıtta formu sıfırlayan kayıtta alanlar kayıt boyunca kilitli olsun.**
  `onSuccess`'te `reset` / `resetField` ya da tanımla anahtarlı yeniden kurulum,
  istek sürerken yazılan değeri siler. Kayıt sürerken alanları salt okunur yap
  (`readOnly={isSubmitting}`; `disabled` RHF'de değeri boşaltır). Testte yanıtı
  geciktir, beklerken `readonly`'yi ve yanıttan sonra alanın açıldığını sına.
  *(PRT-R11: Protel şifre alanı; İzibiz formunda da aynıydı — G1.)*
- **F2 — Sabit başlangıç tarihi + azami aralık, gelecekte kendiliğinden bozulur.**
  Varsayılan filtre `sabit tarih … bugün` ise aralık sınırı bir gün aşılır ve
  ekran açılışta boş kalır. Başlangıcı `max(sabit tarih, bugün − (sınır − 1))`
  hesapla, ortak yardımcıdan al; testte saati sınır gününe sabitle.
  *(PRT-R4: 2 Ocak 2027'de Protel ve İzibiz ekranları listeyi durduracaktı.)*

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
  parçası değilse sırasız karşılaştır. SQLite `varchar(n)` uzunluğunu da
  denetlemez: uzun metin yazan kod ve test yardımcıları (ör. izin listesinden
  üretilen rol adı) PostgreSQL'de düşer — şema/veri değişikliklerinde
  `DB_CONNECTION=pgsql DB_DATABASE=erp_test` ile de çalıştır.
  *(PRT-10: 64 karakteri aşan test rol adı yalnız PostgreSQL'de kırıldı.)*
- **T7 — Testin varsayımını da doğrula.** "Kütüphane bu girdiyi reddeder"
  diye yazmadan önce gerçekten reddettiğini dene.
  *(BY-001: kapanmayan tırnak ayrıştırıcıda hata sanıldı, oysa kabul ediliyordu.)*
- **T9 — "Yok" sonucunu tek bir gözlemden çıkarma.** Bir aracın çıktısında
  görünmemesi, verinin orada olmadığı anlamına gelmez; listeleme komutları bazı
  kayıtları göstermez. "X yok / X yapamaz" demeden önce ikinci bir yoldan dene
  ya da resmî belgeye bak; özellikle yetki ve davranış iddialarında.
  *(BY-002-R1: `pg_restore --list` göstermediği için veritabanı ayarları
  "yedekte yok" sanıldı; arşivdeydi. BY-002-R2: uygulama rolünün kendi
  bağlantılarını koparamadığı yazıldı; PostgreSQL buna izin verir.
  Protel 2026-11-18: Swagger'da `nullable` yazmadığı için PDF metodunun durum
  alanları "boş bırakılamaz" sanıldı; Swashbuckle `$ref` enum alanlarına
  `nullable` koyamaz — aynı denemede süzgeç alanı boş gönderilmiş ve
  çalışmıştı. Şema bir ipucudur; davranışı denemeyle ya da karşı tarafın
  yazılı cevabıyla doğrula.)*
- **T8 — Başarı ölçütü "hata verdi" değildir.** Hata yolunda mevcut verinin
  korunduğunu ve geride artık (geçici dosya, yarım kayıt) kalmadığını da sına.
- **T10 — Factory varsayılanı tasarımdaki gerçek kurguyu üretsin.** İlişkili
  kayıtlar birbirine tutarlı bağlansın (kopyanın planı varsa hedef o plana
  bağlı, yedeğin kaynağı `plan`; dosya adı öneki kaynakla aynı). Tutarsız
  örnek bugünkü testi geçirir, sonraki görevde tasarımın tersini "doğru" diye
  sınatır. Farklı kurgu gerekiyorsa adlı durum (`plansiz()`) kullan.
  *(BY-004-R3: planlı kopyanın yedeği `elle`, hedefi plana bağlı değildi.
  BY-004-R6: `plan` kaynaklı yedeğin dosya adı `erp_elle_` ile başlıyordu.)*
- **T12 — Varsayılanı bugüne bağlı ekranın testinde saati dosya düzeyinde sabitle.**
  `bugunIso()` ile beklenen değer kurmak testi gerçek takvime bağlar: bir sınır
  günü (aralık sınırı, yıl başı) geçince test düşer ya da yanlış davranışı
  "doğru" diye sınar. `beforeEach`'te `vi.useFakeTimers({ toFake: ['Date'] })` +
  `vi.setSystemTime(…)`, beklentiler sabit tarihle; sınır günü ayrı testte.
  *(PRT-R10: 2 Ocak 2027'de dört test düşüyordu.)*
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
  **Karşı tarafın hata gövdesi de sır taşıyabilir:** bazı API'ler hata
  yanıtında isteğin başlıklarını (Bearer token dahil) geri yansıtır. Dış servis
  hata gövdesini günlüğe, hata mesajına ya da istemciye taşıma; yalnız HTTP
  durumu ve doğrulanmış kısa bir kod (`/^[A-Za-z]{1,40}$/`) al. Testte yansıtılan
  token'ın istisna metninde ve bağlamında olmadığını sına.
  *(PRT-02: Protel'in 500 gövdesindeki `Headers` alanında token vardı.)*
- **D3 — Önce geçici ada yaz, doğrula, sonra taşı.** Yarım çıktı hiçbir zaman
  bitmiş gibi görünmemeli; hata ve sinyal yolunda geçici dosya silinmeli.
  Elektrik kesintisine dayanması gereken dosyada: geçici dosyayı `fsync`,
  taşıdıktan sonra **klasörü de `fsync`** (taşıma klasör girdisidir), yeni
  açılan klasörün üst klasörünü de; eşitleme hatası başarı sayılmaz. Geçici
  dosyayı `tempnam` ile açma — klasör yazılamazsa sistem geçici klasörüne düşer.
  "Klasör var" ≠ "klasör kalıcı": önceki deneme klasörü açıp eşitlemede
  kalmış olabilir; yeniden denemede de üst zinciri eşitle, "yoksa aç ve
  eşitle" diye yalnız ilk açılışa bağlama. Hata yolunu gerçek hatayla sına
  (ör. üst klasör 0300: alt klasör açılır, üst klasör eşitlemeye açılamaz).
  Zincir ama köke kadar gitmez: klasörü `fsync` için açmak **okuma izni**
  ister, kurulumun izin sözleşmesi üst klasörde yalnız geçişe (`--x`) izin
  verebilir. Zinciri kurulumun kalıcı kıldığı bir kökte durdur, kökü
  kurulum eşitlesin. Genel kural: bir düzeltme, izin sözleşmesinde olmayan
  yeni bir izin istememeli. Düzeltmeyi o sözleşmenin en dar izinleriyle sına.
  *(BY-005-R3: taşıma sonrası klasör eşitlenmiyordu. BY-005-R9: ilk eşitleme
  başarısız olunca yeniden deneme üst klasörü atlayıp başarı dönüyordu.
  BY-005-R11: R9 düzeltmesi köke kadar eşitleyince yalnız geçilebilir üst
  klasörde yazım reddedildi.)*
- **D6 — "Yok" ile "erişemiyorum"u ayır.** `file_exists`, `is_file` ve
  benzerleri üst klasöre girilemediğinde de false döner. Yokluk bir karar
  dayanağıysa (ör. "açık işlem yok → yayına devam") yalnız doğrulanmış
  yoklukta "yok" de; erişim hatası istisnadır. Testte erişimi kesilmiş klasörü sına.
  Aynı ayrım "meşgul" için de geçerli: bir çağrı başarısız olunca nedenini
  sor (`flock`'ta `$would_block`; kabukta `flock -n -E 75` ile çakışmaya ayrı
  çıkış kodu); desteklenmeyen dosya sistemi ya da izin hatası "başka işlem
  çalışıyor" diye raporlanırsa iş sonsuza dek sessizce atlanır.
  *(BY-005-R1: erişilemeyen durum dosyası "açık geri yükleme yok" sayılıyordu.
  Fable incelemesi F4: `YedekKilidi` her `flock` hatasını "meşgul" sayıyordu;
  BY-001-R5: aynı hata kardeş kabuk betiğinde kalmıştı — G1.)*
  Yokluk araması (üst klasöre çıkan özyineleme) kurulumun garanti ettiği kökte
  durmalı: kök yoksa ya da okunamıyorsa sonuç "bilinmiyor"dur, kökün üstü
  okunabiliyor diye "yok" denmez.
  *(BY-005-R12: kurulmamış kalıcı kökte "açık geri yükleme yok" dönüyordu.)*
- **D7 — Ayrı hesap, daha az güvenilen hesabın yazabildiği kodu çalıştırmasın.**
  Bir işi "ele geçirilse bile X'e ulaşamasın" diye ayrı hesaba veriyorsan, o
  hesabın çalıştırdığı / yüklediği her dosyayı say: PHP önbellekleri
  (`bootstrap/cache/*.php`), derlenmiş view'lar, `storage/` — web kullanıcısı
  bunları yazabiliyorsa sınır yoktur. Ayrı hesap kendi önbellek yolunu
  kullanır (`APP_*_CACHE`, `VIEW_COMPILED_PATH`). Bütün `storage/`'ı ayırmadan
  önce (`LARAVEL_STORAGE_PATH`) iki hesabın PAYLAŞMASI gereken dosyaları say:
  bakım modu dosyası (`storage/framework/down`) ayrılırsa servis siteyi bakım
  moduna alamaz. Yalnız çalıştırılan kodu ayır, paylaşılan veriyi değil.
  Ayrı hesap daha az güvenilen hesabın yazabildiği klasöre de YAZMASIN:
  oradaki dosya bağlantıyla değiştirilip yazım ayrı hesabın kendi
  dosyalarına yönlendirilebilir (D5). Günlükler ayrı hesabın klasörüne gider
  (`LOG_KLASORU`); dosya iznini gevşetmek (`0664`) bunu çözmez, yalnız
  izni değiştirir. Çerçevenin ve paketlerin birleşerek eklediği kanalları da
  say (Laravel 13 `monthly`, Boost `browser`, acil durum kanalı). Paylaşılması
  zorunlu dosyayı (bakım modu) yazan kod bağlantı izlemesin. Sunucudaki gerçek
  izinlere bakarak doğrula; testte uygulamayı servisin ortamıyla açıp
  `get_included_files()`'ı ve çalışma zamanındaki günlük yollarını denetle.
  *(Fable incelemesi F1, F2: servis hesabı web grubuna alınmış, tanımı
  `UMask=0027` ile uygulamanın `storage/` ve `bootstrap/cache`'ini kullanacaktı.
  Önerilen `LARAVEL_STORAGE_PATH` bakım modunu bozacağı için uygulanmadı.
  BY-003-R6: F2'nin `0664` çözümü servisi ortak günlük klasöründe bırakıyordu.)*
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
- **A3 — Laravel HTTP `retry()` koşul fonksiyonu istisna yerine `null` alabilir.**
  `throw: false` ile hata olmayan başarısız yanıtta (3xx) Laravel koşula
  `$response->toException()` geçirir; bu `null`'dır. `fn (Throwable $e)` tür
  hatasıyla çöker: parametreyi `?Throwable` yaz ve 3xx yanıtla sına.
  *(PRT-04 yönlendirme testi; aynı kalıp `IzibizIstemcisi`'nde de düzeltildi — G1.)*

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
- **K6 — Uygulanmış migration'ın yükseltme yolunu boş kurulumdan ayrı sına.**
  Önceden uygulanmış migration yeni dosya içeriğiyle yeniden çalışmaz.
  Eski şema ve örnek kayıtlarla normal `migrate` çalıştır, şema ve verinin
  korunduğunu doğrula (laravel-best-practices: *Treat Deployed Migrations
  as Immutable*, *Stage Changes That Affect Existing Rows*).
  *(BY-004, `6c96ea8`: boş kurulum geçti, yükseltmede yeni tablo ve alan gelmedi.)*
  Kapsam: kural, migration'ın **paylaşılan ya da canlı** bir veritabanında
  uygulanmış olmasına bağlıdır (skill: "For a local migration that has not
  been shared or deployed, editing … may be simpler"). Yerinde değiştirme
  kararı kanıtla verilir ve yazılır: canlıda `migrations` tablosu / sunucu
  commit'i, yerel ve test veritabanlarının durumu.
  *(BY-004-R7 yanıtı: migration canlıya gitmemişti, yerinde değiştirildi.)*
- **K7 — Entegrasyon testi paylaşılan yönetici ayarına dayanmasın.**
  Sunucu davranışı için test oturumuna veya geçici örneğe özgü ayar kullan.
  Kurulum ve temizlik aynı ayarı değiştirebiliyorsa kesinti ve tekrar
  çalıştırmada da mevcut ortamı koru (testing-best-practices: *isolation*).
  *(BY-003, `6c96ea8`: test yerel PostgreSQL yönetici rolü ayarını sıfırlıyordu.)*
- **K8 — Kardeş modülün kullanıcıya açtığı ayarı yeni modülde kendiliğinden
  kapatma.** İkinci bir sağlayıcı / ekran eklerken önce kardeşin ayar ekranını
  örnek al (adres, aralık, sınırlar); bir ayarı koda gömmek ya da gizlemek
  kullanıcı kararıdır. Güvenlik gerekçesi varsa kardeşteki korumayı (izinli
  alan adı, şifreyi yeniden isteme) uygula, ayarı kaldırma.
  *(Protel 2026-11-20: adresler config'te sabit, senkron aralığı formda yoktu;
  kullanıcı İzibiz'deki gibi ekrandan istedi.)*
