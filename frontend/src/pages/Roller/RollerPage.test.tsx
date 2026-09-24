import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Rol } from '@/features/ayarlar/yetkiApi'
import { AppProviders } from '@/providers/AppProviders'

import { RollerPage } from './RollerPage'

const api = vi.hoisted(() => ({
  izinler: vi.fn(),
  roller: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
}))

vi.mock('@/features/ayarlar/yetkiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/ayarlar/yetkiApi')>()),
  izinleriGetir: api.izinler,
  rolleriGetir: api.roller,
  rolKaydet: api.kaydet,
  rolSil: api.sil,
}))

const MUHASEBE: Rol = {
  id: 7,
  ad: 'Muhasebe',
  aciklama: null,
  izinler: ['efatura.goruntule', 'efatura.pdf'],
  kullanici_sayisi: 3,
}

function ciz() {
  render(
    <AppProviders>
      <RollerPage />
    </AppProviders>,
  )
}

describe('RollerPage', () => {
  beforeEach(() => {
    api.izinler.mockResolvedValue([
      'efatura.goruntule',
      'efatura.pdf',
      'efatura.disari_aktar',
      'efatura.senkron',
    ])
    api.roller.mockResolvedValue([MUHASEBE])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('rolleri izin etiketleri ve kullanıcı sayısıyla listeler', async () => {
    ciz()

    expect(await screen.findByText('Muhasebe')).toBeInTheDocument()
    expect(
      screen.getByText('e-Fatura listelerini görüntüleme, e-Fatura PDF görüntüleme'),
    ).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('yeni rolü seçilen izinlerle kaydeder', async () => {
    api.kaydet.mockResolvedValue({ ...MUHASEBE, id: 8, ad: 'Denetçi' })
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Rol' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Rol Adı'), { target: { value: 'Denetçi' } })
    fireEvent.click(
      within(dialog).getByRole('switch', { name: "e-Fatura listesini Excel'e aktarma" }),
    )
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, {
      ad: 'Denetçi',
      aciklama: null,
      izinler: ['efatura.disari_aktar'],
    })
  })

  it('boş adla istek atmadan hata gösterir', async () => {
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Rol' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByText('Rol adı zorunludur.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })

  it('düzenlemede mevcut izinleri açık gösterir ve kapatılanı çıkarır', async () => {
    api.kaydet.mockResolvedValue(MUHASEBE)
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    const pdf = within(dialog).getByRole('switch', { name: 'e-Fatura PDF görüntüleme' })
    expect(pdf).toBeChecked()

    fireEvent.click(pdf)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(7, {
      ad: 'Muhasebe',
      aciklama: null,
      izinler: ['efatura.goruntule'],
    })
  })

  it('silmeyi onaydan sonra yapar', async () => {
    api.sil.mockResolvedValue(undefined)
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Sil' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('3 kullanıcı bu rolün izinlerini kaybedecek')
    expect(api.sil).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(7))
  })
})
