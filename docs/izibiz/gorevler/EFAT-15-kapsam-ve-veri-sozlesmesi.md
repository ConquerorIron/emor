# EFAT-15 — Şirket, ortam, veri ve eşleştirme kararları

- **Durum:** Devam (S5/S9 karara bağlandı; veri sözleşmesi ERP sorgusu ve S2/S3/S4/S7/S10/S11'i bekliyor)
- **Bağımlılık:** — (API alanları için EFAT-02 çıktısı kullanılır)
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices

## Neden

İlk taslakta bağlantı tekilliği şirket/hesap sayısı bilinmeden seçilmiş,
kaynakların ortak veri sözleşmesi EFAT-07'ye bırakılmıştı. Yanlış kapsam
faturaları karıştırabilir; eşleştirme ve saklama öncesinde karar gerekir.

## Yapılacaklar

- [x] Bağlantı aşaması: S5 ve S9 kararlaştırıldı (aşağıda). Tek şirket olduğu
      için mevcut yapıyla ilerlenir; tenant altyapısı kurulmaz.
- [x] ~~Öneri: SQL ve entegratör tanımını aynı ortama açıkça eşle, çelişkide
      backend durdursun.~~ Kullanıcı bu öneri yerine bağımsız seçimi seçti (S5).
- [ ] Bir çalışmaya iki bağlantı kimliği ve tanım sürümü sabitlensin.
      Kuyruk beklerken/çalışırken ortam ya da hesap değişirse eski iş yeni
      bağlantıya geçmesin; geçersiz çalışma sonlandırılıp yeniden başlatılsın.
- [ ] S2/S3/S10: e-Fatura/e-Arşiv kapsamı, ETTN/fatura no, belge görüntüleme
      ve karşılaştırılacak tutar alanları belirlenip örnek kayıtlarla eşlensin.
- [ ] Ortak sözleşme: şirket, ortam, kaynak/kaynak kayıt ID'si, belge türü,
      yön, ETTN, belge no, belge tarihi, alınma/kayıt zamanı, taraf kimlikleri,
      para birimi, seçilen parasal alanlar, ham ve normalleştirilmiş durum.
      Bilinmeyen değer null/eksik olarak korunur; sıfır veya kabul edildi sayılmaz.
- [ ] Parasal değerler binary float ile karşılaştırılmaz; decimal metin ve
      onaylanmış hassasiyet kullanılır. Vergi dahil toplam, ödenecek tutar,
      tevkifat/iskonto ve döviz farkları aynı anlamdaki alanlarla kıyaslanır.
      VKN/TCKN metindir; baştaki sıfırlar kaybolmaz. UUID ve belge no ayrı
      normalleştirilir; ham değerler izlenebilir kalır.
- [ ] S11: belge tarihi / entegratöre ulaşma / ERP kayıt tarihi ayrımını,
      saat dilimini, sınırları, ilk tarama aralığını, hacmi ve bekleme süresini belirle.
- [ ] S4: iki seçenek değerlendir: yalnız çalışma/fark/bildirim geçmişi
      veya yeniden üretilebilir fatura özetleri de. Alanlar, saklama süresi,
      silme ve yetkiler onaylanır; rapor/performans etkisi açıklanır.
      Alarm için tam fatura özetlerini kalıcı tutmak zorunlu kabul edilmez.
- [ ] S7: bağlantı/kural yönetimi, listeleme, detay, dışa aktarma ve manuel
      senkron için mevcut yetki yapısıyla erişim matrisi belirle.

## Kararlar

- **S9 (2026-09-23, kullanıcı):** İlk sürüm tek şirket (tek VKN) ve tek İzibiz
  hesabı. Entegratör tanımı ortam (`test`/`canli`) başına tektir;
  `sql_baglantilari` gibi. Çok şirket gerekirse ayrı bir migration ile açılır.
- **S5 (2026-09-23, kullanıcı):** Entegratörün aktif ortamı SQL'in aktif
  ortamından **tamamen bağımsız** seçilir. Uyuşmazlıkta yalnız uyarı gösterilir;
  backend mutabakatı/alarmı durdurmaz.
  - **Kabul edilen risk:** test ERP'si canlı İzibiz ile (ya da tersi)
    karşılaştırılabilir; o durumda "yalnız ERP'de / yalnız entegratörde"
    sonuçları ve alarmları yanıltıcı olur. Önerilen seçenek (SQL'e bağlı
    ortam) yerine bu seçildi.
  - **Uygulamaya etkisi (karara aykırı olmayan azaltımlar):** her mutabakat
    çalışması kullandığı SQL ve entegratör ortamını kaydeder; ekran, rapor ve
    alarm mailleri ortam çiftini gösterir; ortamlar uyuşmuyorsa uyarı her
    yerde görünür. Karşılaştırma engellenmez.

## Kabul kriterleri

- [x] S5/S9 kararı EFAT-03'ü başlatmaya yeterli ve görevde kayıtlı.
- [ ] Kaynak alanları örnek verilerle eşlenmiş; her belgenin karşılaştırma
      kapsamı ve eksik/mükerrer kimlik davranışı tanımlı.
- [ ] Kullanıcı kararı bekleyen maddeler açıkça işaretli; öneriler karar sayılmıyor.

## Sonuç

—
