# EFAT-10 — Zamanlanmış çekme ve fatura özetlerinin saklanması

- **Durum:** Faz 1 bitti (lokal) — mutabakat/fark kısmı ERP fazında (EFAT-09 sonrası)
- **Bağımlılık:** EFAT-07 (bitti); fark/mutabakat maddeleri için EFAT-09, EFAT-15
- **Skill:** laravel-best-practices, supabase-postgres-best-practices, testing-best-practices

## Neden

Faz 1 (ERP'siz): İzibiz'deki e-Faturalar 01.01.2026'dan itibaren saklanır,
ekranlar (EFAT-11) ve raporlar/Excel bu özetlerden hızlıca çalışır; alarmlar
(EFAT-13) "yeni mi, kaç gündür" sorularını buradan cevaplar.

## Kararlar

- **S4 (2026-09-23, kullanıcı):** fatura özetleri PostgreSQL'de tutulabilir —
  "ERP verisi bizde tutulmaz" kuralına onaylı istisna. Fatura içeriği/XML/PDF
  tutulmaz; görüntüleme İzibiz'den anlık.
- **S11:** ilk tarama 01.01.2026. Saklama/temizlik süresi henüz belirlenmedi
  (şimdilik silme yok).
- "ERP okundu" bayrağı yalnız okunur ve saklanır; hiç değiştirilmez.

## Faz 1 — yapılacaklar

- [x] Migration, model, upsert servisi, zamanlanmış çekme, çalışma günlüğü
      (kapsam, adetler, tamlık, hata, süre).
- [x] İzibiz beklenirken PostgreSQL transaction'ı açık tutulmaz; yazma kısa
      transaction'larla (500'lük partiler) atomik upsert.
- [x] Başarısız/yarım çalışmada önceki özetler bozulmaz; çalışma
      `basarisiz`/`eksik` işaretlenir (ekranda güncellik uyarısı EFAT-11).
- [x] Geç gelen kayıt: artımlı senkron İzibiz'e **ulaşma** tarihine göre (son
      2 gün) → eski tarihli ama yeni ulaşan fatura da yakalanır; günlük tazeleme
      belge tarihine göre son 45 gün (kabul/red/GİB durumu).
- [x] Aynı tanım + yön için eşzamanlı senkron kilitle engellenir; zamanlayıcıda
      ayrıca `withoutOverlapping`. Test/canlı hesap özetleri tanım kimliğiyle ayrılır.
- [x] Durdurma seçeneği: `.env` → `EFATURA_SENKRON_AKTIF=false`.
- [ ] Manuel "şimdi senkronla" düğmesi ve son başarı/hata görünümü — EFAT-11 ekranında.
- [ ] Saklama/temizlik süresi — karar bekliyor.

## Sonraki faz (ERP/mutabakat — EFAT-09 sonrası)

- Çalışma kimliğiyle iki kaynağın (ERP + İzibiz) tamlığı birlikte kaydedilsin;
  okumalar bitmeden mutabakat sonucu yayımlanmasın.
- Açık farklar anahtarla/kontrollü tarihsel aralıkla yeniden kontrol edilsin;
  pencereden çıkan kayıt "çözüldü" sayılmasın; fark/bildirim tekillik geçmişi
  silinerek mail fırtınası yaratılmasın.
- Tanım değişimi/ortam geçişinde eski işin başka hesaba taşınmaması (faz 1'de
  senkron her çalışmada aktif tanımı yeniden okur; iş tanım kimliğine bağlıdır).

## Kabul kriterleri

- [x] İş iki kez çalışınca kayıt çoğalmıyor (test + canlıda doğrulandı).
- [x] Hata durumunda önceki özetler bozulmuyor.
- [x] Farklı ortamda aynı kaynak kimliği karışmıyor; eşzamanlı senkron atlanıyor.
- [ ] PostgreSQL kısıtları/eşzamanlılık EFAT-17 kabulüne aktarılacak.

## Sonuç

2026-09-23 (Claude) — faz 1 uygulandı (lokal):

- Tablolar: `efatura_faturalari` (tanım + yön + kaynak_id unique; tanım+yön+belge
  tarihi ve tanım+ETTN indeksleri; tutarlar `numeric(20,4)`, zamanlar
  `timestamptz`, serbest metinler `text`), `efatura_senkron_calismalari`
  (çalışma günlüğü). Modeller `EFatura`, `EFaturaSenkronCalismasi`.
- `EFaturaSenkronServisi::senkronEt()`: aralığı takvim aylarına böler, her ay bir
  çalışma; `ilk_gorulme`/`created_at` güncellenmez; yazma hatasında çalışma
  `basarisiz` (`YAZMA_HATASI`) kapanır, hata mesajına fatura verisi (SQL
  parametreleri) girmez.
- Komut `efatura:senkron` (`--gun` | `--baslangic`/`--bitis`, `--yon`,
  `--tarih-turu`, `--tetikleyen`); İstanbul takvim günü. Zamanlayıcı
  (`routes/console.php`): 15 dakikada bir `--gun=2 --tarih-turu=DELIVERY`,
  her gün 03:15 (İstanbul) `--gun=45`; ikisi de `withoutOverlapping`.
- **Gerçek veriyle bulunan hatalar:** `issueTime` saat dilimli gelebiliyor
  (`12:47:05+00:00`) ve unvan/GİB açıklaması 255 karakteri aşabiliyor —
  SQLite testleri uzunluğu denetlemediği için yalnız PostgreSQL'de görüldü;
  kolonlar genişletildi / `text` yapıldı. İlk hatada çalışma "çalışıyor"
  durumunda kalmış ve hata mesajı fatura verisini dökmüştü — ikisi de düzeltildi
  ve regresyon testi eklendi.
- Testler: `EFaturaSenkronTest` (12). Backend 185 test yeşil, Pint temiz.
- Canlı (lokal PostgreSQL, test hesabı): **ilk tarama 01.01–23.09.2026 —
  gelen 11.411, giden 27.568 = 38.979 fatura, 18 aylık okumanın hepsi tam,
  108 sn.** Ardından artımlı senkron: 79 + 124 kayıt güncellendi, yeni 0,
  toplam değişmedi (idempotent). `schedule:list` iki görevi gösteriyor.
- Sunucu notu: zamanlayıcı yayından sonra aktif entegratör ortamı varsa kendiliğinden
  çalışır; ilk tarama sunucuda bir kez `efatura:senkron --baslangic=2026-01-01
  --tetikleyen=ilk_tarama` ile yapılmalı (EFAT-14).
