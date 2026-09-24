## Projeye özel skill kullanım kuralları

Kod geliştirme, hata düzeltme, refaktör ve inceleme işlerinde aşağıdaki
skill'leri görev kapsamına göre kullan.

Aşağıdaki dosya yolları proje köküne göredir.

## Proje kapsamında skill kullanımı

Bu projenin kökü erp/ dizinidir. Laravel uygulaması backend/,
React uygulaması frontend/ altında bulunur.

Aşağıdaki yollar proje köküne göredir:
- Codex için skill dizini: .agents/skills/
- Claude Code için skill dizini: .claude/skills/

### Skill seçimi

Kod yazmadan, değiştirmeden veya incelemeden önce görevin kapsamını
belirle ve ilgili skill'leri seç:

- laravel-best-practices:
  Laravel/PHP kodu, controller, service, model, Eloquent, validation,
  authorization, API endpoint, queue ve dış servis entegrasyonları.

- testing-best-practices:
  Laravel testleri, hata düzeltmelerinin doğrulanması, regresyon
  testleri ve backend davranış değişikliklerinin test edilmesi.

- vercel-react-best-practices:
  React bileşenleri, hook'lar, state yönetimi, veri yükleme,
  render davranışı ve frontend performansı.

- vercel-composition-patterns:
  React bileşenlerinin sorumlulukları, bileşen API'leri,
  yeniden kullanılabilirlik ve karmaşık bileşenlerin düzenlenmesi.

- tailwind-design-system:
  React arayüzlerinde Tailwind ile stil, responsive tasarım, tema
  (açık/koyu) ve bileşen görünümü üzerinde çalışırken kullan.
  Skill Tailwind v4 odaklıdır. Önce frontend/package.json'daki
  tailwindcss sürümünü kontrol et (şu an 4.x: @tailwindcss/vite,
  frontend/src/index.css'te `@import 'tailwindcss'` ve `@theme`;
  tailwind.config yok). Proje v3 kullanıyorsa v4'e özgü kuralları
  uygulama; sürümü kendiliğinden yükseltme.
  Mevcut düzene uy: koyu tema index.css'teki
  `@custom-variant dark` + ThemeProvider ile yönetilir. Skill
  örneklerindeki CVA, clsx ve tailwind-merge projede yoktur;
  onay almadan ekleme. Mevcut renk paletini görev dışında anlamsal
  token'lara taşıma.

- supabase-postgres-best-practices:
  PostgreSQL şeması, SQL sorguları, indeksler, ilişkiler,
  transaction'lar, kilitler ve sorgu performansı.
  Laravel migration ve Eloquent değişikliklerinde de ilgili
  veritabanı kurallarını değerlendir.

- code-review:
  Kod incelemesi, değişiklik değerlendirmesi ve mevcut bir
  modülün kalite analizinde kullan.
  İncelenen teknolojinin ilgili skill'lerini de birlikte kullan.

Bir görev birden fazla alanı etkiliyorsa ilgili skill'leri birlikte seç.
Görevle ilgisiz skill'leri yükleme.

### Skill'lerin okunması ve uygulanması

1. Seçtiğin her skill'in SKILL.md dosyasını kendi skill dizininden oku.
   Yalnızca skill adına veya açıklamasına dayanarak işlem yapma.

2. SKILL.md içindeki okuma talimatlarını izle. Görevle ilgili
   rules/, references/ ve diğer destek dosyalarını da oku.

3. İşe başlarken kullanacağın skill'leri ve nedenlerini kısaca belirt.
   Okumadığın veya erişemediğin bir skill'i kullandığını söyleme.

4. Önerileri projenin gerçek framework sürümlerine ve mimarisine
   göre uygula. Önce mevcut bağımlılıkları ve uygulama yapısını incele:
   PHP paketleri için backend/ içinde `composer show --direct` veya
   `composer show <vendor/paket>`, JS paketleri için package.json.
   Kurulu ana sürümle uyumlu API'yi kullan; sürüm varsayma.
   Next.js, React Server Components veya Supabase hizmetlerine özel
   önerileri bu teknolojiler kullanılmıyorsa uygulama.

5. Kullanıcının talimatlarını ve projeye özgü gereksinimleri esas al.
   Bir skill önerisi bunlarla çelişiyorsa çelişkiyi açıkla;
   öneriyi körü körüne uygulama.

### Değişiklik ve doğrulama yaklaşımı

- İstenen kapsam içinde küçük, anlaşılır değişiklikler yap.
- Refactoring sırasında mevcut kullanıcı davranışını ve API
  sözleşmelerini koru; gerekli davranış değişikliklerini açıkça belirt.
- Sırf bir kalıba uymak için gereksiz soyutlama veya bağımlılık ekleme.
- İlgili mevcut testleri ve kontrolleri çalıştır.
  Davranış değişikliği veya hata düzeltmesi gerektiriyorsa anlamlı
  test ekle; testleri geçirtmek için mevcut doğrulamaları zayıflatma.
- Sonuçta yapılan değişiklikleri, çalıştırılan kontrolleri ve
  doğrulanamayan noktaları belirt.

