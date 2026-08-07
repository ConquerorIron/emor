import type { ComponentPropsWithRef } from 'react'

import { ALAN_ETIKETI, ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'

interface InputProps extends ComponentPropsWithRef<'input'> {
  label: string
  hata?: string
}

export function Input({ label, hata, id, className = '', ...props }: InputProps) {
  return (
    <div>
      <label htmlFor={id} className={ALAN_ETIKETI}>
        {label}
      </label>
      <input
        id={id}
        aria-invalid={hata ? true : undefined}
        className={`mt-1 block w-full px-3 py-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)} ${className}`}
        {...props}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
