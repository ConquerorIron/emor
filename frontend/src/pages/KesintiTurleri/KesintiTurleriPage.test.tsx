import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiTuru } from '@/features/kesinti/kesintiTuruApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { KesintiTurleriPage } from './KesintiTurleriPage'

const api = vi.hoisted(() => ({
  turler: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
}))

vi.mock('@/features/kesinti/kesintiTuruApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/kesintiTuruApi')>()),
  kesintiTurleriniGetir: api.turler,
  kesintiTuruKaydet: api.kaydet,
  kesintiTuruSil: api.sil,
}))

const KAMP: KesintiTuru = {
  id: 4,
  kod: 'KMP',
  ad: 'Kamp Kesintisi',
  kdv_orani: '18.50',
  aciklama: 'Ortak kamp',
}

function ciz(izinler = ['kesinti_turleri.goruntule', 'kesinti_turleri.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <KesintiTurleriPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('KesintiTurleriPage', () => {
  beforeEach(() => {
    api.turler.mockResolvedValue([KAMP])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('türleri KDV oranı yüzde biçiminde listeler', async () => {
    ciz()

    expect(await screen.findByText('KMP')).toBeInTheDocument()
    expect(screen.getByText('Kamp Kesintisi')).toBeInTheDocument()
    expect(screen.getByText('%18,5')).toBeInTheDocument()
    expect(screen.getByText('Ortak kamp')).toBeInTheDocument()
  })

  it('yeni türü kodu Türkçe büyük harfe çevirerek kaydeder; boş açıklama null gider', async () => {
    api.kaydet.mockResolvedValue({ ...KAMP, id: 5 })
    ciz()
    await screen.findByText('KMP')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Kesinti Türü' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kod'), { target: { value: 'isg' } })
    fireEvent.change(within(dialog).getByLabelText('Ad'), { target: { value: 'OSGB Kesintisi' } })
    fireEvent.change(within(dialog).getByLabelText('KDV Oranı'), { target: { value: '20' } })
    expect(within(dialog).getByLabelText('Kod')).toHaveValue('İSG')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, {
      kod: 'İSG',
      ad: 'OSGB Kesintisi',
      kdv_orani: '20.00',
      aciklama: null,
    })
  })

  it('100ü aşan KDV oranını ve boş zorunlu alanları göndermez', async () => {
    ciz()
    await screen.findByText('KMP')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Kesinti Türü' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('KDV Oranı'), { target: { value: '120' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(
      await within(dialog).findByText('KDV oranı 0 ile 100 arasında olmalıdır.'),
    ).toBeInTheDocument()
    expect(within(dialog).getByText('Kod zorunludur.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ad zorunludur.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })

  it('düzenlemede türün kimliğiyle kaydeder', async () => {
    api.kaydet.mockResolvedValue(KAMP)
    ciz()
    await screen.findByText('KMP')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Ad'), { target: { value: 'Kamp Konaklama' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(4, {
      kod: 'KMP',
      ad: 'Kamp Konaklama',
      kdv_orani: '18.50',
      aciklama: 'Ortak kamp',
    })
  })

  it('silme onaylanınca türü siler', async () => {
    api.sil.mockResolvedValue(undefined)
    ciz()
    await screen.findByText('KMP')

    fireEvent.click(screen.getByRole('button', { name: 'Sil' }))
    const dialog = await screen.findByRole('dialog')
    expect(
      within(dialog).getByText('"KMP — Kamp Kesintisi" kesinti türü silinecek.'),
    ).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(4))
  })

  it('yalnız görüntüleme izninde ekleme, düzenleme ve silme sunulmaz', async () => {
    ciz(['kesinti_turleri.goruntule'])

    expect(await screen.findByText('KMP')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Kesinti Türü' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })
})