### Kod inceleme çıktısı

İncelemede her somut bulgu için şunları belirt:
- Dosya yolu ve ilgili satır.
- Sorun ve kullanıcıya veya sisteme etkisi.
- İlgili skill kuralı veya teknik gerekçe.
- Önerilen en küçük düzeltme.
- Düzeltmenin nasıl doğrulanacağı.

Gerçek hata, güvenlik riski ve davranış bozukluğunu;
bakım kolaylığı önerisi veya kişisel stil tercihinden ayır.
Sadece inceleme istendiyse uygulama kodunu değiştirme.

### Uygulama yöntemi

- Kod değiştirmeden önce görevle ilgili skill'lerin SKILL.md
  dosyalarını ve ihtiyaç duyulan referanslarını oku.
- Bir görev birden fazla alanı etkiliyorsa ilgili skill'leri birlikte kullan.
- Görev başında hangi skill'leri kullanacağını kısa bir cümleyle belirt.
- Skill dosyasına erişemiyorsan bunu açıkça belirt; okumuş gibi davranma.
- Görevle ilgisiz skill'leri yükleme ve sırf skill öneriyor diye
  görev dışı değişiklik yapma.
- Skill önerilerini projenin gerçek sürümleri, mimarisi ve mevcut
  talimatlarıyla birlikte değerlendir. Uyuşmayan öneriyi zorla uygulama.

### Mevcut uygulamayı koruma

- İstenen değişiklik dışındaki kullanıcı davranışını koru.
- Düzenlemeleri küçük, anlaşılır ve ayrı ayrı doğrulanabilir tut.
- Mevcut kullanıcı değişikliklerini koru.
- Tüm projeyi yeniden yazma veya görev dışı toplu refaktör yapma.
- Yeni bağımlılık, framework veya mimari katman eklemeyi somut
  ihtiyaca dayandır; mevcut çözümleri önce değerlendir. Bağımlılık
  ekleme, kaldırma veya sürüm değiştirme için kullanıcı onayı al.
- Mevcut dizin yapısına uy; onay almadan yeni ana klasör açma.
- Dosya oluştururken veya düzenlerken kardeş dosyalardaki yapıyı,
  yaklaşımı ve adlandırmayı izle; yeni bileşen yazmadan önce
  yeniden kullanılabilecek mevcut olanı ara.
- Kullanıcı açıkça istemedikçe dokümantasyon dosyası oluşturma.
- React skill'lerindeki Next.js, SSR ve sunucu bileşeni kurallarını
  mevcut mimariye (Laravel API + Vite ile derlenen React SPA)
  uygulanabilir olmadıkça kullanma.

### Doğrulama ve teslim

- Çalıştırılacak kontrolleri mevcut package.json, composer.json ve
  CI yapılandırmasından belirle; komut isimlerini tahmin etme.
- Değişiklikle ilgili lint, tür kontrolü, test veya derleme
  kontrollerini çalıştır.
- Hata düzeltmelerinde ve riskli davranış değişikliklerinde,
  mümkünse ilgili davranışı doğrulayan regresyon testi ekle.
  Değişen davranışı ve önemli hata durumlarını test et; bunların
  ötesinde test ekleme. Yalnız metin, stil veya yerleşim
  değişiklikleri test gerektirmez.
- Testlerin kapsadığı işlevi kanıtlamak için ayrı doğrulama
  script'i veya tinker denemesi yazma; test tercih edilir.
- Teslimden önce değişen kodu code-review skill'iyle gözden geçir.
- Önceden var olan hatalarla değişikliğin getirdiği hataları ayır.
- Sonuçta kullanılan skill'leri, yapılan değişikliği ve doğrulama
  sonuçlarını kısa şekilde bildir.
- Çalıştırılmayan kontrolleri ve denenmeyen platformları açıkça belirt.

## Backend kapsamı (backend/ — Laravel)

Bu bölüm yalnız backend/ altındaki Laravel koduna uygulanır.
Komutlar WSL içinde backend/ dizininde çalıştırılır. (Laravel Boost
kılavuzlarından aktarılmıştır; genel kurallar yukarıdaki bölümlerdedir.)

### Laravel Boost MCP araçları

Projede şu an MCP tanımı yoktur. Oturumda laravel-boost MCP sunucusu
tanımlıysa elle yapılan alternatifler yerine onu tercih et:
- `search-docs`: Laravel ekosistemi API'sine, davranışına, yapılandırmasına
  veya sürüme özgü sözdizimine bağlı değişikliklerden önce kullan.
  Yalnız metin değişikliklerinde atla; bağlamda yeterli sonuç varsa
  tekrar arama. İlgili paketleri `packages` dizisiyle sınırla; birden
  fazla geniş, konu bazlı sorgu ver (`['rate limiting', 'routing']`);
  sorguya paket adı ekleme. Kelimeler AND, `"tırnaklı ifade"` bitişik
  eşleşme; OR için ayrı sorgular kullan.
