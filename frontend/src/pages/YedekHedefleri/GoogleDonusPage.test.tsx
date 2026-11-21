import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { GoogleDonusPage } from './GoogleDonusPage'

const api = vi.hoisted(() => ({ donus: vi.fn() }))

vi.mock('@/features/yedekleme/hedefApi', () => ({ googleDonusunuTamamla: api.donus }))

function ciz(adres: string) {
  render(
    <StrictMode>
      <AppProviders>
        <SahteOturum izinler={['yedek_planlari.goruntule']} yedeklemeAktif>
          <MemoryRouter initialEntries={[adres]}>
            <Routes>
              <Route path="/ayarlar/yedek-hedefleri/google-donus" element={<GoogleDonusPage />} />
              <Route path="/ayarlar/yedek-hedefleri" element={<p>Hedefler ekranı</p>} />
            </Routes>
          </MemoryRouter>
        </SahteOturum>
      </AppProviders>
    </StrictMode>,
  )
}

describe('GoogleDonusPage', () => {
  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kodu ve durumu BİR KEZ iletir (StrictMode), sonra hedeflere döner', async () => {
    api.donus.mockResolvedValue(undefined)
    ciz('/ayarlar/yedek-hedefleri/google-donus?code=k0d&state=d0rum')

    expect(await screen.findByText('Hedefler ekranı')).toBeInTheDocument()
    expect(await screen.findByText('Google hesabı bağlandı.')).toBeInTheDocument()
    expect(api.donus).toHaveBeenCalledTimes(1)
    expect(api.donus).toHaveBeenCalledWith('k0d', 'd0rum')
  })

  it('durum değeri reddedilirse nedenini gösterir', async () => {
    api.donus.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: { status: 422, data: { kod: 'GOOGLE_DURUM_GECERSIZ' } },
      }),
    )
    ciz('/ayarlar/yedek-hedefleri/google-donus?code=k0d&state=eski')

    expect(await screen.findByText(/Google bağlantı isteği geçersiz/)).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Hedefler ekranı')).toBeInTheDocument())
  })

  it('kullanıcı izni reddettiyse sunucuya istek göndermez', async () => {
    ciz('/ayarlar/yedek-hedefleri/google-donus?error=access_denied&state=d0rum')

    expect(await screen.findByText('Hedefler ekranı')).toBeInTheDocument()
    expect(await screen.findByText(/Google bağlantısı tamamlanmadı/)).toBeInTheDocument()
    expect(api.donus).not.toHaveBeenCalled()
  })
})
