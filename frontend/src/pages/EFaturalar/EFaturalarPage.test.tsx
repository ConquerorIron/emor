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
}))

vi.mock('@/features/efatura/efaturaApi', () => ({
  faturalariGetir: api.faturalar,
  senkronDurumuGetir: api.durum,
  senkronBaslat: api.senkron,
  excelIndir: api.excel,
  pdfGetir: api.pdf,
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
}

const LISTE: EFaturaListesi = {
  data: [FATURA],
  meta: { current_page: 1, last_page: 1, total: 1 },
  ozet: [{ para_birimi: 'TRY', adet: 1, tutar: '1250.50', vergi_tutari: '208.42' }],
  secenekler: { durumlar: ['RECEIVED'], para_birimleri: ['TRY'] },
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
      null,
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
    expect(screen.queryByRole('button', { name: 'Şimdi Senkronla' })).not.toBeInTheDocument()
  })

  it('başka sayfadayken arama yapılınca gecikmeyle ve 1. sayfadan uygular', async () => {
    api.faturalar.mockResolvedValue({
      ...LISTE,
      meta: { current_page: 1, last_page: 3, total: 60 },
    })
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: '2' }))
    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith('gelen', expect.anything(), null, 2),
    )

    fireEvent.change(screen.getByLabelText('Ara (no, ETTN, VKN, unvan)'), {
      target: { value: 'deniz' },
    })

    await waitFor(() =>
      expect(api.faturalar).toHaveBeenLastCalledWith(
        'gelen',
        expect.objectContaining({ ara: 'deniz' }),
        null,
        1,
      ),
    )
  })

  it('filtre değişirken önceki sonucun toplamını yeni filtreninmiş gibi göstermez', async () => {
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')
    let bitir!: (liste: typeof LISTE) => void
    api.faturalar.mockImplementation(
      () => new Promise<typeof LISTE>((resolve) => (bitir = resolve)),
    )

    fireEvent.change(screen.getByLabelText('Ara (no, ETTN, VKN, unvan)'), {
      target: { value: 'baska' },
    })

    await waitFor(() => expect(screen.queryByLabelText('Özet')).not.toBeInTheDocument())
    expect(screen.queryByText('Deniz Boya Ltd.')).not.toBeInTheDocument()
    expect(screen.getByText('Yükleniyor…')).toBeInTheDocument()

    bitir({ ...LISTE, data: [], meta: { current_page: 1, last_page: 1, total: 0 }, ozet: [] })
    expect(await screen.findByLabelText('Özet')).toHaveTextContent('0')
  })

  it('alarm mailindeki bağlantı ERP okumadı filtresiyle açar', async () => {
    window.history.replaceState(null, '', '/efatura/gelen?erp_okundu=hayir')
    ciz(['efatura.goruntule'])
    await screen.findByText('Deniz Boya Ltd.')

    expect(api.faturalar).toHaveBeenCalledWith(
      'gelen',
      expect.objectContaining({ erp_okundu: 'hayir' }),
      null,
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
      null,
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

  it('PDF penceresi fatura aslını gösterir, açıkken adresi bırakmaz, kapanınca bırakır', async () => {
    let sayac = 0
    const olustur = vi.fn(() => `blob:efatura-${++sayac}`)
    const birak = vi.fn()
    vi.stubGlobal('URL', { ...URL, createObjectURL: olustur, revokeObjectURL: birak })
    api.pdf.mockResolvedValue(new Blob(['%PDF-1.4'], { type: 'application/pdf' }))
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

    fireEvent.keyDown(dialog, { key: 'Escape' })

    await waitFor(() => expect(birak).toHaveBeenCalledWith(adres))
    // Üretilen her adres bırakıldı (sızıntı yok)
    expect(birak).toHaveBeenCalledTimes(olustur.mock.calls.length)
  })

  it('elle senkronu seçilen aralıkla başlatır', async () => {
    api.senkron.mockResolvedValue(undefined)
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Şimdi Senkronla' }))
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

  it('92 günden uzun elle senkron aralığında istek atmaz', async () => {
    ciz(TUM_IZINLER)
    await screen.findByText('Deniz Boya Ltd.')

    fireEvent.click(screen.getByRole('button', { name: 'Şimdi Senkronla' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Başlangıç'), {
      target: { value: '01.01.2026' },
    })
    fireEvent.change(within(dialog).getByLabelText('Bitiş'), { target: { value: '03.04.2026' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Senkronu Başlat' }))

    expect(await within(dialog).findByText('Aralık en fazla 92 gün olabilir.')).toBeInTheDocument()
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
    expect(screen.getByRole('button', { name: 'Şimdi Senkronla' })).toBeDisabled()
  })
})
