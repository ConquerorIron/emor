import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { OsgbKesintisi } from '@/features/kesinti/osgbApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { OsgbKesintileriPage } from './OsgbKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/osgbApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/osgbApi')>()),
  osgbApi: {
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
  kesinti_turleri: [{ id: 1, kod: 'OSGB', ad: 'OSGB', icmal: 'osgb' }],
  firmalar: [{ id: 3, kod: 'TMC KABA', ad: 'TMC Kaba' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: OsgbKesintisi = {
  id: 71,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: 'ZONE 10',
  tarih: '2026-01-31',
  kaynak_firma: 'MEDİKAL OSGB',
  toplam_kisi: '411.00',
  birim_fiyat: '120.0000',
  toplam_tutar: '49320.00',
  iptal_edilen_tutar: '12330.00',
  yansitilan_tutar: '36990.00',
  kesilen_tutar: '36990.00',
  kalan_tutar: '0.00',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('OsgbKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['osgb_kesintileri.goruntule', 'osgb_kesintileri.guncelle']}>
          <OsgbKesintileriPage />
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
    expect(await screen.findByText('MEDİKAL OSGB')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'OSGB Kesintisi' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 18)).toEqual([
      'Sıra No',
      'Kesinti Türü',
      'Taşeron',
      'Yansıtma Türü',
      'Proje',
      'Mahal',
      'Tarih',
      'Yıl',
      'Ay',
      'Kaynak Firma',
      'Toplam Kişi',
      'Birim Fiyat',
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

  it('düzenlemede toplam kişi ve birim fiyatı gönderir', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('MEDİKAL OSGB')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Toplam Kişi'), { target: { value: '400' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet.mock.calls[0][1]).toMatchObject({
      kesinti_turu_id: 1,
      toplam_kisi: '400.00',
      birim_fiyat: '120.0000',
    })
  })
})
