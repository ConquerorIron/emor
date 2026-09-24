import { Navigate, Outlet } from 'react-router-dom'

import { useIzin } from '@/hooks/useIzin'

/**
 * Belirli bir izin isteyen sayfalar (EFAT-18) — KorumaliAlan'ın içinde
 * kullanılır. İzni olmayan kullanıcı doğrudan URL ile gelse de anasayfaya
 * döner. Asıl koruma backend'dedir.
 */
export function IzinAlani({ izin }: { izin: string }) {
  const izinli = useIzin(izin)

  if (!izinli) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
