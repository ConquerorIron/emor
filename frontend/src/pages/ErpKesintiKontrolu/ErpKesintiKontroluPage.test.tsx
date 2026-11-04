import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { ErpKesintiKontroluPage } from './ErpKesintiKontroluPage'
import type { KesintiKontrolSatiri } from './erpKesintiKontroluApi'

const api = vi.hoisted(() => ({
  rapor: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./erpKesintiKontroluApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./erpKesintiKontroluApi')>()),
  erpKesintiKontroluApi: { getir: api.rapor, secenekler: api.secenekler, excel: api.excel },
}))

/** Kesintide yalnız toplam tutar; ERP tutarları verilenler */
const satir = (
  anahtar: string,
  taseron: string,
  para_kodu: string,
  toplam_tutar: number,
  hakedis_yansitilacak_gider: number,
  yansitma_faturasi: number,
): KesintiKontrolSatiri => ({
  anahtar,
  taseron,
  proje: 'ZONE 10',
  para_kodu,
  toplam_tutar,
  iptal_edilen_tutar: 0,
  yansitilan_tutar: 0,
  kesilen_tutar: 0,
  kalan_tutar: 0,
  hakedis_yansitilacak_gider,
  yansitma_faturasi,
})

describe('ErpKesintiKontroluPage', () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue({ projeler: [], firmalar: [] })
    api.rapor.mockResolvedValue([
      satir('a1', 'HAB', 'TL', 50, 0, 0),
      satir('a2', 'KORTEK YAPI', 'TL', 339.5, 0, 0),
      // ERP'de olup kesintisi olmayan grup
      satir('a3', 'KORTEK YAPI', 'USD', 0, 0, 84.5),
      satir('a4', 'TMC YAPI', 'TL', 1500, 1500, 1500),
    ])
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <ErpKesintiKontroluPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kolonlar istenen sırada, özet sekmesi yok; toplamlar para birimine göre, Excel satır anahtarlarıyla', async () => {
    expect(screen.getByRole('heading', { name: 'ERP Kesinti Kontrolü' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('4 kayıt')).toBeInTheDocument()

    expect(screen.getAllByRole('columnheader').map((baslik) => baslik.textContent)).toEqual([
      'Sıra No',
      'Taşeron',
      'Proje',
      'Para Birimi',
      'Toplam Tutar',
      'İptal Edilen Tutar',
      'Yansıtılan Tutar',
      'Kesilen Tutar',
      'Kalan Tutar',
      'Hakediş Yansıtılacak Gider',
      'Yansıtma Faturası',
    ])
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument()

    // Tablonun üstündeki toplamlar: TL, USD — Sıra No, Para Birimi, Toplam Tutar, Hakediş, Fatura
    const [, tl, usd] = screen.getAllByRole('row')
    const secili = (satirOgesi: HTMLElement) => {
      const degerler = within(satirOgesi)
        .getAllByRole('cell')
        .map((hucre) => hucre.textContent)

      return [degerler[0], degerler[3], degerler[4], degerler[9], degerler[10]]
    }
    expect(secili(tl)).toEqual(['Toplam', 'TL', '1.889,50', '1.500,00', '1.500,00'])
    expect(secili(usd)).toEqual(['Toplam', 'USD', '0,00', '0,00', '84,50'])

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ anahtarlar: ['a1', 'a2', 'a3', 'a4'] })
  })

  it('genel arama taşeron adında arar', async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('4 kayıt')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Genel Arama'), { target: { value: 'kortek' } })
    expect(await screen.findByText('4 kayıttan 2 kayıt')).toBeInTheDocument()
  })
})
