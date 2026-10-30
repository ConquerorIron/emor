import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { MakinaEkipmanKesintisi } from '@/features/kesinti/makinaEkipmanApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { MakinaEkipmanKesintileriPage } from './MakinaEkipmanKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/makinaEkipmanApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/makinaEkipmanApi')>()),
  makinaEkipmanApi: {
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
  kesinti_turleri: [
    { id: 1, kod: 'MAKİNA-EKİPMAN', ad: 'Makina Ekipman', icmal: 'makina_ekipman' },
  ],
  firmalar: [{ id: 3, kod: 'AKTAŞ HAFRİYAT', ad: 'Aktaş Hafriyat' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: MakinaEkipmanKesintisi = {
  id: 41,
  yukleme_id: null,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  yansitma_turu: 'taseron',
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  mahal: 'ZONE 10',
  tarih: '2026-01-01',
  kaynak_firma: 'MFK',
  kaynak_fis_no: '11900',
  makina_tanimi: '30 TON EKSKAVATÖR KOVALI',
  aktivite: 'HAFRİYAT',
  disiplin: 'HAFRİYAT AKTARMA',
  toplam_tutar: '15625.27',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '15625.27',
  kesilen_tutar: '15389.32',
  kalan_tutar: '235.95',
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('MakinaEkipmanKesintileriPage', { timeout: 15_000 }, () => {
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
          izinler={['makina_ekipman_kesintileri.goruntule', 'makina_ekipman_kesintileri.guncelle']}
        >
          <MakinaEkipmanKesintileriPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('kolonlar kullanıcının sırasında; birim fiyat kolonu yok', async () => {
    expect(await screen.findByText('30 TON EKSKAVATÖR KOVALI')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Makina Ekipman Kesintileri' })).toBeInTheDocument()

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
      'Kaynak Fiş No',
      'Makina Tanımı',
      'Aktivite',
      'Disiplin',
      'Para Birimi',
      'Toplam Tutar',
    ])
    expect(basliklar).not.toContain('Birim Fiyat')
  })

  it('düzenlemede makinaya özgü alanları gönderir; boş disiplin null gider', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    await screen.findByText('30 TON EKSKAVATÖR KOVALI')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Disiplin'), { target: { value: '' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    const govde = api.kaydet.mock.calls[0][1] as Record<string, unknown>
    expect(govde).toMatchObject({
      kesinti_turu_id: 1,
      kaynak_fis_no: '11900',
      makina_tanimi: '30 TON EKSKAVATÖR KOVALI',
      disiplin: null,
    })
    expect(govde).not.toHaveProperty('birim_fiyat')
  })
})
