import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { KartBasimKesintisi } from '@/features/kesinti/kartBasimApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { KartBasimKesintileriPage } from './KartBasimKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/kartBasimApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/kartBasimApi')>()),
  kartBasimApi: {
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
  kesinti_turleri: [{ id: 1, kod: 'KART BASIM', ad: 'Kart Basım', icmal: 'kart_basim' }],
  firmalar: [{ id: 3, kod: 'TMC KABA', ad: 'TMC Kaba' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: KartBasimKesintisi = {
  id: 75,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: null,
  tarih: '2026-04-15',
  kaynak_firma: 'GÜVENLİK',
  personel_adi_soyadi: 'TUNCAY YEL',
  urun_tanimi: 'PERSONEL GİRİŞ KARTI',
  kart_basim_nedeni: 'KAYIP',
  malzeme_birim_fiyati: '150.0000',
  miktar: '1.00',
  birim: 'ADET',
  toplam_tutar: '150.00',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '150.00',
  kesilen_tutar: '150.00',
  kalan_tutar: '0.00',
}

describe('KartBasimKesintileriPage', () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum
          izinler={['kart_basim_kesintileri.goruntule', 'kart_basim_kesintileri.guncelle']}
        >
          <KartBasimKesintileriPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('kolonlar kullanıcının sırasında; özet sekmeleri diğer ekranlarla aynı', async () => {
    expect(await screen.findByText('TUNCAY YEL')).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Personel Kart Basım Kesintisi' }),
    ).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 22)).toEqual([
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
      'Personel Adı Soyadı',
      'Ürün Tanımı',
      'Kart Basım Nedeni',
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

  it('personel adı soyadı zorunlu; kart basım nedeni boşsa null gönderilir', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('TUNCAY YEL')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    const personel = within(dialog).getByLabelText(/Personel Adı Soyadı/)

    fireEvent.change(personel, { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))
    expect(await within(dialog).findByText('Bu alan zorunludur.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()

    fireEvent.change(personel, { target: { value: 'MÖHSİN UD' } })
    fireEvent.change(within(dialog).getByLabelText('Kart Basım Nedeni'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet.mock.calls[0][1]).toMatchObject({
      personel_adi_soyadi: 'MÖHSİN UD',
      urun_tanimi: 'PERSONEL GİRİŞ KARTI',
      kart_basim_nedeni: null,
      malzeme_birim_fiyati: '150.0000',
      miktar: '1.00',
    })
  })
})
