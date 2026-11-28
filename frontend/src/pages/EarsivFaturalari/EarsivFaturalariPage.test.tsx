import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { EFaturaListesi } from '@/features/efatura/efaturaApi'
import type { IceAktarimKolonlari, IceAktarimOnizlemesi } from '@/features/efatura/kaynakApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { EarsivFaturalariPage } from './EarsivFaturalariPage'

const api = vi.hoisted(() => ({
  faturalar: vi.fn(),
  kontrol: vi.fn(),
  aktar: vi.fn(),
  kolonlar: vi.fn(),
}))

vi.mock('@/features/efatura/kaynakApi', () => ({
  kaynakFaturalariGetir: api.faturalar,
  kaynakPdfGetir: vi.fn(),
  kaynakExcelIndir: vi.fn(),
  iceAktarimKolonlari: api.kolonlar,
  iceAktarimKontrol: api.kontrol,
  iceAktar: api.aktar,
  yuklemeleriGetir: vi.fn().mockResolvedValue([]),
  yuklemeyiGeriAl: vi.fn(),
}))

const BOS_LISTE: EFaturaListesi = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0, from: null },
  ozet: [],
  secenekler: {
    durumlar: [],
    para_birimleri: [],
    tipler: [],
    istisna_kodlari: [],
    gizlenen_adet: 0,
  },
  kapsam: { ortam: 'canli' },
}

/** İki belge türü: kolonları birbirinden farklı (e-Arşiv faturası / e-SMM makbuzu) */
const KOLONLAR: IceAktarimKolonlari[] = [
  {
    belge_turu: 'fatura',
    kolonlar: [
      { alan: 'belge_no', basliklar: ['Fatura No'] },
      { alan: 'tutar', basliklar: ['Toplam Tutar'] },
    ],
  },
  {
    belge_turu: 'makbuz',
    kolonlar: [
      { alan: 'belge_no', basliklar: ['Makbuz No'] },
      { alan: 'brut_tutar', basliklar: ['Brüt Ücret'] },
      { alan: 'stopaj_tutari', basliklar: ['Stopaj'] },
    ],
  },
]

const HATASIZ_MAKBUZ: IceAktarimOnizlemesi = {
  hata: null,
  eksik_kolonlar: [],
  belge_turu: 'makbuz',
  hatalar: [],
  ozet: { okunan: 1, yeni: 1, guncellenecek: 0, degismeyen: 0, red: 0 },
}

function ciz(izinler: string[]) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <EarsivFaturalariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('EarsivFaturalariPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-26T09:00:00'))
    localStorage.clear()
    api.faturalar.mockResolvedValue(BOS_LISTE)
    api.kolonlar.mockResolvedValue(KOLONLAR)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('yapıştırmada seçilen belge türünün kolonları çizilir ve tür isteğe gider; tür değişince tablo ve kontrol sıfırlanır', async () => {
    api.kontrol.mockResolvedValue(HATASIZ_MAKBUZ)
    ciz(['earsiv_faturalari.goruntule', 'earsiv_faturalari.ice_aktar'])

    fireEvent.click(await screen.findByRole('button', { name: "Excel'den İçe Aktar" }))
    const pencere = await screen.findByRole('dialog')
    fireEvent.click(within(pencere).getByRole('tab', { name: 'Kopyala-yapıştır' }))
    const tablo = await within(pencere).findByRole('grid', { name: 'Yapıştırma tablosu' })
    const basliklar = () =>
      within(tablo)
        .getAllByRole('columnheader')
        .map((th) => th.textContent)
    // İlk tür varsayılan
    expect(basliklar()).toEqual(['#', 'Fatura No', 'Toplam Tutar'])

    fireEvent.click(within(pencere).getByRole('radio', { name: 'Makbuz' }))
    expect(basliklar()).toEqual(['#', 'Makbuz No', 'Brüt Ücret', 'Stopaj'])
    fireEvent.paste(tablo, { clipboardData: { getData: () => 'SM-1\t1.000,00\t200,00' } })
    fireEvent.click(within(pencere).getByRole('button', { name: 'Kontrol Et' }))

    await waitFor(() =>
      expect(api.kontrol).toHaveBeenCalledWith('earsiv', {
        metin: 'SM-1\t1.000,00\t200,00',
        belge_turu: 'makbuz',
      }),
    )
    expect(await within(pencere).findByText('1 satır hatasız okundu (Makbuz).')).toBeInTheDocument()
    expect(within(pencere).getByRole('button', { name: 'Kaydet' })).toBeEnabled()

    // Kolonlar türe göre değişir: yapıştırılan satırlar ve kontrol düşer
    fireEvent.click(within(pencere).getByRole('radio', { name: 'Fatura' }))
    expect(basliklar()).toEqual(['#', 'Fatura No', 'Toplam Tutar'])
    expect(within(tablo).queryByText('SM-1')).not.toBeInTheDocument()
    expect(within(pencere).queryByText(/hatasız/)).not.toBeInTheDocument()
    expect(within(pencere).getByRole('button', { name: 'Kaydet' })).toBeDisabled()
    expect(within(pencere).getByRole('button', { name: 'Kontrol Et' })).toBeDisabled()
  })

  it('belge türü filtresi listeyi o türle ister; brüt ve stopaj kolonları vardır', async () => {
    ciz(['earsiv_faturalari.goruntule'])
    await waitFor(() => expect(api.faturalar).toHaveBeenCalled())
    expect(api.faturalar.mock.calls[0][0]).toBe('earsiv')
    expect(screen.getByRole('columnheader', { name: /Brüt \/ Toplam/ })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /Stopaj/ })).toBeInTheDocument()

    fireEvent.keyDown(screen.getByLabelText('Belge Türü'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('Makbuz'))

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'earsiv',
        expect.objectContaining({ belge_turu: 'makbuz' }),
        expect.anything(),
        1,
      ),
    )
  })
})
