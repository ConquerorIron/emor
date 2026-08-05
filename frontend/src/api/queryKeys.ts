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
} as const
