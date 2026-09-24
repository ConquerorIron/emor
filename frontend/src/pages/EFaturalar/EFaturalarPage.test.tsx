import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StrictMode } from 'react'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Kullanici } from '@/features/auth/types'
import type { EFatura, EFaturaListesi, SenkronDurumu } from '@/features/efatura/efaturaApi'
import { AuthContext } from '@/providers/auth-context'
import { ThemeProvider } from '@/providers/ThemeProvider'
import { bugunIso, gunEkle } from '@/utils/tarih'

import { EFaturalarPage } from './EFaturalarPage'

const api = vi.hoisted(() => ({
  faturalar: vi.fn(),
  durum: vi.fn(),
  senkron: vi.fn(),
  excel: vi.fn(),
  pdf: vi.fn(),
  xml: vi.fn(),
  erp: vi.fn(),
}))

const toastlar = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('sonner', () => ({ toast: toastlar }))

vi.mock('@/features/efatura/efaturaApi', () => ({
  faturalariGetir: api.faturalar,
  senkronDurumuGetir: api.durum,
  senkronBaslat: api.senkron,
  excelIndir: api.excel,
  pdfGetir: api.pdf,
  xmlIndir: api.xml,
  erpSenkronla: api.erp,
}))

const FATURA: EFatura = {
  id: 11,
  yon: 'gelen',
  kaynak_id: 185664,
  ettn: 'eeb4f1a9-9bc7-4576-beb6-000000000001',
  belge_no: 'ABC2026000000001',
  belge_tarihi: '2026-01-10',
  belge_saati: '10:15:00',
  olusturma_zamani: '2026-01-10T07:15:00+00:00',
  fatura_tipi: 'SATIS',
  senaryo: 'TICARIFATURA',
  para_birimi: 'TRY',
  tutar: '1250.5000',
  vergi_tutari: '208.4200',
  satir_sayisi: 2,
  gonderici_vkn: '0123456789',
  gonderici_unvan: 'Deniz Boya Ltd.',
  alici_vkn: '9876543210',
  alici_unvan: 'Bizim Tersane A.Ş.',
  durum: 'RECEIVED',
  durum_aciklamasi: 'Alındı',
  gib_durum_kodu: 1300,
  gib_durum_aciklamasi: 'Başarıyla tamamlandı',
  erp_okundu: false,
  okundu: true,
  yanit_aciklamasi: null,
  son_gorulme: '2026-09-23T10:00:00+00:00',
  gonderici_ad_soyad: null,
  alici_ad_soyad: null,
  gonderici_etiketi: 'urn:mail:defaultgb@deniz.com',
  alici_etiketi: 'urn:mail:defaultpk@tersane.com',
  irsaliye_no: 'IRS2026000000007',
  siparis_no: 'SIP-42',
  siparis_tarihi: '2026-01-05',
  gtb_ref_no: null,
  gcb_tescil_no: null,
  gcb_tarihi: null,
  portal_notu: null,
  teslim_ref: null,
  harici_aktarim: null,
  mail_durumu: null,
  emor_durumu: 'islendi',
  vergi_istisna_kodu: '318',
  izibiz_istisna_kodu: '351',
}

/** Gelen faturalar kullanıcı henüz sıralamadıysa en son alınan üstte açılır */
const VARSAYILAN_SIRALAMA = { anahtar: 'olusturma_zamani', yon: 'desc' }

const LISTE: EFaturaListesi = {
  data: [FATURA],
  meta: { current_page: 1, last_page: 1, total: 1 },
  ozet: [{ para_birimi: 'TRY', adet: 1, tutar: '1250.50', vergi_tutari: '208.42' }],
  secenekler: {
    durumlar: [{ deger: 'RECEIVED', aciklama: 'Alındı' }],
    para_birimleri: ['TRY'],
    tipler: ['ISTISNA', 'SATIS'],
    istisna_kodlari: ['318', '351'],
  },
  kapsam: { ortam: 'test' },
}

