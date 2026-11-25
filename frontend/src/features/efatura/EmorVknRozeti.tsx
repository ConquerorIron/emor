import { useTranslation } from 'react-i18next'

import type { EmorKurumu } from './efaturaApi'

/**
 * "eMOR VKN Var" kolonu (kullanıcı isteği 2026-11-25): karşı tarafın VKN'si
 * eMOR'daki bir kurum kartında kayıtlıysa "Evet" (üzerinde kartın kodu ve
 * adı); değilse "Hayır"; ERP okunamadıysa "—". Protel Gelen Faturalar ve Tüm
 * Faturalar ortak kullanır.
 */
export function EmorVknRozeti({ kurum }: { kurum: EmorKurumu | undefined }) {
  const { t } = useTranslation()

  if (kurum === undefined || kurum.var === null) {
    return (
      <span className="text-slate-400" title={t('efatura.emorVkn.bilinmiyor')}>
        —
      </span>
    )
  }

  return kurum.var ? (
    <span
      className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-amber-800 dark:bg-amber-950 dark:text-amber-300"
      title={[kurum.kod, kurum.ad].filter(Boolean).join(' — ')}
    >
      {t('efatura.evet')}
    </span>
  ) : (
    <span className="whitespace-nowrap text-slate-500 dark:text-slate-400">
      {t('efatura.hayir')}
    </span>
  )
}
