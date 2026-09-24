# EFAT-11 — eFatura ekranları ve raporlar

- **Durum:** Faz 1 bitti (lokal, yayınlanmadı) — mutabakat görünümü ERP fazında (EFAT-09 sonrası)
- **Bağımlılık:** EFAT-10, EFAT-18; mutabakat görünümü EFAT-09, EFAT-15
- **Skill:** vercel-react-best-practices, vercel-composition-patterns, tailwind-design-system, laravel-best-practices, supabase-postgres-best-practices, testing-best-practices

## Neden

Kullanıcılar gelen ve giden faturaları ve mutabakat farklarını görüp raporlayacak.

## Faz 1 kapsamı (2026-09-23 kararı: ERP'siz)

İzibiz'den çekilen özetler (EFAT-10) listelenir; tarih aralığı seçilir
(varsayılan 01.01.2026 – bugün), PDF görüntülenir, Excel alınır. Yetki EFAT-18
izinleriyle. Excel için `phpoffice/phpspreadsheet` eklendi (kullanıcı onayı 2026-09-23).

## Yapılacaklar

- [x] Menüde "e-Fatura" grubu: Gelen Faturalar, Giden Faturalar
      (`efatura.goruntule`). Mutabakat ekranı ERP fazında.
- [x] Filtreler: tarih aralığı (en çok 366 gün), arama (no, ETTN, karşı
      VKN/unvan), durum, ERP okudu (evet/hayır/bilinmiyor), para birimi.
      Kategori (mutabakat) ERP fazında.
- [ ] Mutabakat özeti — EFAT-09 sonrası.
- [x] Dışa aktarma: Excel (.xlsx), `efatura.disari_aktar`.
- [x] Yetki (S7): liste/durum `efatura.goruntule`, PDF `efatura.pdf`, Excel
      `efatura.disari_aktar`, elle senkron `efatura.senkron` — hepsi sunucuda;
      PDF/Excel/senkron ayrıca `efatura.goruntule` ister (kod incelemesi).
- [x] Ortam (Test/Canlı rozeti), son başarılı güncelleme, güncel değil
      uyarısı, süren/sıradaki senkron, art arda hatalar görünür. Yükleniyor,
      boş ve hata durumları ayrı; geçersiz tarih aralığında eski liste gösterilmez.
- [x] Detayda kaynak kimlikleri (İzibiz kimliği + ETTN), ham durum kodu ile
      açıklaması, GİB durumu, ERP/portal okundu bayrakları (yalnız gösterilir).
- [x] Fatura aslı: PDF İzibiz `preview/pdf` ucundan SUNUCU üzerinden anlık
      okunur (saklanmaz); token ve İzibiz adresi tarayıcıya gitmez. Başka
      ortamın faturası 404. HTML/XML gösterilmez. Gövde `%PDF-` değilse 502.
- [x] Liste, özet ve Excel aynı sorgudan (`EFaturaSorgusu`): sayılar ile
      satırlar ayrışmaz. Tutarlar para birimi bazında, PostgreSQL numeric ile
      toplanır. Excel görünen sayfayı değil filtrenin tamamını yazar.
- [x] Excel'de metin hücreleri açıkça metin tipinde (formül enjeksiyonu yok);
      satır sınırı 20.000 (aşılırsa 422 + "aralığı daraltın").
- [x] Entegratör aktif ortamı değişince e-Fatura sorgu önbelleği silinir;
      çıkışta tüm sorgular zaten temizleniyor.
- [x] Backend uçları + feature testleri, frontend sayfa testleri.

## Kabul kriterleri

- [ ] Liste ve özet sayıları EFAT-09 sonuçlarıyla tutuyor — ERP fazında.
- [x] Büyük aralıklarda sayfalama ve makul yanıt süresi — lokal PostgreSQL,
      gerçek test hesabı verisi (yıllık 11.411 gelen / 27.568 giden): liste +
      özet + seçenekler 18–30 ms, metin araması 34–91 ms.

## Sonuç

2026-09-23 (Claude) — faz 1 lokalde bitti, yayınlanmadı.

- Backend:
  - `config/efatura.php`: liste 366 gün, elle senkron 92 gün, Excel 20.000
    satır + 512M bellek, güncellik 60 dk, yarım kalma 120 dk.
  - `EFaturaSorgusu` (filtre, allow-list sıralama, para birimi özeti,
    seçenekler), `EFaturaDurumServisi` (veri zamanı: başladığı günü kapsayan
    son TAM çalışma; art arda hata sayısı), `EFaturaExcelAktarici`,
    `IzibizIstemcisi::getPdf` (token yenilemeli GET; ortak yol denetimi).
  - `EFaturaController` (index, excel, pdf), `EFaturaSenkronController`
    (durum, baslat), `EFaturaListeRequest`, `EFaturaSenkronRequest`,
    `EFaturaResource`, `EFaturaManuelSenkron` işi (tanım artık aktif değilse
    çalışmaz; tanım başına tek istek `Cache::add` bayrağıyla), `EFaturaFactory`.
  - Rotalar: `GET /efatura/durum`, `GET /efatura/{gelen|giden}/faturalar`,
    `GET .../faturalar/excel` (throttle 5/dk), `GET /efatura/faturalar/{id}/pdf`
    (throttle 30/dk), `POST /efatura/senkron` (202; ikinci istek 409).
  - PDF ucunda rota model bağlama bilinçli yok: kayıt yetki denetiminden
    sonra aranır (yetkisiz kullanıcı fatura kimliğinin varlığını öğrenemez).
  - `config/queue.php`: `retry_after` varsayılanı 90 → 360 (elle senkron işi 300 sn).
- Frontend: `features/efatura/efaturaApi.ts`, `pages/EFaturalar/`
  (`EFaturalarPage` gelen/giden, `SenkronDurumuPaneli`, `FaturaDetayi`,
  `PdfGoruntuleyici`), rotalar `IzinAlani` altında, menüde "e-Fatura" grubu.
  `?erp_okundu=hayir` adres parametresi (alarm mailinin bağlantısı) filtreyi açar.
- Ölçümler (lokal PG, gerçek test verisi): Excel 11.411 satır 164 MB / 5 sn,
  20.000 satır 226 MB / 8 sn → uçta bellek sınırı 512M. PDF canlı test hesabında
  okundu (gelen 48 KB, giden 67 KB, ~0,7–1,1 sn).
- Testler: backend `EFaturaEkranTest` (30), frontend `EFaturalarPage.test.tsx` (11).
- Doğrulanmayan: ekranların tarayıcıda elle denenmesi; PDF'in tarayıcı
  görüntüleyicisinde açılması (jsdom'da blob adresi sahte).
