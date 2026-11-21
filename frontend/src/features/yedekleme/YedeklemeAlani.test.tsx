import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'

import { IzinAlani } from '@/components/IzinAlani'
import { SahteOturum } from '@/test/SahteOturum'

import { YedeklemeAlani } from './YedeklemeAlani'

function ciz(yedeklemeAktif: boolean) {
  render(
    <SahteOturum izinler={['yedekler.goruntule']} yedeklemeAktif={yedeklemeAktif}>
      <MemoryRouter initialEntries={['/ayarlar/yedekler']}>
        <Routes>
          <Route path="/" element={<p>Anasayfa</p>} />
          <Route element={<YedeklemeAlani />}>
            <Route element={<IzinAlani izin="yedekler.goruntule" />}>
              <Route path="/ayarlar/yedekler" element={<p>Yedekler</p>} />
            </Route>
          </Route>
        </Routes>
      </MemoryRouter>
    </SahteOturum>,
  )
}

describe('YedeklemeAlani', () => {
  afterEach(cleanup)

  it('modül açıkken izinli kullanıcıya ekranı açar', () => {
    ciz(true)

    expect(screen.getByText('Yedekler')).toBeInTheDocument()
  })

  it('modül kapalıyken doğrudan adresle geleni anasayfaya döndürür', () => {
    ciz(false)

    expect(screen.getByText('Anasayfa')).toBeInTheDocument()
    expect(screen.queryByText('Yedekler')).not.toBeInTheDocument()
  })
})
