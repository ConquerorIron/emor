import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { KampKesintisi } from '@/features/kesinti/kampApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { KampKesintileriPage } from './KampKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/kampApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/kampApi')>()),
  kampApi: {
    listele: api.liste,
    kaydet: api.kaydet,
    sil: vi.fn(),
    onizle: vi.fn(),
    iceAktar: vi.fn(),
    yuklemeler: vi.fn().mockResolvedValue([]),
    geriAl: vi.fn(),
    kolonlar: vi.fn(),
  },
}))

const SECENEKLER: KesintiSecenekleri = {
  kesinti_turleri: [{ id: 1, kod: 'KAMP', ad: 'Kamp', icmal: 'kamp' }],
  firmalar: [{ id: 3, kod: 'MFK', ad: 'MFK KOÇ' }],
  projeler: [{ id: 5, kod: 'TÜM MAHALLER (ORTAK)', ad: 'Tüm Mahaller' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: KampKesintisi = {
  id: 31,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: null,
  tarih: '2026-01-01',
  kaynak_firma: null,
  oda_tipi: '6 KİŞİLİK ODA',
  toplam_oda: '489.00',
  birim_fiyat: '1200.0000',
  toplam_tutar: '586800.00',
  iptal_edilen_tutar: '586800.00',
  yansitilan_tutar: '0.00',
  kesilen_tutar: '0.00',
  kalan_tutar: '0.00',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('KampKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['kamp_kesintileri.goruntule', 'kamp_kesintileri.guncelle']}>
          <KampKesintileriPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('kolonlar kullanıcının nihai sırasında; açıklama kolonu yok', async () => {
    expect(await screen.findByText('6 KİŞİLİK ODA')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Kamp Kesintileri' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 13)).toEqual([
      'Kesinti Türü',
      'Taşeron',
      'Yansıtma Türü',
      'Proje',
      'Mahal',
      'Tarih',
      'Yıl',
      'Ay',
      'Kaynak Firma',
      'Oda Tipi',
      'Toplam Oda',
      'Birim Fiyat',
      'Para Birimi',
    ])
    expect(basliklar).not.toContain('Açıklama')
  })

  it('elle girişte oda tipi zorunludur; toplam oda ile kaydedilir', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('6 KİŞİLİK ODA')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Oda Tipi'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByText('Bu alan zorunludur.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText('Oda Tipi'), {
      target: { value: '4 KİŞİLİK ODA' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet.mock.calls[0][1]).toMatchObject({
      oda_tipi: '4 KİŞİLİK ODA',
      toplam_oda: '489.00',
      yansitma_turu: 'taseron',
    })
  })
})
