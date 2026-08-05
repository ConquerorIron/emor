import { createContext } from 'react'

import type { LoginGirdisi } from '@/features/auth/schemas/loginSchema'
import type { Kullanici } from '@/features/auth/types'

export interface AuthContextValue {
  user: Kullanici | null
  yukleniyor: boolean
  login: (girdi: LoginGirdisi) => Promise<void>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
