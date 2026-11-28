import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import en from '@/i18n/en.json'
import tr from '@/i18n/tr.json'

import { BILINEN_ISLEMLER, type IslemKaydi } from './islemGunluguApi'
import { YedekIslemGunluguPage } from './YedekIslemGunluguPage'

const api = vi.hoisted(() => ({ kayitlar: vi.fn() }))

vi.mock('./islemGunluguApi', async (asil) => ({
  ...(await asil<typeof import('./islemGunluguApi')>()),
  islemKayitlariniGetir: api.kayitlar,
}))

const kayit = (ek: Partial<IslemKaydi>): IslemKaydi => ({
  id: 1,
  zaman: '2026-11-21T07:00:00+00:00',
  kullanici: 'Deneme',
  islem: 'yedek_al',
  sonuc: 'basarili',
  sure_ms: 1500,
  yedek: null,
  ayrinti: {},
  hata_mesaji: null,
  ...ek,
})

function ciz() {
  render(
    <AppProviders>
      <SahteOturum izinler={['yedekler.goruntule']} yedeklemeAktif>
        <YedekIslemGunluguPage />
      </SahteOturum>
    </AppProviders>,
  )
}

describe('YedekIslemGunluguPage', () => {
  beforeEach(() => {
    // Varsayılan aralık bugüne bağlı: saat sabit (T12)
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-21T10:00:00'))
    api.kayitlar.mockResolvedValue({
      data: [
        kayit({ id: 1, islem: 'istek', ayrinti: { tur: 'sil', istek: 'abc' } }),
        kayit({ id: 2, islem: 'yeni_bir_islem', kullanici: null, sonuc: 'basarisiz' }),
        kayit({ id: 3, islem: 'geri_yukle_ustune_onay' }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 2 },
    })
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('son 30 günü ister; işlem adlarını çevirir, bilinmeyeni olduğu gibi gösterir; salt okunurdur', async () => {
    ciz()

    await waitFor(() =>
      expect(api.kayitlar).toHaveBeenCalledWith(
        { baslangic: '2026-10-23', bitis: '2026-11-21', kullanici_id: '', islem: '', sonuc: '' },
        1,
      ),
    )
    const istek = (await screen.findByText('İstek: Yedek silme')).closest('tr') as HTMLElement
    expect(within(istek).getByText('Başarılı')).toBeInTheDocument()
    // Üstüne geri yükleme kayıtları da çevrilir (YF-58)
    expect(screen.getByText('Üstüne geri yükleme onayı')).toBeInTheDocument()
    const bilinmeyen = screen.getByText('yeni_bir_islem').closest('tr') as HTMLElement
    expect(within(bilinmeyen).getByText('Sistem')).toBeInTheDocument()
    // Günlük ekrandan değiştirilemez / silinemez
    expect(screen.queryByRole('button', { name: /Sil|Düzenle/ })).not.toBeInTheDocument()
  })

  it('sonuç süzgecini ilk sayfadan ister; ters tarih aralığında istek göndermez', async () => {
    ciz()
    await screen.findByText('İstek: Yedek silme')

    fireEvent.keyDown(screen.getByLabelText('Sonuç'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByRole('option', { name: 'Başarısız' }))
    await waitFor(() =>
      expect(api.kayitlar).toHaveBeenLastCalledWith(
        expect.objectContaining({ sonuc: 'basarisiz' }),
        1,
      ),
    )

    const istekSayisi = api.kayitlar.mock.calls.length
    fireEvent.change(screen.getByLabelText('Başlangıç'), { target: { value: '01.12.2026' } })
    expect(await screen.findByText('Başlangıç bitişten sonra olamaz.')).toBeInTheDocument()
    expect(api.kayitlar).toHaveBeenCalledTimes(istekSayisi)
  })

  it('süzgeçteki her işlemin iki dilde de çevirisi var (YF-58)', () => {
    const eksik = (ceviriler: Record<string, unknown>) =>
      BILINEN_ISLEMLER.filter((islem) => typeof ceviriler[islem] !== 'string')

    expect(eksik(tr.yedekleme.gunluk.islem)).toEqual([])
    expect(eksik(en.yedekleme.gunluk.islem)).toEqual([])
  })

  it('çevirisi olan (arka ucun yazdığı) her işlem süzgeçte de seçilebilir (G2-4)', () => {
    // Arka uç bugün bu adlarla denetim kaydı yazmıyor (istekler `istek` adıyla
    // yazılır); yalnız çeviri anahtarı olarak duruyorlar
    const yazilmayan = ['yedek', 'tara', 'dogrula', 'plan_calistir', 'kopya_geri_yukle']
    const bilinen: readonly string[] = [...BILINEN_ISLEMLER, ...yazilmayan]

    expect(
      Object.keys(tr.yedekleme.gunluk.islem).filter((islem) => !bilinen.includes(islem)),
    ).toEqual([])
  })

  it('çalıştırıcının her istek türünün iki dilde çevirisi var (G2-4)', () => {
    // IstekKutusu::TURLER (backend) ile aynı liste
    const turler = [
      'yedek_al',
      'dogrula',
      'sabitle',
      'sabit_coz',
      'aciklama',
      'sil',
      'tara',
      'plan_calistir',
      'kopya_geri_yukle',
      'kopya_sil',
      'ustune_geri_yukle',
      'onayla',
      'geri_don',
    ]
    const eksik = (ceviriler: Record<string, unknown>) =>
      turler.filter((tur) => typeof ceviriler[tur] !== 'string')

    expect(eksik(tr.yedekleme.istek.tur)).toEqual([])
    expect(eksik(en.yedekleme.istek.tur)).toEqual([])
  })
})
