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
