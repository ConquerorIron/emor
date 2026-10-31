import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { KesintiTanimi } from '@/features/kesinti/icmalApi'
import type { TumKesinti } from '@/features/kesinti/tumKesintilerApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { TumKesintilerPage } from './TumKesintilerPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  filtreSecenekleri: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/tumKesintilerApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/tumKesintilerApi')>()),
  tumKesintilerApi: {
    listele: api.liste,
    filtreSecenekleri: api.filtreSecenekleri,
    pivot: vi.fn(),
    excel: vi.fn(),
  },
}))

const TURLER: KesintiTanimi[] = [
  { id: 1, kod: 'İŞGÜCÜ', ad: 'İşgücü' },
  { id: 2, kod: 'YEMEK', ad: 'Yemek' },
  { id: 3, kod: 'OSGB', ad: 'OSGB' },
]
const TMC: KesintiTanimi = { id: 3, kod: 'TMC KABA', ad: 'TMC Kaba' }
const ZONE: KesintiTanimi = { id: 5, kod: 'ZONE 10', ad: 'Zone 10' }
const TL: KesintiTanimi = { id: 7, kod: 'TL', ad: 'Türk Lirası' }

/** İki ayrı icmalden aynı id'li satırlar */
const satir = (
  icmal: TumKesinti['icmal'],
  tur: KesintiTanimi,
  kaynakFirma: string,
): TumKesinti => ({
  id: 1,
  icmal,
  yukleme_id: null,
  kesinti_turu: tur,
  firma: TMC,
  yansitma_turu: 'taseron',
  proje: ZONE,
  para_birimi: TL,
  mahal: null,
  tarih: '2026-03-01',
  kaynak_firma: kaynakFirma,
  toplam_tutar: '1000.00',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '1000.00',
  kesilen_tutar: '1000.00',
  kalan_tutar: '0.00',
})

describe('TumKesintilerPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.liste.mockResolvedValue({
      data: [satir('isgucu', TURLER[0], 'MFK'), satir('yemek', TURLER[1], 'ATİLLA GIDA')],
      // 2. sayfa: Sıra No 51'den başlar
      meta: { current_page: 2, last_page: 2, total: 52, from: 51 },
      ozet: [],
    })
    api.filtreSecenekleri.mockResolvedValue({
      kesinti_turleri: TURLER,
      firmalar: [TMC],
      projeler: [ZONE],
    })
    render(
      <AppProviders>
        <SahteOturum izinler={['tum_kesintiler.goruntule']}>
          <TumKesintilerPage />
        </SahteOturum>
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('yalnız rapor: ortak kolonlar, en solda sayfalar boyunca süren Sıra No; veri girişi yok', async () => {
    expect(await screen.findByText('ATİLLA GIDA')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Tüm Kesintiler' })).toBeInTheDocument()

    // Sıralama oku (▲▼↕) başlık metnine karışmasın
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar).toEqual([
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
      'Para Birimi',
      'Toplam Tutar',
      'İptal Edilen Tutar',
      'Yansıtılan Tutar',
      'Kesilen Tutar',
      'Kalan Tutar',
    ])
    // Aynı id'li iki icmal satırı ayrı ayrı çizilir
    const satirlar = screen.getAllByRole('row').filter((r) => within(r).queryByText('TMC KABA'))
    expect(satirlar.map((r) => within(r).getAllByRole('cell')[0].textContent)).toEqual(['51', '52'])

    const sekmeler = within(screen.getByRole('tablist', { name: 'Görünümler' }))
      .getAllByRole('tab')
      .map((sekme) => sekme.textContent)
    expect(sekmeler).toEqual(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'])

    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeInTheDocument()
    for (const ad of ["Excel'den Aktar", 'Yüklemeler', 'Yeni Kayıt', 'Düzenle']) {
      expect(screen.queryByRole('button', { name: ad })).not.toBeInTheDocument()
    }
    expect(screen.queryByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).not.toBeInTheDocument()
    // Form açılmadığı için tüm tanımlar okunmaz
    expect(api.secenekler).not.toHaveBeenCalled()
  })

  it('kesinti türleri çoklu seçilip listeye birlikte uygulanır', async () => {
    await screen.findByText('ATİLLA GIDA')

    const tur = screen.getByLabelText('Kesinti Türü')
    fireEvent.keyDown(tur, { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('İŞGÜCÜ — İşgücü'))
    fireEvent.keyDown(tur, { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('OSGB — OSGB'))

    await waitFor(() =>
      expect(api.liste).toHaveBeenLastCalledWith(
        expect.objectContaining({ kesinti_turu_id: ['1', '3'] }),
        expect.anything(),
        1,
      ),
    )
  })
})
