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
  Aynı aile: Laravel bağlantı tanımında `url` (DB_URL) ayrı alanların
  (`username`, `database`…) ÜSTÜNE yazılır. Bir alanı değiştiren ya da ham
  okuyan kod adresli kurulumda etkisizdir. Önce tanımı çerçevenin
  ayrıştırıcısıyla (`ConfigurationUrlParser`) somut hâle getir, sonra
  değiştir; testte varsayılan ayrı alanlar + adresli tanım kullan.
  *(Codex BY-028-R4: servis rolüne geçiş DB_URL'de uygulama rolüne dönüyordu;
  pg_dump da ham tanımın varsayılanlarına gidiyordu — G1.)*
  `url` tek gölge değil: PostgreSQL bağlayıcısı DSN'i `connect_via_database` /
  `connect_via_port`, `read` / `write` alt tanımlarından da kurar. Bağlayıcının
  DSN'i nereden kurduğunu oku ve etkin değeri gerçek bağlantıyla sına
  (`current_database()`), tanımdaki alanla değil.
  *(Codex BY-028-R6: çocuk migration süreci canlı veritabanına bağlanıyordu.)*
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
- **G15 — İzin listesi değerin türünü ve biçimini de denetlesin; ayrıştırıcının
  kabul ettiğine güvenme.** Anahtar izinli diye değeri serbest bırakma: dizi /
  nesne değer, içinde yasak anahtar taşıyabilir; serbest alana tür (metin) ve
  biçim (UUID, tarih) şartı koy. Adreste `parse_url`'in "host" dediğine güvenme:
  ters eğik çizgi, `%2f`, boşluk içeren host'u da döndürür ve "izinli alan
  adıyla bitiyor" denetimini geçer. Alan adını izinli karakter kümesiyle
  (`[a-z0-9.-]`) doğrula; testte iç içe dizi değeri ve
  `https://kotu.com\.izinli.com.tr` örneğini sına.
  *(Fable incelemesi PRT-F4, PRT-F5: Protel kilidi `uuid` için dizi kabul
  ediyor, adres kuralı `evil.com\.protel.com.tr`'yi kaydediyordu.)*
  Tanımın doğrulaması **her yolda** ilk dış istekten önce, tek yöntemde olsun:
  ilk kurulum / ilk kullanımda güven gibi yan yolun isteği (ssh-keyscan, token
  alma) de doğrulamadan sonra gelir. Testte o yan yolu açan durumla (anahtar
  kayıtlı DEĞİL) geçersiz tanımı dene: `Process::assertNothingRan()`.
  *(Gerçek sunucu kabulü 2026-11-22: SFTP ilk "Bağlantıyı Sına", geçersiz
  klasörlü ya da kimlik bilgisiz tanımda ssh-keyscan çalıştırıp anahtarı
  kaydediyordu; doğrulama yalnız sftp yolundaydı.)*
- **G16 — Akış kopyasının başarısını `feof()` ile ölçme.** Farklı dosya
  sistemleri arasında (`ext4` → `tmpfs` / ağ paylaşımı) `stream_copy_to_stream`
  bütün baytları kopyaladığı hâlde kaynakta `feof()` false kalabiliyor; kopya
  "başarısız" sayılır. Ölçüt kopyalanan bayt sayısının kaynağın boyutuna
  (`fstat`) eşitliği. Kopyalayan kodu ayrı bir dosya sistemine (`/dev/shm`)
  yazarak sına; aynı diskte geçmesi kanıt değildir.
  *(BY-008 `AtomikDosya::kopyala`; NAS bağlayıcısını yazan ajan yakaladı.)*
- **G17 — Zamanı yazmadan önce saat dilimini değiştirme; anı koru.** Laravel
  zaman damgasını uygulamanın saat dilimiyle (UTC) metne çevirir, nesnenin
  kendi dilimine bakmaz: İstanbul'a çevrilmiş bir an veritabanına 3 saat kayık
  yazılır. Dış kaynaktan okunan zamanı (dosya adı, yanıt) kaynağın diliminde
  ayrıştır, uygulamaya o anı ver; gösterim dönüşümünü ekranda yap. Testte
  kaydedilip geri okunan anı UTC ile karşılaştır.
  *(BY-013 `yedek:eski-yedekleri-al`: addan okunan UTC zaman İstanbul'a
  çevrilip kaydedilince 13:10 oldu.)*
  Eloquent'e dilimli (`+03:00`) Carbon vermek de aynı kaymadır: dosya adı için
  İstanbul'da üretilen ya da manifestten ofsetle okunan zaman modele `->utc()`
  ile verilir. Testte beklentiyi iki tarafı aynı (kayık) yoldan üreterek
  kurma; sabit bir UTC anıyla karşılaştır.
  *(Fable incelemesi YF-01: yedeğin `alinma_zamani` üç yerde 3 saat ileri
  yazılıyordu; BY-013'te yalnız içe alma düzeltilmişti — G1.)*
- **G18 — Denetle-ve-yap tek atomik adım olsun.** Denetim ile eylem arasında
  kilit beklemesi ya da eşzamanlı istek varsa son denetim kilit altında, eylemden
  hemen önce tekrarlanır (beklerken yetkisi alınan kullanıcı). "Oku-sil"
  (`Cache::pull` iki ayrı işlemdir) tek kullanımlık değeri iki isteğe
  tüketebilir: `Cache::lock` altında önce eşleşmeyi denetle, eşleşmezse
  TÜKETME, eşleşirse bir kez tüket. Testte bekleme anını `Sleep` sahtesiyle ya
  da kilidi testin kendisi tutarak kur.
  *(Codex BY-025-R2: yetki kilitten önce denetleniyordu. BY-021-R1: Google
  dönüş durumu.)*
  Tek kullanımlık değerlerin "görüldü" listesini kapasiteyle KIRPMA: süresi
  dolmamış bir değer listeden düşerse yeniden kullanılabilir. Yalnız süresi
  dolanı at, liste doluysa yenisini açık hatayla reddet. Yeniden denenebilir
  istekte istemci eylem başına bir anahtar tutar; sunucu kimliği anahtar +
  eylemden türetir, "var mı" bakışı ile yazım aynı kilit altındadır.
  *(Codex BY-030-R2: 64'lük liste süresi dolmamış kanıtı unutuyordu.
  BY-014-R2, BY-030-R3: yeniden deneme ikinci iş bırakabiliyordu.)*
  Eşzamansız bir adımdan (Web Crypto, fetch) SONRA depoya yazan taşıma,
  beklemeden önce aldığı anlık kopyadan yazmasın: yazımdan hemen önce, aynı
  eşzamanlı blokta kaynağı yeniden oku, aynıysa yaz + kaynağı sil. Yoksa başka
  örneğin işlediği (kabul edilip silinen) kaydı geri getirir. Testte ilk
  eşzamansız adımı kontrollü bir Promise ile geciktir, iki örnek aynı depoda.
  *(Codex BY-014-R6: iki sayfanın v1→v2 taşıması.)*
  `await`'siz blok yalnız o sekmenin JavaScript ortamında atomiktir; sekmeler
  ayrı süreçlerdir ve `localStorage`'da kilit yoktur (HTML standardı). Ortak
  depoda okuyup-değiştirip-yazan HER yol (taşıma, yeni kayıt, kabul temizliği,
  süresi geçenin silinmesi) tek bir adlı Web Lock altında olsun; kilit yoksa
  depoyu ortak kullanma, aynı garantiyi varsayma. Testte ayrı süreci, son
  okumanın hemen ardından çalışan ve kilidi gözeten bir sahteyle kur.
  *(Codex BY-014-R7: R6'nın yeniden okuması pencereyi yalnız daralttı.)*
  Kilit sırayı verir, kimliği değil: bir olayın sonucu olarak yapılan
  temizlik (202 kabulü, tamamlanma) yalnız O olayın kaydını silsin — sınıfa
  (eylem özeti, tür) göre değil kayıt kimliğine (UUID) göre süz. Yoksa eski
  isteğin geciken yanıtı aynı sınıfın sonradan açılmış kaydını siler. Testte
  aynı eylem için iki kimlik üret, eskinin kabulünü en son ver.
  *(Codex BY-014-R8: eski isteğin geciken 202'si yeni anahtarı siliyordu.)*
- **G19 — Aynı fiziksel nesneyi tanıyan ölçüt tek yardımcıda ve gerçek konuma
  göre olsun.** Yol metniyle karşılaştıran bir kod ile `realpath` / aygıt +
  düğüm ile karşılaştıran kardeşi birlikte yaşarsa (sondaki `/`, `..`,
  sembolik bağlantı) aynı dosya "bağımsız kopya" sayılır ve tek arşiv silinir.
  Testte aynı klasörün üç yazımını dene.
  *(Codex BY-008-R1.)*
  Bağımsızlık varsayan karar ("iki kopya", "kalan kopya") kayıt kimliğine değil
  bu ölçüte dayansın: aynı depoyu iki kayıtla tanımlamaya izin veren tanım
  kopya sayısını şişirir, silme kararı son kopyayı götürür. Tanımı kayıtta
  reddet; eskiden kalan çakışmada ortak nesneyi silme, görünür hata yaz.
  Testte aynı yeri farklı ad ve yazımla iki kez tanımla.
  *(Codex BY-010-R7: aynı klasörü gösteren iki yerel hedef.)*
  "Bütün eşdeğerlikleri kanıtlayamıyorum" korumayı tümden kapatma gerekçesi
  değildir: kanıtlanabilen alt kümeyi (normalleştirilmiş aynı tanım: aynı S3
  uç nokta + kova + önek, aynı sunucu + kullanıcı + klasör) uygula, kalanını
  (alan adı takma adı, türler arası) bilinen sınır olarak yaz. D4 yönü: fazla
  engelleme bir 409'dur, eksik engelleme veri kaybı. Test çifti (bellek
  hedefi) gerçek bağlayıcının adresleme kuralını taşımıyorsa fiziksel sonucu
  gerçek bağlayıcı + sahte HTTP deposuyla sına.
  *(Codex BY-010-R8: uzak türler aynı depo denetiminin dışında bırakılmıştı.)*
- **G20 — Kimlik, konum ve sır kararında PHP `==` / `!=` kullanma.** Sayı
  görünümlü iki metni sayı olarak karşılaştırır: `"001" == "1"`, `"1e1" ==
  "10"`. Alan tipine uygun normalleştir (port → tam sayı, boş port →
  bağlayıcının kendi varsayılanı, yol → sondaki `/`), sonra `===`. Konumu
  belirleyen alanların listesini bağlayıcının GERÇEKTEN kullandıklarından
  çıkar (port, kullanıcı, yol biçimi); listede olmayan alan değişikliği
  denetimi atlar. Testte `"001"` / `"1"`, `"22"` / `22` örneklerini dene.
  *(Codex BY-016-R3: kopyalı hedefte port ve `001 → 1` değişikliği geçiyordu.)*
- **G21 — Dış kayıt değişebilen bir varlığı kimliğiyle anıyorsa anlamının
  izini de taşısın.** Olay günlüğü "hedef 5'e kopyalandı" derken hedef 5'in
  adresi sonra değişebilir; geri yükleme / yeniden kurma aynı kimliği bugünkü
  adrese bağlar. Kimliğe anlam veren özniteliklerin izini (konum, hesap) kayda
  yaz, bağlarken izi karşılaştır; izsiz eski kaydı "doğrulanamadı" say. Aynı
  ailede: kimlik alanı (hesap e-postası) ilk kayıtta isteğe bağlı bırakılırsa
  sonra doğrulanamaz ve yenilemeyi kilitler — ilk kayıtta zorunlu tut, eski
  kaydı somut kanıtla (klasöre erişim) uzlaştır.
  *(Codex BY-028-R5: yeni konumdaki kopya eski adrese `tamam` bağlanıyordu.
  BY-021-R3: hesabı okunamayan ilk Drive bağlantısı yenilenemiyordu.)*
  **Erişim kanıtı sahiplik kanıtı değildir:** paylaşılmış klasör başka hesaba
  da 200 döner. "Aynı hesap / aynı sahip" kararında sahiplik alanını açıkça
  iste (`ownedByMe === true`, yazma / okuma yetenekleri, kayıtlı bir nesneye
  erişim); alan gelmezse kanıt yok say, eski bağlantıyı koru. Testte sahibi
  olmadığı hâlde 200 dönen hesabı sına.
  *(Codex BY-021-R4.)*
  **"Kullanılamaz" ile "yok" ayrı kümelerdir.** Doğrulanamayan durum
  (`konum_belirsiz`) kullanım kararlarında (geri yükleme, silme, saklama) yok
  sayılır; ama bir kaydı `silindi` / kayıp yapan kararda nesne fiziksel olarak
  durabilir. İki kümeyi ayrı ve tek yerde tanımla (`yedegiTutanlar()`), her
  "silindi" kararını ona bağla; testte "kullanılamaz ama var" kayıtla listede
  görünürlüğü ve yazılan silme olayını sına.
  *(Codex BY-028-R7: konumu belirsiz son kopya yedeği listeden düşürüyordu.)*
  Kümeyi tek yerde tanımlamak yetmez: "nesne fiziksel olarak var mı" sorusuna
  dayanan **her** kararı say — yalnız `silindi` değil, dayanağı silme (paket
  dönüşümü), son kopyayı silme, hesabı / konumu değiştirme. Denetim:
  `where('durum', …Tamam` geçen her yeri grep'le, her biri için "kullanım
  kararı mı, varlık kararı mı" yaz. Kümenin üstüne uygulanan ikincil eşitlik
  de ("ana klasör kopyası = yerel dosya") yalnız konumu DOĞRULANMIŞ üyeye
  uygulanır. Yeni durum değerini migration getiriyorsa testi eski şemayla
  kurup gerçek ileri migration'ı çalıştırarak yaz.
  *(Codex BY-024-R8, BY-028-R8, BY-021-R5: konumu belirsiz kopya paket
  korumasının, ana klasör taramasının ve Drive hesap kanıtının dışında kaldı.)*
  **Eşlenemeyen dayanak "yok" değildir.** Dış kaydın bağlandığı tanım
  bulunamayınca kaydı atlama ya da sayaca indirme: kullanıma kapalı ama
  görünür tut, eşleşmeyi yalnız değişmez kimlikle (iz) yeniden kur. Geri
  yüklenen veritabanında sıra eski değerinden devam eder; eski kimlik başka
  kayda verilebilir — kimlik eşitliği aynı nesnenin kanıtı değildir.
  *(Codex BY-028-R9: hedefi geri yüklenen veritabanında olmayan kopya.)*
  Yeniden eşleme (iz, ad, yeni kimlik) birden çok kaynak kaydı tek hedef
  anahtarına bağlayabiliyorsa kaynakları önce fiziksel nesne kimliğine (konum
  izi + uzak kimlik) göre topla; ölü kaynak yaşayanı ezemez, fazladan yaşayan
  nesne kaybolmadan ayrı kayıtta kalır. Eşlenmiş kayda yazılan silme olayı da
  nesnenin kimliğini taşısın; yoksa yanlış kaynağa düşer ve silinen nesne
  sonraki yeniden kurmada geri gelir. Testte iki kaynak, iki olay sırası ve
  eşlenmiş kaydın silinmesini sına.
  *(Codex BY-028-R10: iki olay hedefi tek güncel hedefe düşünce yaşayan
  Drive kopyası silinmiş başka nesneyle eziliyordu.)*

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
- **F4 — İki yönlü eşlemede programla verilen değerin olayı geri yansıtılmasın.**
  Kaydırma gibi olaylar bir kare SONRA gelir; o arada kaynak ilerlemiş olur
  (yumuşak kaydırma) ya da hedef değeri kırpar (farklı azami). "Değer aynıysa
  olay gelmez" varsayımı yetmez: atadığın değeri (atamadan sonra okunmuş
  haliyle) sakla, hedefin o değerdeki olayını yut. Testte kaynak yankıdan önce
  ilerlesin ve hedefin aralığı kısa olsun.
  *(DataTable üst kaydırma çubuğu: sağa kayarken tablo geri çekilip titriyordu.)*

- **F6 — Tarayıcıda süzülen büyük sonuçta türetilen veri her çizimde
  hesaplanmasın.** Süzme, seçenek listesi, toplam ve özet `useMemo` ile
  yalnız kaynağı değişince hesaplanır; sıralamada `localeCompare(…, 'tr')`
  yerine modül düzeyinde tek `Intl.Collator` (her çağrıda yeniden kurulur);
  yüzlerce satırlık özet tablosu da sayfalanır. Sınırı gerçek boyutta ölç:
  canlı tam yıl satır ve grup sayısıyla (12.000 satır, 1.442 firma grubu).
  *(Gelen Fatura Raporu 2026-11-27: Firma / Entegratör / Tarih sekmelerine
  her tıklama 12.000 satırı yeniden süzüp sıralıyor, firma özeti tek seferde
  ~39.000 hücre çiziyordu.)*

- **F5 — İş başlatan düğmeyi bekleme durumuyla da kilitle; `isPending` tek
  başına yetmez.** İstek kimliği dönene kadar düğme açık kalırsa aynı iş iki kez
  bırakılır; TanStack'in bekleme durumu da bir sonraki çizimde gelir, iki hızlı
  tıklamayı kaçırır. Eşzamanlı `useRef` bayrağı + `isPending`; testte yanıtı
  geciktir, art arda iki `fireEvent.click` → tek istek.
  *(Codex BY-015-R1.)*

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
  PostgreSQL `jsonb` anahtar sırasını da korumaz (anahtarları kendi sırasına
  dizer): JSON kolondan okunan diziyi `assertSame` ile değil sırasız
  (`assertEquals`) karşılaştır.
  *(BY-021 Google Drive testi yalnız PostgreSQL'de kırıldı.)*
  `ORDER BY … DESC`'te NULL'lar PostgreSQL'de başta, SQLite'ta sondadır: NULL
  olabilen kolonda sıralamayı açık yaz (`coalesce(…)`, `kolon is null`).
  PostgreSQL'de işlem içindeki hatalı ifade bütün işlemi iptal eder; hatayı
  yakalayıp sürmek COMMIT'i sessizce ROLLBACK yapar. "Yazılamazsa yalnız
  dosyaya yaz" gibi yutulan yazımı kendi savepoint'inde yap (iç içe
  `DB::transaction`); testi gerçek bir sorgu hatasıyla PostgreSQL'de koş.
  *(Fable incelemesi YF-17: başarısız yedekler listenin hep tepesindeydi.
  YF-12: denetim kaydı hatası ayar değişikliğini sessizce geri alıyordu.)*
  SQLite `date` kolonunu metin saklar; Eloquent `date` cast'i yazarken saat de
  ekler (`2026-11-22 00:00:00`): `where('tarih', '<=', '2026-11-22')` o günü
  SQLite'ta dışarıda bırakır, PostgreSQL'de bırakmaz. Gün sınırını
  `< ertesi gün` diye yaz; testte sınır gününe denk gelen kayıt koy.
  *(EK-10 hatırlatması: eşik günündeki fatura yalnız SQLite'ta sayılmıyordu.)*
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
- **T13 — Dış servis istemcisinde hata yollarını da sına.** Mutlu yol ve kilit
  testleri yetmez: bağlantı hatası (`Http::failedConnection`), 5xx / 429'da kaç
  kez denendiği (`assertSentCount`), token süresi dolunca yeniden giriş.
  Uygulamanın ele aldığı her hata dalına bir test (laravel-best-practices
  http-client: *test timeouts, connection failures, and error responses*).
  *(Fable incelemesi PRT-F13: Protel istemcisinde bu üçü de sınanmıyordu.)*

- **T14 — Hata üreten test kancasının kendisinin çalıştığını doğrula.**
  Kancanın hatası (ör. `Schema::drop` PostgreSQL'de bağımlı tablo yüzünden
  reddedilir) sınanan koda sızarsa kod onu başka bir hata sanar, test yanlış
  gerekçeyle geçer ya da düşer. Kancanın istisnasını yakalayıp sakla, testte
  `assertNull($kancaHatasi)` ile kurgunun gerçekten kurulduğunu sına.
  *(BY-028: 8. adım hata kancası yalnız PostgreSQL'de çalışmıyordu.)*
  Bir tabloya yabancı anahtarla bağlanan YENİ tablo eklerken o tabloyu
  `Schema::drop` ile düşüren testleri tara (G1): PostgreSQL bağımlısı olanı
  düşürmez, kanca sessizce başka hata üretir. Çocuğu önce düşür.
  *(EK-03: `efatura_islem_gecmisi` gelince senkron yazma-hatası testi yalnız
  PostgreSQL'de düştü.)*
  Aynı işte: bağlantı bırakma (`DB::purge`) yalnız etkilenen veritabanına
  bağlı bağlantılara uygulanır; hepsini bırakmak testi saran işlemi de koparır.
- **T15 — Yeniden başlatmayı taklit eden test bellekteki durumu sıfırlasın.**
  `Artisan::call` aynı komut nesnesini yeniden kullanır; komut özelliğinde
  tutulan durum (son çalışma zamanı) "süreç yeniden başladı" testini yanlış
  yeşil yapar. `app(Kernel::class)->setArtisan(null)` ile yeni nesne kur.
  *(Codex BY-009-R1 düzeltmesinin ilk testi bu yüzden yanlış yeşildi.)*
- **T16 — İkinci bağlantıyla eşzamanlılık sınarken test işlemini hesaba kat.**
  Satır kilidi SQLite'ta görünmez; PostgreSQL'de ikinci bağlantıyla sına ve
  ona `lock_timeout` koy (bekleme 55P03 olarak görünsün, süreç asılı
  kalmasın). `LazilyRefreshDatabase` ilk sorguda `migrate:fresh`
  çalıştırabilir: ikinci bağlantıdan yazmadan önce test bağlantısında bir
  sorgu çalıştır. İkinci bağlantının satırlarını, test işlemi geri alındıktan
  sonra çalışan bir `beforeApplicationDestroyed` geri çağrısında temizle.
  *(BY-016-R3 kilit testi: temizlik test işleminin satırlarını bekleyip asıldı.)*

## 4. Betikler ve dış komutlar

- **D1 — Dış komuta ve ağ çağrısına süre sınırı koy.** Asılı kalan süreç
  kilidi tutar ve sonraki çalışmaları sessizce engeller. Süre aşımı günlüğe
  hata olarak yazılmalı, alt süreçler sonlanmalı. Süreyi **monoton saatle**
  ölç (`hrtime`), sistem saatiyle (`microtime`, `time`) değil — saat
  ayarlanınca bekleme uzar ya da erken biter; sonlu olmayan / negatif süreyi
  baştan reddet.
  *(BY-001-R2. BY-005-R5: kilit beklemesi `microtime` ile ölçülüyordu.)*
  Alt bütçenin tabanı tüketicinin kabul eşiğine eşitse (taban 1 sn, tüketici
  `kalan < 1` → ret) ilk denetimde biter: taban, eşik + hazırlık payından
  büyük olsun. İki denetim arasında ilerleyen sahte saatle sına.
  *(Codex BY-016-R5: sınama dosyasının temizliği hiç çağrılmıyordu.)*
  "Süreç ölünce / durunca ne olur" iddiasını alt süreçlerle birlikte düşün:
  systemd'nin `KillMode` varsayılanı (`control-group`) durdurmada pg_dump /
  gpg gibi alt süreçleri de öldürür (`KillMode=mixed`); PHP `fopen` kilit
  tanıtıcısını alt süreçlere miras bırakır, ebeveyn ölse de kilit çocuk
  bitene kadar tutulur (`'re'` / `'ce'` kipi, close-on-exec).
  *(Fable incelemesi YF-23, YF-36.)*
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
  **Tarayıcı deposu da bir günlüktür:** `localStorage`'a yazılan eşleştirme
  anahtarı ham parametre (serbest metin) değil özet olsun. Depo adını
  sürümlerken eski sürümün hâlâ geçerli kaydının gereken alanlarını hassas
  olmayan biçimde (özet + kimlik + zaman) yeni sürüme taşı, ham kaydı her
  durumda sil — körce silmek bekleyen isteğin kimliğini kaybettirir; testte
  geçerli eski kayıtla yükselt ve kimliğin korunduğunu sına. Süresi geçen kaydı
  yalnız yazımda değil okumada da fiziksel olarak kaldır. Testte yanıtı
  kaybolan isteğin ardından depodaki hiçbir değerde düz metin olmadığını sına.
  *(Codex BY-014-R4: yedek açıklaması depoda düz duruyordu. BY-014-R5: v1
  kayıtları silinince yanıtı kaybolan isteğin anahtarı da gitti.)*
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
  **Çok parçalı yayında (paket, dosya seti) sabit adları tek tek değiştirme:**
  yeni sürümü yaz, bütün parçalar doğrulanınca tamamlandı işaretçisini yaz;
  eskiyi silmek ve başarı sinyali (yaşam sinyali, bildirim) işaretçiden SONRA.
  Geri alınamaz bir isteği denetim kaydıyla birlikte yayınlıyorsan önce kaydı
  yaz, en son yayınla; yayınlama düşerse aynı kimlikle izleyen kayıt ekle.
  Testte ortadaki parçayı düşür: önceki set aynen, sinyal gitmemiş olmalı.
  *(Codex BY-024-R1: kurtarma paketi. BY-014-R1: istek yayınlandıktan sonra
  denetim kaydı düşünce iş çalışıyor, istemci 500 görüp tekrar deniyordu.)*
  **Taşımadan SONRAKİ hata "yapılmadı" değildir.** `rename` ile okuyana
  görünen dosya, ardından klasör eşitlenemese de yayımlanmıştır: çağıran
  "yayımlandı, kalıcılığı belirsiz" (ayrı istisna) ile "yayımlanmadı"yı
  ayırsın; yoksa iş çalışırken istemci hata görür ve ikinci işi bırakır,
  çalıştırıcı yazdığı sonucu "yarıda kaldı" sayar. Ayrımı ekleyince her
  çağıranı tara (G1). Geri alınamayan yazım (dosya) veritabanı işleminden
  SONRA, commit olmuş veriden üretilir; yazılamazsa durum izlenebilir kalır
  ve düzenli çalışan bir iş uzlaştırır.
  *(Codex BY-014-R2, BY-018-R2; G1: `yedek:yayin-oncesi` ve çalıştırıcının
  sonuç yazımı da aynı ayrımı yapmıyordu.)*
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
  Aynı tuzağın liste biçimi: `is_dir($k) ? scandir($k) : []` erişilemeyen
  klasörü BOŞ liste yapar; buna dayanan eşitleme (katalogdan silme) geçici bir
  disk arızasında kayıtları düşürür. Erişilemeyen kaynakta değişiklikten önce dur.
  *(Codex BY-011-R1.)*
  **Okunabilen klasör doğru klasör değildir.** Ayrı diske bağlanan klasör,
  disk ayrılınca alttaki boş ve okunabilir dizin olarak kalır: `scandir`
  başarılı, her dosya "yok". Silme, katalog değişikliği ya da yazım öncesi
  depoyu diskin kendisindeki bir işaretle doğrula. O klasöre yazan / oradan
  karar veren HER yolu say (tarama, yedek alma, saklama, elle silme, olay
  günlüğü) — biri atlanırsa kayıt düşer ya da yazım kök diske gider. Testte
  klasörü taşıyıp yerine boş dizin koy ve "hiçbir şey yazılmadı"yı da sına.
  *(Codex BY-011-R3; G1: saklama ve elle silme yedeği "silindi" yapıyor,
  olay günlüğünü boş bağlama noktasına yazıyordu.)*
  İşareti kendiliğinden açan "ilk kurulum" ölçütü durumdan bağımsız olsun:
  `calisiyor` ya da `basarisiz` kayıt da dosyasını yayımlamış olabilir;
  katalogda HİÇ kayıt yoksa ilk kurulumdur. Kayıt varken işaret yalnız somut
  kanıtla (imzası geçerli, kimliği kayıtla aynı manifest) yeniden açılır; adı
  tutan dosya kanıt değildir. Testte tamamlanmış yedek OLMADAN yarıda kalan
  ilk yedekle aynı disk ayırma senaryosunu koş.
  *(Codex BY-011-R5.)*
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
  Ayrı hesabın önbelleğini ayırmak yetmez: o hesabın çalıştırdığı KODU
  yazabilen hesabın (yayın kullanıcısı) yüklediği dosyaları da say —
  `deploy.sh`'ın `php artisan`'ı web'in yazabildiği `bootstrap/cache/*.php`'yi
  ve derlenmiş görünümleri yüklerse zincir web → admin → servis olur.
  *(Fable incelemesi YF-03; panoda "ayrı iş" diye kalmıştı.)*
  Bir işi ayrı hesaba taşırken onu tetikleyen HER giriş noktasını say:
  zamanlayıcı, kuyruk, yayın betiği, elle komut — biri eski hesapla çalışmaya
  devam ederse iş yetkisizlikten sessizce düşer. Ortak yazılabilir klasördeki
  isteğin göndericisini dosyanın İÇERİĞİ değil çekirdeğin verdiği sahibi (uid)
  belirler; içerikteki bir alana istisna tanımak taklit edilebilir.
  *(Codex BY-009-R1: servis kipinde planlı yedek web hesabıyla çalışıyordu.
  BY-025-R1: kullanıcısız yayın isteği yalnız JSON alanına dayanıyordu.)*
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
  Ölçüt **"kurtarmaya yeterli"**dir, "aktarıldı" değil: şifreli dump
  uzakta doğrulanmış olsa da onu açacak manifest / anahtar paketi oraya
  gitmediyse uzak kopya tek başına kurtarma sağlamaz. Hazırlığı ayrı izle
  (kopya başına bayrak), silme kararını ona bağla; ortadaki parça düşünce
  yerelin kaldığını sına.
  *(Codex BY-010-R2 / BY-024-R3: paket başarısızken yerel arşiv silinebiliyordu.)*
  Hazırlık bayrağı **doğrulanıp yayımlanan baytlara** bağlanır (imza + kimlik +
  özet), ad eşleşmesine değil; dayandığı şey sonradan kalkınca (sürüm
  dönüşümü, temizlik) bayrak kalanlardan yeniden hesaplanır.
  *(Codex BY-024-R5 / BY-010-R3: aynı adlı bozuk manifest "hazır" sayılıyordu.)*
  **Aynı turun kararları birbirine dayanak olmasın:** bir silme kararı başka
  bir kopyanın varlığına dayanıyorsa o kopyanın AYNI turda silinmeyeceğini,
  yani kararlar uygulandıktan sonraki durumu denetle; önce dayanakların
  kararını ver, sonra onlara dayanan silmeyi. Aynı nesneyi silen her yol aynı
  yardımcıyı kullansın. Dönüşüm de ("en yeni N kalsın") silmeden önce eski
  setin saklanan bir nesnenin TEK doğrulanabilir dayanağı olup olmadığına
  baksın; öyleyse yaşına bakmadan korusun ve uyarsın. Başarı sinyali "iş
  bitti"ye değil, işin o nesne için ürettiği güncel duruma bağlansın.
  *(Codex BY-010-R4: yerel dosya aynı turda silinen hazır kopyaya dayanıyordu.
  BY-024-R6: yaş kuralı uzak dump'ın son manifestini sildi. BY-024-R7:
  kurtarılamayan kopya yaşam sinyali gönderiyordu.)*
  **Önizleme "her adım başarılı" varsayar; uygulama varsayamaz.** Bir silme
  başka silmelerin sonucuna dayanıyorsa önce onları uygula, sonra fiilen
  kalan durumu kaynaktan (veritabanı) yeniden oku ve aynı eşiği uygula;
  testte ortadaki adımın hatasını her dayanak türü için ayrı sına. Döngüde
  yalnız o yinelemeye ait koruma (grup başına en az adet, yerel koruma) ortak
  duruma yazılmasın; yazılırsa sonuç işlenme sırasına bağlanır — testte aynı
  veriyi iki farklı kimlik sırasıyla kur.
  *(Codex BY-010-R5: bir uzak silme düşünce yerel dosya yine silindi.
  BY-010-R6: yerel koruma sonraki grupların kararına sızıyordu.)*
- **D5 — Root, başkasının değiştirebildiği yolda bağlantı izleyerek işlem
  yapmasın.** `chown`, `chmod`, `setfacl`, `cp`, `>` hedefteki sembolik
  bağlantıyı izler; yolu yazabilen hesap root'u yönetilen alanın dışına
  yönlendirir (CWE-59). Yolun üst klasörleri yalnız root'un olsun; dokunmadan
  önce türü denetle (bağlantı / sabit bağlantı → dur); root yalnız `chown -h`
  yapsın, izni ve içeriği nesnenin sahibi yazsın. Testte, o hesabın koyduğu
  bağlantıyla root dosyasının değişmediğini sına.
  *(BY-003-R5: servis hesabının `gizli/pgpass` ve `yedekler/` yerine koyduğu
  bağlantılar, root'a ait dosya ve klasörü servise geçirdi.)*
- **D8 — Kendiliğinden düzelmeyen hatayı otomatik iş yeniden denemesin.**
  Kimlik reddi (hatalı şifre), yetki ve doğrulama hataları girdi değişmeden
  düzelmez. Zamanlayıcı her turda, döngü her adımda yeniden denerse karşı
  hesapta başarısız giriş birikir ve hesap kilitlenebilir — hesap başkasınınsa
  onun işleyişi durur. Kalıcı hatada döngünün kalan adımlarını kes; hatayı
  girdinin sürümüyle (kimlik sürümü) anahtarlı hatırla ve girdi değişene kadar
  istek gönderme; elle sınama açık kalsın. Testte hatalı şifreyle çok adımlı
  çalışmada giden istek sayısını sına (bir), ikinci çalışmada sıfır.
  *(Fable incelemesi PRT-F1: Protel şifresi değişse senkron 15 dakikada bir,
  ilk tarama tek çalışmada ay sayısı kadar giriş deneyecekti.)*
- **D9 — Süre sınırı × deneme sayısı, çağıranın bekleyebileceğinden kısa olsun.**
  İstemci ayarı toplu işe göre seçilirse (60 sn × 3 deneme) aynı ayar kullanıcı
  isteğinde (PDF, sınama) web sunucusunun sınırını aşar; işçi süreç dakikalarca
  tutulur. Etkileşimli çağrıya ayrı, kısa süre ve az deneme ver; kilit
  beklemesini de buna göre sınırla (laravel-best-practices http-client:
  *timeouts that fit the calling request or job*).
  *(Fable incelemesi PRT-F2.)*

- **D10 — Sunucunun araçları yereldekilerle aynı değil; sıraya dayanan araç
  zincirini dilden bağımsız çağır.** Ubuntu 26.04 GNU coreutils yerine uutils
  (Rust) kullanır: `sort` yerel dilin harf sırasıyla (en_US: `:` yok sayılır),
  `comm` bayt sırasıyla çalışır; `sort | comm` / `join` zinciri "not in sorted
  order" ile durur. Yerelde (GNU, C.UTF-8) görünmez. Sıralı girdi bekleyen her
  zincirde `LC_ALL=C` iki tarafa da verilir; testte sunucunun davranışını
  taklit eden sahte `sort` ile sına. Uzaktan çıkış kodu ölçerken `$?`'yi yerel
  kabuğun açmadığından emin ol (betiği `bash -s` ile gönder).
  *(BY-003, 2026-11-22: kurulum betiği canlıda ilk çalıştırmada 3. adımda
  kaldı; 226 yerel test geçiyordu.)*

- **D11 — Büyük dosyayı `sys_get_temp_dir()`'e yazma.** Sunucuda `/tmp` bir
  `tmpfs`'tir (RAM + takas): dump boyutunda geçici dosya veritabanı büyüdükçe
  belleği tüketir. Büyük çalışma dosyaları yapılandırılabilir, kalıcı diskteki
  bir köke (0700); `/tmp`'de yalnız küçük, sır taşıyan ve hemen silinen
  dosyalar kalır. Öldürülen süreçten kalan geçici klasörleri bir sonraki
  açılışta (önek + yaş + sahip, bağlantı atlanarak) temizle: `finally` SIGKILL /
  OOM'da çalışmaz.
  *(Fable incelemesi YF-28: kopya, şifreli dosyayı ve geri okunan kopyayı
  `/tmp`'ye yazıyordu; kesilen `yedek:sifre-coz` özel anahtarı orada bırakıyordu.)*

## 5. Tasarım dokümanları

- **P1 — Aynı kural iki bölümde çelişmesin.** Bir bölümde "onaysız
  başlatılmaz" yazıp akışta otomatik başlatma; dokümanı teslim etmeden önce
  kuralları akışlarla karşılaştır.
  *(Yedekleme tasarımı §6.4 ↔ §7.6.)*
- **P2 — Bir işlem, değiştirdiği şeye dayanmasın.** Geri yüklenen
  veritabanında duran kilit, kuyruk, oturum, mail ayarı işlem sırasında kaybolur.
  "Bu adım çalışırken neye ihtiyaç duyuyor ve o şey o anda yerinde mi" diye sor.
  Erişimi daraltan bir koruma (bağlantı yetkisini geri alan bariyer) kuruyorsan
  işin kendi AÇACAĞI yeni bağlantıları da say: hangi rolle açılacaklarını
  belirle, korumanın altında gerçek bağlantıyla sına. Paylaşılan bir durumu
  (bakım modu) yalnız onu açan kapatsın; başta açıksa dokunma.
  *(Codex BY-028-R1: öncesi yedeğin anlık görüntü bağlantısı uygulama rolüyle
  açılıyordu, bariyer altında açılamazdı. BY-013-R1: yayın, geri yüklemenin
  bakım modunu `artisan up` ile kaldırabiliyordu.)*
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

- **P5 — Güvenlik gerekçesiyle kullanıcının beklediği akışı kısma; aynı
  güvenliği veren akışı ara.** "En dar yetki" doğruydu ama karşılığında
  kullanıcı Drive'ında klasör seçemiyor, yalnız ad yazıyordu; Google'ın seçim
  penceresi (Picker) aynı dar yetkiyle seçimi sağlıyordu. Dış hizmete bağlanan
  özelliği bitti saymadan önce: kullanıcının o hizmette alışık olduğu akışı
  (hesapla giriş → yer seçimi) sağlıyor mu, canlıda gereken yapılandırma
  (istemci kimliği, anahtar) gerçekten var mı — yoksa "kod hazır, canlıda
  çalışmaz" diye açıkça söyle.
  *(BY-021, 2026-11-22: canlıda Google istemci kimliği hiç yoktu; form
  yalnız klasör adı soruyordu.)*

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
- **A4 — Kullanılan PHP uzantısını `composer.json`'da beyan et.** `bcmath`,
  `zip`, `dom` gibi uzantılar geliştirme makinesinde kurulu diye çalışır; beyan
  yoksa yeni sunucuda `composer install` uyarmaz, kod ilk çağrıda ölür.
  (Bağımlılık değişikliğidir: kullanıcı onayı.)
  *(Fable incelemesi PRT-F12: `bcadd` / `bccomp`.)*

- **A5 — Windows araçları `\\wsl$` üzerinden yazdığı dosyanın çalıştırma
  iznini düşürür.** Düzenleme aracıyla değiştirilen `deploy.sh` 644 oldu,
  `git add -A` mod değişikliğini commit'e kattı; sunucu çekseydi `./deploy.sh`
  çalışmazdı. Betik düzenledikten sonra WSL'de `chmod +x` yap; commit'ten önce
  `git diff --cached --summary | grep 'mode change'` bak. `publish.sh` artık
  izni kalkan dosya varken commit atmaz.
  *(d280a00: deploy.sh 100755 → 100644; sonraki commit'te düzeltildi.)*

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
  Belgedeki bir davranış ("başarısız kopya yeniden denenir") için onu çağıran
  ÜRETİM kodu var mı, `grep` ile doğrula: testin kendisinin çağırması kanıt
  değildir.
  *(Fable incelemesi YF-04: yeniden denemeyi yalnız test çağırıyordu.)*
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
  SQLite'ta `->change()` tabloyu yeniden kurar; migration işlemi içinde
  `PRAGMA foreign_keys=OFF` etkisizdir, eski tablo düşerken CASCADE'li çocuk
  satırlar sessizce silinir (PostgreSQL'de görünmez). Çocukları koru;
  yükseltme testinde çocuk tablonun satırlarını da karşılaştır.
  *(BY-028-R9 migration'ı: `yedek_kopya_planlari` bağları.)*
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
- **K9 — Yeni kaynağın alanını, adı benziyor diye kardeşin kolonuna yazma.**
  Ortak tabloya ikinci bir sağlayıcı eklerken her alan için "bu kolon ekranda
  ne anlama geliyor" diye sor. Anlam farklıysa (bizim ERP'miz mi, karşı tarafın
  ERP'si mi) ayrı kolon aç. Yoksa birleşik ekranda, filtrede, alarmda yanlış
  bilgi olarak çıkar. Birleşik bir ekran eklerken de kardeş kolonların her
  sağlayıcıdaki anlamını yeniden denetle.
  *(Protel 2026-11-20: `isErpRecieved` (otelin ERP'si) `erp_okundu`'ya
  yazılmıştı; Tüm Faturalar Protel faturasını "eMOR Okudu: Evet" gösterdi.)*
- **K10 — İkinci sağlayıcıyı / ekranı kopyalayarak ekleme; ortak kısmı çıkar.**
  İşaret: bir bulgu "… dahil — G1" notuyla iki yerde düzeltiliyorsa o kod
  kopyadır. İkinci kullanım yazılırken ortak parçayı (token önbelleği, liste
  kancası, çıktı akışı) tek yere al. Aynı ekranın çeşitleri için tek yöntem
  seç: bir çeşidi kopya sayfa, diğerini boolean bayrakla yapma
  (vercel-composition-patterns: *avoid boolean props*, *explicit variants*).
  *(Fable incelemesi PRT-F8…F10: İzibiz ↔ Protel istemcisi, EFatura ↔ Tüm
  Faturalar denetleyicisi, üç liste ekranı; PRT-R3 / R4 / R9 / R10 ve A3
  ikişer kez düzeltildi.)*
- **K11 — Karar değişince yorumu, dokümanı ve ayarı aynı commit'te güncelle.**
  Davranışı değiştiren commit, onu anlatan sınıf yorumunu, config açıklamasını
  ve doküman tablosunu da değiştirir. Okuyanı kalmayan ayar anahtarını sil
  (`grep` ile okuyanı doğrula): değiştirince hiçbir şey olmayan ayar yanıltır.
  *(Fable incelemesi PRT-F11: adresler ekrana taşındı, üç yorum "ekrandan
  alınmaz" diyordu; `entegrator.protel.saat_dilimi` hiç okunmuyordu.)*
