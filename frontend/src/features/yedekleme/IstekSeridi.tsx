import { useTranslation } from 'react-i18next'

import type { TakipEdilenIstek } from './useIstekTakibi'

/** Süren yedekleme işleri; iş bitene kadar ekranın işlem düğmeleri kapalıdır */
export function IstekSeridi({
  calisanlar,
}: {
  calisanlar: (TakipEdilenIstek & { durum: 'kuyrukta' | 'calisiyor' })[]
}) {
  const { t } = useTranslation()

  if (calisanlar.length === 0) {
    return null
  }

  return (
    <div
      role="status"
      className="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-5 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200"
    >
      <ul className="space-y-1">
        {calisanlar.map((istek) => (
          <li key={istek.kimlik} className="flex items-center gap-2">
            <span
              aria-hidden="true"
              className="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
            />
            <span>
              {t(`yedekleme.istek.tur.${istek.tur}`)} — {t(`yedekleme.istek.durum.${istek.durum}`)}
            </span>
          </li>
        ))}
      </ul>
      <p className="mt-1 text-xs opacity-80">{t('yedekleme.istek.ayrilabilirsiniz')}</p>
    </div>
  )
}
