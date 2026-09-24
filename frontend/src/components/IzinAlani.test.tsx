import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'

import type { Kullanici } from '@/features/auth/types'
import { AuthContext } from '@/providers/auth-context'

import { IzinAlani } from './IzinAlani'

function ciz(izinler: string[]) {
  const user: Kullanici = {
    id: 1,
    ad: 'Deneme',
    kullanici_adi: 'deneme',
    email: null,
    kaynak: 'erp',
    sistem_yoneticisi: false,
    izinler,
  }

  render(
    <AuthContext.Provider
      value={{ user, yukleniyor: false, login: async () => {}, logout: async () => {} }}
    >
      <MemoryRouter initialEntries={['/efatura']}>
        <Routes>
          <Route path="/" element={<p>Anasayfa</p>} />
          <Route element={<IzinAlani izin="efatura.goruntule" />}>
            <Route path="/efatura" element={<p>e-Fatura</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('IzinAlani', () => {
  afterEach(cleanup)

  it('izni olan kullanıcıya sayfayı açar', () => {
    ciz(['efatura.goruntule'])

    expect(screen.getByText('e-Fatura')).toBeInTheDocument()
  })

  it('izni olmayan kullanıcıyı anasayfaya döndürür', () => {
    ciz(['efatura.pdf'])

    expect(screen.getByText('Anasayfa')).toBeInTheDocument()
    expect(screen.queryByText('e-Fatura')).not.toBeInTheDocument()
  })
})
