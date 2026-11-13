import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { FirmaOzetiPage } from './FirmaOzetiPage'
import type { FirmaOzeti } from './firmaOzetiApi'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
  ekstre: vi.fn(),
}))

vi.mock('./firmaOzetiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./firmaOzetiApi')>()),
  firmaOzetiApi: api,
}))

/** Backend testindeki veri: kolonlar ekran sırasıyla, KAMP verisiz */
const OZET: FirmaOzeti = {
  tarih: '2026-09-30',
  para_birimi: 'TL',
  kesinti_turleri: [
    { id: 1, kod: 'İŞGÜCÜ', ad: 'İŞ GÜCÜ' },
    { id: 8, kod: 'KAMP', ad: 'KAMP' },
    { id: 5, kod: 'YEMEK', ad: 'YEMEK' },
  ],
  satirlar: [
    {
      firma_id: 2,
      taseron: 'MFK',
      taseron_ad: 'MFK',
      proje_id: 6,
      proje: 'ZONE 6',
      kalanlar: ['100.00', '0.00', '0.00'],
      toplam_kalan: '100.00',
    },
    {
      firma_id: 1,
      taseron: 'ZETAŞ',
      taseron_ad: 'ZETAŞ ZEMİN',
      proje_id: 10,
      proje: 'ZONE 10',
      kalanlar: ['40.00', '0.00', '160.00'],
      toplam_kalan: '200.00',
    },
  ],
  toplam: { kalanlar: ['140.00', '0.00', '160.00'], toplam_kalan: '300.00' },
}

const metinler = (ogeler: HTMLElement[]) =>
  ogeler.map((oge) => oge.textContent?.replace(/[↕▲▼]/g, '') ?? '')

/** Veri satırlarının taşeronları, tablodaki sırayla (toplam satırları hariç) */
const taseronSirasi = (tablo: HTMLElement) =>
  within(tablo)
    .getAllByRole('row')
    .map((satir) => within(satir).queryAllByRole('cell')[0]?.textContent)
    .filter((ilk) => ilk === 'MFK' || ilk === 'ZETAŞ')

describe('FirmaOzetiPage', () => {
  beforeEach(() => {
    localStorage.clear()
    api.secenekler.mockResolvedValue({
      projeler: [],
      firmalar: [],
      para_birimleri: [{ id: 1, kod: 'TL' }],
    })
    api.getir.mockResolvedValue(OZET)
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <FirmaOzetiPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('taşeron + proje satırlarında kesinti türlerinin kalanı yan yana; toplam satırı, sıfır "-"', async () => {
    const tablo = await screen.findByRole('table')
    expect(api.getir).toHaveBeenCalledWith(
      expect.objectContaining({ para_birimi_id: 1, proje_id: [], firma_id: [] }),
    )
    expect(screen.getByText('2 taşeron / proje satırı (TL)')).toBeInTheDocument()

    expect(metinler(within(tablo).getAllByRole('columnheader'))).toEqual([
      'Taşeron',
      'Proje',
      'İŞGÜCÜ',
      'KAMP',
      'YEMEK',
      'Toplam Kalan',
    ])
    expect(
      metinler(within(within(tablo).getByRole('row', { name: /^ZETAŞ/ })).getAllByRole('cell')),
    ).toEqual(['ZETAŞ', 'ZONE 10', '40,00', '-', '160,00', '200,00'])
    expect(within(tablo).getByText('ZETAŞ')).toHaveAttribute('title', 'ZETAŞ ZEMİN')
    // Toplam: tablonun üstünde ve altında
    const toplamlar = within(tablo).getAllByRole('row', { name: /^Toplam/ })
    expect(toplamlar).toHaveLength(2)
    expect(metinler(within(toplamlar[0]).getAllByRole('cell'))).toEqual([
      'Toplam',
      '',
      '140,00',
      '-',
      '160,00',
      '300,00',
    ])

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ para_birimi_id: 1, firma_id: [] })
  })

  it('kolon başlığından sıralanır: Toplam Kalan ikinci tıkta büyükten küçüğe', async () => {
    const tablo = await screen.findByRole('table')
    expect(taseronSirasi(tablo)).toEqual(['MFK', 'ZETAŞ'])

    const toplamKalan = within(tablo).getByRole('button', { name: 'Toplam Kalan' })
    fireEvent.click(toplamKalan)
    fireEvent.click(toplamKalan)

    expect(taseronSirasi(tablo)).toEqual(['ZETAŞ', 'MFK'])
  })

  it('rakama tıklanınca o hücrenin ekstresi iner; Toplam Kalan bütün türleri, "-" tıklanmaz', async () => {
    api.ekstre.mockResolvedValue(undefined)
    await screen.findByRole('table')

    fireEvent.click(screen.getByRole('button', { name: 'ZETAŞ / ZONE 10 / YEMEK ekstresi (PDF)' }))
    await waitFor(() => expect(api.ekstre).toHaveBeenCalledTimes(1))
    expect(api.ekstre).toHaveBeenLastCalledWith(
      expect.objectContaining({ para_birimi_id: 1, firma_id: 1, proje_id: 10, kesinti_turu_id: 5 }),
      'kesinti-ekstresi-ZETAŞ-ZONE-10-YEMEK.pdf',
    )

    // Kalanı olmayan hücre düğme değil
    expect(
      screen.queryByRole('button', { name: 'ZETAŞ / ZONE 10 / KAMP ekstresi (PDF)' }),
    ).not.toBeInTheDocument()

    fireEvent.click(
      await screen.findByRole('button', {
        name: 'MFK / ZONE 6 / tüm kesinti türleri ekstresi (PDF)',
      }),
    )
    await waitFor(() => expect(api.ekstre).toHaveBeenCalledTimes(2))
    const [hucre, dosyaAdi] = api.ekstre.mock.calls[1]
    expect(hucre).toMatchObject({ firma_id: 2, proje_id: 6 })
    expect(hucre).not.toHaveProperty('kesinti_turu_id')
    expect(dosyaAdi).toBe('kesinti-ekstresi-MFK-ZONE-6.pdf')
  })

  it('kalan yoksa tablo yerine bilgi gösterir; Excel kapalı', async () => {
    api.getir.mockResolvedValue({ ...OZET, satirlar: [] })

    expect(
      await screen.findByText('Seçilen tarih, proje, firma ve para biriminde kalan kesinti yok.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeDisabled()
  })
})
