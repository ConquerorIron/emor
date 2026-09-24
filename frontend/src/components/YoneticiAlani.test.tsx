import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'

import type { Kullanici } from '@/features/auth/types'
import { AuthContext } from '@/providers/auth-context'

import { YoneticiAlani } from './YoneticiAlani'

function kullanici(sistemYoneticisi: boolean): Kullanici {
  return {
    id: 1,
    ad: 'Deneme',
    kullanici_adi: 'deneme',
    email: null,
    kaynak: 'erp',
    sistem_yoneticisi: sistemYoneticisi,
    izinler: [],
  }
}

function ciz(user: Kullanici) {
  render(
    <AuthContext.Provider
      value={{ user, yukleniyor: false, login: async () => {}, logout: async () => {} }}
    >
      <MemoryRouter initialEntries={['/ayarlar/sql-baglantilari']}>
        <Routes>
          <Route path="/" element={<p>Anasayfa</p>} />
          <Route element={<YoneticiAlani />}>
            <Route path="/ayarlar/sql-baglantilari" element={<p>SQL Bağlantıları</p>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('YoneticiAlani', () => {
  afterEach(cleanup)

  it('sistem yöneticisine sayfayı açar', () => {
    ciz(kullanici(true))

    expect(screen.getByText('SQL Bağlantıları')).toBeInTheDocument()
  })

  it('standart kullanıcıyı doğrudan URL ile gelse de anasayfaya döndürür', () => {
    ciz(kullanici(false))

    expect(screen.getByText('Anasayfa')).toBeInTheDocument()
    expect(screen.queryByText('SQL Bağlantıları')).not.toBeInTheDocument()
  })
})
