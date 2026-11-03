import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { GiderYansitmaFaturalariPage } from './GiderYansitmaFaturalariPage'
import type { GiderYansitmaFaturasi } from './giderYansitmaFaturalariApi'

const api = vi.hoisted(() => ({
  rapor: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./giderYansitmaFaturalariApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./giderYansitmaFaturalariApi')>()),
  giderYansitmaFaturalariApi: { getir: api.rapor, secenekler: api.secenekler, excel: api.excel },
}))

/** Genel toplam dışındaki tutarlar 0 */
const fatura = (
  fatura_no: string,
  para_kodu: string,
  genelToplamTl: number,
  genelToplamDoviz: number,
): GiderYansitmaFaturasi => ({
  tarih: '2026-01-10',
  fatura_no,
  unvan: 'MFK KOÇ LTD.ŞTİ',
  proje_kodu: 'ZONE 4-5 - ANA BÜTÇE',
  aciklama: 'YANSITMA BEDELİ',
  kdv_matrahi_tl: 0,
  kdv_tl: 0,
  vergi_tl: 0,
  tevkifat_tl: 0,
  genel_toplam_tl: genelToplamTl,
  kesinti_toplami_tl: 0,
  net_toplam_tl: 0,
  para_kodu,
  kdv_matrahi_doviz: 0,
  kdv_doviz: 0,
  vergi_doviz: 0,
  tevkifat_doviz: 0,
  genel_toplam_doviz: genelToplamDoviz,
  kesinti_toplami_doviz: 0,
  net_toplam_doviz: 0,
  kayit_eden: 'ferhat.topcuoglu',
  guncelleyen: 'umit.ozen',
})

describe('GiderYansitmaFaturalariPage', () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue({ projeler: [], firmalar: [] })
    api.rapor.mockResolvedValue([
      fatura('INS3', 'USD', 2150, 50),
      fatura('INS2', 'USD', 4300, 100),
      fatura('INS1', 'TL', 120, 120),
    ])
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <GiderYansitmaFaturalariPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('22 kolon proc sırasıyla; TL genel toplam ve para koduna göre döviz toplamları', async () => {
    expect(screen.getByRole('heading', { name: 'Gider Yansıtma Faturaları' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('3 kayıt')).toBeInTheDocument()

    expect(screen.getAllByRole('columnheader').map((baslik) => baslik.textContent)).toEqual([
      'Sıra No',
      'Tarih',
      'Fatura No',
      'Ünvan',
      'Proje Kodu',
      'Açıklama',
      'KDV Matrahı (TL)',
      'KDV (TL)',
      'Vergi (TL)',
      'Tevkifat (TL)',
      'Genel Toplam (TL)',
      'Kesinti Toplamı (TL)',
      'Net Toplam (TL)',
      'Para Kodu',
      'KDV Matrahı (Döviz)',
      'KDV (Döviz)',
      'Vergi (Döviz)',
      'Tevkifat (Döviz)',
      'Genel Toplam (Döviz)',
      'Kesinti Toplamı (Döviz)',
      'Net Toplam (Döviz)',
      'Kayıt Eden',
      'Güncelleyen',
    ])

    // Tablonun üstündeki toplamlar: TL genel toplam, ardından TL ve USD döviz toplamları
    const [, genelTl, tl, usd] = screen.getAllByRole('row')
    const hucreler = (satir: HTMLElement) =>
      within(satir)
        .getAllByRole('cell')
        .map((hucre) => hucre.textContent)
    // Sıra No, …, Genel Toplam (TL) = 11. kolon, Para Kodu = 14., Genel Toplam (Döviz) = 19.
    const secili = (satir: HTMLElement) => {
      const degerler = hucreler(satir)

      return [degerler[0], degerler[10], degerler[13], degerler[18]]
    }
    expect(secili(genelTl)).toEqual(['Genel Toplam (TL)', '6.570,00', '', ''])
    expect(secili(tl)).toEqual(['Toplam', '', 'TL', '120,00'])
    expect(secili(usd)).toEqual(['Toplam', '', 'USD', '150,00'])

    // Excel ekrandaki faturalarla, fatura numaralarıyla
    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ anahtarlar: ['INS3', 'INS2', 'INS1'] })

    // Genel arama fatura no'da da arar; toplamlar aranan satırlardan
    fireEvent.change(screen.getByLabelText('Genel Arama'), { target: { value: 'ins2' } })
    expect(await screen.findByText('3 kayıttan 1 kayıt')).toBeInTheDocument()
    expect(screen.getAllByText('4.300,00')).toHaveLength(3)
  })
})
