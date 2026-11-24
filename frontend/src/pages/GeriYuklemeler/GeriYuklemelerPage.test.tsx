import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { GeriYukleme } from '@/features/yedekleme/geriYuklemeApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { GeriYuklemelerPage } from './GeriYuklemelerPage'

const api = vi.hoisted(() => ({ liste: vi.fn(), sil: vi.fn(), istek: vi.fn() }))

vi.mock('@/features/yedekleme/geriYuklemeApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/geriYuklemeApi')>()),
  geriYuklemeleriGetir: api.liste,
  kopyaSil: api.sil,
}))
vi.mock('@/features/yedekleme/yedekApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/yedekApi')>()),
  istekDurumuGetir: api.istek,
}))

const temel: GeriYukleme = {
  kimlik: '44444444-4444-4444-8444-444444444444',
  tur: 'kopya',
  durum: 'tamam',
  yedek: {
    kimlik: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    dosya_adi: 'erp_plan_20261120_0200.dump',
    alinma_zamani: '2026-11-19T23:00:00+00:00',
  },
  hedef_veritabani: 'erp_geri_20261114_120000_mart1',
  onceki_veritabani: null,
  isteyen: 'Deneme',
  adimlar: [],
  hata_kodu: null,
  basladi: '2026-11-14T09:00:00+00:00',
  bitti: '2026-11-14T09:02:00+00:00',
  veritabani_silinme_zamani: null,
  bekleyen_kopya: true,
  // Saat sabit: 21.11.2026 10:00 → 8 saat kaldı
  silinecegi_zaman: '2026-11-21T18:00:00+03:00',
  boyut: 1677722,
}

/** Kalıp dışı ad: ekran silme düğmesi çizmez */
const KALIP_DISI: GeriYukleme = {
  ...temel,
  kimlik: '55555555-5555-4555-8555-555555555555',
  hedef_veritabani: 'erp',
  silinecegi_zaman: '2026-11-25T18:00:00+03:00',
}

const USTUNE: GeriYukleme = {
  ...temel,
  kimlik: '66666666-6666-4666-8666-666666666666',
  tur: 'ustune',
  durum: 'basarisiz',
  hedef_veritabani: 'erp',
  onceki_veritabani: 'erp_onceki_20261110_080000',
  hata_kodu: 'GERI_YUKLEME_PG_RESTORE_HATASI',
  adimlar: [
    { adim: 'hazirlik', durum: 'tamam', zaman: '2026-11-10T05:00:00+00:00' },
    { adim: 'pg_restore', durum: 'basarisiz', zaman: '2026-11-10T05:01:00+00:00' },
  ],
  bekleyen_kopya: false,
  silinecegi_zaman: null,
}

const USTUNE_DEPO = 'erp.yedekleme.ustuneGeriYukleme.v1'
const ISTEK = '77777777-7777-4777-8777-777777777777'

/** USTUNE işleminin erişim anahtarı bu tarayıcıda (bitiş, sabit saatten sonra) */
function anahtariSakla() {
  localStorage.setItem(
    USTUNE_DEPO,
    JSON.stringify({
      islem_kimligi: USTUNE.kimlik,
      erisim_anahtari: 'd'.repeat(64),
      erisim_anahtari_bitis: '2026-11-21T18:00:00+03:00',
      karar_istegi: null,
    }),
  )
}

/** Bekleyen kopyanın "Sil" düğmesine basar; onay penceresinin "Sil" düğmesini döndürür */
async function kopyayiSil() {
  await screen.findAllByText('erp_geri_20261114_120000_mart1')
  const kopya = within(screen.getAllByRole('table')[0])
    .getByText('erp_geri_20261114_120000_mart1')
    .closest('tr') as HTMLElement
  fireEvent.click(within(kopya).getByRole('button', { name: 'Sil' }))

  return within(await screen.findByRole('dialog')).getByRole('button', { name: 'Sil' })
}

