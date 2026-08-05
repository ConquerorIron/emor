import type { ComponentPropsWithRef } from 'react'

interface InputProps extends ComponentPropsWithRef<'input'> {
  label: string
  hata?: string
}

export function Input({ label, hata, id, className = '', ...props }: InputProps) {
  return (
    <div>
      <label
        htmlFor={id}
        className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
      >
        {label}
      </label>
      <input
        id={id}
        aria-invalid={hata ? true : undefined}
        className={`mt-1 block h-[42px] w-full rounded-xl border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none placeholder:text-slate-400 focus:ring-2 dark:bg-slate-950 dark:text-slate-100 ${
          hata
            ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
            : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
        } ${className}`}
        {...props}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
