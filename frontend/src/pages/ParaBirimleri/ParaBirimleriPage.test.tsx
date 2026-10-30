import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { ParaBirimleriPage } from './ParaBirimleriPage'

const api = vi.hoisted(() => ({
  liste: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
}))

vi.mock('@/features/kesinti/paraBirimiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/paraBirimiApi')>()),
  paraBirimleriniGetir: api.liste,
  paraBirimiKaydet: api.kaydet,
  paraBirimiSil: api.sil,
}))

function ciz(izinler = ['para_birimleri.goruntule', 'para_birimleri.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <ParaBirimleriPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('ParaBirimleriPage', () => {
  beforeEach(() => {
    api.liste.mockResolvedValue([
      { id: 1, kod: 'TL', ad: 'Türk Lirası' },
      { id: 2, kod: 'USD', ad: 'ABD Doları' },
    ])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('yeni para birimini kodu büyük harfe çevirerek kaydeder', async () => {
    api.kaydet.mockResolvedValue({ id: 3, kod: 'GBP', ad: 'Sterlin' })
    ciz()
    await screen.findByText('Türk Lirası')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Para Birimi' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kod'), { target: { value: 'gbp' } })
    fireEvent.change(within(dialog).getByLabelText('Ad'), { target: { value: 'Sterlin' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.kaydet).toHaveBeenCalledWith(null, { kod: 'GBP', ad: 'Sterlin' }),
    )
  })

  it('kullanılan para birimi silinemezse nedenini gösterir', async () => {
    api.sil.mockRejectedValue(
      Object.assign(new Error('409'), {
        isAxiosError: true,
        response: { status: 409, data: { kod: 'KAYIT_KULLANILIYOR' } },
      }),
    )
    ciz()
    await screen.findByText('Türk Lirası')

    fireEvent.click(screen.getAllByRole('button', { name: 'Sil' })[0])
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    expect(
      await screen.findByText('Bu kayıt kesinti satırlarında kullanıldığı için silinemez.'),
    ).toBeInTheDocument()
  })
})
