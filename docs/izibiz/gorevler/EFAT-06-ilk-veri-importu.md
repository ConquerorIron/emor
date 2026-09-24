# EFAT-06 — Test tanımının tek seferlik importu

- **Durum:** Bitti (lokal) — sunucu importu EFAT-14 yayın adımında
- **Bağımlılık:** EFAT-03, EFAT-04, EFAT-05
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices

## Neden

İlk test tanımı elle girilmek yerine bir kez veritabanına aktarılacak.

## Yapılacaklar

- [x] Kaynak: `docs/izibiz/mail.md` → ortam `test`, sağlayıcı `izibiz`,
      API `https://apitest.izibiz.com.tr`, portal `https://portaltest.izibiz.com.tr`,
      VKN, posta kutusu ve gönderici birim.
- [x] Şifre koda, seeder'a ya da migration'a **yazılmaz**. Korumalı yerel
      girdiden veya gizli etkileşimli girişten al; shell argümanına/geçmişine,
      Tinker geçmişine, stdout veya loga yazma. Model encrypted cast kullanır.
- [x] İlk test importu kullanıcı tarafından istenmiştir. Hedef DB/ortam
      doğrulanır; aynı şirket/sağlayıcı/ortam için tekrar import kayıt çoğaltmaz.
      Önceden elle değişmiş tanımı sessizce ezmez, SQL ortamını değiştirmez.
- [x] Lokal (`erp`) veritabanına aktar, ardından ekrandan "Bağlantıyı sına".
- [ ] Sunucu importunu EFAT-14 yayın adımında ayrıca yürüt; test tanımının
      sunucuda bulunması canlı alarm veya otomatik aktifleşme başlatmasın.
- [ ] Canlı tanımı, bilgiler geldiğinde ekrandan girilir (EFAT-14).

## Kabul kriterleri

- [ ] Ekranda test tanımı görünüyor ve sınama başarılı.
- [x] Repoda şifre yok.
- [x] Tekrar importun mevcut kayıtları koruduğu ve ciphertext saklandığı
      sentetik test verisiyle doğrulanmış; gerçek sırlar test fixture'ına girmemiş.

## Sonuç

2026-09-23 (Claude) — lokalde uygulandı:

- Komut: `php artisan entegrator:tanim-aktar {ortam} --dosya=<json|-> [--uzerine-yaz] [--aktif-yap]`
  (`app/Console/Commands/EntegratorTanimAktar.php`). Ekranla aynı doğrulama
  kuralları; aynı tanım tekrar aktarılınca "zaten güncel" (kayıt çoğalmaz,
  kimlik sürümü değişmez); farklı tanım `--uzerine-yaz` olmadan ezilmez; SQL
  ortamına dokunmaz; aktiflik yalnız `--aktif-yap` ile. Çıktı ve hata mesajları
  şifre/JSON içeriği taşımaz.
- Lokal aktarım: `mail.md` satırları (etiketlere göre) JSON'a çevrilip pipe ile
  `--dosya=-`'e verildi — şifre komut satırına, dosyaya, shell geçmişine veya
  çıktıya girmedi. Sonuç: "test tanımı oluşturuldu", aktif ortam `test`. İkinci
  çalıştırma "zaten güncel" dedi.
- Kayıtlı tanımla gerçek İzibiz test API'sinden token alındı (EFAT-04 Sonuç).
- Testler: `EntegratorTanimAktarTest` (7) — sentetik veriyle: şifreli saklama ve
  çıktıda şifre olmaması, tekrar aktarımda çoğalmama, elle değişmiş tanımın
  korunması, `--uzerine-yaz`, `--aktif-yap`'ın SQL'e dokunmaması, şifresiz ve
  bozuk JSON reddi.
- Not: WSL'de `file_get_contents('/dev/stdin')` false döndüğü için standart
  girdi `--dosya=-` (`php://stdin`) ile okunur.
- Açık: sunucu aktarımı EFAT-14 yayın adımında aynı pipe yöntemiyle (sunucuda
  aktif yapılmadan); canlı tanım bilgiler gelince ekrandan girilecek. Ekranda
  görünüm tarayıcıda elle kontrol edilmedi.
