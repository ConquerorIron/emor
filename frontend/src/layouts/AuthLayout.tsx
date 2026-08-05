import { Outlet } from 'react-router-dom'

import { LanguageSwitcher } from '@/components/LanguageSwitcher'
import { ThemeToggle } from '@/components/ThemeToggle'

export function AuthLayout() {
  return (
    <div className="relative flex min-h-full items-center justify-center p-4">
      <div className="absolute top-4 right-4 flex items-center gap-4">
        <LanguageSwitcher />
        <ThemeToggle />
      </div>
      <Outlet />
    </div>
  )
}
