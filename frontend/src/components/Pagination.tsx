import { useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import Select from 'react-select'

import { selectStilleri, type SecenekOgesi } from '@/components/selectStilleri'
import { useTheme } from '@/hooks/useTheme'
import { SAYFA_BOYUTLARI, sayfaBoyutuOku, sayfaBoyutuYaz } from '@/utils/sayfaBoyutu'

interface PaginationProps {
  sayfa: number
  toplamSayfa: number
  sayfaDegistir: (sayfa: number) => void
  disabled?: boolean
  /** Sayfa boyutu seçicisini gizler (ör. modal içi küçük listeler). */
  boyutSecici?: boolean
}

const butonSinifi =
  'cursor-pointer rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:border-slate-300 disabled:hover:bg-transparent disabled:hover:text-slate-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-blue-500 dark:hover:bg-blue-950 dark:hover:text-blue-300 dark:disabled:hover:border-slate-700 dark:disabled:hover:text-slate-200'

/** ornek/dashboard ReportPagination pattern'i: boyut seçici/First/Prev/numaralar+ellipsis/Next/Last. */
export function Pagination({
  sayfa,
  toplamSayfa,
  sayfaDegistir,
  disabled = false,
  boyutSecici = true,
}: PaginationProps) {
  const { t } = useTranslation()
  const { theme } = useTheme()
  const queryClient = useQueryClient()
  const [boyut, setBoyut] = useState(sayfaBoyutuOku)

  const ogeler = useMemo<(number | '...')[]>(() => {
    if (toplamSayfa <= 7) {
      return Array.from({ length: toplamSayfa }, (_, i) => i + 1)
    }
    if (sayfa <= 4) {
      return [1, 2, 3, 4, 5, '...', toplamSayfa]
    }
    if (sayfa >= toplamSayfa - 3) {
      return [
        1,
        '...',
        toplamSayfa - 4,
        toplamSayfa - 3,
        toplamSayfa - 2,
        toplamSayfa - 1,
        toplamSayfa,
      ]
    }
    return [1, '...', sayfa - 1, sayfa, sayfa + 1, '...', toplamSayfa]
  }, [sayfa, toplamSayfa])

  const kilitli = disabled || toplamSayfa <= 1

  const boyutSecenekleri: SecenekOgesi[] = SAYFA_BOYUTLARI.map((secenek) => ({
    value: String(secenek),
    label: secenek === 0 ? t('ortak.hepsi') : String(secenek),
  }))

  const git = (hedef: number): void => {
    if (hedef >= 1 && hedef <= toplamSayfa && hedef !== sayfa) {
      sayfaDegistir(hedef)
    }
  }

  // Boyut ORTAK tercihtir (localStorage) — istekler interceptor'dan alır;
  // değişince 1. sayfaya dönülür ve tüm liste sorguları tazelenir
  const boyutDegistir = (yeni: number): void => {
    sayfaBoyutuYaz(yeni)
    setBoyut(yeni)
    sayfaDegistir(1)
    void queryClient.invalidateQueries()
  }

  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {boyutSecici ? (
        <label
          htmlFor="sayfa-boyutu"
          className="flex items-center gap-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300"
        >
          {t('ortak.sayfaBoyutu')}
          {/* Selectbox'lar her zaman react-select (kullanıcı kuralı 2026-07-11 — rules.md §2) */}
          <div className="w-24">
            <Select<SecenekOgesi, false>
              inputId="sayfa-boyutu"
              value={boyutSecenekleri.find((oge) => oge.value === String(boyut)) ?? null}
              onChange={(secim) => {
                if (secim) {
                  boyutDegistir(Number(secim.value))
                }
              }}
              options={boyutSecenekleri}
              isDisabled={disabled}
              isSearchable={false}
              menuPlacement="auto"
              styles={selectStilleri<false>(theme === 'dark')}
            />
          </div>
        </label>
      ) : null}
      <button
        type="button"
        onClick={() => git(1)}
        disabled={kilitli || sayfa <= 1}
        className={butonSinifi}
      >
        {t('ortak.ilk')}
      </button>
      <button
        type="button"
        onClick={() => git(sayfa - 1)}
        disabled={kilitli || sayfa <= 1}
        className={butonSinifi}
      >
        {t('ortak.onceki')}
      </button>

      {ogeler.map((oge, index) =>
        oge === '...' ? (
          <span
            key={`e-${index}`}
            className="px-2 text-xs font-semibold text-slate-500 dark:text-slate-400"
          >
            …
          </span>
        ) : (
          <button
            key={oge}
            type="button"
            onClick={() => git(oge)}
            disabled={kilitli || oge === sayfa}
            className={
              oge === sayfa
                ? 'cursor-default rounded-lg border border-slate-900 bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-100 dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900'
                : butonSinifi
            }
          >
            {oge}
          </button>
        ),
      )}

      <button
        type="button"
        onClick={() => git(sayfa + 1)}
        disabled={kilitli || sayfa >= toplamSayfa}
        className={butonSinifi}
      >
        {t('ortak.sonraki')}
      </button>
      <button
        type="button"
        onClick={() => git(toplamSayfa)}
        disabled={kilitli || sayfa >= toplamSayfa}
        className={butonSinifi}
      >
        {t('ortak.son')}
      </button>
    </div>
  )
}
