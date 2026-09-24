# EFAT-04 — İzibiz istemcisi: token yönetimi + bağlantı sınama

- **Durum:** Bitti (lokal) — yayın bekliyor
- **Bağımlılık:** EFAT-03
- **Skill:** laravel-best-practices, testing-best-practices

## Neden

İzibiz'e yapılan her çağrı token gerektirir. Token alma, önbellekte tutma ve
süresi dolunca yenileme tek bir yerde olmalı; ekranlardaki "Bağlantıyı sına"
düğmesi de bu istemciyi kullanır.

## Yapılacaklar

- [x] `IzibizIstemcisi` servisi (Laravel `Http` client):
  - `POST {api_url}/v1/auth/token` ile `{username, password}` gönderir,
    yanıttan `data.accessToken` ve `data.validity` okunur.
  - `validity` bitiş tarihi olarak ve teyit edilen saat dilimiyle ayrıştırılır;
    güvenlik payıyla TTL hesaplanır. Geçmiş/bozuk değer başarı kabul edilmez.
    16 saat sabiti kullanılmaz.
  - Token cache anahtarı sağlayıcı, şirket/hesap, ortam, bağlantı kimliği ve
    tanım sürümünü içerir; anahtarda düz şifre bulunmaz. Web ve worker aynı
    cache/kilit altyapısını kullanır; token cache'te korumalı saklanır.
  - Eşzamanlı yenileme kilitlenir, kilit alındıktan sonra cache yeniden okunur.
    Kaydedilmemiş form sınaması kayıtlı bağlantının cache'ini kirletmez.
  - 401 veya EFAT-02'de doğrulanan oturum-geçersiz/süresi-doldu kodunda
    token en fazla bir kez yenilenir. Kalıcı kimlik/yetki hatasında döngüye girilmez.
  - Zaman aşımı ve bağlantı hataları makine okunur `kod` ile döner
    (mevcut büyük harf kalıbıyla `ENTEGRATOR_ERISILEMEDI`, `ENTEGRATOR_KIMLIK_HATALI`).
- [x] EFAT-02'de doğrulanan hata kodlarını API sözleşmesine/i18n'e eşle;
      HTTP 200 içindeki `error` ve eksik başarı gövdesi de denetlensin.
- [x] Bağlantı ve toplam süre sınırı, 429/Retry-After ve geçici 5xx için
      sınırlı bekleyerek yeniden deneme tanımla. Yalnız güvenli işlemler
      tekrar edilir; hata hiçbir zaman başarılı boş listeye çevrilmez.
- [x] Token, şifre ve yanıt gövdesindeki kişisel veriler loglanmaz.
      HTTP exception mesajı, debug yanıtı, failed-job ve audit kayıtları da kontrol edilir.
- [x] `POST ayarlar/entegrator-baglantilari/{ortam}/sina`: form değerleriyle
      ya da kayıtlı tanımla token almayı dener. `throttle` uygulanır
      (SQL `sina` deseni). Başarıda token dönmez; `customerType` ve bitiş
      zamanı döner. Sonuç yalnız kimlik doğrulamanın başarılı olduğunu söyler;
      fatura izinleri EFAT-07'de ayrıca sınanır.
- [x] Testler `Http::fake()` ile yazılır: başarılı token, hatalı şifre (102),
      süresi dolmuş token → yenileme, zaman aşımı. Testte gerçek ağ çağrısı yapılmaz.

## Kabul kriterleri

- [x] Sınama, test ortamı bilgileriyle gerçek API'de başarılı (elle doğrulama).
- [x] Aynı süreçte ardışık çağrılar tek token kullanıyor.
- [ ] Eşzamanlı web/worker çağrıları, hesap değişimi, kaydedilmemiş sınama,
      200+error, bozuk validity ve ikinci kez 401 senaryoları testlerle kapsanıyor.
- [x] `Http::preventStrayRequests()` ile beklenmeyen gerçek ağ çağrısı engelleniyor.

## Sonuç

2026-09-23 (Claude) — uygulandı:

