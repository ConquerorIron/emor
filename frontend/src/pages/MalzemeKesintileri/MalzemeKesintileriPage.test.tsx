import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { MalzemeKesintisi } from '@/features/kesinti/malzemeApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { MalzemeKesintileriPage } from './MalzemeKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/malzemeApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/malzemeApi')>()),
  malzemeApi: {
    listele: api.liste,
    kaydet: api.kaydet,
    sil: vi.fn(),
    onizle: vi.fn(),
    iceAktar: vi.fn(),
    yuklemeler: vi.fn().mockResolvedValue([]),
    geriAl: vi.fn(),
    kolonlar: vi.fn(),
    pivot: vi.fn(),
    filtreSecenekleri: vi.fn().mockResolvedValue({ firmalar: [], projeler: [] }),
    excel: vi.fn(),
  },
}))

const SECENEKLER: KesintiSecenekleri = {
  kesinti_turleri: [{ id: 1, kod: 'MALZEME KESİNTİSİ', ad: 'Malzeme Kesintisi', icmal: 'malzeme' }],
  firmalar: [{ id: 3, kod: 'TMC KABA', ad: 'TMC Kaba' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: MalzemeKesintisi = {
  id: 74,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: null,
  tarih: '2026-05-15',
  kaynak_firma: 'İSG BİRİMİ',
  kaynak_irsaliye_no: '006.06.04.0000060501',
  urun_tanimi: 'HALAT TUTUCU',
  malzeme_birim_fiyati: '350.0000',
  miktar: '15.00',
  birim: 'ADET',
  toplam_tutar: '5250.00',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '5250.00',
  kesilen_tutar: '5250.00',
  kalan_tutar: '0.00',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('MalzemeKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['malzeme_kesintileri.goruntule', 'malzeme_kesintileri.guncelle']}>
          <MalzemeKesintileriPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('kolonlar kullanıcının sırasında; özet sekmeleri Yemek ile aynı', async () => {
    expect(await screen.findByText('HALAT TUTUCU')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Malzeme Kesintisi' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 20)).toEqual([
      'Kesinti Türü',
      'Taşeron',
      'Yansıtma Türü',
      'Proje',
      'Mahal',
      'Tarih',
      'Yıl',
      'Ay',
      'Kaynak Firma',
      'Kaynak İrsaliye No',
      'Ürün Tanımı',
      'Malzeme Birim Fiyatı',
      'Miktar',
      'Birim',
      'Para Birimi',
      'Toplam Tutar',
      'İptal Edilen Tutar',
      'Yansıtılan Tutar',
      'Kesilen Tutar',
      'Kalan Tutar',
    ])

    const sekmeler = within(screen.getByRole('tablist', { name: 'Görünümler' }))
      .getAllByRole('tab')
      .map((sekme) => sekme.textContent)
    expect(sekmeler).toEqual(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'])
  })

  it('düzenlemede malzeme alanlarını gönderir; boş irsaliye no null', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('HALAT TUTUCU')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kaynak İrsaliye No'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet.mock.calls[0][1]).toMatchObject({
      kaynak_irsaliye_no: null,
      urun_tanimi: 'HALAT TUTUCU',
      malzeme_birim_fiyati: '350.0000',
      miktar: '15.00',
      birim: 'ADET',
    })
  })
})
