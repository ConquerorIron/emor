import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { GelenFaturaRaporuPage } from './GelenFaturaRaporuPage'
import { ORIJINAL_TUTARLAR, TL_TUTARLAR, type GelenFaturaSatiri } from './gelenFaturaRaporuApi'

const api = vi.hoisted(() => ({
  rapor: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./gelenFaturaRaporuApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./gelenFaturaRaporuApi')>()),
  gelenFaturaRaporuApi: { getir: api.rapor, secenekler: api.secenekler, excel: api.excel },
}))

const satir = (id: number, entegrator: string, red: 'evet' | 'hayir'): GelenFaturaSatiri => ({
  id,
  fatura_tarihi: '2026-11-20',
  fatura_no: `FTR${id}`,
  fatura_profili: 'TICARIFATURA',
  entegrator,
  firma_unvani: 'Güvenlik A.Ş.',
  vkn: '1112223333',
  vergi_dairesi: 'Beyoğlu',
  para_kodu: 'TRY',
  kur: '1',
  oran_0: '',
  oran_1: '',
  oran_10: '',
  oran_20: '%20',
  istisna_kodu: '',
  istisna_adi: '',
  tevkifat_kodu: '',
  tevkifat_aciklama: '',
  red,
  ...(Object.fromEntries(
    [...ORIJINAL_TUTARLAR, ...TL_TUTARLAR].map((alan) => [alan, 10]),
  ) as Record<(typeof ORIJINAL_TUTARLAR)[number] | (typeof TL_TUTARLAR)[number], number>),
})

describe('GelenFaturaRaporuPage', () => {
  beforeEach(() => {
    api.rapor.mockResolvedValue([
      satir(3, 'İzibiz', 'hayir'),
      satir(2, 'Protel', 'evet'),
      satir(1, 'Logo', 'hayir'),
    ])
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <GelenFaturaRaporuPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('ERP parametre seçicileri yok, seçenekler istenmez; Logo kolonları sırasıyla, Hareket No ve Kesinti yok', async () => {
    expect(screen.getByRole('heading', { name: 'Gelen Fatura Raporu' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Proje Kodu')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Firma Adı')).not.toBeInTheDocument()
    expect(api.secenekler).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('3 kayıt')).toBeInTheDocument()
    expect(api.rapor.mock.calls[0][0]).toMatchObject({ proje_kodu: '', firma_adi: '' })

    const basliklar = screen.getAllByRole('columnheader').map((baslik) => baslik.textContent)
    expect(basliklar.slice(0, 9)).toEqual([
      'Sıra No',
      'Fatura Tarihi',
      'Fatura No',
      'Fatura Profili',
      'Entegratör',
      'Firma Ünvanı',
      'Vergi / T.C. Kimlik No',
      'Vergi Dairesi Adı',
      'Döviz Cinsi',
    ])
    expect(basliklar).toContain('Matrah 20')
    expect(basliklar).not.toContain('Hareket No')
    expect(basliklar.some((b) => b?.includes('Kesinti'))).toBe(false)
    expect(screen.getByLabelText('Entegratöre göre filtrele')).toBeInTheDocument()
  })

  it('"RED\'leri çıkar" RED satırlarını düşürür; Excel ekrandaki satırları kimlikleriyle alır', async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('3 kayıt')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('switch', { name: "RED'leri çıkar" }))
    expect(await screen.findByText('3 kayıttan 2 kayıt')).toBeInTheDocument()
    expect(screen.queryByText('FTR2')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ anahtarlar: ['3', '1'] })
  })

  it('toplamlar yalnız tablonun sonunda; firma özeti sayfalı çizilir', async () => {
    api.rapor.mockResolvedValue(
      Array.from({ length: 60 }, (_, i) => ({
        ...satir(i + 1, 'İzibiz', 'hayir'),
        firma_unvani: `Firma ${i + 1}`,
      })),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('60 kayıt')).toBeInTheDocument()

    const [baslik, , son] = screen.getAllByRole('rowgroup')
    expect(baslik).not.toHaveTextContent('Genel Toplam (TL)')
    expect(son).toHaveTextContent('Genel Toplam (TL)')

    fireEvent.click(screen.getByRole('tab', { name: 'Firma' }))
    expect(await screen.findByText('60 satır')).toBeInTheDocument()
    const [ozetBasligi, ozetGovdesi, ozetSonu] = screen.getAllByRole('rowgroup')
    // Varsayılan sayfa boyutu 50: 60 firmanın ilk 50'si
    expect(within(ozetGovdesi).getAllByRole('row')).toHaveLength(50)
    expect(ozetBasligi).not.toHaveTextContent('Genel Toplam (TL)')
    expect(ozetSonu).toHaveTextContent('Genel Toplam (TL)')
  })
})
