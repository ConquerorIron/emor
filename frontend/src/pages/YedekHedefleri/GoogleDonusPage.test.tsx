import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { StrictMode } from 'react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { GoogleDonusPage } from './GoogleDonusPage'

const api = vi.hoisted(() => ({ donus: vi.fn() }))

vi.mock('@/features/yedekleme/hedefApi', () => ({ googleDonusunuTamamla: api.donus }))

/** Hedefler ekranının yerine: dönüş sayfasının bıraktığı durumu gösterir */
function HedeflerEkrani() {
  const konum = useLocation()

  return <p>Hedefler ekranı {JSON.stringify(konum.state)}</p>
}

const hedef = (baglanti: Record<string, string>) => ({
  id: 7,
  ad: 'Drive',
  tur: 'google_drive',
  baglanti,
})

function ciz(adres: string) {
  render(
    <StrictMode>
      <AppProviders>
        <SahteOturum izinler={['yedek_planlari.goruntule']} yedeklemeAktif>
          <MemoryRouter initialEntries={[adres]}>
            <Routes>
              <Route path="/ayarlar/yedek-hedefleri/google-donus" element={<GoogleDonusPage />} />
              <Route path="/ayarlar/yedek-hedefleri" element={<HedeflerEkrani />} />
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

  it('kodu ve durumu BİR KEZ iletir (StrictMode), klasörü seçili hedefte hedeflere yalın döner', async () => {
    api.donus.mockResolvedValue(
      hedef({ eposta: 'a@b.test', klasor_kimligi: 'k1', klasor_adi: 'Yedek' }),
    )
    ciz('/ayarlar/yedek-hedefleri/google-donus?code=k0d&state=d0rum')

    expect(await screen.findByText('Hedefler ekranı null')).toBeInTheDocument()
    expect(await screen.findByText('Google hesabı bağlandı.')).toBeInTheDocument()
    expect(api.donus).toHaveBeenCalledTimes(1)
    expect(api.donus).toHaveBeenCalledWith('k0d', 'd0rum')
  })

  it('klasörü seçilmemiş hedef bağlanınca hedefler ekranına klasör seçimini açmasını bildirir', async () => {
    api.donus.mockResolvedValue(hedef({ eposta: 'a@b.test' }))
    ciz('/ayarlar/yedek-hedefleri/google-donus?code=k0d&state=d0rum')

    expect(await screen.findByText('Hedefler ekranı {"klasorSecilecek":7}')).toBeInTheDocument()
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
    await waitFor(() => expect(screen.getByText('Hedefler ekranı null')).toBeInTheDocument())
  })

  it('kullanıcı izni reddettiyse sunucuya istek göndermez', async () => {
    ciz('/ayarlar/yedek-hedefleri/google-donus?error=access_denied&state=d0rum')

    expect(await screen.findByText('Hedefler ekranı null')).toBeInTheDocument()
    expect(await screen.findByText(/Google bağlantısı tamamlanmadı/)).toBeInTheDocument()
    expect(api.donus).not.toHaveBeenCalled()
  })
})
