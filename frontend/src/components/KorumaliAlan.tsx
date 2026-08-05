import { Navigate } from 'react-router-dom'

import { FullPageLoader } from '@/components/FullPageLoader'
import { useAuth } from '@/hooks/useAuth'
import { AppLayout } from '@/layouts/AppLayout'

/** Oturum gerektiren alan: yükleniyorsa spinner, oturum yoksa /giris'e yönlendirme. */
export function KorumaliAlan() {
  const { user, yukleniyor } = useAuth()

  if (yukleniyor) {
    return <FullPageLoader />
  }

  if (!user) {
    return <Navigate to="/giris" replace />
  }

  return <AppLayout />
}
