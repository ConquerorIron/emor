import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, type ReactNode } from 'react'

import { YETKISIZ_EVENT } from '@/api/client'
import { queryKeys } from '@/api/queryKeys'
import { loginIstegi, logoutIstegi, meIstegi } from '@/features/auth/api'
import type { LoginGirdisi } from '@/features/auth/schemas/loginSchema'

import { AuthContext, type AuthContextValue } from './auth-context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const { data, isPending } = useQuery({
    queryKey: queryKeys.auth.me,
    queryFn: meIstegi,
    retry: false,
    staleTime: 15_000,
    refetchOnWindowFocus: 'always',
    refetchOnReconnect: 'always',
    refetchInterval: 60_000,
  })

  // Interceptor 401 yakalarsa oturumu düşür (api/client.ts)
  useEffect(() => {
    const oturumDustu = (): void => {
      queryClient.setQueryData(queryKeys.auth.me, null)
    }

    window.addEventListener(YETKISIZ_EVENT, oturumDustu)

    return () => window.removeEventListener(YETKISIZ_EVENT, oturumDustu)
  }, [queryClient])

  const login = useCallback(
    async (girdi: LoginGirdisi): Promise<void> => {
      const kullanici = await loginIstegi(girdi)
      // Önceki kullanıcının cache'i sızmasın — giriş anında kullanıcıya özel
      // tüm cache boşaltılır (puantaj deseni)
      queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
      queryClient.setQueryData(queryKeys.auth.me, kullanici)
    },
    [queryClient],
  )

  const logout = useCallback(async (): Promise<void> => {
    await logoutIstegi()
    queryClient.setQueryData(queryKeys.auth.me, null)
    // Kullanıcıya özel tüm cache'i boşalt (me sorgusu hariç)
    queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
  }, [queryClient])

  const value = useMemo<AuthContextValue>(
    () => ({
      user: data ?? null,
      yukleniyor: isPending,
      login,
      logout,
    }),
    [data, isPending, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
