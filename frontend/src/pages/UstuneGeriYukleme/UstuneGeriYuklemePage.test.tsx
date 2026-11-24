import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
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

/** Gezinme durumu (location.state): testte görünür kılınır */
function DurumGozcusu() {
  return <pre aria-label="gezinme durumu">{JSON.stringify(useLocation().state)}</pre>
}

function ciz(durum: unknown = null) {
  render(
    <AppProviders>
      <MemoryRouter
        initialEntries={[{ pathname: `/ayarlar/geri-yuklemeler/ustune/${ISLEM}`, state: durum }]}
      >
        <Routes>
          <Route
            path="/ayarlar/geri-yuklemeler/ustune/:islemKimligi"
            element={<UstuneGeriYuklemePage />}
          />
        </Routes>
        <DurumGozcusu />
      </MemoryRouter>
    </AppProviders>,
  )
}

describe('UstuneGeriYuklemePage', () => {
  beforeEach(() => {
    // Anahtarın bitişi (2026-11-21T18:00Z) saate göre denetlenir: saat sabit (T12)
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-21T10:00:00Z'))
    localStorage.clear()
    api.me.mockRejectedValue(new Error('503 bakım modu'))
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('başlatan formun gezinme durumundaki anahtarla izler; depo boş olsa da (YF-10)', async () => {
    api.durum.mockResolvedValue(gorunum({}))
    ciz({
      kayit: {
        islem_kimligi: ISLEM,
        erisim_anahtari: ANAHTAR,
        erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
        karar_istegi: null,
      },
    })

    expect(await screen.findByRole('list', { name: 'Adımlar' })).toBeInTheDocument()
    expect(api.durum).toHaveBeenCalledWith(ISLEM, ANAHTAR, null)
  })

  it.each([
    ['başka işlemin', { islem_kimligi: KARAR }],
    ['biçimsiz anahtarlı', { erisim_anahtari: 'kisa' }],
    ['süresi geçmiş', { erisim_anahtari_bitis: '2026-11-21T09:59:59+00:00' }],
  ])('gezinme durumundaki %s kayda güvenilmez; istek gitmez (YF-10)', async (_ad, bozuk) => {
    ciz({
      kayit: {
        islem_kimligi: ISLEM,
        erisim_anahtari: ANAHTAR,
        erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
        karar_istegi: null,
        ...bozuk,
      },
    })

    expect(await screen.findByText(/erişim anahtarı bu tarayıcıda yok/)).toBeInTheDocument()
    expect(api.durum).not.toHaveBeenCalled()
  })

  it('süresi geçmiş saklı anahtarla istek atmaz ve anahtarı depodan siler (YF-60)', async () => {
    anahtariSakla()
    vi.setSystemTime(new Date('2026-11-21T18:00:01Z'))
    ciz()

    expect(await screen.findByText(/erişim anahtarı bu tarayıcıda yok/)).toBeInTheDocument()
    expect(api.durum).not.toHaveBeenCalled()
    expect(localStorage.getItem(DEPO)).toBeNull()
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

  it('yanıtı kaybolan karar sayfa yenilenince aynı istek anahtarıyla yeniden gönderilir; depoya erişim anahtarı girmez (BY-030-R4)', async () => {
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
            { grup: 'hedefler', farkli: true },
          ],
          ayar_kopyasi_yok: false,
        },
      }),
    )
    // İlk karar sunucuda kabul edildi ama yanıt ulaşmadı
    api.karar
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ istek_kimligi: KARAR, durum: 'kuyrukta' })
    const sec = async (gruplar: [string, string][]) => {
      const bolum = await screen.findByRole('region', { name: 'Hangi ayarlar geçerli olsun?' })
      for (const [grup, secim] of gruplar) {
        fireEvent.click(
          within(within(bolum).getByRole('group', { name: grup })).getByRole('radio', {
            name: secim,
          }),
        )
      }
      fireEvent.click(screen.getByRole('button', { name: 'Onayla' }))
      fireEvent.click(
        within(await screen.findByRole('dialog')).getByRole('button', { name: 'Onayla' }),
      )
    }
    const bekleyenDepo = `erp.yedekleme.istekAnahtarlari.v2.ustune.${ISLEM}`

    ciz()
    await sec([
      ['Mail ayarları', 'Güncel (bugünkü)'],
      ['Yedek hedefleri', 'Geri yüklenen (yedekteki)'],
    ])
    await waitFor(() => expect(api.karar).toHaveBeenCalledTimes(1))
    const ilk = api.karar.mock.calls[0][2] as string
    // Bekleyen kayıtta yalnız eylem özeti, UUID anahtar ve zaman; erişim anahtarı yok
    const bekleyen = JSON.parse(localStorage.getItem(bekleyenDepo) ?? '[]') as object[]
    expect(bekleyen.map((kayit) => Object.keys(kayit).sort())).toEqual([
      ['anahtar', 'ozet', 'zaman'],
    ])
    expect(localStorage.getItem(bekleyenDepo)).not.toContain(ANAHTAR)

    // Sayfa yenilendi: aynı karar (seçimler başka sırayla) aynı anahtarı taşır
    cleanup()
    ciz()
    await sec([
      ['Yedek hedefleri', 'Geri yüklenen (yedekteki)'],
      ['Mail ayarları', 'Güncel (bugünkü)'],
    ])

    await waitFor(() => expect(api.karar).toHaveBeenCalledTimes(2))
    expect(api.karar.mock.calls[1][2]).toBe(ilk)
    // Kabul geldi: bekleyen anahtar silinir, karar izlenir
    await waitFor(() => expect(api.durum).toHaveBeenCalledWith(ISLEM, ANAHTAR, KARAR))
    expect(localStorage.getItem(bekleyenDepo)).toBeNull()
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

  it('işlem bitince anahtarı tarayıcıdan ve gezinme durumundan siler', async () => {
    anahtariSakla(KARAR)
    api.durum.mockResolvedValue(gorunum({ durum: 'onaylandi', adim: 'onaylandi', acik: false }))
    ciz({
      kayit: {
        islem_kimligi: ISLEM,
        erisim_anahtari: ANAHTAR,
        erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
        karar_istegi: null,
      },
    })

    expect(await screen.findByText('Tamamlandı')).toBeInTheDocument()
    await waitFor(() => expect(localStorage.getItem(DEPO)).toBeNull())
    await waitFor(() => expect(screen.getByLabelText('gezinme durumu')).toHaveTextContent('null'))
  })
})
