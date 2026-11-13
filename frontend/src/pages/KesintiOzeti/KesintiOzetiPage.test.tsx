import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { KesintiOzetiPage } from './KesintiOzetiPage'
import type { KesintiOzeti, OzetGrubu } from './kesintiOzetiApi'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./kesintiOzetiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./kesintiOzetiApi')>()),
  kesintiOzetiApi: api,
}))

const grup = (
  kod: string,
  toplam: string,
  hab: string,
  taseron: string,
  iptal: string,
  kesilen: string,
  kalan: string,
): OzetGrubu => ({ kod, ad: kod, toplam, hab, taseron, iptal, kesilen, kalan })

/** Backend testindeki veri: TL, 30.09.2026'ya kadar */
const OZET: KesintiOzeti = {
  tarih: '2026-09-30',
  para_birimi: 'TL',
  isveren: 'HAB',
  projeler: ['ZONE 6', 'ZONE 10'],
  genel: {
    toplam: '2050.00',
    hab: '750.00',
    taseron: '1300.00',
    iptal: '100.00',
    kesilen: '1000.00',
    kalan: '200.00',
  },
  proje_dagilimi: [
    grup('ZONE 6', '800.00', '500.00', '300.00', '0.00', '200.00', '100.00'),
    grup('ZONE 10', '1250.00', '250.00', '1000.00', '100.00', '800.00', '100.00'),
  ],
  tur_dagilimi: [
    grup('YEMEK', '1500.00', '500.00', '1000.00', '100.00', '800.00', '100.00'),
    grup('İŞGÜCÜ', '550.00', '250.00', '300.00', '0.00', '200.00', '100.00'),
  ],
  taseronlar: [
    {
      ...grup('ZETAŞ', '1000.00', '0.00', '1000.00', '100.00', '800.00', '100.00'),
      ad: 'ZETAŞ ZEMİN',
    },
    grup('MFK', '300.00', '0.00', '300.00', '0.00', '200.00', '100.00'),
  ],
}

/** Satır başlığı (kod / TOPLAM) + tutar hücreleri */
const hucreler = (satir: HTMLElement) =>
  [...within(satir).queryAllByRole('rowheader'), ...within(satir).getAllByRole('cell')].map(
    (hucre) => hucre.textContent,
  )

describe('KesintiOzetiPage', () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue({
      projeler: [{ id: 3, kod: 'ZONE 6', ad: 'ZONE 6' }],
      // En çok satırlı önce: varsayılan TL
      para_birimleri: [
        { id: 1, kod: 'TL' },
        { id: 2, kod: 'USD' },
      ],
    })
    api.getir.mockResolvedValue(OZET)
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <KesintiOzetiPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('varsayılan para birimiyle açılır; başlık, kartlar ve dağılımlar örnek rapordaki gibi', async () => {
    expect(
      await screen.findByRole('heading', {
        name: 'ZONE 6 - ZONE 10 – EYLÜL SONU İTİBARIYLA KESİNTİ LİSTESİ',
      }),
    ).toBeInTheDocument()
    expect(api.getir).toHaveBeenCalledWith(
      expect.objectContaining({ para_birimi_id: 1, proje_id: [] }),
    )

    // Kartlar: tam sayı + oran (HAB toplamın, iptal taşeron payının)
    expect(screen.getByText('2.050')).toBeInTheDocument()
    expect(screen.getByText('TL · TOPLAMIN %36,6')).toBeInTheDocument()
    expect(screen.getByText('TL · TAŞERON PAYININ %7,7')).toBeInTheDocument()

    // Zone bazında: sıfır "-", lacivert TOPLAM
    const [zone, tur, taseron] = screen.getAllByRole('table')
    expect(hucreler(within(zone).getByRole('row', { name: /^ZONE 6/ }))).toEqual([
      'ZONE 6',
      '800,00',
      '500,00',
      '300,00',
      '-',
      '200,00',
      '100,00',
    ])
    expect(hucreler(within(zone).getByRole('row', { name: /^TOPLAM/ }))).toEqual([
      'TOPLAM',
      '2.050,00',
      '750,00',
      '1.300,00',
      '100,00',
      '1.000,00',
      '200,00',
    ])
    expect(
      within(tur)
        .getAllByRole('rowheader')
        .map((h) => h.textContent),
    ).toEqual(['YEMEK', 'İŞGÜCÜ', 'TOPLAM'])

    // Taşeronlar: firmanın toplamı = taşeron payı, toplam satırı yok; tam unvan üzerine gelince
    expect(
      within(taseron)
        .getAllByRole('columnheader')
        .map((h) => h.textContent),
    ).toEqual(['TAŞERON', 'TAŞERON FİRMA', 'TOPLAM KESİNTİ', 'İPTAL EDİLEN', 'KESİLEN', 'KALAN'])
    expect(hucreler(within(taseron).getByRole('row', { name: /^MFK/ }))).toEqual([
      'MFK',
      '300,00',
      '-',
      '200,00',
      '100,00',
    ])
    expect(within(taseron).getByRole('rowheader', { name: 'ZETAŞ' })).toHaveAttribute(
      'title',
      'ZETAŞ ZEMİN',
    )
    expect(within(taseron).queryByRole('row', { name: /^TOPLAM/ })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ para_birimi_id: 1, proje_id: [] })
  })

  // Rapor isteği seçenekler gelince atılır: yanıt testin başında değiştirilebilir
  it('ay sonu olmayan tarih başlıkta gün olarak yazılır', async () => {
    api.getir.mockResolvedValue({ ...OZET, tarih: '2026-09-15' })

    expect(
      await screen.findByRole('heading', {
        name: 'ZONE 6 - ZONE 10 – 15.09.2026 İTİBARIYLA KESİNTİ LİSTESİ',
      }),
    ).toBeInTheDocument()
  })

  it('seçimde kesinti yoksa rapor yerine bilgi gösterir; Excel kapalı', async () => {
    api.getir.mockResolvedValue({ ...OZET, proje_dagilimi: [], tur_dagilimi: [], taseronlar: [] })

    expect(
      await screen.findByText('Seçilen tarih, proje ve para biriminde kesinti yok.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeDisabled()
  })
})
