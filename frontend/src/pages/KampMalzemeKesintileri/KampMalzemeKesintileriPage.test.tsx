import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { KampMalzemeKesintisi } from '@/features/kesinti/kampMalzemeApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { KampMalzemeKesintileriPage } from './KampMalzemeKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
  yuklemeler: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/kampMalzemeApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/kampMalzemeApi')>()),
  kampMalzemeApi: {
    listele: api.liste,
    kaydet: api.kaydet,
    sil: vi.fn(),
    onizle: vi.fn(),
    iceAktar: vi.fn(),
    yuklemeler: api.yuklemeler,
    geriAl: vi.fn(),
    kolonlar: vi.fn(),
  },
}))

const SECENEKLER: KesintiSecenekleri = {
  kesinti_turleri: [{ id: 1, kod: 'KAMP MALZEME', ad: 'Kamp Malzeme' }],
  firmalar: [{ id: 3, kod: 'MFK', ad: 'MFK KOÇ' }],
  projeler: [{ id: 5, kod: 'TÜM MAHALLER (ORTAK)', ad: 'Tüm Mahaller' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: KampMalzemeKesintisi = {
  id: 21,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: null,
  tarih: '2026-01-05',
  kaynak_firma: null,
  urun_tanimi: 'BATTANİYE',
  birim_fiyat: '350.0000',
  miktar: '4.00',
  birim: 'ADET',
  toplam_tutar: '1400.00',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '1400.00',
  kesilen_tutar: '1000.00',
  kalan_tutar: '400.00',
}

function ciz() {
  render(
    <AppProviders>
      <SahteOturum
        izinler={['kamp_malzeme_kesintileri.goruntule', 'kamp_malzeme_kesintileri.guncelle']}
      >
        <KampMalzemeKesintileriPage />
      </SahteOturum>
    </AppProviders>,
  )
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('KampMalzemeKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue({
      data: [SATIR],
      meta: { current_page: 1, last_page: 1, total: 1 },
      ozet: [],
    })
    api.yuklemeler.mockResolvedValue([])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('ürün, miktar ve birimi gösterir; yansıtma türü taşeronun hemen sağındadır', async () => {
    ciz()

    expect(await screen.findByText('BATTANİYE')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Kamp Malzeme Kesintileri' })).toBeInTheDocument()
    expect(screen.getByText('4,00')).toBeInTheDocument()
    expect(screen.getByText('350,0000')).toBeInTheDocument()
    expect(screen.getByText('TAŞERON')).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.indexOf('Yansıtma Türü')).toBe(basliklar.indexOf('Taşeron') + 1)
    expect(basliklar).not.toContain('Kaynak Fiş No')
  })

  it('düzenlemede icmale özgü alanları gönderir; boş birim null, hesaplananlar gönderilmez', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    ciz()
    await screen.findByText('BATTANİYE')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Birim'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    const [id, govde] = api.kaydet.mock.calls[0] as [number, Record<string, unknown>]
    expect(id).toBe(21)
    expect(govde).toMatchObject({
      kesinti_turu_id: 1,
      firma_id: 3,
      yansitma_turu: 'taseron',
      proje_id: 5,
      urun_tanimi: 'BATTANİYE',
      miktar: '4.00',
      birim: null,
    })
    expect(govde).not.toHaveProperty('kaynak_fis_no')
    expect(govde).not.toHaveProperty('kalan_tutar')
  })
})
