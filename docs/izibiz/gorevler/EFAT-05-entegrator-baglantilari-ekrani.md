# EFAT-05 — Entegratör Bağlantıları ekranı

- **Durum:** Bitti (lokal) — yayın bekliyor
- **Bağımlılık:** EFAT-04
- **Skill:** vercel-react-best-practices, vercel-composition-patterns, tailwind-design-system

## Neden

Tanımlar `/ayarlar/entegrator-baglantilari` adresinde, SQL Bağlantıları
ekranı gibi girilecek.

## Yapılacaklar

- [x] `features/ayarlar/entegratorApi.ts` (TanStack Query) — `sqlApi.ts` kalıbı.
- [x] `pages/EntegratorBaglantilari/EntegratorBaglantilariPage.tsx`:
      Test ve Canlı kartları, react-hook-form + zod, şifre alanında
      "kayıtlı" göstergesi (`sifre_dolu`), "Bağlantıyı sına", "Aktif yap".
- [x] Aktif SQL ve entegratör ortamını birlikte göster. S5 kararı: seçimler
      bağımsız; uyuşmazsa (`ortam_uyumsuz`) belirgin uyarı gösterilir, işlem
      engellenmez. Canlıya geçiş onay ister (SQL ekranı kalıbı).
- [x] Router'a ekle; menüde Ayarlar altında, `yoneticiye: true`.
- [x] Doğrudan URL erişimini de denetle. Mevcut `route.lazy`, ortak Input,
      Button, ErrorState ve ConfirmDialog bileşenlerini kullan; sırf bu görev
      için genel form motoru veya bağımlılık ekleme.
- [x] Kaydetme/sınama/aktiflik ayrı sonuçlar olsun. Form değişince önceki
      başarılı sınama geçerli gösterilmesin; kaydedilmemiş form otomatik
      aktifleşmesin. Kaydetme ve sınama sonrası şifre alanı temizlensin;
      şifre/token localStorage veya kalıcı sorgu cache'ine konmasın.
- [x] Query key'ler ve invalidation hesap/ortamı ayırsın; ortam değişince
      önceki hesaba ait liste ve sınama sonucu yeni hesaba aitmiş gibi görünmesin.
- [x] i18n (tr/en) anahtarları ve `api/errors.ts` hata kodları.
- [x] Sayfa testi (vitest): form doldurma, sınama sonucu, yetkisiz görünüm.

## Kabul kriterleri

- [x] `npm run typecheck`, `npm run lint`, ilgili `npm run test -- --run`
      testleri ve `npm run build` yeşil.
- [ ] Açık/koyu temada ve dar ekranda düzgün görünüyor.
- [x] Menü öğesi yalnız sistem yöneticisine görünüyor.

## Sonuç

2026-09-23 (Claude) — uygulandı:

- `features/ayarlar/entegratorApi.ts`, `queryKeys.ayarlar.entegratorBaglantilari`,
  `pages/EntegratorBaglantilari/EntegratorBaglantilariPage.tsx` (+ test),
  router (`YoneticiAlani` altında, `route.lazy`), menü (`yoneticiye: true`,
  link ikonu), `i18n/tr.json` + `en.json` (`ayarlar.entegrator.*`,
  `hata.ENTEGRATOR_*`).
- Sayfa: SQL ve entegratör aktif ortamı yan yana; `ortam_uyumsuz` iken sarı
  uyarı (`role="alert"`), işlem engellenmez. Canlıya geçiş onay ister. Kartlarda
  türetilmiş API adresi salt okunur; VKN/URN istemcide de doğrulanır.
- Form herhangi bir alan değişince önceki sınama sonucu/hatası silinir.
  Kayıttan sonra form yeniden kurulur, şifre alanı boşalır. Şifre/token
  localStorage'a veya sorgu önbelleğine girmez (yalnız form state'inde).
- **Sapma:** sınamadan sonra şifre alanı **temizlenmiyor** — kullanıcı şifreyi
  girip sınayıp ardından kaydediyor; temizlemek yeniden yazmayı gerektirirdi.
- Testler (6): kayıtlı tanım ve adres gösterimi, uyumsuzluk uyarısı, boş şifrenin
  gönderilmemesi, geçersiz VKN'de istek atılmaması, sınama sonucu + form
  değişince kalkması, kimlik hatası mesajı. Yetkisiz görünüm `YoneticiAlani`
  testleriyle kapsanıyor (EFAT-16).
- Kontroller: typecheck, lint (önceden var olan 4 uyarı), prettier, 139 vitest,
  build yeşil.
- Doğrulanmayan: tarayıcıda açık/koyu tema ve dar ekran görünümü elle denenmedi.

2026-09-23 (Codex incelemesi): Sınama sürerken form değişirse eski isteğin
sonucu artık başarı/hata olarak gösterilmiyor. Aktif ortam değişimi formdaki
kaydedilmemiş alanları silmiyor; başarılı kayıttan sonra şifre alanı açıkça
temizleniyor. Üç davranışın regresyon testi geçti. Tarayıcıda görsel kontrol
bu incelemede yapılmadı.
