import { createBrowserRouter, Navigate } from 'react-router-dom'

import { IzinAlani } from '@/components/IzinAlani'
import { KorumaliAlan } from '@/components/KorumaliAlan'
import { UygulamaHatasi } from '@/components/UygulamaHatasi'
import { YoneticiAlani } from '@/components/YoneticiAlani'
import { AuthLayout } from '@/layouts/AuthLayout'
import { DashboardPage } from '@/pages/Dashboard/DashboardPage'
import { LoginPage } from '@/pages/Login/LoginPage'

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
        path: 'satinalma/talep',
        lazy: async () => ({
          Component: (await import('@/pages/SatinalmaTalebi/SatinalmaTalebiPage'))
            .SatinalmaTalebiPage,
        }),
      },
      {
        // e-Fatura (EFAT-11): görüntüleme izni olanlar (backend de denetler)
        element: <IzinAlani izin="efatura.goruntule" />,
        children: [
          {
            path: 'efatura/gelen',
            lazy: async () => ({
              Component: (await import('@/pages/EFaturalar/EFaturalarPage')).GelenFaturalarPage,
            }),
          },
          {
            path: 'efatura/giden',
            lazy: async () => ({
              Component: (await import('@/pages/EFaturalar/EFaturalarPage')).GidenFaturalarPage,
            }),
          },
        ],
      },
      {
        // Yönetim ekranları: yalnız sistem yöneticisi (backend de denetler)
        element: <YoneticiAlani />,
        children: [
          {
            path: 'ayarlar/ekran-tasarimi',
            lazy: async () => ({
              Component: (await import('@/pages/EkranTasarimAyarlari/EkranTasarimAyarlariPage'))
                .EkranTasarimAyarlariPage,
            }),
          },
          {
            path: 'ayarlar/sql-baglantilari',
            lazy: async () => ({
              Component: (await import('@/pages/SqlBaglantilari/SqlBaglantilariPage'))
                .SqlBaglantilariPage,
            }),
          },
          {
            path: 'ayarlar/kullanicilar',
            lazy: async () => ({
              Component: (await import('@/pages/Kullanicilar/KullanicilarPage')).KullanicilarPage,
            }),
          },
          {
            path: 'ayarlar/roller',
            lazy: async () => ({
              Component: (await import('@/pages/Roller/RollerPage')).RollerPage,
            }),
          },
          {
            path: 'ayarlar/alarm-kurallari',
            lazy: async () => ({
              Component: (await import('@/pages/AlarmKurallari/AlarmKurallariPage'))
                .AlarmKurallariPage,
            }),
          },
          {
            path: 'ayarlar/mail',
            lazy: async () => ({
              Component: (await import('@/pages/MailAyarlari/MailAyarlariPage')).MailAyarlariPage,
            }),
          },
          {
            path: 'ayarlar/entegrator-baglantilari',
            lazy: async () => ({
              Component: (await import('@/pages/EntegratorBaglantilari/EntegratorBaglantilariPage'))
                .EntegratorBaglantilariPage,
            }),
          },
        ],
      },
    ],
  },
  {
    path: '*',
    element: <Navigate to="/" replace />,
  },
])