function durum(degisen: Partial<SenkronDurumu> = {}): SenkronDurumu {
  const yon = {
    veri_zamani: '2026-09-23T09:45:00+00:00',
    guncel: true,
    calisiyor: false,
    ardisik_hata: 0,
    son_calisma: null,
  }

  return {
    ortam: 'test',
    senkron_aktif: true,
    manuel_istek: null,
    yonler: { gelen: yon, giden: yon },
    ...degisen,
  }
}

function ciz(izinler: string[]) {
  const user: Kullanici = {
    id: 1,
    ad: 'Deneme',
    kullanici_adi: 'deneme',
    email: null,
    kaynak: 'erp',
    sistem_yoneticisi: false,
    izinler,
  }
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  // main.tsx gibi StrictMode: effect'ler kur-temizle-kur döngüsünden geçer
  render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <ThemeProvider>
          <AuthContext.Provider
            value={{ user, yukleniyor: false, login: async () => {}, logout: async () => {} }}
          >
            <EFaturalarPage yon="gelen" />
          </AuthContext.Provider>
        </ThemeProvider>
      </QueryClientProvider>
    </StrictMode>,
  )
}

/** Aramanın 300 ms gecikmesini içeren beklemeler için (yük altında 1 sn yetmeyebiliyor) */
const ARAMA_BEKLEME = 3000

const TUM_IZINLER = ['efatura.goruntule', 'efatura.pdf', 'efatura.disari_aktar', 'efatura.senkron']

