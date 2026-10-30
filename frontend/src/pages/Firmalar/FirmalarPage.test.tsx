import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { ErpFirmaSatiri, Firma } from '@/features/kesinti/firmaApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { FirmalarPage } from './FirmalarPage'

const api = vi.hoisted(() => ({
  firmalar: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
  erpOnizleme: vi.fn(),
  erpAktar: vi.fn(),
}))

vi.mock('@/features/kesinti/firmaApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/firmaApi')>()),
  firmalariGetir: api.firmalar,
  firmaKaydet: api.kaydet,
  firmaSil: api.sil,
  erpFirmalariniGetir: api.erpOnizleme,
  erpFirmalariniAktar: api.erpAktar,
}))

function firma(id: number, ad: string, ek: Partial<Firma> = {}): Firma {
  return {
    id,
    kod: String(1000000000 + id),
    ad,
    vergi_kimlik_no: String(1000000000 + id),
    vergi_dairesi: null,
    eposta: null,
    gsm_no: null,
    erp_guid: null,
    ...ek,
  }
}

// 61 firma: liste 50'şerli sayfalanır
const FIRMALAR: Firma[] = [
  firma(1, 'AKTAŞ HAFRİYAT', { erp_guid: '11111111-1111-4111-8111-111111111111' }),
  ...Array.from({ length: 60 }, (_, i) => firma(i + 2, `Firma ${String(i).padStart(2, '0')}`)),
]

function erpSatiri(
  sira: number,
  ad: string,
  durum: ErpFirmaSatiri['durum'],
  ek: Partial<ErpFirmaSatiri> = {},
): ErpFirmaSatiri {
  return {
    sira,
    guid: `0000000${sira}-0000-4000-8000-000000000000`,
    kod: String(2000000000 + sira),
    ad,
    vergi_kimlik_no: String(2000000000 + sira),
    vergi_dairesi: null,
    eposta: null,
    gsm_no: null,
    durum,
    neden: null,
    mevcut: null,
    ...ek,
  }
}

function ciz(izinler = ['firmalar.goruntule', 'firmalar.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <FirmalarPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('FirmalarPage', () => {
  beforeEach(() => {
    api.firmalar.mockResolvedValue(FIRMALAR)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('firmaları 50şerli sayfalar ve Türkçe büyük/küçük harf duyarsız arar', async () => {
    ciz()

    expect(await screen.findByText('AKTAŞ HAFRİYAT')).toBeInTheDocument()
    expect(screen.getByText('61 firma')).toBeInTheDocument()
    expect(screen.queryByText('Firma 59')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: '2' }))
    expect(await screen.findByText('Firma 59')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Ara'), { target: { value: 'aktaş hafriyat' } })
    expect(await screen.findByText('1 firma')).toBeInTheDocument()
    expect(screen.getByText('AKTAŞ HAFRİYAT')).toBeInTheDocument()
    expect(screen.queryByText('Firma 59')).not.toBeInTheDocument()
  })

  it('yeni firmayı kodu büyük harfe çevirip boş isteğe bağlı alanları null göndererek kaydeder', async () => {
    api.kaydet.mockResolvedValue(firma(99, 'MFK'))
    ciz()
    await screen.findByText('AKTAŞ HAFRİYAT')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Firma' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Firma Kodu'), { target: { value: 'mfk' } })
    fireEvent.change(within(dialog).getByLabelText('Firma Adı'), {
      target: { value: 'MFK İnşaat' },
    })
    fireEvent.change(within(dialog).getByLabelText('Vergi Kimlik No'), {
      target: { value: '6301126332' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, {
      kod: 'MFK',
      ad: 'MFK İnşaat',
      vergi_kimlik_no: '6301126332',
      vergi_dairesi: null,
      eposta: null,
      gsm_no: null,
    })
  })

  it('ERP önizlemesi seçimsiz gelir; aranıp seçilen tek firma aktarılır, görünmeyenler aktarılmaz', async () => {
    api.erpOnizleme.mockResolvedValue([
      erpSatiri(1, 'ALFA YAPI', 'yeni'),
      erpSatiri(2, 'BETA İNŞAAT', 'eslesecek', {
        mevcut: {
          kod: 'ELLE-B',
          ad: 'Beta',
          vergi_kimlik_no: '2000000002',
          vergi_dairesi: null,
          eposta: null,
          gsm_no: null,
        },
      }),
      erpSatiri(3, 'GAMA ELEKTRİK', 'yeni'),
      erpSatiri(4, 'DELTA', 'ayni'),
    ])
    api.erpAktar.mockResolvedValue({ eklenen: 0, guncellenen: 0, eslesen: 1, atlanan: 0 })
    ciz()
    await screen.findByText('AKTAŞ HAFRİYAT')

    fireEvent.click(screen.getByRole('button', { name: "ERP'den Getir" }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('ELLE-B')).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Seçilenleri Aktar (0)' })).toBeDisabled()

    // Yalnız BETA görünürken "tümünü seç" yalnız onu seçer
    fireEvent.change(within(dialog).getByLabelText('Ara'), { target: { value: 'beta' } })
    expect(within(dialog).queryByText('ALFA YAPI')).not.toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Tümünü seç' }))

    fireEvent.click(within(dialog).getByRole('button', { name: 'Seçilenleri Aktar (1)' }))

    await waitFor(() => expect(api.erpAktar).toHaveBeenCalledTimes(1))
    expect(api.erpAktar).toHaveBeenCalledWith(['00000002-0000-4000-8000-000000000000'])
  })

  it('yalnız görüntüleme izninde ekleme, ERP aktarımı, düzenleme ve silme sunulmaz', async () => {
    ciz(['firmalar.goruntule'])

    expect(await screen.findByText('AKTAŞ HAFRİYAT')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Firma' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: "ERP'den Getir" })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
  })
})
