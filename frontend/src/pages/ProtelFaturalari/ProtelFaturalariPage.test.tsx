import { onlineManager } from '@tanstack/react-query'
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
  islendi: vi.fn(),
}))

vi.mock('@/features/efatura/protelApi', () => ({
  protelFaturalariGetir: api.faturalar,
  protelDurumuGetir: api.durum,
  protelSenkronBaslat: api.senkron,
  protelPdfGetir: api.pdf,
  protelExcelIndir: api.excel,
  protelIslendiDegistir: api.islendi,
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
  erp_okundu: null,
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
  otel_erp_okundu: true,
  sap_islendi: null,
  protel_red: false,
}

function liste(faturalar: EFatura[]): EFaturaListesi {
  return {
    data: faturalar,
    meta: { current_page: 1, last_page: 1, total: faturalar.length, from: 1 },
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
    // Varsayılan tarih aralığı bugüne bağlı: saat sabit (PRT-R10)
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-19T09:00:00'))
    localStorage.clear()
    api.durum.mockResolvedValue(durum())
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
    onlineManager.setOnline(true)
  })

  it('en solda Sıra No; Protel durum kolonlarını gösterir; eMOR ve gizleme yoktur', async () => {
    api.faturalar.mockResolvedValue(liste([FATURA]))
    ciz(['protel_faturalari.goruntule'])

    const satir = (await screen.findByText('OTL2026000000001')).closest('tr') as HTMLElement
    expect(screen.getAllByRole('columnheader')[0]).toHaveTextContent('Sıra No')
    expect(satir.querySelector('td')).toHaveTextContent('1')
    expect(within(satir).getByText('Muhasebeleşti')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: /Otel ERP Durumu/ })).toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: /^eMOR$/ })).not.toBeInTheDocument()
    // PDF izni yoksa düğme de yok
    expect(screen.queryByRole('button', { name: 'PDF' })).not.toBeInTheDocument()
  })

  it('İşlendi anahtarı PDF’in sağında; tıklanınca hemen kaydeder, bildirir, satırı yerinde günceller; art arda tıklama tek istek', async () => {
    api.faturalar.mockResolvedValue(liste([FATURA]))
    let bitir: (fatura: EFatura) => void = () => undefined
    api.islendi.mockImplementation(
      () =>
        new Promise<EFatura>((coz) => {
          bitir = coz
        }),
    )
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.pdf', 'protel_faturalari.isle'])

    const satir = (await screen.findByText('OTL2026000000001')).closest('tr') as HTMLElement
    expect(screen.getAllByRole('columnheader')[2]).toHaveTextContent('İşlendi')
    const hucreler = satir.querySelectorAll('td')
    expect(within(hucreler[1]).getByRole('button', { name: 'PDF' })).toBeInTheDocument()
    const anahtar = within(hucreler[2]).getByRole('switch', { name: 'OTL2026000000001 işlendi' })
    expect(anahtar).not.toBeChecked()

    fireEvent.click(anahtar)
    fireEvent.click(anahtar)
    await waitFor(() => expect(api.islendi).toHaveBeenCalled())
    expect(api.islendi).toHaveBeenCalledTimes(1)
    expect(api.islendi).toHaveBeenCalledWith(21, true)

    bitir({ ...FATURA, sap_islendi: true })
    expect(
      await screen.findByText('OTL2026000000001 nolu fatura işlendi olarak güncellendi.'),
    ).toBeInTheDocument()
    await waitFor(() => expect(anahtar).toBeChecked())
    // Satır sunucunun yanıtıyla güncellenir; liste yeniden çekilmez
    expect(api.faturalar).toHaveBeenCalledTimes(1)
  })

  it('işaret kaldırılınca "işlenmedi" bildirilir; işleme izni yoksa anahtar durumu gösterir ama değiştirilemez', async () => {
    api.faturalar.mockResolvedValue(liste([{ ...FATURA, sap_islendi: true }]))
    api.islendi.mockResolvedValue({ ...FATURA, sap_islendi: null })
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.isle'])

    const anahtar = await screen.findByRole('switch', { name: 'OTL2026000000001 işlendi' })
    expect(anahtar).toBeChecked()
    fireEvent.click(anahtar)

    await waitFor(() => expect(api.islendi).toHaveBeenCalledWith(21, false))
    expect(
      await screen.findByText('OTL2026000000001 nolu fatura işlenmedi olarak güncellendi.'),
    ).toBeInTheDocument()
    await waitFor(() => expect(anahtar).not.toBeChecked())

    cleanup()
    api.islendi.mockClear()
    ciz(['protel_faturalari.goruntule'])
    const salt = await screen.findByRole('switch', { name: 'OTL2026000000001 işlendi' })
    expect(salt).toBeChecked()
    expect(salt).toBeDisabled()
    fireEvent.click(salt)
    expect(api.islendi).not.toHaveBeenCalled()
  })

  it('portalda reddedilen faturada İşlendi anahtarı yok, yalnız "Red" yazar', async () => {
    api.faturalar.mockResolvedValue(
      liste([
        {
          ...FATURA,
          id: 22,
          belge_no: 'OTL2026000000002',
          durum: 'RejectAnswered',
          protel_red: true,
        },
        FATURA,
      ]),
    )
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.pdf', 'protel_faturalari.isle'])

    const redSatiri = (await screen.findByText('OTL2026000000002')).closest('tr') as HTMLElement
    // İşlendi kolonu (PDF'in sağı) yalnız "Red" içerir
    const islendiHucresi = redSatiri.querySelectorAll('td')[2]
    expect(islendiHucresi).toHaveTextContent(/^Red$/)
    expect(
      within(islendiHucresi).getByTitle(/reddedildi; İşlendi işaretlenemez/),
    ).toBeInTheDocument()
    expect(within(redSatiri).queryByRole('switch')).not.toBeInTheDocument()
    // Reddedilmemiş satırın anahtarı yerinde
    expect(screen.getByRole('switch', { name: 'OTL2026000000001 işlendi' })).toBeEnabled()
  })

  it('eMOR VKN Var kolonu kurum kartını gösterir; hızlı filtre emor_vkn=evet ister, tekrar basınca kalkar', async () => {
    api.faturalar.mockResolvedValue(
      liste([
        { ...FATURA, emor_kurum: { var: true, kod: '120.01.001', ad: 'Deniz İletişim A.Ş.' } },
        {
          ...FATURA,
          id: 22,
          belge_no: 'OTL2026000000002',
          emor_kurum: { var: false, kod: null, ad: null },
        },
        {
          ...FATURA,
          id: 23,
          belge_no: 'OTL2026000000003',
          emor_kurum: { var: null, kod: null, ad: null },
        },
      ]),
    )
    ciz(['protel_faturalari.goruntule'])

    const hucre = async (belgeNo: string) => {
      const satir = (await screen.findByText(belgeNo)).closest('tr') as HTMLElement
      const sira = screen
        .getAllByRole('columnheader')
        .findIndex((th) => th.textContent?.startsWith('eMOR VKN Var'))

      return satir.querySelectorAll('td')[sira]
    }
    expect(await hucre('OTL2026000000001')).toHaveTextContent(/^Evet$/)
    expect(
      within(await hucre('OTL2026000000001')).getByTitle('120.01.001 — Deniz İletişim A.Ş.'),
    ).toBeInTheDocument()
    expect(await hucre('OTL2026000000002')).toHaveTextContent(/^Hayır$/)
    // ERP okunamadı: bilinmiyor
    expect(within(await hucre('OTL2026000000003')).getByTitle(/okunamadı/)).toHaveTextContent('—')

    const dugme = screen.getByRole('button', { name: "eMOR'da Kayıtlı Firmalar" })
    expect(dugme).toHaveAttribute('aria-pressed', 'false')
    fireEvent.click(dugme)
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        expect.objectContaining({ emor_vkn: 'evet' }),
        expect.anything(),
        1,
      ),
    )
    expect(dugme).toHaveAttribute('aria-pressed', 'true')
    // Tekrar basınca kalkar (önceki sonuç önbellekten gelir)
    fireEvent.click(dugme)
    expect(dugme).toHaveAttribute('aria-pressed', 'false')
  })

  it('PDF portalda yazdırılmamış faturada da açılır ve Protel PDF ucu doğru id ile çağrılır', async () => {
    api.faturalar.mockResolvedValue(
      liste([FATURA, { ...FATURA, id: 22, belge_no: 'OTL2026000000002', yazdirildi: false }]),
    )
    api.pdf.mockResolvedValue({ pdf: new Blob(['%PDF-1.4']), kaynak: 'entegrator' })
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.pdf'])

    const yazdirilmamis = (await screen.findByText('OTL2026000000002')).closest('tr') as HTMLElement
    fireEvent.click(within(yazdirilmamis).getByRole('button', { name: 'PDF' }))

    await waitFor(() => expect(api.pdf).toHaveBeenCalledWith(22))
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
    const [filtre, siralama, sayfa, kolonlar, ortam] = api.excel.mock.calls[0] as [
      { baslangic: string; otel_erp_okundu: string },
      { anahtar: string; yon: string },
      number,
      { anahtar: string; baslik: string }[],
      string,
    ]
    // Ekrandaki listenin ortamı (kapsam) — sunucuda farklıysa 409 (PRT-R3)
    expect(ortam).toBe('canli')
    expect(filtre).toMatchObject({
      baslangic: '2026-01-01',
      bitis: '2026-11-19',
      otel_erp_okundu: '',
    })
    expect(siralama).toEqual({ anahtar: 'olusturma_zamani', yon: 'desc' })
    expect(sayfa).toBe(1)
    expect(kolonlar.map((k) => k.anahtar)).toEqual(
      expect.arrayContaining(['emor_vkn', 'belge_no', 'erp_durumu', 'yazdirildi']),
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

  it('2 Ocak 2027 açılışında geçerli aralıkla liste ister (PRT-R4)', async () => {
    vi.setSystemTime(new Date('2027-01-02T09:00:00'))
    api.faturalar.mockResolvedValue(liste([FATURA]))
    ciz(['protel_faturalari.goruntule'])

    await waitFor(() => expect(api.faturalar).toHaveBeenCalled())
    expect(api.faturalar.mock.calls[0][0]).toMatchObject({
      baslangic: '2026-01-02',
      bitis: '2027-01-02',
    })
  })

  it('bağlantı yokken yeni filtre beklerken Excel eski listede kapalıdır; sonuç gelince yeni filtreyle iner (PRT-R9)', async () => {
    api.faturalar.mockResolvedValue(liste([FATURA]))
    api.excel.mockResolvedValue(undefined)
    ciz(['protel_faturalari.goruntule', 'protel_faturalari.disari_aktar'])
    await screen.findByText('OTL2026000000001')
    const excel = screen.getByRole('button', { name: 'Excel' })
    expect(excel).toBeEnabled()

    onlineManager.setOnline(false)
    fireEvent.change(screen.getByLabelText('Ara'), { target: { value: 'deniz' } })

    // Arama gecikmeyle uygulanır; istek beklemeye alınır, eski satırlar ekranda kalır
    await waitFor(() => expect(excel).toBeDisabled())
    expect(screen.getByText('OTL2026000000001')).toBeInTheDocument()
    expect(api.faturalar).toHaveBeenCalledTimes(1)

    onlineManager.setOnline(true)
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        expect.objectContaining({ ara: 'deniz' }),
        expect.anything(),
        1,
      ),
    )
    await waitFor(() => expect(excel).toBeEnabled())
    fireEvent.click(excel)

    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ ara: 'deniz' })
    expect(api.excel.mock.calls[0][4]).toBe('canli')
  })
})
