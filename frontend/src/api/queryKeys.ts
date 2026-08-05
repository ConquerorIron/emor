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
  },
  // ERP view'larından ortak seçim listeleri (program genelinde kullanılır)
  secenekler: {
    personeller: ['secenekler', 'personeller'],
    tabloMaddesi: (tur: number) => ['secenekler', 'tabloMaddesi', tur] as const,
    ilgili: (cins: string) => ['secenekler', 'ilgili', cins] as const,
  },
} as const
