import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import {
  UstuneGeriYuklemeHatasi,
  type OnayBilgisi,
  type UstuneGorunum,
} from '@/features/yedekleme/ustuneGeriYuklemeApi'
import { AppProviders } from '@/providers/AppProviders'

import { UstuneGeriYuklemePage } from './UstuneGeriYuklemePage'

const api = vi.hoisted(() => ({ durum: vi.fn(), karar: vi.fn(), me: vi.fn() }))

vi.mock('@/features/yedekleme/ustuneGeriYuklemeApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/ustuneGeriYuklemeApi')>()),
  ustuneDurumGetir: api.durum,
  ustuneKararVer: api.karar,
}))
// Bakım modunda oturum ucu da 503 döner: sayfa oturuma dayanmamalı
vi.mock('@/features/auth/api', () => ({
  meIstegi: api.me,
  loginIstegi: vi.fn(),
  logoutIstegi: vi.fn(),
}))

const ISLEM = '11111111-1111-4111-8111-111111111111'
const ANAHTAR = 'c'.repeat(64)
const KARAR = '99999999-9999-4999-8999-999999999999'
const DEPO = 'erp.yedekleme.ustuneGeriYukleme.v1'

function gorunum(ek: Partial<UstuneGorunum>): UstuneGorunum {
  return {
    islem_kimligi: ISLEM,
    durum: 'calisiyor',
    adim: 'oncesi_yedek',
    acik: true,
    yer_degistirildi: false,
    hata: null,
    adimlar: [
      { adim: 'on_denetim', durum: 'tamam' },
      { adim: 'bakim_modu', durum: 'tamam' },
      { adim: 'oncesi_yedek', durum: 'suruyor' },
      { adim: 'veritabani', durum: 'bekliyor' },
    ],
    yapilabilir: { onayla: false, geri_don: false },
    onay_bilgisi: null,
    ...ek,
  }
}

const ONAY_BILGISI: OnayBilgisi = {
  onceki_bilinmiyor: false,
  aktif_erp_ortami: { onceki: 'canli', geri_yuklenen: 'test', farkli: true },
  entegratorler: { onceki: ['izibiz canli'], geri_yuklenen: ['izibiz canli'], farkli: false },
  alarm_kurallari: { onceki: [], geri_yuklenen: [], farkli: false },
  ayar_farklari: [],
  kullanici_farklari: [
    {
      kullanici_adi: 'AYILMAZ',
      ad: 'Ayşe Yılmaz',
      degisiklik: 'degisti',
      alanlar: ['aktif'],
      onceki: { ad: 'Ayşe Yılmaz', aktif: false, sistem_yoneticisi: false, roller: [] },
      geri_yuklenen: { ad: 'Ayşe Yılmaz', aktif: true, sistem_yoneticisi: false, roller: [] },
    },
  ],
  rol_farklari: [],
  kaybolacak_yukleme_sayisi: 3,
}

function anahtariSakla(kararIstegi: string | null = null) {
  localStorage.setItem(
    DEPO,
    JSON.stringify({
      islem_kimligi: ISLEM,
      erisim_anahtari: ANAHTAR,
      erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
      karar_istegi: kararIstegi,
    }),
  )
}

function ciz() {
  render(
    <AppProviders>
      <MemoryRouter initialEntries={[`/ayarlar/geri-yuklemeler/ustune/${ISLEM}`]}>
        <Routes>
          <Route
            path="/ayarlar/geri-yuklemeler/ustune/:islemKimligi"
            element={<UstuneGeriYuklemePage />}
          />
        </Routes>
      </MemoryRouter>
    </AppProviders>,
  )
}

