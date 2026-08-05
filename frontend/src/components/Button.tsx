import type { ComponentPropsWithRef } from 'react'

interface ButtonProps extends ComponentPropsWithRef<'button'> {
  variant?: 'primary' | 'secondary' | 'danger' | 'mor' | 'turuncu' | 'yesil'
  yukleniyor?: boolean
}

const varyantlar: Record<NonNullable<ButtonProps['variant']>, string> = {
  primary:
    'bg-blue-600 text-white hover:bg-blue-700 focus-visible:outline-blue-600 disabled:hover:bg-blue-600',
  secondary:
    'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus-visible:outline-slate-400 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700 dark:hover:bg-slate-800',
  danger:
    'bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600 disabled:hover:bg-red-600',
  // "Yeni …" butonları mor, "Düzenle" butonları turuncu (UI notları 2026-07-07)
  mor: 'bg-violet-600 text-white hover:bg-violet-700 focus-visible:outline-violet-600 disabled:hover:bg-violet-600',
  turuncu:
    'bg-orange-500 text-white hover:bg-orange-600 focus-visible:outline-orange-500 disabled:hover:bg-orange-500',
  // Excel indirme butonları açık yeşil (TRANSFER-1 — kullanıcı isteği 2026-07-09)
  yesil:
    'bg-emerald-100 text-emerald-800 hover:bg-emerald-200 focus-visible:outline-emerald-600 disabled:hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900',
}

export function Button({
  variant = 'primary',
  yukleniyor = false,
  disabled,
  className = '',
  children,
  type = 'button',
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled ?? yukleniyor}
      className={`inline-flex cursor-pointer items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${varyantlar[variant]} ${className}`}
      {...props}
    >
      {yukleniyor ? (
        <span
          aria-hidden="true"
          className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      ) : null}
      {children}
    </button>
  )
}
