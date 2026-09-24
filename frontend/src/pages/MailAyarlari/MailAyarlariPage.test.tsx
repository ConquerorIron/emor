import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { MailAyari } from '@/features/ayarlar/mailApi'
import { AppProviders } from '@/providers/AppProviders'

import { MailAyarlariPage } from './MailAyarlariPage'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  guncelle: vi.fn(),
  test: vi.fn(),
}))

vi.mock('@/features/ayarlar/mailApi', () => ({
  mailAyariGetir: api.getir,
  mailAyariGuncelle: api.guncelle,
  testMailiGonder: api.test,
}))

const AYAR: MailAyari = {
  sunucu: 'smtp.office365.com',
  port: 587,
  sifreleme: 'tls',
  kullanici_adi: 'gonderen@ornek.test',
  sifre_dolu: true,
  gonderen_adres: 'gonderen@ornek.test',
  gonderen_ad: 'eMOR ERP',
  yonlendirme_adresi: null,
  updated_at: '2026-09-23T10:00:00Z',
}

function ciz() {
  render(
    <AppProviders>
      <MailAyarlariPage />
    </AppProviders>,
  )
}

function alan(id: string): HTMLInputElement {
  return document.getElementById(id) as HTMLInputElement
}

describe('MailAyarlariPage', () => {
  beforeEach(() => {
    api.getir.mockResolvedValue(AYAR)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kayıtlı ayarı gösterir, şifreyi göstermez', async () => {
    ciz()

    expect(await screen.findByDisplayValue('smtp.office365.com')).toBeInTheDocument()
    expect(alan('mail-sifre')).toHaveValue('')
    expect(screen.getByText(/Şifre kayıtlı/)).toBeInTheDocument()
  })

  it('boş şifreyle kaydederken şifreyi göndermez ve yönlendirmeyi null yollar', async () => {
    api.guncelle.mockResolvedValue(AYAR)
    ciz()
    await screen.findByDisplayValue('smtp.office365.com')

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    expect(api.guncelle).toHaveBeenCalledWith({
      sunucu: 'smtp.office365.com',
      port: 587,
      sifreleme: 'tls',
      kullanici_adi: 'gonderen@ornek.test',
      gonderen_adres: 'gonderen@ornek.test',
      gonderen_ad: 'eMOR ERP',
      yonlendirme_adresi: null,
    })
  })

  it('sunucu adına şema yazılırsa istek atmadan hata gösterir', async () => {
    ciz()
    await screen.findByDisplayValue('smtp.office365.com')

    fireEvent.change(alan('mail-sunucu'), { target: { value: 'smtp://x.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    expect(await screen.findByText(/Yalnız sunucu adı girin/)).toBeInTheDocument()
    expect(api.guncelle).not.toHaveBeenCalled()
  })

  it('yönlendirme açıksa uyarı gösterir', async () => {
    api.getir.mockResolvedValue({ ...AYAR, yonlendirme_adresi: 'test@ornek.test' })
    ciz()

    expect(await screen.findByRole('note')).toHaveTextContent('test@ornek.test')
  })

  it('test mailini yazılan alıcıya gönderir ve sonucu gösterir', async () => {
    api.test.mockResolvedValue(undefined)
    ciz()
    await screen.findByDisplayValue('smtp.office365.com')

    fireEvent.change(alan('mail-test-alici'), { target: { value: 'alici@ornek.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Test Maili Gönder' }))

    expect(await screen.findByRole('status')).toHaveTextContent('alici@ornek.test')
    expect(api.test).toHaveBeenCalledWith('alici@ornek.test')
  })

  it('test maili SMTP hatasını gösterir', async () => {
    api.test.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: {
          status: 422,
          data: {
            kod: 'DOGRULAMA',
            hatalar: { alici: ['Mail gönderilemedi: 535 Authentication unsuccessful'] },
          },
        },
      }),
    )
    ciz()
    await screen.findByDisplayValue('smtp.office365.com')

    fireEvent.change(alan('mail-test-alici'), { target: { value: 'alici@ornek.test' } })
    fireEvent.click(screen.getByRole('button', { name: 'Test Maili Gönder' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('535 Authentication unsuccessful')
  })
})
