import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { bugunIso } from '@/utils/tarih'

import { TaseronHakedisleriPage } from './TaseronHakedisleriPage'
import type { HakedisRaporu } from './taseronHakedisleriApi'

const api = vi.hoisted(() => ({
  rapor: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./taseronHakedisleriApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./taseronHakedisleriApi')>()),
  taseronHakedisleriGetir: api.rapor,
  taseronHakedisSecenekleriGetir: api.secenekler,
  taseronHakedisleriExcel: api.excel,
}))

const tutarlar = (imalat: number) => ({
  imalat,
  tutanak: 0,
  hakedis: 0,
  imalat_kesintisi: 0,
  kdv: 0,
  kdvli_hakedis: 0,
  kdv_tevkifati: 0,
  cari_kesinti: 0,
  yansitilacak_gider: 0,
  taseron_adina_yapilan_is: 0,
  net_odenecek: 0,
  odeme_talebi_tutari: 0,
  odenen_tutar: 0,
})

const RAPOR: HakedisRaporu = {
  data: [
    { proje_adi: 'ZONE 7', para_kodu: 'TL', ...tutarlar(240663.52) },
    { proje_adi: 'ZONE 10', para_kodu: 'TL', ...tutarlar(1000) },
  ],
  toplamlar: [{ para_kodu: 'TL', ...tutarlar(241663.52) }],
}

const VARSAYILAN = { ilk_tarih: '2000-01-01', son_tarih: bugunIso(), proje_kodu: '', firma_adi: '' }

describe('TaseronHakedisleriPage', () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue({
      projeler: [{ kod: 'ZONE 10', ad: 'ZONE 10' }],
      firmalar: ['ATİLLA GIDA'],
    })
    api.rapor.mockResolvedValue(RAPOR)
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <TaseronHakedisleriPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('rapor yalnız Raporu Çalıştır ile çalışır; kolonlar kullanıcının sırasında, para koduna göre toplamlı', async () => {
    expect(screen.getByText("Parametreleri seçip Raporu Çalıştır'a basın.")).toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeDisabled()
    expect(api.rapor).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))

    expect(await screen.findByText('ZONE 7')).toBeInTheDocument()
    expect(api.rapor).toHaveBeenCalledWith(VARSAYILAN)
    expect(screen.getAllByRole('columnheader').map((baslik) => baslik.textContent)).toEqual([
      'Sıra No',
      'Proje Adı',
      'Para Kodu',
      'İmalat',
      'Tutanak',
      'Hakediş',
      'İmalat Kesintisi',
      'KDV',
      "KDV'li Hakediş",
      'KDV Tevkifatı',
      'Cari Kesinti',
      'Yansıtılacak Gider',
      'Taşeron Adına Yapılan İş',
      'Net Ödenecek',
      'Ödeme Talebi Tutarı',
      'Ödenen Tutar',
    ])
    expect(screen.getByText('240.663,52')).toBeInTheDocument()
    // Toplam satırı tablonun üstünde ve altında
    expect(screen.getAllByText('241.663,52')).toHaveLength(2)

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toEqual(VARSAYILAN)
  })

  it('tarihler zorunlu; son tarih ilk tarihten önce olamaz', async () => {
    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('Tarih zorunludur.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('İlk Tarih'), { target: { value: '01.06.2026' } })
    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '01.01.2026' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('Son tarih ilk tarihten önce olamaz.')).toBeInTheDocument()
    expect(api.rapor).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '30.06.2026' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    await waitFor(() =>
      expect(api.rapor).toHaveBeenCalledWith({
        ...VARSAYILAN,
        ilk_tarih: '2026-06-01',
        son_tarih: '2026-06-30',
      }),
    )
  })
})
