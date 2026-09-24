# EFAT-03 — Entegratör bağlantıları: veri modeli + API

- **Durum:** Bitti (lokal) — yayın bekliyor
- **Bağımlılık:** EFAT-01 (bitti), EFAT-15 bağlantı kararları (S5/S9 verildi)
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices

## Neden

Test ve canlı İzibiz tanımlarının, SQL Bağlantıları gibi arayüzden
girilebilmesi gerekiyor.

## Tasarım (öneri)

`entegrator_baglantilari` tablosu, `sql_baglantilari` kalıbını izler:

| Kolon | Tür | Not |
|---|---|---|
| `saglayici` | string | Şimdilik yalnız `izibiz`; ileride başka entegratör eklenebilir |
| `ortam` | string | `test` / `canli`; (`saglayici`, `ortam`) unique — S9: tek şirket/hesap |
| `api_url` | string | Ortama göre izinli İzibiz adresi; serbest dış URL kabul edilmez |
| `portal_url` | string, null | Bilgi amaçlı |
| `kullanici_adi` | string | |
| `sifre` | text | `encrypted` cast, `$hidden`; API'ye asla dönmez (`sifre_dolu`) |
| `vkn` | string(11) | Şirket/hesap kimliği; VKN/TCKN türü ve uzunluğu ayrıca doğrulanır |
| `posta_kutusu` | string, null | `urn:mail:...pk@...` |
| `gonderici_birim` | string, null | `urn:mail:...gb@...` |
| `aktif` | boolean | Sağlayıcı başına en fazla bir aktif (kısmi unique indeks) |

**Kararlar (EFAT-15):** S9 — tek şirket, tek İzibiz hesabı; ortam başına tek
tanım. S5 — entegratörün aktif ortamı SQL'den **bağımsız** seçilir; uyuşmazlıkta
yalnız uyarı. Bu yüzden API, aktif SQL ve entegratör ortamını birlikte döndürür
(`ortam_uyumsuz` bayrağı) ki ekranlar uyarıyı gösterebilsin.

## Yapılacaklar

- [x] Migration, `EntegratorBaglanti` modeli (`casts()`, `$hidden`), factory.
- [x] `EntegratorBaglantiServisi` (listele, guncelle, aktifYap, aktif).
- [x] Controller + FormRequest + Resource; rotalar `ayarlar/entegrator-baglantilari*`.
- [x] **Yetki:** tüm uçlar `can:sistem-yonetimi` middleware'li grupta ve
      FormRequest `authorize()` aynı Gate'le (EFAT-16 altyapısı). 403 `ERISIM_ENGELLI`.
- [x] Boş şifre gönderilirse kayıtlı şifre korunur (SQL deseni).
- [x] URL'yi sağlayıcı/ortamdan üret veya tam origin allowlist'iyle doğrula;
      HTTPS zorunlu, farklı hedefe redirect izlenmez. Kayıtlı şifreyle
      serbest URL sınama yapılmaz (SSRF ve sır aktarımı riski).
- [x] Oluşturma ile aktif yapma ayrı işlemdir. Eşzamanlı güncelleme/aktiflik
      için transaction ve DB kısıtı kullan; çatışmayı anlaşılır hata olarak dön.
- [x] Şifre, kullanıcı, hesap, API hedefi veya ortam değişince token cache'i
      ve önceki sınama sonucu geçersizleşir; işlerin tanım sürümü değişir.
- [x] Kim/ne zaman/hangi tanımı değiştirdi kaydı tutulur; eski/yeni şifre
      veya token audit içeriğine girmez. APP_KEY ve yedeklerin birlikte
      korunması, geri yüklemede şifrenin açılabilmesi EFAT-14'te doğrulanır.
- [x] i18n hata mesajları (`lang/tr`, `lang/en`).
- [x] Feature testleri: listeleme, güncelleme, şifre gizliliği, tek aktif
      kuralı, yetkisiz kullanıcıya 403.

## Kabul kriterleri

- [x] `php artisan test` yeşil, `vendor/bin/pint --dirty --format agent` temiz.
- [x] Şifre hiçbir API yanıtında ve logda görünmüyor.
- [x] Sistem yöneticisi olmayan kullanıcı uçlara erişemiyor.
- [x] HTTP, izin dışı host ve redirect üzerinden kimlik bilgisi çıkmıyor;
      test/canlı/hesap değişimi eski oturumla çalışmıyor.

## Sonuç

2026-09-23 (Claude) — uygulandı:

- **Kararlar / taslaktan sapmalar:**
  - `api_url` ve `portal_url` kolonları **yok**: adres `config/entegrator.php`'de
    sağlayıcı + ortamdan türetilir, gövdeden alınmaz (gönderilse de yok sayılır).
    Böylece SSRF ve kayıtlı şifrenin başka hedefe gitmesi yapısal olarak kapalı.
  - Token önbellek anahtarı için `kimlik_surumu` kolonu eklendi; kullanıcı adı
    veya şifre değişince artar, eski anahtar `Cache::forget` ile silinir.
  - Kullanıcı adı değişirse şifre yeniden istenir (SQL'deki hedef kuralının karşılığı).
  - Değişiklik kaydı `Log::info` (sağlayıcı, ortam, kullanıcı ID, `sifre_degisti`) —
    EFAT-16 kalıbı; ayrı audit tablosu açılmadı.
  - Backend'de yalnız `lang/tr` var (`lang/en` projede yok); mesajlar oraya eklendi.
- Dosyalar: migration `create_entegrator_baglantilari_table` (unique
  `saglayici+ortam`, kısmi unique `tek_aktif`), `EntegratorBaglanti` +
  factory, `EntegratorBaglantiServisi`, `EntegratorBaglantiController`
  (`index`, `guncelle`, `aktifYap`), `EntegratorBaglantiGuncelleRequest`,
  `EntegratorBaglantiResource`, rotalar `can:sistem-yonetimi` grubunda,
  `config/entegrator.php`, `lang/tr/hata.php`.
- Testler: `EntegratorBaglantiTest` — 401, uç başına 403 (tanım değişmeden),
  şifre zorunluluğu/gizliliği/şifreli saklama, adres türetme (gövdedeki
  `api_url` yok sayılıyor), kullanıcı değişince şifre, kimlik sürümü + eski
  token silme, VKN/URN doğrulaması, tek aktif ve SQL'e dokunmama,
  `ortam_uyumsuz`, log içeriği.
- Doğrulanmayan: eşzamanlı ilk kayıtta unique ihlalinin 422'ye çevrilmesi
  (süreç içi testte üretilemiyor; kod yolu `UniqueConstraintViolationException`).
  PostgreSQL'e özgü kısmi indeks davranışı testte SQLite ile çalıştı; lokal
  PostgreSQL'de migration uygulandı.

2026-09-23 (Codex incelemesi): Aktif ortam geçişinde hedef satırı tek başına
kilitlemek, iki eşzamanlı ters geçişte kilit sırasını ters çevirebilirdi.
`EntegratorBaglantiServisi::aktifYap()` sağlayıcının iki ortam satırını ID
sırasıyla kilitliyor. Tek aktif kuralı testleri geçti; gerçek PostgreSQL'de
eşzamanlı geçiş testi yapılmadı.
