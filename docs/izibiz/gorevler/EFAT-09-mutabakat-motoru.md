# EFAT-09 — Mutabakat (eşleştirme) motoru

- **Durum:** Bekliyor
- **Bağımlılık:** EFAT-07, EFAT-08
- **Skill:** laravel-best-practices, testing-best-practices

## Neden

İki tam ve aynı kapsamdaki listeyi karşılaştırmak. Sonuçlar: Eşleşti /
Yalnız ERP'de / Yalnız entegratörde / Farklı / İnceleme gerekli.
Eksik çalışma ayrı durumdur; yokluk veya çözülme kanıtı oluşturmaz.

## Yapılacaklar

- [ ] Eşleştirmeden önce şirket, ortam/bağlantı çifti, yön, belge türü,
      tarih kapsamı ve iki kaynağın tamlığını doğrula. Uyuşmazsa işlemi reddet.
- [ ] Öncelik normalize ETTN. İki tarafta da ETTN varsa ve farklıysa aynı
      belge no nedeniyle eşleştirme yapma. ETTN eksikse belge no + düzenleyen
      kimliği + kapsam ile ancak tek aday olduğunda, onaylı kuralla eşleştir.
- [ ] Mükerrer/çok adaylı/eksik kimlikli kayıtları `İnceleme gerekli` yap;
      associative map ile son kaydın öncekini ezmesine izin verme. Kullanılan
      eşleşme yöntemi ve çelişen alanlar sonuçta görülsün.
- [ ] Karşılaştırılacak parasal alan ve tolerans EFAT-15 kararından gelir;
      ±0,01 kendiliğinden seçilmez. Para birimi farklıysa doğrudan tutar
      eşitliği aranmaz; null/sıfır ve eksik tarih ayrımı korunur.
- [ ] Gelen/giden yönü ERP sorgusuyla doğrulanır; gelen/giden iade, iptal,
      red ve taslak politikası ayrıdır. İade varlığı asıl faturanın yokluğu değildir.
- [ ] Tek tarafta görünen kayıt için farklı tarih alanı/geç kayıt olasılığını
      ele al; gerekiyorsa kaynak katmanında kimlikle doğrula. Sonuç seçilen
      kapsamda yokluğu anlatır, tek başına "GİB'e gönderilmedi" demek değildir.
- [ ] Saf servis (G/Ç yok) → birim testlerle her sonuç türü kapsanır.
- [ ] Sonuç özeti: sayılar ve kategori bazında listeler.
- [ ] Sıralamadan bağımsız sonuç üret; her kaynak kaydı sonuç veya inceleme
      kategorisinde izlenebilir olsun. Büyük veri için iç içe tam liste taraması yapma.

## Kabul kriterleri

- [ ] Birim testleri her kategoriyi ve sınır durumlarını
      (mükerrer anahtar, eksik ETTN) kapsıyor.
- [ ] Çelişen ETTN, farklı şirket/ortam, eksik kaynak, iade ve para birimi
      sınırlarında yanlış eşleşme/yokluk oluşmuyor; sayımlar veri kaybını gizlemiyor.

## Sonuç

—
