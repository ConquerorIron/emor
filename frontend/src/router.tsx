import { createBrowserRouter, Navigate } from 'react-router-dom'

import { IzinAlani } from '@/components/IzinAlani'
import { KorumaliAlan } from '@/components/KorumaliAlan'
import { UygulamaHatasi } from '@/components/UygulamaHatasi'
import { AuthLayout } from '@/layouts/AuthLayout'
import { DashboardPage } from '@/pages/Dashboard/DashboardPage'
import { LoginPage } from '@/pages/Login/LoginPage'

/*
 * Route bazlı code splitting: sayfalar route.lazy ile ayrı chunk'lara bölünür.
 * Login ve Dashboard bilinçli statik: auth kabuğu ve ilk yönlendirme beklemesiz açılır.
 *
 * Her ekran kendi görüntüleme izniyle korunur (backend de denetler); güncelleme
 * izni yoksa sayfa salt okunur açılır.
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
        element: <IzinAlani izin="satinalma_talebi.goruntule" />,
        children: [
          {
            path: 'satinalma/talep',
            lazy: async () => ({
              Component: (await import('@/pages/SatinalmaTalebi/SatinalmaTalebiPage'))
                .SatinalmaTalebiPage,
            }),
          },
        ],
      },
      {
        // e-Fatura (EFAT-11): görüntüleme izni olanlar
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
        element: <IzinAlani izin="ekran_tasarimi.goruntule" />,
        children: [
          {
            path: 'ayarlar/ekran-tasarimi',
            lazy: async () => ({
              Component: (await import('@/pages/EkranTasarimAyarlari/EkranTasarimAyarlariPage'))
                .EkranTasarimAyarlariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="sql_baglantilari.goruntule" />,
        children: [
          {
            path: 'ayarlar/sql-baglantilari',
            lazy: async () => ({
              Component: (await import('@/pages/SqlBaglantilari/SqlBaglantilariPage'))
                .SqlBaglantilariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kullanicilar.goruntule" />,
        children: [
          {
            path: 'ayarlar/kullanicilar',
            lazy: async () => ({
              Component: (await import('@/pages/Kullanicilar/KullanicilarPage')).KullanicilarPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="roller.goruntule" />,
        children: [
          {
            path: 'ayarlar/roller',
            lazy: async () => ({
              Component: (await import('@/pages/Roller/RollerPage')).RollerPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="alarm_kurallari.goruntule" />,
        children: [
          {
            path: 'ayarlar/alarm-kurallari',
            lazy: async () => ({
              Component: (await import('@/pages/AlarmKurallari/AlarmKurallariPage'))
                .AlarmKurallariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="mail_ayarlari.goruntule" />,
        children: [
          {
            path: 'ayarlar/mail',
            lazy: async () => ({
              Component: (await import('@/pages/MailAyarlari/MailAyarlariPage')).MailAyarlariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="entegrator_baglantilari.goruntule" />,
        children: [
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
