import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { TutanakKesintisi } from '@/features/kesinti/tutanakApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { TutanakKesintileriPage } from './TutanakKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/tutanakApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/tutanakApi')>()),
  tutanakApi: {
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
  kesinti_turleri: [{ id: 1, kod: 'TUTANAK', ad: 'Tutanak', icmal: 'tutanak' }],
  firmalar: [{ id: 3, kod: 'TMC KABA', ad: 'TMC Yapı' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: TutanakKesintisi = {
  id: 51,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: 'ZONE 10',
  tarih: '2026-02-15',
  kaynak_firma: 'MFK',
  kaynak_tutanak_no: 'T-125',
  kaynak_sozlesme: 'KABA İNŞAAT',
  kaynak_hakedis_no: '2-ARA',
  aktivite: 'İLAVE BETON DÖKÜMÜ',
  toplam_tutar: '1000.00',
  iptal_edilen_tutar: '100.00',
  yansitilan_tutar: '900.00',
  kesilen_tutar: '500.00',
  kalan_tutar: '400.00',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('TutanakKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['tutanak_kesintileri.goruntule', 'tutanak_kesintileri.guncelle']}>
          <TutanakKesintileriPage />
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
    expect(await screen.findByText('T-125')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Tutanak Kesintisi' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 15)).toEqual([
      'Kesinti Türü',
      'Taşeron',
      'Yansıtma Türü',
      'Proje',
      'Mahal',
      'Tarih',
      'Yıl',
      'Ay',
      'Kaynak Firma',
      'Kaynak Tutanak No',
      'Kaynak Sözleşme',
      'Kaynak Hakediş No',
      'Aktivite',
      'Para Birimi',
      'Toplam Tutar',
    ])

    const sekmeler = within(screen.getByRole('tablist', { name: 'Görünümler' }))
      .getAllByRole('tab')
      .map((sekme) => sekme.textContent)
    expect(sekmeler).toEqual(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'])
  })

  it('düzenlemede tutanağa özgü alanları gönderir; boş sözleşme null gider', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('T-125')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kaynak Sözleşme'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    const govde = api.kaydet.mock.calls[0][1] as Record<string, unknown>
    expect(govde).toMatchObject({
      kesinti_turu_id: 1,
      kaynak_tutanak_no: 'T-125',
      kaynak_hakedis_no: '2-ARA',
      aktivite: 'İLAVE BETON DÖKÜMÜ',
      kaynak_sozlesme: null,
    })
    expect(govde).not.toHaveProperty('yansitilan_tutar')
  })
})
