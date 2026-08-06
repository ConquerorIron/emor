import { cleanup, fireEvent, render as rtlRender, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'

import '../i18n/i18n'
import { AppProviders } from '../providers/AppProviders'
import { bugunIso, tarihGoster } from '../utils/tarih'
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

    // Tarih bugünle dolu gelir (ERP davranışı) — GG.AA.YYYY maskeli gösterim
    const tarih = screen.getByLabelText('Tarih')
    expect(tarih).toHaveValue(tarihGoster(bugunIso()))

    // Talep No kullanıcı tarafından yazılamaz; ERP kayıtta üretir
    // (SOHOM_NUMERATOR_URET 47 → @SIPARIS_NO OUTPUT)
    expect(screen.getByLabelText('No')).toBeDisabled()

    // Grid tek boş satırla başlar; tek satır silinemez
    expect(screen.getByLabelText('Ürün kodu 1')).toBeInTheDocument()
    expect(screen.getByLabelText('Satırı sil 1')).toBeDisabled()
  })

  it('ilgi konusu varsayılan Projemiz gelir ve bağlı alan etiketi Proje olur', () => {
    render(<SatinalmaTalebiPage />)

    // Varsayılan @ILGI_CINSI = 7 (Projemiz); react-select seçili değeri metin olarak
    // basar ("Projemiz" satır kolonunda da geçtiği için select'e daraltılır)
    expect(
      screen.getByText('Projemiz', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()

    // Bağlı alanın (@ILGILI_ID) etiketi cinse göre: 7 → "Proje"
    expect(screen.getByLabelText('Proje')).toBeInTheDocument()
  })

  it('teslimat bloğundaki seçim alanları react-select olarak basılır', () => {
    // Regresyon: ozelBilesenler yalnız talep bloğuna geçilmişti; teslimat
    // alanları sessizce düz input'a düşüyordu (react-select girişi combobox'tır)
    render(<SatinalmaTalebiPage />)

    expect(screen.getByLabelText('Depo adı')).toHaveAttribute('role', 'combobox')
    expect(screen.getByLabelText('Teslimat adresi')).toHaveAttribute('role', 'combobox')
  })

  it('açıklama 200 karakterden fazlasını almaz', () => {
    render(<SatinalmaTalebiPage />)

    const aciklama = screen.getByLabelText('Açıklama')
    fireEvent.change(aciklama, { target: { value: 'a'.repeat(250) } })

    expect(aciklama).toHaveValue('a'.repeat(200))
    expect(screen.getByText('200/200')).toBeInTheDocument()
  })

  it('termin anahtarı açılmadan tarih girilemez', async () => {
    render(<SatinalmaTalebiPage />)

    // Varsayılan: boş ve kilitli (ERP Opsiyon tarihi davranışı)
    const terminGirisi = screen.getByLabelText('Termin', { selector: 'input[type="text"]' })
    expect(terminGirisi).toBeDisabled()
    expect(terminGirisi).toHaveValue('')

    // Anahtar açılınca giriş aktifleşir
    fireEvent.click(screen.getByRole('switch', { name: 'Termin' }))
    await waitFor(() => {
      expect(terminGirisi).toBeEnabled()
    })
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