describe('UstuneGeriYuklemePage', () => {
  beforeEach(() => {
    localStorage.clear()
    api.me.mockRejectedValue(new Error('503 bakım modu'))
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('anahtar bu tarayıcıda yoksa istek atmaz, acil durum komutunu anlatır', async () => {
    ciz()

    expect(await screen.findByText(/erişim anahtarı bu tarayıcıda yok/)).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Acil durum yolu (sunucuda)' })).toHaveTextContent(
      'php artisan yedek:acil durum',
    )
    expect(api.durum).not.toHaveBeenCalled()
  })

  it('oturum olmadan, tarayıcıda saklanan anahtarla kaldığı yerden izler ve adımları gösterir', async () => {
    anahtariSakla()
    api.durum.mockResolvedValue(gorunum({}))
    ciz()

    const adimlar = await screen.findByRole('list', { name: 'Adımlar' })
    expect(api.durum).toHaveBeenCalledWith(ISLEM, ANAHTAR, null)
    const satirlar = within(adimlar).getAllByRole('listitem')
    expect(satirlar.map((s) => s.getAttribute('data-durum'))).toEqual([
      'tamam',
      'tamam',
      'suruyor',
      'bekliyor',
    ])
    expect(satirlar[2]).toHaveTextContent('Geri yükleme öncesi yedek')
    expect(screen.getByRole('status')).toHaveTextContent('Sürüyor')
  })

  it('onay beklerken farkları gösterir; "Onayla" onaydan sonra anahtarla gönderilir ve kararı yoklar', async () => {
    anahtariSakla()
    api.durum.mockResolvedValue(
      gorunum({
        durum: 'onay_bekliyor',
        adim: 'onay_bekliyor',
        yer_degistirildi: true,
        yapilabilir: { onayla: true, geri_don: true },
        onay_bilgisi: ONAY_BILGISI,
      }),
    )
    api.karar.mockResolvedValue({ istek_kimligi: KARAR, durum: 'kuyrukta' })
    ciz()

    expect(await screen.findByText('Ayşe Yılmaz (AYILMAZ)')).toBeInTheDocument()
    expect(screen.getByText(/Giriş izni: Hayır →/)).toBeInTheDocument()
    expect(screen.getByText(/3 içe aktarım/)).toBeInTheDocument()
    expect(screen.getByText('Farklı')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Onayla' }))
    const dialog = await screen.findByRole('dialog')
    expect(api.karar).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Onayla' }))

    await waitFor(() =>
      expect(api.karar).toHaveBeenCalledWith(ISLEM, ANAHTAR, expect.any(String), 'onayla', {}),
    )
    await waitFor(() => expect(api.durum).toHaveBeenCalledWith(ISLEM, ANAHTAR, KARAR))
    expect(JSON.parse(localStorage.getItem(DEPO) ?? '{}').karar_istegi).toBe(KARAR)
  })

  it('farkı olan her ayar grubu seçilmeden "Onayla" kapalı; seçimler onayla gönderilir', async () => {
    anahtariSakla()
    api.durum.mockResolvedValue(
      gorunum({
        durum: 'onay_bekliyor',
        adim: 'onay_bekliyor',
        yer_degistirildi: true,
        yapilabilir: { onayla: true, geri_don: true },
        onay_bilgisi: {
          ...ONAY_BILGISI,
          ayar_gruplari: [
            { grup: 'mail', farkli: true },
            { grup: 'yedek_ayarlari', farkli: false },
            { grup: 'planlar', farkli: false },
            { grup: 'hedefler', farkli: true },
          ],
          ayar_kopyasi_yok: false,
        },
      }),
    )
    api.karar.mockResolvedValue({ istek_kimligi: KARAR, durum: 'kuyrukta' })
    ciz()

    const bolum = await screen.findByRole('region', { name: 'Hangi ayarlar geçerli olsun?' })
    // Yalnız farklı gruplar sorulur; varsayılan seçim yok
    expect(
      within(bolum)
        .getAllByRole('group')
        .map((g) => g.textContent),
    ).toEqual([
      expect.stringContaining('Mail ayarları'),
      expect.stringContaining('Yedek hedefleri'),
    ])
    expect(
      within(bolum)
        .getAllByRole('radio')
        .every((r) => !(r as HTMLInputElement).checked),
    ).toBe(true)
    const onayla = screen.getByRole('button', { name: 'Onayla' })
    expect(onayla).toBeDisabled()

    fireEvent.click(
      within(within(bolum).getByRole('group', { name: 'Mail ayarları' })).getByRole('radio', {
        name: 'Güncel (bugünkü)',
      }),
    )
    expect(onayla).toBeDisabled()
    fireEvent.click(
      within(within(bolum).getByRole('group', { name: 'Yedek hedefleri' })).getByRole('radio', {
        name: 'Geri yüklenen (yedekteki)',
      }),
    )
    expect(onayla).toBeEnabled()

    fireEvent.click(onayla)
    fireEvent.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Onayla' }),
    )

    await waitFor(() =>
      expect(api.karar).toHaveBeenCalledWith(ISLEM, ANAHTAR, expect.any(String), 'onayla', {
        mail: 'guncel',
        hedefler: 'geri_yuklenen',
      }),
    )
  })

  it('"Geri dön" geri-don ucuna gider', async () => {
    anahtariSakla()
    api.durum.mockResolvedValue(
      gorunum({
        durum: 'onay_bekliyor',
        yapilabilir: { onayla: true, geri_don: true },
        onay_bilgisi: ONAY_BILGISI,
      }),
    )
    api.karar.mockResolvedValue({ istek_kimligi: KARAR, durum: 'kuyrukta' })
    ciz()

    fireEvent.click(await screen.findByRole('button', { name: 'Geri dön' }))
    fireEvent.click(
      within(await screen.findByRole('dialog')).getByRole('button', { name: 'Geri dön' }),
    )

    await waitFor(() =>
      expect(api.karar).toHaveBeenCalledWith(ISLEM, ANAHTAR, expect.any(String), 'geri-don'),
    )
  })

  it('süresi dolan anahtarda nedeni ve acil durum komutunu gösterir', async () => {
    anahtariSakla()
    api.durum.mockRejectedValue(
      new UstuneGeriYuklemeHatasi(403, 'GERI_YUKLEME_ERISIM_ANAHTARI_SURESI_DOLDU'),
    )
    ciz()

    expect(await screen.findByText(/Erişim anahtarının süresi doldu/)).toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'Acil durum yolu (sunucuda)' })).toHaveTextContent(
      'yedek:acil onayla',
    )
    expect(screen.queryByRole('button', { name: 'Onayla' })).not.toBeInTheDocument()
  })

  it('işlem bitince anahtarı tarayıcıdan siler', async () => {
    anahtariSakla(KARAR)
    api.durum.mockResolvedValue(gorunum({ durum: 'onaylandi', adim: 'onaylandi', acik: false }))
    ciz()

    expect(await screen.findByText('Tamamlandı')).toBeInTheDocument()
    await waitFor(() => expect(localStorage.getItem(DEPO)).toBeNull())
  })
})
