import { useAuth } from '@/hooks/useAuth'

/**
 * Oturumdaki kullanıcının izni var mı (EFAT-18)? Yalnız arayüzü düzenler
 * (menü, düğme, rota); yetki backend'de ayrıca denetlenir.
 */
export function useIzin(izin: string): boolean {
  const { user } = useAuth()

  return user?.izinler.includes(izin) ?? false
}
