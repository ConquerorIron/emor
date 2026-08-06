import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'

import { queryKeys } from '@/api/queryKeys'
import { CevrimdisiBanner } from '@/components/CevrimdisiBanner'
import { LanguageSwitcher } from '@/components/LanguageSwitcher'
import { ThemeToggle } from '@/components/ThemeToggle'
import { sqlBaglantilariGetir } from '@/features/ayarlar/sqlApi'
import { useAuth } from '@/hooks/useAuth'

import { NavIkon } from './navIkonlari'

const DARALTMA_ANAHTARI = 'erp.sidebarDar'

interface NavOgesi {
  to: string
  /** i18n anahtarı (nav.*) — ikon adı olarak da kullanılır */
  ad: string
  end?: boolean
  /** Yalnız ERP sistem yöneticilerine görünür */
  yoneticiye?: boolean
}

interface NavGrubu {
  baslikAnahtari: string | null
  ogeler: NavOgesi[]
}

const NAV_GRUPLARI: NavGrubu[] = [
  {
    baslikAnahtari: null,
    ogeler: [{ to: '/', ad: 'anasayfa', end: true }],
  },
  {
    baslikAnahtari: 'nav.satinalma',
    ogeler: [{ to: '/satinalma/talep', ad: 'satinalmaTalepleri' }],
  },
  {
    baslikAnahtari: 'nav.ayarlar',
    ogeler: [
      { to: '/ayarlar/sql-baglantilari', ad: 'sqlBaglantilari' },
      { to: '/ayarlar/ekran-tasarimi', ad: 'ekranTasarimi', yoneticiye: true },
    ],
  },
]

export function AppLayout() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  // Sidebar kapanıp açılabilir; tercih kalıcıdır
  const [dar, setDar] = useState(() => localStorage.getItem(DARALTMA_ANAHTARI) === '1')

  // Menü/rota değişiminde içerik en üste kaydırılır; sorgu parametresi
  // değişimleri (aynı sayfada filtre) kaydırmaz
  const { pathname } = useLocation()
  useEffect(() => {
    window.scrollTo(0, 0)
  }, [pathname])

  // Header'da aktif ortam rozeti: Test'te miyiz Canlı'da mı her ekranda görünür
  const baglantilar = useQuery({
    queryKey: queryKeys.ayarlar.sqlBaglantilari,
    queryFn: sqlBaglantilariGetir,
    staleTime: 60_000,
  })
  const aktifOrtam = baglantilar.data?.aktif_ortam ?? null

  const darDegistir = () => {
    setDar((onceki) => {
      localStorage.setItem(DARALTMA_ANAHTARI, onceki ? '0' : '1')
      return !onceki
    })
  }

  const navLinkSinifi = ({ isActive }: { isActive: boolean }): string =>
    `relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition-colors ${
      dar ? 'justify-center' : ''
    } ${
      isActive
        ? 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300'
        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
    }`

  return (
    <div className="flex min-h-full">
      <aside
        className={`flex shrink-0 flex-col border-r border-slate-200 bg-white transition-all dark:border-slate-800 dark:bg-slate-900 ${
          dar ? 'w-16' : 'w-72'
        }`}
      >
        <div
          className={`flex items-center border-b border-slate-200 py-4 dark:border-slate-800 ${
            dar ? 'justify-center px-2' : 'justify-between px-6'
          }`}
        >
          {dar ? null : (
            <Link to="/" className="truncate text-xl font-bold text-blue-600 dark:text-blue-400">
              {t('ortak.uygulamaAdi')}
            </Link>
          )}
          <button
            type="button"
            onClick={darDegistir}
            title={t(dar ? 'ortak.menuyuAc' : 'ortak.menuyuDaralt')}
            aria-label={t(dar ? 'ortak.menuyuAc' : 'ortak.menuyuDaralt')}
            className="cursor-pointer rounded-lg p-1.5 text-slate-500 transition-colors hover:bg-slate-100 hover:text-blue-600 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-blue-400"
          >
            <svg
              aria-hidden="true"
              className="size-5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              strokeWidth={1.8}
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d={
                  dar
                    ? 'm5.25 4.5 7.5 7.5-7.5 7.5m6-15 7.5 7.5-7.5 7.5'
                    : 'm18.75 4.5-7.5 7.5 7.5 7.5m-6-15L5.25 12l7.5 7.5'
                }
              />
            </svg>
          </button>
        </div>

        <nav className={`flex-1 space-y-1 overflow-y-auto py-4 ${dar ? 'px-2' : 'px-3'}`}>
          {NAV_GRUPLARI.map((grup, indeks) => (
            <div key={grup.baslikAnahtari ?? indeks} className={indeks === 0 ? '' : 'pt-4'}>
              {grup.baslikAnahtari && !dar ? (
                <p className="px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase dark:text-slate-500">
                  {t(grup.baslikAnahtari)}
                </p>
              ) : null}
              {grup.baslikAnahtari && dar ? (
                <div className="mx-2 mb-1 border-t border-slate-200 dark:border-slate-700" />
              ) : null}
              {grup.ogeler
                .filter((oge) => !oge.yoneticiye || user?.sistem_yoneticisi === true)
                .map((oge) => (
                  <NavLink
                    key={oge.to}
                    to={oge.to}
                    end={oge.end}
                    className={navLinkSinifi}
                    title={dar ? t(`nav.${oge.ad}`) : undefined}
                  >
                    <NavIkon ad={oge.ad} />
                    {dar ? null : <span className="truncate">{t(`nav.${oge.ad}`)}</span>}
                  </NavLink>
                ))}
            </div>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <CevrimdisiBanner />
        <header className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
          <div className="flex items-center justify-between gap-4 px-6 py-3">
            <div className="flex min-w-0 items-center gap-3">
              {/* Aktif ortam rozeti: canlıda yanlışlıkla işlem yapılmasın diye hep görünür */}
              {aktifOrtam ? (
                <span
                  className={`rounded-full px-3 py-1 text-xs font-bold tracking-wide uppercase ${
                    aktifOrtam === 'canli'
                      ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                      : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                  }`}
                >
                  {t(aktifOrtam === 'canli' ? 'ortak.canliOrtam' : 'ortak.testOrtami')}
                </span>
              ) : (
                <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold tracking-wide text-amber-700 uppercase dark:bg-amber-950 dark:text-amber-300">
                  {t('ortak.ortamSecilmedi')}
                </span>
              )}
            </div>
            <div className="flex items-center gap-4">
              <LanguageSwitcher />
              <ThemeToggle />

              <Menu as="div" className="relative">
                <MenuButton className="rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-100 data-open:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 dark:data-open:bg-slate-800">
                  {user?.ad} ▾
                </MenuButton>
                <MenuItems className="absolute right-0 z-40 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg focus:outline-none dark:border-slate-700 dark:bg-slate-900">
                  <MenuItem>
                    <button
                      type="button"
                      onClick={() => void logout()}
                      className="block w-full px-4 py-2 text-left text-sm text-red-600 data-focus:bg-slate-100 dark:text-red-400 dark:data-focus:bg-slate-800"
                    >
                      {t('ortak.cikisYap')}
                    </button>
                  </MenuItem>
                </MenuItems>
              </Menu>
            </div>
          </div>
        </header>

        <main className="w-full flex-1 px-6 py-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