- **API davranışı test hesabında doğrulandı** (EFAT-02, `api-notlari.md`):
  `validity` İstanbul saati, ömür 12 saat, kimlik hatası HTTP 401 +
  `error.code = "10004"` (dokümandaki 102 değil), geçersiz token 403 boş gövde,
  uzatma süreyi uzatmıyor → kullanılmıyor.
- `App\Services\Entegrator\IzibizIstemcisi`:
  - `tokenAl()` önbelleksiz (sınama); `token()` önbellekli — `Crypt` ile şifreli,
    anahtar `entegrator-token:{saglayici}:{ortam}:{id}:{kimlik_surumu}`, TTL =
    bitiş − 300 sn; `Cache::lock` + kilit içinde yeniden okuma; çözülemeyen
    kayıt atılıp yeniden alınır.
  - `getJson()` (EFAT-07 için): 401/403'te token bir kez yenilenir; ikinci red
    kalıcı hata; 200 içinde dolu `error` hata sayılır, boş listeye çevrilmez.
  - Zaman aşımı 5 sn bağlantı / 20 sn istek; bağlantı hatası, 5xx ve 429'da
    250 ms + 1 sn beklemeyle 2 yeniden deneme; yönlendirme izlenmez.
- `EntegratorHatasi` → `bootstrap/app.php`'de `{kod, mesaj}`: `ENTEGRATOR_ERISILEMEDI`
  (502), `ENTEGRATOR_KIMLIK_HATALI` (422), `ENTEGRATOR_YANIT_GECERSIZ` (502),
  `ENTEGRATOR_HATA` (502), `ENTEGRATOR_AKTIF_YOK` (422). Kullanıcı kaynaklı iki kod
  raporlanmaz; bağlamda yalnız kod ve sağlayıcı kodu/HTTP durumu var.
- `POST .../{ortam}/sina`: `throttle:entegrator-sina` (5/dk — hesap kilitlenmesi
  bilinmediği için sıkı), önbellek ve kayıtlı tanım değişmez, boş şifre yalnız
  aynı kullanıcı adında kayıtlıya düşer. Yanıt: `musteri_tipi`, `gecerlilik_bitis`.
- Testler: `IzibizIstemcisiTest` (16) + `EntegratorBaglantiTest` sınama senaryoları;
  hepsinde `Http::preventStrayRequests()`, zaman `travelTo`, bekleme `Sleep::fake`.
- Canlı doğrulama: kayıtlı test tanımıyla gerçek `apitest.izibiz.com.tr`'den token
  alındı (müşteri tipi C, 12 saat); iki `token()` çağrısı tek token, önbellekte şifreli.
- **Sapma:** `Retry-After` başlığı okunmuyor; sabit bekleme kullanılıyor.
- Doğrulanmayan: iki ayrı sürecin (web + kuyruk) aynı anda token yenilemesi
  gerçek eşzamanlılıkla sınanmadı (kilit kodu var, süreç içi test yok).
  "Sına" ucu tarayıcıdan denenmedi; servis seviyesinde gerçek API ile denendi.

2026-09-23 (Codex incelemesi): Beş hata düzeltildi. `getJson()` artık Bearer
token'ı yalnız `/v1/` altındaki göreli İzibiz yoluna gönderir ve `data`
içermeyen HTTP 200 yanıtını geçerli veri saymaz. Token bitişi ayrıştırılırken
31 Eylül gibi normalize edilen geçersiz tarihler reddedilir. HTTP yeniden
denemelerinin toplam süresi 30 saniyeyi aşabildiği için token yenileme kilidi
bu süreyi kapsayacak şekilde hesaplanır. Kuyruk işinde önceden yüklenmiş model
örneği varsa `token()` tanımı yeniden okuyup güncel şifre ve kimlik sürümünü
kullanır. Bunlar için regresyon testleri eklendi; gerçek İzibiz çağrısı bu
incelemede yeniden yapılmadı. Eşzamanlı ayrı süreç testi hâlâ açık.
