import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { Toaster } from 'sonner'

import { useTheme } from '@/hooks/useTheme'

import { AuthProvider } from './AuthProvider'
import { ThemeProvider } from './ThemeProvider'

function AppToaster() {
  const { theme } = useTheme()

  return <Toaster richColors position="top-right" theme={theme} />
}

export function AppProviders({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            retry: 1,
            refetchOnWindowFocus: false,
            staleTime: 30_000,
          },
        },
      }),
  )

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <AuthProvider>
          {children}
          <AppToaster />
        </AuthProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
