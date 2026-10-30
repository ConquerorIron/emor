import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { ErpProjeSatiri, Proje } from '@/features/kesinti/projeApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { ProjelerPage } from './ProjelerPage'

const api = vi.hoisted(() => ({
  projeler: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
  erpOnizleme: vi.fn(),
  erpAktar: vi.fn(),
}))

vi.mock('@/features/kesinti/projeApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/projeApi')>()),
  projeleriGetir: api.projeler,
  projeKaydet: api.kaydet,
  projeSil: api.sil,
  erpProjeleriniGetir: api.erpOnizleme,
  erpProjeleriniAktar: api.erpAktar,
}))

const ELLE: Proje = { id: 1, kod: 'Z10', ad: 'Zone 10', erp_guid: null }
const ERP: Proje = {
  id: 2,
  kod: 'A01',
  ad: 'Ana Proje',
  erp_guid: '11111111-1111-4111-8111-111111111111',
}

function satir(
  sira: number,
  kod: string,
  durum: ErpProjeSatiri['durum'],
  ek: Partial<ErpProjeSatiri> = {},
): ErpProjeSatiri {
  return {
    sira,
    guid: `0000000${sira}-0000-4000-8000-000000000000`,
    kod,
    ad: `${kod} Adı`,
    durum,
    neden: null,
    mevcut: null,
    ...ek,
  }
}

const ONIZLEME: ErpProjeSatiri[] = [
  satir(0, 'A01', 'ayni'),
  satir(1, 'B01', 'guncellenecek', { mevcut: { kod: 'B01', ad: 'Eski Ad' }, ad: 'Yeni Ad' }),
  satir(2, 'Z10', 'eslesecek', { mevcut: { kod: 'Z10', ad: 'Zone 10' } }),
  satir(3, 'E01', 'yeni'),
  satir(4, 'F01', 'gecersiz', { neden: 'kod_tekrar' }),
]

function ciz(izinler = ['projeler.goruntule', 'projeler.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <ProjelerPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('ProjelerPage', () => {
  beforeEach(() => {
    api.projeler.mockResolvedValue([ERP, ELLE])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('projeleri kaynağıyla (ERP / elle) listeler', async () => {
    ciz()

    expect(await screen.findByText('Ana Proje')).toBeInTheDocument()
    expect(screen.getByText('Zone 10')).toBeInTheDocument()
    expect(screen.getByText('ERP')).toBeInTheDocument()
    expect(screen.getByText('Elle')).toBeInTheDocument()
  })

  it('yeni projeyi kodu Türkçe büyük harfe çevirerek kaydeder', async () => {
    api.kaydet.mockResolvedValue({ ...ELLE, id: 3 })
    ciz()
    await screen.findByText('Zone 10')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Proje' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Proje Kodu'), { target: { value: 'zone-i' } })
    fireEvent.change(within(dialog).getByLabelText('Proje Adı'), {
      target: { value: 'İstanbul Zone' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, { kod: 'ZONE-İ', ad: 'İstanbul Zone' })
  })

  it('ERP önizlemesinde hiçbir satır seçili gelmez; yalnız seçilenlerin GUIDleri gönderilir', async () => {
    api.erpOnizleme.mockResolvedValue(ONIZLEME)
    api.erpAktar.mockResolvedValue({ eklenen: 1, guncellenen: 1, eslesen: 0, atlanan: 0 })
    ciz()
    await screen.findByText('Zone 10')

    fireEvent.click(screen.getByRole('button', { name: "ERP'den Getir" }))
    const dialog = await screen.findByRole('dialog')

    expect(
      await within(dialog).findByText(
        '1 yeni, 1 güncellenecek, 1 elle açılmış kayıtla eşleşecek, 1 aktarılamaz. 1 kayıt zaten güncel.',
      ),
    ).toBeInTheDocument()
    // Güncel olan satır listelenmez; aktarılamayanın nedeni görünür
    expect(within(dialog).queryByText('A01')).not.toBeInTheDocument()
    expect(within(dialog).getByText("ERP'de aynı kod birden fazla kayıtta.")).toBeInTheDocument()
    expect(within(dialog).getByText('Eski Ad')).toBeInTheDocument()

    expect(within(dialog).getByRole('button', { name: 'Seçilenleri Aktar (0)' })).toBeDisabled()

    // Güncellenecek ve yeni satır seçilir; eşleşecek satır seçilmez
    const satirSecimleri = within(dialog).getAllByRole('button', { name: 'Satırı seç' })
    fireEvent.click(satirSecimleri[0])
    fireEvent.click(satirSecimleri[2])
    fireEvent.click(within(dialog).getByRole('button', { name: 'Seçilenleri Aktar (2)' }))

    await waitFor(() => expect(api.erpAktar).toHaveBeenCalledTimes(1))
    expect(api.erpAktar).toHaveBeenCalledWith([ONIZLEME[1].guid, ONIZLEME[3].guid])
  })

  it('ERP okunamazsa nedenini gösterir ve hiçbir şey aktarılmaz', async () => {
    api.erpOnizleme.mockRejectedValue(
      Object.assign(new Error('502'), {
        isAxiosError: true,
        response: { status: 502, data: { kod: 'ERP_OKUMA_YETKISI_YOK' } },
      }),
    )
    ciz()
    await screen.findByText('Zone 10')

    fireEvent.click(screen.getByRole('button', { name: "ERP'den Getir" }))
    const dialog = await screen.findByRole('dialog')

    expect(
      await within(dialog).findByText(
        'ERP veritabanı kullanıcısının bu veriyi okuma yetkisi yok. Veritabanı yöneticisinden okuma (SELECT) yetkisi isteyin.',
      ),
    ).toBeInTheDocument()
    expect(api.erpAktar).not.toHaveBeenCalled()
  })

  it('yalnız görüntüleme izninde ekleme, ERP aktarımı, düzenleme ve silme sunulmaz', async () => {
    ciz(['projeler.goruntule'])

    expect(await screen.findByText('Zone 10')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Proje' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: "ERP'den Getir" })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })
})
