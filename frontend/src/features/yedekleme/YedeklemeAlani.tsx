import { Navigate, Outlet } from 'react-router-dom'

import { useAuth } from '@/hooks/useAuth'

/**
 * Yedekleme ekranları yalnız modül sunucuda açıkken (YEDEKLEME_AKTIF) açılır;
 * kapalıyken doğrudan adresle gelen de anasayfaya döner. İzin denetimi içteki
 * IzinAlani'ndadır; asıl koruma backend'dedir (409 YEDEKLEME_KAPALI).
 */
export function YedeklemeAlani() {
  const { user } = useAuth()

  if (user?.yedekleme_aktif !== true) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
