import { useTranslation } from 'react-i18next'

import { useAuth } from '@/hooks/useAuth'

export function DashboardPage() {
  const { t } = useTranslation()
  const { user } = useAuth()

  return (
    <>
      <h2 className="text-2xl font-bold">{t('anasayfa.hosgeldin', { ad: user?.ad ?? '' })}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{t('anasayfa.aciklama')}</p>

      <div className="mt-6 rounded-xl border border-slate-200 p-6 dark:border-slate-700">
        <p className="text-sm text-slate-500 dark:text-slate-400">{t('anasayfa.bosDurum')}</p>
      </div>
    </>
  )
}
