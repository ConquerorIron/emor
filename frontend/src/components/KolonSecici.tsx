import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react'
import { useTranslation } from 'react-i18next'

import { Switch } from '@/components/Switch'

export interface SecilebilirKolon {
  anahtar: string
  baslik: string
}

interface KolonSeciciProps {
  kolonlar: SecilebilirKolon[]
  gorunurMu: (anahtar: string) => boolean
  degistir: (anahtar: string) => void
  hepsiniGoster: () => void
}

/**
 * Tablo kolonlarını göster/gizle menüsü. Tercihin saklanması çağıranın
 * işidir (useKolonGorunurlugu). Checkbox değil Switch (rules.md §2).
 */
export function KolonSecici({ kolonlar, gorunurMu, degistir, hepsiniGoster }: KolonSeciciProps) {
  const { t } = useTranslation()
  const gorunen = kolonlar.filter((kolon) => gorunurMu(kolon.anahtar)).length

  return (
    <Popover className="relative">
      <PopoverButton className="cursor-pointer rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-700 dark:text-slate-200 dark:hover:border-blue-500 dark:hover:bg-blue-950 dark:hover:text-blue-300">
        {t('ortak.kolonlar', { gorunen, toplam: kolonlar.length })}
      </PopoverButton>
      <PopoverPanel
        anchor="bottom start"
        className="z-50 mt-1 max-h-[70vh] w-72 overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-900"
      >
        <div className="mb-2 flex items-center justify-between gap-2">
          <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
            {t('ortak.kolonSecimi')}
          </p>
          <button
            type="button"
            onClick={hepsiniGoster}
            disabled={gorunen === kolonlar.length}
            className="cursor-pointer text-xs font-semibold text-blue-600 hover:underline disabled:cursor-default disabled:opacity-40 disabled:hover:no-underline dark:text-blue-400"
          >
            {t('ortak.tumKolonlariGoster')}
          </button>
        </div>
        <div className="space-y-1">
          {kolonlar.map((kolon) => (
            <Switch
              key={kolon.anahtar}
              id={`kolon-${kolon.anahtar}`}
              label={kolon.baslik}
              checked={gorunurMu(kolon.anahtar)}
              onChange={() => degistir(kolon.anahtar)}
              vurgulu={false}
            />
          ))}
        </div>
      </PopoverPanel>
    </Popover>
  )
}
