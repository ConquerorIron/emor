import { cleanup, fireEvent, render as rtlRender, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import '../i18n/i18n'
import { AppProviders } from '../providers/AppProviders'
import { SatinalmaTalebiPage } from './SatinalmaTalebiPage'

// Sayfa useQuery (personel seçenekleri) ve SelectField (tema) kullanır
function render(ui: React.ReactElement) {
  return rtlRender(<AppProviders>{ui}</AppProviders>)
}

describe('SatinalmaTalebiPage', () => {
  afterEach(cleanup)

  it('başlık blokları ve tek boş satırla açılır', () => {
    render(<SatinalmaTalebiPage />)

    expect(screen.getByRole('heading', { name: 'Satınalma Talebi' })).toBeInTheDocument()
    expect(screen.getByText('Talep Bilgileri')).toBeInTheDocument()
    expect(screen.getByText('Teslimat Bilgileri')).toBeInTheDocument()

    // Tarih bugünle dolu gelir (ERP davranışı)
    const tarih = screen.getByLabelText('Tarih')
    expect(tarih).toHaveValue(new Date().toLocaleDateString('tr-TR'))

    // Grid tek boş satırla başlar; tek satır silinemez
    expect(screen.getByLabelText('Ürün kodu 1')).toBeInTheDocument()
    expect(screen.getByLabelText('Satırı sil 1')).toBeDisabled()
  })

  it('satır eklenip silinebilir', async () => {
    render(<SatinalmaTalebiPage />)

    fireEvent.click(screen.getByRole('button', { name: 'Satır Ekle' }))
    expect(await screen.findByLabelText('Ürün kodu 2')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Satırı sil 2'))
    await waitFor(() => {
      expect(screen.queryByLabelText('Ürün kodu 2')).not.toBeInTheDocument()
    })
  })

  it('ürün kodu boşken kaydet hücre hatası üretir', async () => {
    render(<SatinalmaTalebiPage />)

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Ürün kodu 1')).toHaveAttribute('aria-invalid', 'true')
    })
  })
})
