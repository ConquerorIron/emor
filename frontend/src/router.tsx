import { createBrowserRouter, Navigate } from 'react-router-dom'

import { KorumaliAlan } from '@/components/KorumaliAlan'
import { UygulamaHatasi } from '@/components/UygulamaHatasi'
import { AuthLayout } from '@/layouts/AuthLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { LoginPage } from '@/pages/LoginPage'

/*
 * Route bazlı code splitting: sayfalar route.lazy ile ayrı chunk'lara bölünür.
 * Login ve Dashboard bilinçli statik: auth kabuğu ve ilk yönlendirme beklemesiz açılır.
 */
export const router = createBrowserRouter([
  {
    path: '/giris',
    element: <AuthLayout />,
    errorElement: <UygulamaHatasi />,
    children: [{ index: true, element: <LoginPage /> }],
  },
  {
    path: '/',
    element: <KorumaliAlan />,
    errorElement: <UygulamaHatasi />,
    children: [
      { index: true, element: <DashboardPage /> },
      {
        path: 'ayarlar/sql-baglantilari',
        lazy: async () => ({
          Component: (await import('@/pages/SqlBaglantilariPage')).SqlBaglantilariPage,
        }),
      },
    ],
  },
  {
    path: '*',
    element: <Navigate to="/" replace />,
  },
])
