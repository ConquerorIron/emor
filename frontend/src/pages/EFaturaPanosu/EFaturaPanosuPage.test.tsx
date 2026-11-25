import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { PanoOzeti } from '@/features/efatura/panoApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { EFaturaPanosuPage } from './EFaturaPanosuPage'

const api = vi.hoisted(() => ({ pano: vi.fn(), firmalar: vi.fn(), excel: vi.fn() }))

vi.mock('@/features/efatura/panoApi', () => ({
  panoGetir: api.pano,
  panoFirmalariGetir: api.firmalar,
  panoFirmalariExcelIndir: api.excel,
}))

const SIFIR = { gelen: 0, islenen: 0, red: 0, islenecek: 0, red_islenmis: 0, gizlenen: 0 }

const PANO: PanoOzeti = {
  portallar: [
    {
      saglayici: 'logo',
      gelen: 581,
      islenen: 220,
      red: 342,
      islenecek: 19,
      red_islenmis: 0,
      gizlenen: 0,
    },
    {
      saglayici: 'protel',
      gelen: 566,
      islenen: 312,
      red: 62,
      islenecek: 192,
      red_islenmis: 2,
      gizlenen: 0,
    },
    {
      saglayici: 'izibiz',
      gelen: 2037,
      islenen: 1831,
      red: 109,
      islenecek: 97,
      red_islenmis: 0,
      gizlenen: 4,
    },
    { saglayici: 'earsiv', ...SIFIR },
  ],
  toplam: { gelen: 3184, islenen: 2363, red: 513, islenecek: 308, red_islenmis: 2, gizlenen: 4 },
  aylik: [
    { saglayici: 'logo', aylar: { '2026-01': 12, '2026-02': 7 } },
    { saglayici: 'protel', aylar: { '2026-01': 147 } },
    { saglayici: 'izibiz', aylar: { '2026-03': 75 } },
    { saglayici: 'earsiv', aylar: {} },
  ],
}

function ciz(izinler: string[]) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <MemoryRouter>
          <EFaturaPanosuPage />
        </MemoryRouter>
      </SahteOturum>
    </AppProviders>,
  )
}

describe('EFaturaPanosuPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-03-15T09:00:00'))
    api.pano.mockResolvedValue(PANO)
    api.firmalar.mockResolvedValue([
      {
        vkn: '1160417867',
        unvan: 'Aydoğan Dış Ticaret',
        para_birimi: 'TRY',
        adet: 12,
        tutar: '214740.55',
      },
    ])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('yıl başından bugüne sayıları ister; her sayı Tüm Faturalar’ı aynı süzgeçle açar', async () => {
    ciz(['efatura_panosu.goruntule', 'tum_faturalar.goruntule'])

    await waitFor(() =>
      expect(api.pano).toHaveBeenCalledWith({ baslangic: '2026-01-01', bitis: '2026-03-15' }),
    )
    const dagilim = (await screen.findByRole('heading', { name: 'Fatura Dağılımı' })).closest(
      'section',
    ) as HTMLElement
    const logo = within(dagilim).getByText('Logo').closest('tr') as HTMLElement

    expect(within(logo).getByRole('link', { name: '19' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-01-01&bitis=2026-03-15&entegrator=logo&sinif=islenecek',
    )
    expect(within(logo).getByRole('link', { name: '342' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-01-01&bitis=2026-03-15&entegrator=logo&sinif=red',
    )
    expect(within(logo).getByRole('link', { name: '581' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-01-01&bitis=2026-03-15&entegrator=logo',
    )
    // Uyarı ve gizlenen sayıları da kendi süzgeçleriyle
    const protel = within(dagilim).getByText('Portal Plus (Protel)').closest('tr') as HTMLElement
    expect(within(protel).getByRole('link', { name: '2' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-01-01&bitis=2026-03-15&entegrator=protel&sinif=red_islenmis',
    )
    const izibiz = within(dagilim).getByText('İzibiz').closest('tr') as HTMLElement
    expect(within(izibiz).getByRole('link', { name: '4' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-01-01&bitis=2026-03-15&entegrator=izibiz&gizlenenler=yalniz',
    )
    // Toplam satırı tablonun üstünde ve altında
    expect(within(dagilim).getAllByText('3.184')).toHaveLength(2)
  })

  it('aylık sayı yalnız o ayın işlenecek faturalarını açar; Tüm Faturalar izni yoksa sayılar bağlantı değildir', async () => {
    ciz(['efatura_panosu.goruntule', 'tum_faturalar.goruntule'])
    const aylik = (
      await screen.findByRole('heading', { name: 'İşlenmemiş Faturaların Aylara Göre Dağılımı' })
    ).closest('section') as HTMLElement
    const logo = within(aylik).getByText('Logo').closest('tr') as HTMLElement
    expect(within(logo).getByRole('link', { name: '7' })).toHaveAttribute(
      'href',
      '/efatura/tum?baslangic=2026-02-01&bitis=2026-02-28&entegrator=logo&sinif=islenecek',
    )
    // Grafik: ay toplamları çubukların üstünde (Ocak 12 + 147)
    expect(
      within(aylik).getByRole('img', { name: 'İşlenecek faturaların aylara göre adedi' }),
    ).toHaveTextContent('159')

    cleanup()
    ciz(['efatura_panosu.goruntule'])
    await screen.findByRole('heading', { name: 'Fatura Dağılımı' })
    expect(screen.queryAllByRole('link')).toHaveLength(0)
  })

  it('firma dökümü portal ve durum süzgeciyle istenir, Excel aynı süzgeçle iner', async () => {
    api.excel.mockResolvedValue(undefined)
    ciz(['efatura_panosu.goruntule'])
    expect(await screen.findByText('Aydoğan Dış Ticaret')).toBeInTheDocument()
    expect(api.firmalar).toHaveBeenLastCalledWith({
      baslangic: '2026-01-01',
      bitis: '2026-03-15',
      entegrator: '',
      sinif: 'islenecek',
    })

    fireEvent.click(screen.getByRole('button', { name: 'Excel' }))
    await waitFor(() =>
      expect(api.excel).toHaveBeenCalledWith({
        baslangic: '2026-01-01',
        bitis: '2026-03-15',
        entegrator: '',
        sinif: 'islenecek',
      }),
    )
  })
})
