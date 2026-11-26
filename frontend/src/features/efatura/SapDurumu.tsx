import { useTranslation } from 'react-i18next'

import type { EFatura } from './efaturaApi'
import { RedRozeti } from './RedRozeti'

/**
 * "SAP" kolonu (yazı, eMOR kolonu gibi — kullanıcı isteği 2026-11-26): "SAP
 * (Manuel İşle)" anahtarıyla işaretlenen fatura "İşlendi (elle)". RED fatura
 * (kaynakta ya da elle) "Red" (önce gelir); işaretlenmemişse soluk tire.
 * Bütün gelen fatura ekranları ortak kullanır.
 */
export function SapDurumu({ fatura }: { fatura: EFatura }) {
  const { t } = useTranslation()

  if (fatura.red.var) {
    return <RedRozeti red={fatura.red} />
  }

  return fatura.sap_islendi === true ? (
    <span className="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-sky-800 dark:bg-sky-950 dark:text-sky-300">
      {t('efatura.sapIslendi')}
    </span>
  ) : (
    <span className="text-slate-400" title={t('efatura.kolon.sapIsaretlenmedi')}>
      —
    </span>
  )
}
