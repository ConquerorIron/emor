import { useTranslation } from 'react-i18next'

export function FullPageLoader() {
  const { t } = useTranslation()

  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-3">
      <span
        aria-hidden="true"
        className="size-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"
      />
      <span className="text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</span>
    </div>
  )
}
