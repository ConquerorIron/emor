import type { ReactNode } from 'react'

import type { Kullanici } from '@/features/auth/types'
import { AuthContext } from '@/providers/auth-context'

/**
 * Testlerde oturumu verilen izinlerle kurar. AppProviders'ın içine konur;
 * içteki sağlayıcı AuthProvider'ın (ağdan gelen) oturumunu ezer.
 */
export function SahteOturum({ izinler, children }: { izinler: string[]; children: ReactNode }) {
  const user: Kullanici = {
    id: 1,
    ad: 'Deneme',
    kullanici_adi: 'deneme',
    email: null,
    kaynak: 'erp',
    sistem_yoneticisi: false,
    izinler,
  }

  return (
    <AuthContext.Provider
      value={{ user, yukleniyor: false, login: async () => {}, logout: async () => {} }}
    >
      {children}
    </AuthContext.Provider>
  )
}
