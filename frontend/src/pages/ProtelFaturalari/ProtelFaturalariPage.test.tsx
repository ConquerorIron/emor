import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { EFatura, EFaturaListesi, SenkronDurumu } from '@/features/efatura/efaturaApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { ProtelFaturalariPage } from './ProtelFaturalariPage'

const api = vi.hoisted(() => ({
  faturalar: vi.fn(),
  durum: vi.fn(),
  senkron: vi.fn(),
  pdf: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('@/features/efatura/protelApi', () => ({
  protelFaturalariGetir: api.faturalar,
  protelDurumuGetir: api.durum,
  protelSenkronBaslat: api.senkron,
  protelPdfGetir: api.pdf,
  protelExcelIndir: api.excel,
}))

const FATURA: EFatura = {
  id: 21,
  yon: 'gelen',
  kaynak_id: 12236857,
  ettn: 'c14ffe5c-f9e9-42d9-ad5e-c8c84d592e84',
  belge_no: 'OTL2026000000001',
  belge_tarihi: '2026-05-25',
  belge_saati: '00:00:00',
  olusturma_zamani: '2026-05-29T15:48:37+00:00',
  fatura_tipi: 'SATIS',
  senaryo: 'TEMELFATURA',
  para_birimi: 'TRY',
  tutar: '1786.0000',
  vergi_tutari: '401.9800',
  satir_sayisi: 4,
  gonderici_vkn: '0123456789',
  gonderici_unvan: 'Deniz İletişim A.Ş.',
  alici_vkn: '4550357065',
  alici_unvan: 'Deneme Otel A.Ş.',
  durum: 'Received',
  durum_aciklamasi: 'Alındı',
  gib_durum_kodu: 1200,
  gib_durum_aciklamasi: 'ZARF_BASARIYLA_ISLENDI',
  erp_okundu: true,
  emor_durumu: null,
  vergi_istisna_kodu: null,
  izibiz_istisna_kodu: null,
  okundu: false,
  yanit_aciklamasi: null,
  son_gorulme: '2026-11-19T10:00:00+00:00',
  gonderici_ad_soyad: null,
  alici_ad_soyad: null,
  gonderici_etiketi: null,
  alici_etiketi: null,
  irsaliye_no: null,
  siparis_no: null,
  siparis_tarihi: null,
  gtb_ref_no: null,
  gcb_tescil_no: null,
  gcb_tarihi: null,
  portal_notu: null,
  teslim_ref: null,
  harici_aktarim: null,
  mail_durumu: null,
  erp_durumu: 'Booked',
  yazdirildi: true,
  gizli: false,
  gizlenme_zamani: null,
  gizleyen: null,
}

function liste(faturalar: EFatura[]): EFaturaListesi {
  return {
    data: faturalar,
    meta: { current_page: 1, last_page: 1, total: faturalar.length },
    ozet: [
      { para_birimi: 'TRY', adet: faturalar.length, tutar: '1786.00', vergi_tutari: '401.98' },
    ],
    secenekler: {
      durumlar: [],
      para_birimleri: ['TRY'],
      tipler: [],
      istisna_kodlari: [],
      gizlenen_adet: 0,
    },
    kapsam: { ortam: 'canli' },
  }
}

function durum(degisen: Partial<SenkronDurumu> = {}): SenkronDurumu {
  return {
    ortam: 'canli',
    senkron_aktif: true,
    manuel_istek: null,
    yonler: {
      gelen: {
        veri_zamani: '2026-11-19T10:00:00+00:00',
        guncel: true,
        calisiyor: false,
        ardisik_hata: 0,
        son_calisma: null,
      },
    } as SenkronDurumu['yonler'],
    ...degisen,
  }
}

function ciz(izinler: string[]) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <ProtelFaturalariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('ProtelFaturalariPage', () => {
  beforeEach(() => {
    localStorage.clear()
    api.durum.mockResolvedValue(durum())
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('Protel durum kolonlarını gösterir; eMOR ve gizleme yoktur', async () => {
    api.faturalar.mockResolvedValue(liste([FATURA]))
    ciz(['protel_faturalari.goruntule'])

    const satir = (await screen.findByText('OTL2026000000001')).closest('tr') as HTMLElement
    expect(within(satir).getByText('Muhasebeleşti')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /Otel ERP Durumu/ })).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: /eMOR/ })).not.toBeInTheDocument()
    // PDF izni yoksa düğme de yok
    expect(screen.queryByRole('button', { name: 'PDF' })).not.toBeInTheDocument()
  })

  it('yazdırılmamış faturanın PDF düğmesi kapalıdır; yazdırılmışta Protel PDF ucu çağrılır', async () => {
    api.faturalar.mockResolvedValue(
      liste([FATURA, { ...FATURA, id: 22, belge_no: 'OTL2026000000002', yazdirildi: false }]),
    )
    api.pdf.mockResolvedValue({ pdf: new Blob(['%PDF-1.4']), kaynak: 'entegrator' })
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.pdf'])

    const kapali = (await screen.findByText('OTL2026000000002')).closest('tr') as HTMLElement
    const kapaliDugme = within(kapali).getByRole('button', { name: 'PDF' })
    expect(kapaliDugme).toBeDisabled()
    expect(kapaliDugme).toHaveAttribute('title', expect.stringContaining('yazdırılmış'))

    const acik = screen.getByText('OTL2026000000001').closest('tr') as HTMLElement
    fireEvent.click(within(acik).getByRole('button', { name: 'PDF' }))

    await waitFor(() => expect(api.pdf).toHaveBeenCalledWith(21))
    expect(api.pdf).toHaveBeenCalledTimes(1)
    // Protel'de XML yok
    expect(screen.queryByRole('button', { name: /XML/ })).not.toBeInTheDocument()
  })

  it('Excel tablonun hâlini ister: filtre, sıralama, sayfa ve görünen kolonlar', async () => {
    api.faturalar.mockResolvedValue(liste([FATURA]))
    api.excel.mockResolvedValue(undefined)
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.disari_aktar'])
    await screen.findByText('OTL2026000000001')

    fireEvent.click(screen.getByRole('button', { name: 'Excel' }))

    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    const [filtre, siralama, sayfa, kolonlar] = api.excel.mock.calls[0] as [
      { baslangic: string; erp_okundu: string },
      { anahtar: string; yon: string },
      number,
      { anahtar: string; baslik: string }[],
    ]
    expect(filtre).toMatchObject({ baslangic: '2026-01-01', erp_okundu: '' })
    expect(siralama).toEqual({ anahtar: 'olusturma_zamani', yon: 'desc' })
    expect(sayfa).toBe(1)
    expect(kolonlar.map((k) => k.anahtar)).toEqual(
      expect.arrayContaining(['belge_no', 'erp_durumu', 'yazdirildi']),
    )
    expect(kolonlar.find((k) => k.anahtar === 'erp_durumu')?.baslik).toBe('Otel ERP Durumu')
  })

  it('modül kapalıysa uyarı gösterir; senkron izni yoksa elle senkron düğmesi yoktur', async () => {
    api.durum.mockResolvedValue(durum({ senkron_aktif: false }))
    api.faturalar.mockResolvedValue(liste([]))
    ciz(['protel_faturalari.goruntule'])

    expect(await screen.findByText(/Protel bağlantısı sunucuda kapalı/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Senkron/i })).not.toBeInTheDocument()
  })
})
