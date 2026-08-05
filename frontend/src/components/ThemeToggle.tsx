import { Switch } from '@headlessui/react'
import { useTranslation } from 'react-i18next'

import { useTheme } from '@/hooks/useTheme'

export function ThemeToggle() {
  const { t } = useTranslation()
  const { theme, toggleTheme } = useTheme()

  return (
    <Switch
      checked={theme === 'dark'}
      onChange={toggleTheme}
      aria-label={theme === 'dark' ? t('ortak.acikTemayaGec') : t('ortak.koyuTemayaGec')}
      className="group inline-flex h-6 w-11 items-center rounded-full bg-slate-300 transition-colors data-checked:bg-blue-600 dark:bg-slate-700"
    >
      <span className="size-4 translate-x-1 rounded-full bg-white transition-transform group-data-checked:translate-x-6" />
    </Switch>
  )
}
