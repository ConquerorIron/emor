import type { ReactNode } from 'react'

export type RozetRengi = 'gri' | 'yesil' | 'kirmizi' | 'sari' | 'mavi' | 'mor'

const RENKLER: Record<RozetRengi, string> = {
  gri: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
  yesil: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  kirmizi: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  sari: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  mavi: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
  mor: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
}

/** Yedekleme ekranlarının durum etiketi (liste hücreleri, özet) */
export function Rozet({
  renk,
  title,
  children,
}: {
  renk: RozetRengi
  title?: string
  children: ReactNode
}) {
  return (
    <span
      title={title}
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${RENKLER[renk]}`}
    >
      {children}
    </span>
  )
}
