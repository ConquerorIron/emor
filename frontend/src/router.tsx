import { createBrowserRouter, Navigate } from 'react-router-dom'

import { IzinAlani } from '@/components/IzinAlani'
import { KorumaliAlan } from '@/components/KorumaliAlan'
import { UygulamaHatasi } from '@/components/UygulamaHatasi'
import { YedeklemeAlani } from '@/features/yedekleme/YedeklemeAlani'
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
    // Üstüne geri yüklemenin ilerlemesi (BY-030): oturum ve menü korumasının
    // DIŞINDA — işlem sırasında bakım modu var, veritabanı yer değiştirir,
    // oturumlar düşer; tek yetki tarayıcıda saklanan işleme özel anahtardır
    path: '/ayarlar/geri-yuklemeler/ustune/:islemKimligi',
    errorElement: <UygulamaHatasi />,
    lazy: async () => ({
      Component: (await import('@/pages/UstuneGeriYukleme/UstuneGeriYuklemePage'))
        .UstuneGeriYuklemePage,
    }),
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
        // e-Fatura panosu (EK-09): portal bazında sayılar; ayrı izin
        element: <IzinAlani izin="efatura_panosu.goruntule" />,
        children: [
          {
            path: 'efatura/pano',
            lazy: async () => ({
              Component: (await import('@/pages/EFaturaPanosu/EFaturaPanosuPage'))
                .EFaturaPanosuPage,
            }),
          },
        ],
      },
      {
        // e-Fatura işlem geçmişi (EK-03): elle işlemlerin kullanıcı bazlı kaydı
        element: <IzinAlani izin="efatura_islem_gecmisi.goruntule" />,
        children: [
          {
            path: 'efatura/islem-gecmisi',
            lazy: async () => ({
              Component: (await import('@/pages/EFaturaIslemGecmisi/EFaturaIslemGecmisiPage'))
                .EFaturaIslemGecmisiPage,
            }),
          },
        ],
      },
      {
        // Logo Gelen Faturalar (EK-07): kapanan portalın statik listesi
        element: <IzinAlani izin="logo_faturalari.goruntule" />,
        children: [
          {
            path: 'efatura/logo',
            lazy: async () => ({
              Component: (await import('@/pages/LogoFaturalari/LogoFaturalariPage'))
                .LogoFaturalariPage,
            }),
          },
        ],
      },
      {
        // e-Arşiv / e-SMM Gelen Faturalar / Makbuzlar (EK-08): aylık statik liste
        element: <IzinAlani izin="earsiv_faturalari.goruntule" />,
        children: [
          {
            path: 'efatura/earsiv',
            lazy: async () => ({
              Component: (await import('@/pages/EarsivFaturalari/EarsivFaturalariPage'))
                .EarsivFaturalariPage,
            }),
          },
        ],
      },
      {
        // Tüm Faturalar — bütün kaynakların birleşik gelen listesi; ayrı izin
        element: <IzinAlani izin="tum_faturalar.goruntule" />,
        children: [
          {
            path: 'efatura/tum',
            lazy: async () => ({
              Component: (await import('@/pages/EFaturalar/EFaturalarPage')).TumFaturalarPage,
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
        // Protel Gelen Faturalar — otelin posta kutusu (PRT-12); ayrı izin
        element: <IzinAlani izin="protel_faturalari.goruntule" />,
        children: [
          {
            path: 'efatura/protel',
            lazy: async () => ({
              Component: (await import('@/pages/ProtelFaturalari/ProtelFaturalariPage'))
                .ProtelFaturalariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kesinti_turleri.goruntule" />,
        children: [
          {
            path: 'kesinti/turler',
            lazy: async () => ({
              Component: (await import('@/pages/KesintiTurleri/KesintiTurleriPage'))
                .KesintiTurleriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="projeler.goruntule" />,
        children: [
          {
            path: 'kesinti/projeler',
            lazy: async () => ({
              Component: (await import('@/pages/Projeler/ProjelerPage')).ProjelerPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="firmalar.goruntule" />,
        children: [
          {
            path: 'kesinti/firmalar',
            lazy: async () => ({
              Component: (await import('@/pages/Firmalar/FirmalarPage')).FirmalarPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="para_birimleri.goruntule" />,
        children: [
          {
            path: 'kesinti/para-birimleri',
            lazy: async () => ({
              Component: (await import('@/pages/ParaBirimleri/ParaBirimleriPage'))
                .ParaBirimleriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="tum_kesintiler.goruntule" />,
        children: [
          {
            path: 'kesinti/tumu',
            lazy: async () => ({
              Component: (await import('@/pages/TumKesintiler/TumKesintilerPage'))
                .TumKesintilerPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kesinti_ozeti.goruntule" />,
        children: [
          {
            path: 'kesinti/ozet',
            lazy: async () => ({
              Component: (await import('@/pages/KesintiOzeti/KesintiOzetiPage')).KesintiOzetiPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="pivot.goruntule" />,
        children: [
          {
            // Menüde "Pivot" (kullanıcı isteği 2026-11-16); eski adres yönlenir
            path: 'kesinti/pivot',
            lazy: async () => ({
              Component: (await import('@/pages/Pivot/PivotPage')).PivotPage,
            }),
          },
          { path: 'kesinti/firma-ozeti', element: <Navigate to="/kesinti/pivot" replace /> },
        ],
      },
      {
        element: <IzinAlani izin="isgucu_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/isgucu',
            lazy: async () => ({
              Component: (await import('@/pages/IsgucuKesintileri/IsgucuKesintileriPage'))
                .IsgucuKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kamp_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/kamp',
            lazy: async () => ({
              Component: (await import('@/pages/KampKesintileri/KampKesintileriPage'))
                .KampKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kamp_malzeme_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/kamp-malzeme',
            lazy: async () => ({
              Component: (await import('@/pages/KampMalzemeKesintileri/KampMalzemeKesintileriPage'))
                .KampMalzemeKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="makina_ekipman_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/makina-ekipman',
            lazy: async () => ({
              Component: (
                await import('@/pages/MakinaEkipmanKesintileri/MakinaEkipmanKesintileriPage')
              ).MakinaEkipmanKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="tutanak_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/tutanak',
            lazy: async () => ({
              Component: (await import('@/pages/TutanakKesintileri/TutanakKesintileriPage'))
                .TutanakKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="yemek_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/yemek',
            lazy: async () => ({
              Component: (await import('@/pages/YemekKesintileri/YemekKesintileriPage'))
                .YemekKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="osgb_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/osgb',
            lazy: async () => ({
              Component: (await import('@/pages/OsgbKesintileri/OsgbKesintileriPage'))
                .OsgbKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="tetkik_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/tetkik',
            lazy: async () => ({
              Component: (await import('@/pages/TetkikKesintileri/TetkikKesintileriPage'))
                .TetkikKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="isg_ceza_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/isg-ceza',
            lazy: async () => ({
              Component: (await import('@/pages/IsgCezaKesintileri/IsgCezaKesintileriPage'))
                .IsgCezaKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="malzeme_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/malzeme',
            lazy: async () => ({
              Component: (await import('@/pages/MalzemeKesintileri/MalzemeKesintileriPage'))
                .MalzemeKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="kart_basim_kesintileri.goruntule" />,
        children: [
          {
            path: 'kesinti/kart-basim',
            lazy: async () => ({
              Component: (await import('@/pages/KartBasimKesintileri/KartBasimKesintileriPage'))
                .KartBasimKesintileriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="erp_kesinti_kontrolu.goruntule" />,
        children: [
          {
            path: 'raporlar/erp-kesinti-kontrolu',
            lazy: async () => ({
              Component: (await import('@/pages/ErpKesintiKontrolu/ErpKesintiKontroluPage'))
                .ErpKesintiKontroluPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="taseron_hakedisleri.goruntule" />,
        children: [
          {
            path: 'raporlar/taseron-hakedisleri',
            lazy: async () => ({
              Component: (await import('@/pages/TaseronHakedisleri/TaseronHakedisleriPage'))
                .TaseronHakedisleriPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="gider_yansitma_faturalari.goruntule" />,
        children: [
          {
            path: 'raporlar/gider-yansitma-faturalari',
            lazy: async () => ({
              Component: (
                await import('@/pages/GiderYansitmaFaturalari/GiderYansitmaFaturalariPage')
              ).GiderYansitmaFaturalariPage,
            }),
          },
        ],
      },
      {
        element: <IzinAlani izin="gelen_fatura_raporu.goruntule" />,
        children: [
          {
            path: 'raporlar/gelen-fatura-raporu',
            lazy: async () => ({
              Component: (await import('@/pages/GelenFaturaRaporu/GelenFaturaRaporuPage'))
                .GelenFaturaRaporuPage,
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
        // Yedekleme (BY-015…018): modül sunucuda kapalıyken hiçbir ekranı açılmaz
        element: <YedeklemeAlani />,
        children: [
          {
            element: <IzinAlani izin="yedekler.goruntule" />,
            children: [
              {
                path: 'ayarlar/yedekler',
                lazy: async () => ({
                  Component: (await import('@/pages/Yedekler/YedeklerPage')).YedeklerPage,
                }),
              },
              {
                // Farklı adla geri yüklemeler ve bekleyen kopyalar (BY-027)
                path: 'ayarlar/geri-yuklemeler',
                lazy: async () => ({
                  Component: (await import('@/pages/GeriYuklemeler/GeriYuklemelerPage'))
                    .GeriYuklemelerPage,
                }),
              },
              {
                path: 'ayarlar/yedek-islem-gunlugu',
                lazy: async () => ({
                  Component: (await import('@/pages/YedekIslemGunlugu/YedekIslemGunluguPage'))
                    .YedekIslemGunluguPage,
                }),
              },
            ],
          },
          {
            element: <IzinAlani izin="yedek_planlari.goruntule" />,
            children: [
              {
                path: 'ayarlar/yedek-planlari',
                lazy: async () => ({
                  Component: (await import('@/pages/YedekPlanlari/YedekPlanlariPage'))
                    .YedekPlanlariPage,
                }),
              },
              {
                path: 'ayarlar/yedek-hedefleri',
                lazy: async () => ({
                  Component: (await import('@/pages/YedekHedefleri/YedekHedefleriPage'))
                    .YedekHedefleriPage,
                }),
              },
              {
                // Google'ın tarayıcıyı döndürdüğü adres (BY-021)
                path: 'ayarlar/yedek-hedefleri/google-donus',
                lazy: async () => ({
                  Component: (await import('@/pages/YedekHedefleri/GoogleDonusPage'))
                    .GoogleDonusPage,
                }),
              },
              {
                path: 'ayarlar/yedekleme-ayarlari',
                lazy: async () => ({
                  Component: (await import('@/pages/YedeklemeAyarlari/YedeklemeAyarlariPage'))
                    .YedeklemeAyarlariPage,
                }),
              },
            ],
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
