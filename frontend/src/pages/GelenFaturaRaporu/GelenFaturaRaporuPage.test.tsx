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

/** Kuru olmayan dövizli fatura: TL kolonları boş (backend GelenFaturaRaporu) */
const kursuz = (id: number, firma: string): GelenFaturaSatiri => ({
  ...satir(id, 'İzibiz', 'hayir'),
  firma_unvani: firma,
  para_kodu: 'USD',
  kur: '',
  ...(Object.fromEntries(TL_TUTARLAR.map((alan) => [alan, null])) as Record<
    (typeof TL_TUTARLAR)[number],
    null
  >),
})

function ciz() {
  render(
    <AppProviders>
      <GelenFaturaRaporuPage />
    </AppProviders>,
  )
}

async function calistir(kayitSayisi: string) {
  fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
  expect(await screen.findByText(kayitSayisi)).toBeInTheDocument()
}

/** Data / özet tablosunun gövde satırları */
const govdeSatirlari = () => within(screen.getAllByRole('rowgroup')[1]).getAllByRole('row')

const basliklar = () =>
  screen
    .getAllByRole('columnheader')
    .map((baslik) => baslik.textContent?.replace(/[↕▲▼]/g, '').trim())

describe('GelenFaturaRaporuPage', () => {
  beforeEach(() => {
    // Varsayılan ilk tarih yılın başı: saat sabit (Dikkat T12)
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-27T10:00:00'))
    api.rapor.mockResolvedValue([
      satir(3, 'İzibiz', 'hayir'),
      satir(2, 'Protel', 'evet'),
      satir(1, 'Logo', 'hayir'),
    ])
    api.excel.mockResolvedValue(undefined)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
    // Kolon sıralaması tarayıcıda hatırlanır: testler birbirini etkilemesin
    localStorage.clear()
  })

  it('ERP parametre seçicileri yok, seçenekler istenmez; Logo kolonları sırasıyla, Hareket No ve Kesinti yok', async () => {
    ciz()
    expect(screen.getByRole('heading', { name: 'Gelen Fatura Raporu' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Proje Kodu')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Firma Adı')).not.toBeInTheDocument()
    expect(api.secenekler).not.toHaveBeenCalled()

    await calistir('3 kayıt')
    expect(api.rapor.mock.calls[0][0]).toMatchObject({ proje_kodu: '', firma_adi: '' })

    expect(basliklar().slice(0, 9)).toEqual([
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
    expect(basliklar()).toContain('Matrah 20')
    expect(basliklar()).not.toContain('Hareket No')
    expect(basliklar().some((b) => b?.includes('Kesinti'))).toBe(false)
    expect(screen.getByLabelText('Entegratöre göre filtrele')).toBeInTheDocument()
  })

  it('açılışta aralık yılın başından bugüne (en çok 366 gün)', async () => {
    ciz()

    await calistir('3 kayıt')

    expect(api.rapor.mock.calls[0][0]).toMatchObject({
      ilk_tarih: '2026-01-01',
      son_tarih: '2026-11-27',
    })
  })

  it('"RED\'leri çıkar" RED satırlarını düşürür; Excel ekrandaki satırları kimlikleriyle alır', async () => {
    ciz()
    await calistir('3 kayıt')

    fireEvent.click(screen.getByRole('switch', { name: "RED'leri çıkar" }))
    expect(await screen.findByText('3 kayıttan 2 kayıt')).toBeInTheDocument()
    expect(screen.queryByText('FTR2')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ anahtarlar: ['3', '1'] })
  })

  it('her kolon başlığa basınca sıralanır; Excel ekrandaki sırayla iner', async () => {
    ciz()
    await calistir('3 kayıt')

    const faturaNolari = () =>
      govdeSatirlari().map((satir) => within(satir).getAllByRole('cell')[2].textContent)
    expect(faturaNolari()).toEqual(['FTR3', 'FTR2', 'FTR1'])

    fireEvent.click(screen.getByRole('button', { name: 'Fatura No' }))
    expect(faturaNolari()).toEqual(['FTR1', 'FTR2', 'FTR3'])
    fireEvent.click(screen.getByRole('button', { name: 'Entegratör' }))
    expect(faturaNolari()).toEqual(['FTR3', 'FTR1', 'FTR2'])

    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toMatchObject({ anahtarlar: ['3', '1', '2'] })
  })

  it('boş tutar "0,00" değil "—" görünür; tutar ve kur sayı gibi sıralanır', async () => {
    api.rapor.mockResolvedValue([
      { ...satir(1, 'İzibiz', 'hayir'), tutar_tl: 7, kdv_20: 7, kur: '10,5' },
      { ...satir(2, 'İzibiz', 'hayir'), tutar_tl: null, kdv_20: null, kur: '9,2' },
      { ...satir(3, 'İzibiz', 'hayir'), tutar_tl: -5, kdv_20: -5, kur: '34,12' },
      { ...satir(4, 'İzibiz', 'hayir'), tutar_tl: 12.5, kdv_20: 12.5, kur: '34,5' },
    ])
    ciz()
    await calistir('4 kayıt')
    const kolon = (baslik: string) => basliklar().indexOf(baslik)
    const hucreler = (baslik: string) =>
      govdeSatirlari().map((satir) => within(satir).getAllByRole('cell')[kolon(baslik)].textContent)

    // Bilinmeyen (null) tutar sıfırdan ayırt edilir; Excel'de de hücre boş
    const bos = govdeSatirlari()[1]
    expect(within(bos).getAllByRole('cell')[kolon('Tutar TL')]).toHaveTextContent('—')
    expect(within(bos).getAllByRole('cell')[kolon('KDV 20')]).toHaveTextContent('—')

    fireEvent.click(screen.getByRole('button', { name: 'Tutar TL' }))
    // Boş en küçük; eksi tutar sıfırın önünde
    expect(hucreler('Tutar TL')).toEqual(['—', '-5,00', '7,00', '12,50'])

    fireEvent.click(screen.getByRole('button', { name: 'KUR' }))
    expect(hucreler('KUR')).toEqual(['9,2', '10,5', '34,12', '34,5'])
  })

  it('kuru olmayan dövizli fatura TL toplamının etiketinde belirtilir; özet grubunda TL hücresi boş', async () => {
    api.rapor.mockResolvedValue([
      { ...satir(1, 'İzibiz', 'hayir'), firma_unvani: 'Ankara A.Ş.' },
      kursuz(2, 'Chicago Inc'),
    ])
    ciz()
    await calistir('2 kayıt')

    const son = screen.getAllByRole('rowgroup')[2]
    expect(son).toHaveTextContent('Genel Toplam (TL) — kuru olmayan 1 fatura hariç')

    fireEvent.click(screen.getByRole('tab', { name: 'Firma' }))
    expect(await screen.findByText('2 satır')).toBeInTheDocument()
    const tutarTl = basliklar().indexOf('Tutar TL')
    const [ankara, chicago] = govdeSatirlari()
    expect(within(ankara).getAllByRole('cell')[tutarTl]).toHaveTextContent('10,00')
    expect(within(chicago).getAllByRole('cell')[tutarTl]).toHaveTextContent('—')
  })

  it('toplamlar yalnız tablonun sonunda; firma özeti sayfalı çizilir', async () => {
    api.rapor.mockResolvedValue(
      Array.from({ length: 60 }, (_, i) => ({
        ...satir(i + 1, 'İzibiz', 'hayir'),
        firma_unvani: `Firma ${i + 1}`,
      })),
    )
    ciz()
    await calistir('60 kayıt')

    const [baslik, , son] = screen.getAllByRole('rowgroup')
    expect(baslik).not.toHaveTextContent('Genel Toplam (TL)')
    expect(son).toHaveTextContent('Genel Toplam (TL)')
    expect(son).not.toHaveTextContent('kuru olmayan')

    fireEvent.click(screen.getByRole('tab', { name: 'Firma' }))
    expect(await screen.findByText('60 satır')).toBeInTheDocument()
    const [ozetBasligi, ozetGovdesi, ozetSonu] = screen.getAllByRole('rowgroup')
    // Varsayılan sayfa boyutu 50: 60 firmanın ilk 50'si
    expect(within(ozetGovdesi).getAllByRole('row')).toHaveLength(50)
    expect(ozetBasligi).not.toHaveTextContent('Genel Toplam (TL)')
    expect(ozetSonu).toHaveTextContent('Genel Toplam (TL)')
  })

  it('özet sekmesinde değişen sayfa boyutu Data sekmesine de uygulanır', async () => {
    api.rapor.mockResolvedValue(
      Array.from({ length: 120 }, (_, i) => ({
        ...satir(i + 1, 'İzibiz', 'hayir'),
        firma_unvani: `Firma ${i + 1}`,
      })),
    )
    ciz()
    await calistir('120 kayıt')
    expect(govdeSatirlari()).toHaveLength(50)

    fireEvent.click(screen.getByRole('tab', { name: 'Firma' }))
    const ust = document.querySelector<HTMLElement>(
      'label[for="gelenFaturaRaporu-firma-sayfa-boyutu-ust"]',
    )!
    fireEvent.keyDown(within(ust).getByRole('combobox'), { key: 'ArrowDown' })
    fireEvent.click(await within(ust).findByText('100'))
    await waitFor(() => expect(govdeSatirlari()).toHaveLength(100))

    fireEvent.click(screen.getByRole('tab', { name: 'Data' }))
    expect(govdeSatirlari()).toHaveLength(100)
  })

  it('"Hepsi" sayfa boyutu raporda 1.000 satırla sınırlanır, sayfalama sürer', async () => {
    localStorage.setItem('erp.sayfaBoyutu', '0')
    // Çizim süresi için yalnız Fatura No görünür (gizlenen kolonlar tarayıcıda saklanır)
    localStorage.setItem(
      'erp.kolonlar.rapor.gelenFaturaRaporu',
      JSON.stringify(
        Object.keys(satir(0, '', 'hayir')).filter(
          (alan) => !['fatura_no', 'id', 'red'].includes(alan),
        ),
      ),
    )
    api.rapor.mockResolvedValue(
      Array.from({ length: 1500 }, (_, i) => satir(i + 1, 'İzibiz', 'hayir')),
    )
    ciz()
    await calistir('1500 kayıt')

    expect(govdeSatirlari()).toHaveLength(1000)
    fireEvent.click(screen.getAllByRole('button', { name: '2' })[0])
    expect(govdeSatirlari()).toHaveLength(500)
  }, 30_000)
})