- `database-schema`: Migration veya model yazmadan önce tablo yapısını incele.
- `database-query`: Tinker'da ham SQL yerine salt okunur sorgular için.
  Bu araçlar uygulamanın PostgreSQL veritabanına bakar; ERP MSSQL
  verisi için değildir.
- `get-absolute-url`: Kullanıcıyla proje URL'si paylaşmadan önce
  doğru şema, alan adı ve portu çözmek için.
- `browser-logs`: Tarayıcı log ve hatalarını okumak için; yalnız
  yeni kayıtlar anlamlıdır.
- Kullanıcı açıkça istemedikçe `record-rule` ile kural kaydetme.

MCP yoksa bunu belirt; sürüm bilgisini resmi dokümantasyondan veya
vendor/ altındaki kaynak koddan doğrula.

### Artisan ve Tinker

- Dosya oluştururken `php artisan make:` komutlarını kullan (genel PHP
  sınıfı için `make:class`). Tüm Artisan komutlarına `--no-interaction`
  ve doğru seçenekleri ver; seçenekleri `php artisan [komut] --help`
  ile kontrol et.
- Rotalar için `php artisan route:list` (`--method=GET`, `--name=`,
  `--path=api`, `--except-vendor`); yapılandırma için
  `php artisan config:show app.name` veya config/ dosyaları.
- Tinker'da kodu tek tırnakla ver:
  `php artisan tinker --execute 'User::where("active", true)->count();'`.
  Kullanıcı onayı olmadan model kaydı oluşturma; factory'li testleri tercih et.

### Laravel yapısı (Laravel 13, sadeleştirilmiş yapı)

- Middleware, exception ve routing kaydı bootstrap/app.php içinde
  `Application::configure()` ile yapılır; app/Http/Kernel.php ve
  app/Console/Kernel.php yoktur.
- Uygulamaya özel service provider'lar bootstrap/providers.php'dedir.
- Console yapılandırması bootstrap/app.php veya routes/console.php'de;
  app/Console/Commands/ altındaki komutlar otomatik kaydolur.
- API uçları routes/api.php'deki `v1` öneki ve app/Http/Resources
  altındaki Eloquent API Resource'larıyla yazılır; mevcut kalıbı izle.
- Sayfa bağlantısı üretirken isimli rotaları ve `route()` kullan.

### Model ve migration

- Bir kolonu değiştiren migration, kolonun önceki tüm özniteliklerini
  de içermelidir; aksi halde bunlar düşer.
- Cast'ler `$casts` özelliği yerine modeldeki `casts()` metodunda
  tanımlanır (mevcut modellerin kalıbı).
- Eager load edilen kayıtlar paket gerekmeden sınırlanabilir:
  `$query->latest()->limit(10)`.
- Yeni model oluştururken faydalı factory ve seeder'ları da ekle;
  başka ihtiyaç olup olmadığını kullanıcıya sor.

### PHP (8.5)

- Kontrol yapılarında tek satırlık gövdede bile süslü parantez kullan.
- PHP 8 constructor property promotion kullan; özel değilse boş,
  parametresiz `__construct()` bırakma.
- Tüm metot parametrelerinde tür ipucu ve açık dönüş türü kullan.
- Enum anahtarları TitleCase olsun (`Aylik`, `Haftalik`).
- Satır içi yorum yerine PHPDoc tercih et; satır içi yorumu yalnız
  gerçekten karmaşık mantıkta kullan. PHPDoc'ta dizi şekli
  (array shape) tanımları kullan.
- Laravel collection kullanılmayan yerlerde döngü yerine
  `array_first()` / `array_last()`; iç içe çağrılar yerine pipe
  operatörü (`|>`); readonly sınıflarda `clone($nesne, ['alan' => $deger])`.
  Not: lokal ve sunucu PHP 8.5 çalıştırır, ancak backend/composer.json
  kısıtı `^8.3`'tür; 8.5'e özgü sözdizimini kullanmadan önce bu
  uyumsuzluğu kullanıcıya bildir.

### Testler (PHPUnit)

- Bu proje PHPUnit kullanır. Test yazmadan önce testing-best-practices
  skill'ini oku.
- Testi `php artisan make:test --phpunit {Ad}` ile oluştur; ada suite
  dizinini ekleme (`Feature/XTest` değil `XTest`). Birim test için
  `--unit`; testlerin çoğu feature test olmalı.
- Test modellerini factory ile oluştur; önce factory'nin özel
  state'lerini kontrol et. Faker için mevcut kalıbı izle
  (`$this->faker` veya `fake()`).
- Değişikliği kapsayan en dar test kümesini çalıştır:
  `php artisan test --compact` ile dosya yolu veya `--filter=testAdi`
  (ya da doğrudan `vendor/bin/phpunit`). Testi her değişiklikten
  sonra yeniden çalıştır.

### Biçimlendirme (Pint)

- PHP dosyası değiştirdiysen teslimden önce
  `vendor/bin/pint --dirty --format agent` çalıştır.
- `--test` ile kontrol etme; biçim hatalarını
  `vendor/bin/pint --format agent` ile düzelt.