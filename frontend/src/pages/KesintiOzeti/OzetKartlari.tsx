import { useTranslation } from 'react-i18next'

import { tutarGoster } from '@/features/kesinti/bicim'

import type { OzetTutari, OzetTutarlari } from './kesintiOzetiApi'
import { oranGoster } from './ozetBicimi'

/**
 * GENEL: toplam, HAB'ın üstlendiği ve taşeron payı (toplamın oranı); TAŞERON:
 * iptal edilen, kesilen, kalan (taşeron payının oranı).
 */
const KART_GRUPLARI: { anahtar: string; payda: OzetTutari; kartlar: OzetTutari[] }[] = [
  { anahtar: 'genel', payda: 'toplam', kartlar: ['toplam', 'hab', 'taseron'] },
  { anahtar: 'taseron', payda: 'taseron', kartlar: ['iptal', 'kesilen', 'kalan'] },
]

export function OzetKartlari({ genel, paraBirimi }: { genel: OzetTutarlari; paraBirimi: string }) {
  const { t } = useTranslation()

  const altYazi = (alan: OzetTutari, payda: OzetTutari) => {
    if (alan === payda) {
      return `${paraBirimi} · %100`
    }

    return `${paraBirimi} · ${t(`kesinti.ozet.oran.${payda}`, { oran: oranGoster(genel[alan], genel[payda]) })}`
  }

  return (
    <div className="mt-3 divide-y divide-slate-200 dark:divide-slate-700">
      {KART_GRUPLARI.map((grup) => (
        <div key={grup.anahtar} className="grid gap-3 py-4 sm:grid-cols-[8rem_1fr]">
          <p className="text-xs font-bold text-slate-500 dark:text-slate-400">
            {t(`kesinti.ozet.${grup.anahtar}`)}
          </p>
          <dl className="grid gap-4 sm:grid-cols-3">
            {grup.kartlar.map((alan) => (
              <div key={alan} className="border-l border-slate-200 pl-4 dark:border-slate-700">
                <dt className="text-xs font-semibold text-slate-500 dark:text-slate-400">
                  {t(`kesinti.ozet.kolon.${alan}`)}
                </dt>
                <dd className="mt-1 text-2xl font-bold text-slate-800 tabular-nums dark:text-slate-100">
                  {tutarGoster(genel[alan], 0)}
                </dd>
                <dd className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                  {altYazi(alan, grup.payda)}
                </dd>
              </div>
            ))}
          </dl>
        </div>
      ))}
    </div>
  )
}
