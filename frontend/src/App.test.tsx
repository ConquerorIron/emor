import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import App from './App'
import './i18n/i18n'
import { AppProviders } from './providers/AppProviders'

describe('App', () => {
  it('oturum yokken login sayfasına yönlendirir', async () => {
    render(
      <AppProviders>
        <App />
      </AppProviders>,
    )

    // jsdom'da me isteği başarısız olur → kullanıcı null → /giris'e yönlenir
    expect(await screen.findByRole('button', { name: 'Giriş Yap' })).toBeInTheDocument()
    expect(screen.getByLabelText('Kullanıcı Adı')).toBeInTheDocument()
    expect(screen.getByLabelText('Şifre')).toBeInTheDocument()
  })
})
