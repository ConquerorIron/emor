import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react'
import type { ReactNode } from 'react'

interface ModalProps {
  acik: boolean
  kapat: () => void
  baslik: string
  /**
   * genis: uzun alanlı formlar (ör. firma adı 200 karakter);
   * devasa: hücreli yapıştırma gridleri / geniş tablolar — üst sınır YOK
   * (kullanıcı isteği 2026-07-18: "açabildiğin kadar aç"); pencere her ekranda
   * kenarlardaki nefes payı dışında tüm genişliği kullanır
   */
  boyut?: 'normal' | 'genis' | 'devasa'
  children: ReactNode
}

const BOYUT_SINIFLARI: Record<NonNullable<ModalProps['boyut']>, string> = {
  normal: 'max-w-lg',
  genis: 'max-w-2xl',
  devasa: 'max-w-none',
}

export function Modal({ acik, kapat, baslik, boyut = 'normal', children }: ModalProps) {
  return (
    <Dialog open={acik} onClose={kapat} className="relative z-50">
      <DialogBackdrop className="fixed inset-0 bg-black/40" />
      <div className="fixed inset-0 flex items-center justify-center overflow-y-auto p-4">
        <DialogPanel
          className={`w-full ${BOYUT_SINIFLARI[boyut]} rounded-xl border border-slate-200 bg-white p-6 shadow-lg dark:border-slate-800 dark:bg-slate-900`}
        >
          <DialogTitle className="text-lg font-bold">{baslik}</DialogTitle>
          <div className="mt-4">{children}</div>
        </DialogPanel>
      </div>
    </Dialog>
  )
}
