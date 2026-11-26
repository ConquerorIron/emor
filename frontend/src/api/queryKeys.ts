/**
 * Tüm TanStack Query key'leri burada tanımlanır; invalidation bu key'ler
 * üzerinden yapılır (rules.md §2). Modül ekledikçe genişler.
 */
export const queryKeys = {
  auth: {
    me: ['auth', 'me'],
  },
  ayarlar: {
    sqlBaglantilari: ['ayarlar', 'sqlBaglantilari'],
    // sqlBaglantilari'nın altında: o anahtarın invalidation'ı rozeti de tazeler
    aktifOrtam: ['ayarlar', 'sqlBaglantilari', 'aktifOrtam'],
    entegratorBaglantilari: ['ayarlar', 'entegratorBaglantilari'],
    mail: ['ayarlar', 'mail'],
    izinler: ['ayarlar', 'izinler'],
    roller: ['ayarlar', 'roller'],
    kullanicilar: ['ayarlar', 'kullanicilar'],
    alarmKurallari: ['ayarlar', 'alarmKurallari'],
  },
  // e-Fatura (EFAT-11): entegratör ortamı değişince ['efatura'] ile hepsi tazelenir
  efatura: {
    hepsi: ['efatura'],
    durum: ['efatura', 'durum'],
    /** Yöne ait tüm liste sayfaları (senkron/ortam değişiminde tazelenir) */
    yonListeleri: (yon: string) => ['efatura', 'liste', yon] as const,
    liste: (yon: string, parametreler: Record<string, unknown>) =>
      ['efatura', 'liste', yon, parametreler] as const,
    pdf: (faturaId: number) => ['efatura', 'pdf', faturaId] as const,
  },
  // Tüm Faturalar — bütün kaynakların (İzibiz, Protel, Logo, e-Arşiv) birleşik gelen listesi
  tumFaturalar: {
    hepsi: ['tumFaturalar'],
    liste: (parametreler: Record<string, unknown>) =>
      ['tumFaturalar', 'liste', parametreler] as const,
  },
  // Protel Gelen Faturalar (PRT-12) — otelin posta kutusu
  protel: {
    hepsi: ['protel'],
    durum: ['protel', 'durum'],
  },
  // Tek kaynaklı gelen fatura ekranları (Protel, Logo, e-Arşiv): onEk başına listeler
  kaynak: {
    listeler: (onEk: string) => [onEk, 'liste'] as const,
    liste: (onEk: string, parametreler: Record<string, unknown>) =>
      [onEk, 'liste', parametreler] as const,
    yuklemeler: (onEk: string) => [onEk, 'yuklemeler'] as const,
    iceAktarimKolonlari: (onEk: string) => [onEk, 'iceAktarimKolonlari'] as const,
  },
  // e-Fatura işlem geçmişi (EK-03) ve pano (EK-09): elle işlemden sonra tazelenir
  efaturaGecmis: {
    hepsi: ['efaturaGecmis'],
    fatura: (ekran: string, faturaId: number) => ['efaturaGecmis', ekran, faturaId] as const,
    liste: (parametreler: Record<string, unknown>) =>
      ['efaturaGecmis', 'liste', parametreler] as const,
  },
  efaturaPano: {
    hepsi: ['efaturaPano'],
    ozet: (parametreler: Record<string, unknown>) => ['efaturaPano', 'ozet', parametreler] as const,
    firmalar: (parametreler: Record<string, unknown>) =>
      ['efaturaPano', 'firmalar', parametreler] as const,
  },
  kesinti: {
    turler: ['kesinti', 'turler'],
    projeler: ['kesinti', 'projeler'],
    firmalar: ['kesinti', 'firmalar'],
    paraBirimleri: ['kesinti', 'paraBirimleri'],
    // Formların seçim listeleri: tanım ekranlarında değişince tazelenir (['kesinti'] altında)
    secenekler: ['kesinti', 'secenekler'],
    // İcmal ekranları (anahtar: 'isgucu', 'kampMalzeme' …)
    icmalListeleri: (icmal: string) => ['kesinti', icmal, 'liste'] as const,
    icmalListesi: (icmal: string, parametreler: Record<string, unknown>) =>
      ['kesinti', icmal, 'liste', parametreler] as const,
    // Listenin altında: satır eklenip silinince liste ile birlikte tazelenir
    icmalPivotu: (icmal: string, pivot: string, parametreler: Record<string, unknown>) =>
      ['kesinti', icmal, 'liste', 'pivot', pivot, parametreler] as const,
    // Taşeron / proje filtreleri ekranın kayıtlarından: satırlar değişince liste ile birlikte tazelenir
    icmalFiltreSecenekleri: (icmal: string) =>
      ['kesinti', icmal, 'liste', 'filtreSecenekleri'] as const,
    icmalYuklemeleri: (icmal: string) => ['kesinti', icmal, 'yuklemeler'] as const,
    // Kesinti raporları (parametre başına) ve parametre seçimleri (rapor: 'ozet', 'pivot')
    ozet: (parametreler: unknown) => ['kesinti', 'ozet', parametreler] as const,
    pivot: (parametreler: unknown) => ['kesinti', 'pivot', parametreler] as const,
    raporSecenekleri: (rapor: string) => ['kesinti', rapor, 'secenekler'] as const,
    icmalKolonlari: (icmal: string) => ['kesinti', icmal, 'kolonlar'] as const,
    // ERP önizlemeleri her açılışta taze okunur (önbelleğe güvenilmez)
    erpProjeleri: ['kesinti', 'erpProjeleri'],
    erpFirmalari: ['kesinti', 'erpFirmalari'],
  },
  // Yedekleme (BY-015…018): bir isteğin bitişi ['yedekleme'] altındakileri tazeler
  yedekleme: {
    hepsi: ['yedekleme'],
    yedekListeleri: ['yedekleme', 'yedekler'],
    yedekler: (parametreler: Record<string, unknown>) =>
      ['yedekleme', 'yedekler', parametreler] as const,
    ozet: ['yedekleme', 'ozet'],
    /** İstek durumu her yoklamada taze okunur; tazeleme anahtarlarının dışında */
    istek: (kimlik: string) => ['yedeklemeIstegi', kimlik] as const,
    hedefler: ['yedekleme', 'hedefler'],
    hedefTurleri: ['yedekleme', 'hedefTurleri'],
    planlar: ['yedekleme', 'planlar'],
    geriYuklemeler: ['yedekleme', 'geriYuklemeler'],
    islemKayitlari: (parametreler: Record<string, unknown>) =>
      ['yedekleme', 'islemKayitlari', parametreler] as const,
    ayarlar: ['yedekleme', 'ayarlar'],
  },
  // ERP raporlarının parametre seçenekleri (raporun kendisi "Raporu Çalıştır"la, mutation)
  raporlar: {
    secenekler: (rapor: string) => ['raporlar', rapor, 'secenekler'] as const,
  },
  /** Ekran tasarım motoru — formu çizen yayındaki düzen */
  ekranTasarimi: (ekran: string) => ['ekranTasarimi', ekran] as const,
  ekranTaslagi: (ekran: string) => ['ekranTaslagi', ekran] as const,
  // ERP view'larından ortak seçim listeleri (program genelinde kullanılır)
  secenekler: {
    personeller: ['secenekler', 'personeller'],
    tabloMaddesi: (tur: number, sirala: string) =>
      ['secenekler', 'tabloMaddesi', tur, sirala] as const,
    ilgili: (cins: string) => ['secenekler', 'ilgili', cins] as const,
    depolar: ['secenekler', 'depolar'],
    firmamizAdresleri: ['secenekler', 'firmamizAdresleri'],
    // Satır listeleri seçili projeye bağlıdır — proje başına önbelleklenir
    aktiviteler: (projemizId: string) => ['secenekler', 'aktiviteler', projemizId] as const,
    masrafMerkezleri: (projemizId: string) =>
      ['secenekler', 'masrafMerkezleri', projemizId] as const,
    // Ürün araması sunucuda: her arama metni ayrı önbellek girdisidir
    urunler: (ara: string) => ['secenekler', 'urunler', ara] as const,
    ekipmanlar: ['secenekler', 'ekipmanlar'],
    butceKalemleri: (projemizId: string) => ['secenekler', 'butceKalemleri', projemizId] as const,
    duranVarliklar: ['secenekler', 'duranVarliklar'],
    ambalajlar: ['secenekler', 'ambalajlar'],
    paralar: ['secenekler', 'paralar'],
    onayRolleri: ['secenekler', 'onayRolleri'],
    // Kur para + belge tarihi ikilisine bağlıdır
    kur: (paraId: string, tarih: string) => ['secenekler', 'kur', paraId, tarih] as const,
    // Satırdaki personel — başlıktaki 'personeller' listesinden farklı kaynak
    partiYamasiPersonelleri: ['secenekler', 'partiYamasiPersonelleri'],
  },
} as const