describe('EFaturalarPage', () => {
  beforeEach(() => {
    localStorage.clear()
    api.faturalar.mockResolvedValue(LISTE)
    api.durum.mockResolvedValue(durum())
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.unstubAllGlobals()
    window.history.replaceState(null, '', '/')
  })

  it('01.01.2026–bugün aralığını listeler; gelen faturada göndericiyi ve para birimi özetini gösterir', async () => {
    ciz(['efatura.goruntule'])

    expect(await screen.findByText('Deniz Boya Ltd.')).toBeInTheDocument()
    expect(api.faturalar).toHaveBeenCalledWith(
      'gelen',
      expect.objectContaining({ baslangic: '2026-01-01', bitis: bugunIso() }),
      VARSAYILAN_SIRALAMA,
      1,
    )
    const ozet = screen.getByLabelText('Özet')
    expect(within(ozet).getByText('TRY · 1 fatura')).toBeInTheDocument()
    expect(within(ozet).getByText('1.250,50 TRY')).toBeInTheDocument()
    expect(screen.getByText('Test hesabı')).toBeInTheDocument()
  })

  it('izni olmayan işlemlerin düğmelerini göstermez', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    expect(screen.queryByRole('button', { name: 'PDF' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Excel' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Entegratör Senkronla' })).not.toBeInTheDocument()
  })

  it('başka sayfadayken arama yapılınca gecikmeyle ve 1. sayfadan uygular', async () => {
    api.faturalar.mockResolvedValue({
      ...LISTE,
      meta: { current_page: 1, last_page: 3, total: 60 },
    })
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    // Tablonun üstündeki sayfalamadan
    fireEvent.click(screen.getAllByRole('button', { name: '2' })[0])
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.anything(),
        VARSAYILAN_SIRALAMA,
        2,
      ),
    )

    fireEvent.change(
      screen.getByLabelText(
        'Ara (no, ETTN, VKN, unvan, ad soyad, tip, sipariş, irsaliye, zarf durumu)',
      ),
      {
        target: { value: 'deniz' },
      },
    )

    await waitFor(
      () =>
        expect(api.faturalar).toHaveBeenLastCalledWith(
          'gelen',
          expect.objectContaining({ ara: 'deniz' }),
          VARSAYILAN_SIRALAMA,
          1,
        ),
      { timeout: ARAMA_BEKLEME },
    )
  })

  it('filtre değişirken tabloyu silmez; önceki sonucu güncelleniyor diye işaretler ve Excel’i kapatır', async () => {
    ciz(['efatura.goruntule', 'efatura.disari_aktar'])
    await screen.findByText('Deniz Boya Ltd.')
    let bitir!: (liste: typeof LISTE) => void
    api.faturalar.mockImplementation(
      () => new Promise<typeof LISTE>((resolve) => (bitir = resolve)),
    )

    fireEvent.change(
      screen.getByLabelText(
        'Ara (no, ETTN, VKN, unvan, ad soyad, tip, sipariş, irsaliye, zarf durumu)',
      ),
      {
        target: { value: 'baska' },
      },
    )

    // Önceki satırlar yerinde kalır (tablo kaybolup yeniden çizilmez)...
    // Arama 300 ms gecikmeli uygulanır; tüm takım yük altında koşarken
    // varsayılan 1 sn bekleme yetmeyebiliyor (kararsız test olarak görüldü)
    expect(
      await screen.findByText('Güncelleniyor…', {}, { timeout: ARAMA_BEKLEME }),
    ).toBeInTheDocument()
    expect(screen.getByText('Deniz Boya Ltd.')).toBeInTheDocument()
    expect(screen.queryByText('Yükleniyor…')).not.toBeInTheDocument()
    // ...ama yeni filtreninmiş gibi kullanılamaz
    expect(screen.getByRole('button', { name: 'Excel' })).toBeDisabled()

    bitir({ ...LISTE, data: [], meta: { current_page: 1, last_page: 1, total: 0 }, ozet: [] })

    await waitFor(() => expect(screen.queryByText('Güncelleniyor…')).not.toBeInTheDocument())
    expect(screen.queryByText('Deniz Boya Ltd.')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Özet')).toHaveTextContent('0')
  })

  it('sayfalama tablonun üstünde ve altında var; varsayılan 50, biri değişince diğeri de değişir', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const ust = document.querySelector<HTMLElement>('label[for="sayfa-boyutu-ust"]')!
    const alt = document.querySelector<HTMLElement>('label[for="sayfa-boyutu-alt"]')!

    expect(within(ust).getByText('50')).toBeInTheDocument()
    expect(within(alt).getByText('50')).toBeInTheDocument()

    fireEvent.keyDown(within(ust).getByRole('combobox'), { key: 'ArrowDown' })
    fireEvent.click(await within(ust).findByText('100'))

    await waitFor(() => expect(within(alt).getByText('100')).toBeInTheDocument())
    expect(localStorage.getItem('erp.sayfaBoyutu')).toBe('100')
  })

  it('kolonlar istenen sırada ve varsayılan hepsi açık; gizlenen kolon tarayıcıda hatırlanır', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    // Sıralanabilir başlıklardaki ok simgesi hariç
    const basliklar = () =>
      screen.getAllByRole('columnheader').map((th) => th.textContent?.replace(/[↕▲▼]/g, ''))

    // İşlemler (Detay/PDF) en solda, başlıksız
    expect(basliklar()[0]).toBe('')
    expect(screen.getAllByRole('row')[1].querySelector('td')).toHaveTextContent('Detay')
    expect(basliklar().slice(1, 14)).toEqual([
      'ERP Okudu',
      'eMOR',
      'Fatura No',
      'Tarih',
      'VKN/TCKN',
      'Unvan',
      'Ad Soyad',
      'Tip',
      'İstisna Kodu (Entegratör)',
      'İstisna Kodu (ERP)',
      'Tutar',
      'Para Birimi',
      'Alınma Zamanı',
    ])
    expect(screen.getByText('IRS2026000000007')).toBeInTheDocument()
    // İki kaynağın istisna kodu farklı: ikisi de kırmızı ve açıklamalı
    expect(screen.getByText('318')).toHaveAttribute(
      'title',
      "Entegratördeki ve ERP'deki istisna kodu farklı",
    )
    expect(screen.getByText('351')).toHaveAttribute(
      'title',
      "Entegratördeki ve ERP'deki istisna kodu farklı",
    )
    expect(screen.getByText('urn:mail:defaultpk@tersane.com')).toBeInTheDocument()
    // ERP'ye işlenmiş fatura (TOHOM_FATURA eşleşmesi)
    expect(screen.getByText('İşlendi')).toBeInTheDocument()
    // Açılışta Alınma Zamanı'na göre yeniden eskiye sıralı
    expect(screen.getByRole('columnheader', { name: /Alınma Zamanı/ })).toHaveAttribute(
      'aria-sort',
      'descending',
    )

    fireEvent.click(screen.getByRole('button', { name: /^Kolonlar/ }))
    fireEvent.click(await screen.findByRole('switch', { name: 'İrsaliye No' }))

    await waitFor(() => expect(screen.queryByText('IRS2026000000007')).not.toBeInTheDocument())
    expect(basliklar()).not.toContain('İrsaliye No')
    expect(JSON.parse(localStorage.getItem('erp.kolonlar.efatura-gelen')!)).toEqual(['irsaliye_no'])

    // Sayfa yeniden açıldığında seçim korunur
    cleanup()
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    expect(basliklar()).not.toContain('İrsaliye No')
    expect(basliklar()).toContain('Sipariş No')
  })

  it('durum filtresi Türkçe açıklamayı, parantez içinde İzibiz kodunu gösterir', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const alan = document.querySelector<HTMLElement>('label[for="efatura-durum"]')!.parentElement!

    fireEvent.keyDown(within(alan).getByRole('combobox'), { key: 'ArrowDown' })
    // Menü body'ye taşınır (menuPortalTarget)
    fireEvent.click(await screen.findByText('Alındı (RECEIVED)'))

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ durum: 'RECEIVED' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )
  })

  it('satırlar duruma göre açık renkle boyanır; reddedilen kırmızı, alınan renksiz', async () => {
    api.faturalar.mockResolvedValue({
      ...LISTE,
      data: [
        FATURA,
        {
          ...FATURA,
          id: 12,
          belge_no: 'ABC2026000000002',
          gonderici_unvan: 'Reddedilen Ltd.',
          durum: 'REJECTED',
        },
      ],
    })
    ciz(['efatura.goruntule'])

    const reddedilen = (await screen.findByText('Reddedilen Ltd.')).closest('tr')!
    const alinan = screen.getByText('Deniz Boya Ltd.').closest('tr')!

    expect(reddedilen).toHaveClass('bg-red-50')
    expect(alinan.className).not.toMatch(/bg-(red|emerald|amber)-50/)
  })

  it('eMOR üç durumlu: havuzdaki fatura sarı rozetle, ERP’de olmayan boş görünür', async () => {
    api.faturalar.mockResolvedValue({
      ...LISTE,
      data: [
        {
          ...FATURA,
          id: 21,
          belge_no: 'HAV1',
          gonderici_unvan: 'Havuz Ltd.',
          emor_durumu: 'havuzda',
        },
        { ...FATURA, id: 22, belge_no: 'YOK1', gonderici_unvan: 'Yok Ltd.', emor_durumu: 'yok' },
      ],
    })
    ciz(['efatura.goruntule'])

    const havuzSatiri = (await screen.findByText('Havuz Ltd.')).closest('tr') as HTMLElement
    expect(within(havuzSatiri).getByText('Havuzda')).toHaveAttribute(
      'title',
      'ERP entegratörden almış (TOHOM_E_FATURA), henüz muhasebeleşmemiş',
    )
    const yokSatiri = screen.getByText('Yok Ltd.').closest('tr') as HTMLElement
    expect(within(yokSatiri).queryByText('İşlendi')).not.toBeInTheDocument()
    expect(within(yokSatiri).queryByText('Havuzda')).not.toBeInTheDocument()
  })

  it('eMOR filtresi ve eMOR başlığıyla sıralama isteğe gider', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const alan = document.querySelector<HTMLElement>('label[for="efatura-emor"]')!.parentElement!

    fireEvent.keyDown(within(alan).getByRole('combobox'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('Havuzda (muhasebeleşmemiş)'))
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ emor: 'havuzda' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )

    fireEvent.click(within(screen.getByRole('columnheader', { name: /eMOR/ })).getByRole('button'))
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.anything(),
        { anahtar: 'emor', yon: 'asc' },
        1,
      ),
    )
  })

  it('hızlı filtreler işlenmeyenleri ve istisnalıları ister, tekrar basınca kapanır', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const grup = screen.getByRole('group', { name: 'Hızlı filtreler' })
    const islenmeyenler = within(grup).getByRole('button', { name: 'ERP İşlenmeyenler' })

    fireEvent.click(islenmeyenler)
    fireEvent.click(within(grup).getByRole('button', { name: 'Vergi İstisnası Olanlar' }))

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ emor: 'islenmemis', istisnali: 'evet' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )
    expect(islenmeyenler).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(islenmeyenler)
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ emor: '', istisnali: 'evet' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )
    expect(islenmeyenler).toHaveAttribute('aria-pressed', 'false')
  })

  it('Havuzda Olmayanlar eMOR "ERP’de yok" ister; ERP İşlenmeyenler ile birlikte basılı kalmaz', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const grup = screen.getByRole('group', { name: 'Hızlı filtreler' })
    const havuzdaOlmayanlar = within(grup).getByRole('button', { name: 'Havuzda Olmayanlar' })
    const islenmeyenler = within(grup).getByRole('button', { name: 'ERP İşlenmeyenler' })

    fireEvent.click(islenmeyenler)
    fireEvent.click(havuzdaOlmayanlar)

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ emor: 'yok' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )
    expect(havuzdaOlmayanlar).toHaveAttribute('aria-pressed', 'true')
    expect(islenmeyenler).toHaveAttribute('aria-pressed', 'false')
  })

  it('tip ve vergi istisna kodu seçenekleri listedeki değerlerden gelir', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    const alan = (id: string) =>
      document.querySelector<HTMLElement>(`label[for="${id}"]`)!.parentElement!

    fireEvent.keyDown(within(alan('efatura-tip')).getByRole('combobox'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('ISTISNA'))
    fireEvent.keyDown(within(alan('efatura-istisna')).getByRole('combobox'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByRole('option', { name: '351' }))

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ tip: 'ISTISNA', istisna_kodu: '351' }),
        VARSAYILAN_SIRALAMA,
        1,
      ),
    )
  })

  it('alarm mailindeki bağlantı ERP okumadı filtresiyle açar', async () => {
    window.history.replaceState(null, '', '/efatura/gelen?erp_okundu=hayir')
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    expect(api.faturalar).toHaveBeenCalledWith(
      'gelen',
      expect.objectContaining({ erp_okundu: 'hayir' }),
      VARSAYILAN_SIRALAMA,
      1,
    )
  })

  it('senkron durumu alınamazsa listenin güncelliği bilinmiyor uyarısı gösterir', async () => {
    api.durum.mockRejectedValue(new Error('ağ'))
    ciz(['efatura.goruntule'])

    expect(
      await screen.findByText(
        'Senkron durumu alınamadı; listenin güncel olup olmadığı bilinmiyor.',
      ),
    ).toBeInTheDocument()
  })

  it('bitiş başlangıçtan önceyse istek atmadan hata gösterir', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    api.faturalar.mockClear()

    fireEvent.change(screen.getByLabelText('Bitiş'), { target: { value: '31.12.2025' } })

    expect(await screen.findByText('Bitiş tarihi başlangıçtan önce olamaz.')).toBeInTheDocument()
    expect(api.faturalar).not.toHaveBeenCalled()
  })

  it('veri güncel değilse ve senkron art arda hata verdiyse uyarır', async () => {
    api.durum.mockResolvedValue(
      durum({
        yonler: {
          gelen: {
            veri_zamani: null,
            guncel: false,
            calisiyor: false,
            ardisik_hata: 3,
            son_calisma: {
              durum: 'basarisiz',
              tetikleyen: 'zamanlanmis',
              tarih_turu: 'DELIVERY',
              baslangic: '2026-09-22',
              bitis: '2026-09-23',
              basladi: '2026-09-23T09:45:00+00:00',
              bitti: '2026-09-23T09:45:05+00:00',
              okunan_adet: null,
              beklenen_adet: null,
              yeni_adet: null,
              eksik_nedeni: null,
              hata_kodu: 'ENTEGRATOR_ERISILEMEDI',
            },
          },
          giden: durum().yonler!.giden,
        },
      }),
    )
    ciz(['efatura.goruntule'])

    expect(await screen.findByText(/Veri güncel olmayabilir/)).toBeInTheDocument()
    // Hata kodu kullanıcıya çevrilmiş metinle gösterilir
    expect(screen.getByText(/Son 3 senkron başarısız ya da eksik bitti/)).toHaveTextContent(
      'Entegratöre ulaşılamadı',
    )
    expect(screen.getByText('Bugünü kapsayan başarılı bir senkron henüz yok.')).toBeInTheDocument()
  })

  it('Excel filtrenin tamamını aynı filtreyle ister', async () => {
    api.excel.mockResolvedValue(undefined)
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Excel' }))

    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel).toHaveBeenCalledWith(
      'gelen',
      expect.objectContaining({ baslangic: '2026-01-01', bitis: bugunIso() }),
      VARSAYILAN_SIRALAMA,
    )
  })

  it('detayda kaynak kimliklerini ve ERP bayrağının yalnız gösterildiğini belirtir', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Detay' }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByText(FATURA.ettn)).toBeInTheDocument()
    expect(within(dialog).getByText('185664')).toBeInTheDocument()
    expect(within(dialog).getByText(/Bu ekran yalnız gösterir, değiştirmez/)).toBeInTheDocument()
  })

  it('detayda unvanı olmayan göndericinin ad soyadını gösterir', async () => {
    api.faturalar.mockResolvedValue({
      ...LISTE,
      data: [{ ...FATURA, gonderici_unvan: null, gonderici_ad_soyad: 'ASLI AKTAY' }],
    })
    ciz(['efatura.goruntule'])
    await screen.findByText('ABC2026000000001')

    fireEvent.click(screen.getByRole('button', { name: 'Detay' }))
    const dialog = await screen.findByRole('dialog')

    const gonderici = within(dialog).getByText('Gönderici').closest('div') as HTMLElement
    expect(within(gonderici).getByText('ASLI AKTAY')).toBeInTheDocument()
    expect(within(gonderici).getByText(FATURA.gonderici_vkn as string)).toBeInTheDocument()
  })

  it('PDF penceresi fatura aslını gösterir, açıkken adresi bırakmaz, kapanınca bırakır', async () => {
    let sayac = 0
    const olustur = vi.fn(() => `blob:efatura-${++sayac}`)
    const birak = vi.fn()
    vi.stubGlobal('URL', { ...URL, createObjectURL: olustur, revokeObjectURL: birak })
    api.pdf.mockResolvedValue({
      pdf: new Blob(['%PDF-1.4'], { type: 'application/pdf' }),
      kaynak: 'erp',
    })
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'PDF' }))
    const dialog = await screen.findByRole('dialog')

    const cerceve = await within(dialog).findByTitle('Fatura ABC2026000000001 — PDF')
    await waitFor(() => expect(cerceve.getAttribute('src')).toMatch(/^blob:efatura-/))
    const adres = cerceve.getAttribute('src')
    // StrictMode'un kur-temizle-kur döngüsünden sonra gösterilen adres geçerli kalmalı
    expect(birak).not.toHaveBeenCalledWith(adres)
    expect(api.pdf).toHaveBeenCalledWith(11)
    // Kaynak kullanıcıya gösterilir (ERP havuzu mu, entegratör mü)
    expect(within(dialog).getByText('Kaynak: ERP arşivi')).toBeInTheDocument()

    fireEvent.keyDown(dialog, { key: 'Escape' })

    await waitFor(() => expect(birak).toHaveBeenCalledWith(adres))
    // Üretilen her adres bırakıldı (sızıntı yok)
    expect(birak).toHaveBeenCalledTimes(olustur.mock.calls.length)
  })

  it('PDF penceresinden faturanın XML’i indirilir; hata bildirilir', async () => {
    vi.stubGlobal('URL', {
      ...URL,
      createObjectURL: vi.fn(() => 'blob:efatura-x'),
      revokeObjectURL: vi.fn(),
    })
    api.pdf.mockResolvedValue({
      pdf: new Blob(['%PDF-1.4'], { type: 'application/pdf' }),
      kaynak: 'entegrator',
    })
    api.xml.mockRejectedValueOnce(new Error('ağ')).mockResolvedValueOnce(undefined)
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'PDF' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('Kaynak: Entegratör')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'XML İndir' }))
    await waitFor(() => expect(toastlar.error).toHaveBeenCalled())
    expect(api.xml.mock.calls[0]?.[0]).toMatchObject({ id: 11, belge_no: 'ABC2026000000001' })

    fireEvent.click(within(dialog).getByRole('button', { name: 'XML İndir' }))
    await waitFor(() => expect(api.xml).toHaveBeenCalledTimes(2))
    expect(toastlar.error).toHaveBeenCalledTimes(1)
  })

  it('ERP Senkronla eMOR’u tazeler, sonucu bildirir ve listeyi yeniden okur', async () => {
    api.erp.mockResolvedValue({ islendi: 5, elle_islendi: 4, havuzda: 3, yok: 2, degisen: 1 })
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')
    const okumaSayisi = api.faturalar.mock.calls.length

    fireEvent.click(screen.getByRole('button', { name: 'ERP Senkronla' }))

    await waitFor(() =>
      expect(toastlar.success).toHaveBeenCalledWith(
        "eMOR güncellendi: 5 işlendi, 4 elle işlendi, 3 havuzda, 2 ERP'de yok (1 değişti).",
      ),
    )
    expect(api.erp).toHaveBeenCalledExactlyOnceWith('gelen')
    await waitFor(() => expect(api.faturalar.mock.calls.length).toBeGreaterThan(okumaSayisi))
  })

  it('senkron izni olmayan ERP Senkronla düğmesini görmez', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    expect(screen.queryByRole('button', { name: 'ERP Senkronla' })).not.toBeInTheDocument()
  })

  it('elle senkronu seçilen aralıkla başlatır', async () => {
    api.senkron.mockResolvedValue(undefined)
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Entegratör Senkronla' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Senkronu Başlat' }))

    await waitFor(() => expect(api.senkron).toHaveBeenCalledTimes(1))
    const bugun = bugunIso()
    const yediGunOnce = gunEkle(bugun, -6)
    expect(api.senkron).toHaveBeenCalledWith({
      baslangic: yediGunOnce < '2026-01-01' ? '2026-01-01' : yediGunOnce,
      bitis: bugun,
    })
  })

  it('sene başından bugüne elle senkron başlatılabilir (92 günlük eski sınır yok)', async () => {
    api.senkron.mockResolvedValue(undefined)
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Entegratör Senkronla' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Başlangıç'), {
      target: { value: '01.01.2026' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Senkronu Başlat' }))

    await waitFor(() =>
      expect(api.senkron).toHaveBeenCalledWith({ baslangic: '2026-01-01', bitis: bugunIso() }),
    )
  })

  it('ilk tarama tarihinden önceki elle senkron aralığında istek atmaz', async () => {
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Entegratör Senkronla' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Başlangıç'), {
      target: { value: '31.12.2025' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Senkronu Başlat' }))

    expect(
      await within(dialog).findByText('e-Faturalar 01.01.2026 tarihinden itibaren izlenir.'),
    ).toBeInTheDocument()
    expect(api.senkron).not.toHaveBeenCalled()
  })

  it('elle senkron sıradayken yeni senkron düğmesi kilitlidir ve durum gösterilir', async () => {
    api.durum.mockResolvedValue(
      durum({
        manuel_istek: {
          zaman: '2026-09-23T10:00:00+00:00',
          baslangic: '2026-09-01',
          bitis: '2026-09-23',
        },
      }),
    )
    ciz(TUM_IZINLER)

    expect(
      await screen.findByText('Elle senkron sırada ya da çalışıyor (01.09.2026 – 23.09.2026).'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Entegratör Senkronla' })).toBeDisabled()
  })
})
