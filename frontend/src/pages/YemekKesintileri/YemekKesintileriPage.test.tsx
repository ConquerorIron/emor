import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { YemekKesintisi } from '@/features/kesinti/yemekApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { YemekKesintileriPage } from './YemekKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/yemekApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/yemekApi')>()),
  yemekApi: {
    listele: api.liste,
    kaydet: api.kaydet,
    sil: vi.fn(),
    onizle: vi.fn(),
    iceAktar: vi.fn(),
    yuklemeler: vi.fn().mockResolvedValue([]),
    geriAl: vi.fn(),
    kolonlar: vi.fn(),
    pivot: vi.fn(),
    excel: vi.fn(),
  },
}))

const SECENEKLER: KesintiSecenekleri = {
  kesinti_turleri: [{ id: 1, kod: 'YEMEK', ad: 'Yemek', icmal: 'yemek' }],
  firmalar: [{ id: 3, kod: 'HAB', ad: 'HAB' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: YemekKesintisi = {
  id: 61,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'hab',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: 'ZONE 10',
  tarih: '2026-01-01',
  kaynak_firma: 'ATİLLA GIDA',
  kaynak_hakedis_no: '1',
  toplam_tutar: '5097.83',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '5097.83',
  kesilen_tutar: '5097.83',
  kalan_tutar: '0.00',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('YemekKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['yemek_kesintileri.goruntule', 'yemek_kesintileri.guncelle']}>
          <YemekKesintileriPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('kolonlar kullanıcının sırasında; özet sekmeleri diğer kesinti ekranlarındaki gibi', async () => {
    expect(await screen.findByText('ATİLLA GIDA')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Yemek Kesintisi' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 16)).toEqual([
      'Kesinti Türü',
      'Taşeron',
      'Yansıtma Türü',
      'Proje',
      'Mahal',
      'Tarih',
      'Yıl',
      'Ay',
      'Kaynak Firma',
      'Kaynak Hakediş No',
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

  it('düzenlemede hakediş numarasını gönderir; boşsa null', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('ATİLLA GIDA')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kaynak Hakediş No'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet.mock.calls[0][1]).toMatchObject({
      kesinti_turu_id: 1,
      kaynak_firma: 'ATİLLA GIDA',
      kaynak_hakedis_no: null,
    })
  })
})
