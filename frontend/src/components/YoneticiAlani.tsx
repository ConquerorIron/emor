import { Navigate, Outlet } from 'react-router-dom'

import { useAuth } from '@/hooks/useAuth'

/**
 * Yalnız sistem yöneticisine açık sayfalar (KorumaliAlan'ın içinde kullanılır;
 * oturum orada çözülmüştür). Doğrudan URL ile gelen diğer kullanıcılar
 * anasayfaya döner. Asıl koruma backend'dedir — bu yalnız arayüzü düzenler.
 */
export function YoneticiAlani() {
  const { user } = useAuth()

  if (user?.sistem_yoneticisi !== true) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