function ciz(izinler = ['yedekler.goruntule', 'geri_yukleme.kopya']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} yedeklemeAktif>
        <MemoryRouter>
          <GeriYuklemelerPage />
        </MemoryRouter>
      </SahteOturum>
    </AppProviders>,
  )
}

/** Bekleyen kopyalar tablosu (ilk tablo) */
const bekleyenTablosu = () => screen.getAllByRole('table')[0]

describe('GeriYuklemelerPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-21T10:00:00+03:00'))
    sessionStorage.clear()
    localStorage.clear()
    api.liste.mockResolvedValue([temel, KALIP_DISI, USTUNE])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('otomatik silinmeye 1 günden az kalan kopyayı uyarır; erp / kalıp dışı ad ekrandan silinemez', async () => {
    ciz()
    await screen.findAllByText('erp_geri_20261114_120000_mart1')

    const tablo = bekleyenTablosu()
    const kopya = within(tablo)
      .getByText('erp_geri_20261114_120000_mart1')
      .closest('tr') as HTMLElement
    expect(within(kopya).getByText('1 günden az kaldı')).toBeInTheDocument()
    expect(within(kopya).getByRole('button', { name: 'Sil' })).toBeInTheDocument()

    const erp = within(tablo).getByText('erp').closest('tr') as HTMLElement
    expect(within(erp).queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
    expect(within(erp).getByText('Ekrandan silinemez')).toBeInTheDocument()
    expect(within(erp).queryByText('1 günden az kaldı')).not.toBeInTheDocument()
  })

  it('üstüne geri yükleme geçmişte salt okunur; hata kodu çevrilir, adımlar açılır', async () => {
    ciz()
    const ustune = (await screen.findByText('erp_onceki_20261110_080000')).closest(
      'tr',
    ) as HTMLElement

    expect(within(ustune).getByText('Üstüne')).toBeInTheDocument()
    expect(
      within(ustune).getByText('Geri yükleme (pg_restore) başarısız oldu.'),
    ).toBeInTheDocument()
    expect(within(ustune).queryByRole('button')).not.toBeInTheDocument()
    fireEvent.click(within(ustune).getByText('2 adım'))
    expect(within(ustune).getByText('Geri yükleme (pg_restore)')).toBeVisible()
  })

  it('kopya silme onay ister; onaydan sonra istek bırakılır ve yoklanır', async () => {
    api.sil.mockResolvedValue({
      istek_kimligi: '77777777-7777-4777-8777-777777777777',
      durum: 'kuyrukta',
    })
    api.istek.mockResolvedValue({
      kimlik: '77777777-7777-4777-8777-777777777777',
      durum: 'calisiyor',
    })
    ciz()
    await screen.findAllByText('erp_geri_20261114_120000_mart1')

    const kopya = within(bekleyenTablosu())
      .getByText('erp_geri_20261114_120000_mart1')
      .closest('tr') as HTMLElement
    fireEvent.click(within(kopya).getByRole('button', { name: 'Sil' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('erp_geri_20261114_120000_mart1')
    expect(api.sil).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(temel.kimlik, expect.any(String)))
    expect(await screen.findByRole('status')).toHaveTextContent('Kopya silme — çalışıyor')
  })

  it('geri_yukleme.kopya izni yoksa silme düğmesi yoktur', async () => {
    ciz(['yedekler.goruntule'])
    await screen.findAllByText('erp_geri_20261114_120000_mart1')

    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })

  it('süren üstüne geri yüklemenin anahtarı bu tarayıcıdaysa satır ilerleme sayfasına bağlanır', async () => {
    api.liste.mockResolvedValue([temel, { ...USTUNE, durum: 'onay_bekliyor', hata_kodu: null }])
    anahtariSakla()
    ciz()

    const baglanti = await screen.findByRole('link', { name: 'İlerlemeyi aç' })
    expect(baglanti).toHaveAttribute('href', `/ayarlar/geri-yuklemeler/ustune/${USTUNE.kimlik}`)
    expect(screen.getAllByRole('link', { name: 'İlerlemeyi aç' })).toHaveLength(1)
    expect(localStorage.getItem(USTUNE_DEPO)).not.toBeNull()
  })

  it('liste üstüne geri yüklemeyi bitmiş gösteriyorsa erişim anahtarı ilerleme sayfası açılmadan silinir (YF-60)', async () => {
    anahtariSakla()
    ciz()

    await screen.findByText('erp_onceki_20261110_080000')
    await waitFor(() => expect(localStorage.getItem(USTUNE_DEPO)).toBeNull())
    expect(screen.queryByRole('link', { name: 'İlerlemeyi aç' })).not.toBeInTheDocument()
  })

  it('kopya silme yanıtı beklenirken ikinci onay ikinci istek göndermez (F5, YF-11)', async () => {
    let bitir: (kabul: { istek_kimligi: string; durum: 'kuyrukta' }) => void = () => undefined
    api.sil.mockReturnValue(new Promise((coz) => (bitir = coz)))
    api.istek.mockResolvedValue({ kimlik: ISTEK, durum: 'calisiyor' })
    ciz()

    const onay = await kopyayiSil()
    fireEvent.click(onay)
    fireEvent.click(onay)

    await waitFor(() => expect(api.sil).toHaveBeenCalledTimes(1))
    bitir({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    expect(await screen.findByRole('status')).toHaveTextContent('Kopya silme')
    expect(api.sil).toHaveBeenCalledTimes(1)
  })

  it('yanıtı alınamayan kopya silme yeniden denenince aynı istek anahtarını taşır (YF-11)', async () => {
    api.sil
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK, durum: 'calisiyor' })
    ciz()

    const onay = await kopyayiSil()
    fireEvent.click(onay)
    await waitFor(() => expect(api.sil).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(onay).toBeEnabled())
    fireEvent.click(onay)
    await waitFor(() => expect(api.sil).toHaveBeenCalledTimes(2))

    const ilk = api.sil.mock.calls[0][1] as string
    expect(ilk).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(api.sil.mock.calls[1]).toEqual([temel.kimlik, ilk])
  })

  it('Yedekler ekranında bırakılan farklı adla geri yükleme kuyruktayken liste yoklanır; bitince bildirilir (YF-62)', async () => {
    vi.useFakeTimers({
      toFake: ['Date', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
      shouldAdvanceTime: true,
    })
    vi.setSystemTime(new Date('2026-11-21T10:00:00+03:00'))
    sessionStorage.setItem(
      'erp.yedekleme.istekler.v2.kullanici.1.yedekler',
      JSON.stringify([{ kimlik: ISTEK, tur: 'kopya_geri_yukle' }]),
    )
    api.istek
      .mockResolvedValueOnce({ kimlik: ISTEK, durum: 'kuyrukta' })
      .mockResolvedValueOnce({ kimlik: ISTEK, durum: 'kuyrukta' })
      .mockResolvedValueOnce({ kimlik: ISTEK, durum: 'kuyrukta' })
      .mockResolvedValue({ kimlik: ISTEK, durum: 'tamam', hedef_veritabani: 'erp_geri_yeni' })
    ciz()

    expect(await screen.findByRole('status')).toHaveTextContent('Farklı adla geri yükleme')
    const ilkListe = api.liste.mock.calls.length
    await vi.advanceTimersByTimeAsync(5100)
    expect(api.liste.mock.calls.length).toBeGreaterThan(ilkListe)

    await vi.advanceTimersByTimeAsync(4000)
    expect(await screen.findByText(/erp_geri_yeni/)).toBeInTheDocument()
    await waitFor(() =>
      expect(sessionStorage.getItem('erp.yedekleme.istekler.v2.kullanici.1.yedekler')).toBeNull(),
    )
    // İstek bitti, süren satır yok: liste artık yoklanmaz
    const sonListe = api.liste.mock.calls.length
    await vi.advanceTimersByTimeAsync(11000)
    expect(api.liste.mock.calls.length).toBe(sonListe)
  })
})
