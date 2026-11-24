import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Hedef } from '@/features/yedekleme/hedefApi'
import { AppProviders } from '@/providers/AppProviders'
import { AuthContext } from '@/providers/auth-context'
import { SahteOturum } from '@/test/SahteOturum'

import type { Plan } from './planApi'
import { YedekPlanlariPage } from './YedekPlanlariPage'

const api = vi.hoisted(() => ({
  planlar: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
  calistir: vi.fn(),
  onizle: vi.fn(),
  hedefler: vi.fn(),
  istek: vi.fn(),
}))

vi.mock('./planApi', async (asil) => ({
  ...(await asil<typeof import('./planApi')>()),
  planlariGetir: api.planlar,
  planKaydet: api.kaydet,
  planSil: api.sil,
  planCalistir: api.calistir,
  planOnizle: api.onizle,
}))
vi.mock('@/features/yedekleme/hedefApi', () => ({ hedefleriGetir: api.hedefler }))
vi.mock('@/features/yedekleme/yedekApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/yedekApi')>()),
  istekDurumuGetir: api.istek,
}))

const ISTEK = '33333333-3333-4333-8333-333333333333'
/** Planlar ekranının takip deposu (kullanıcı 1) */
const DEPO = 'erp.yedekleme.istekler.v2.kullanici.1.planlar'

/** Aralık değişince eski zamanlayıcı da temizlenebilsin: clear* de sahte olmalı */
function saatiSahtele() {
  vi.useFakeTimers({
    toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'],
    shouldAdvanceTime: true,
  })
}

const hedef = (id: number, ad: string, tur: string): Hedef => ({
  id,
  ad,
  tur,
  aktif: true,
  sunucu_disi: tur !== 'yerel',
  baglanti: {},
  gizli_kayitli: {},
  plan_sayisi: 0,
  konum_kilitli_alanlar: [],
  son_kopya: null,
})

const AYLIK: Plan = {
  id: 5,
  ad: 'Aylık arşiv',
  aktif: true,
  siklik_turu: 'aylik',
  saat: '02:00',
  siklik: { ayin_gunu: 31 },
  son_calisma: null,
  son_sonuc: null,
  sonraki_calisma: '2026-11-29T23:00:00+00:00',
  ay_sonu_uyarisi: true,
  hedefler: [
    {
      hedef_id: 1,
      ad: 'Yerel',
      tur: 'yerel',
      sakla_son_adet: 14,
      sakla_gun: null,
      kopya_sayisi: 4,
    },
  ],
}

function hata(status: number, data: unknown) {
  return Object.assign(new Error('istek'), { isAxiosError: true, response: { status, data } })
}

function ciz(izinler = ['yedek_planlari.goruntule', 'yedek_planlari.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} yedeklemeAktif>
        <YedekPlanlariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

/** Başka bir kullanıcının oturumuyla (SahteOturum kimliği 1'dir) */
function kullaniciIleCiz(id: number) {
  render(
    <AppProviders>
      <AuthContext.Provider
        value={{
          user: {
            id,
            ad: 'Diğer',
            kullanici_adi: 'diger',
            email: null,
            kaynak: 'erp',
            sistem_yoneticisi: false,
            izinler: ['yedek_planlari.goruntule', 'yedek_planlari.guncelle'],
            yedekleme_aktif: true,
          },
          yukleniyor: false,
          login: async () => {},
          logout: async () => {},
        }}
      >
        <YedekPlanlariPage />
      </AuthContext.Provider>
    </AppProviders>,
  )
}

const satir = async (ad: string) => (await screen.findByText(ad)).closest('tr') as HTMLElement

async function sec(kap: HTMLElement, etiket: string, secenek: string) {
  fireEvent.keyDown(within(kap).getByLabelText(etiket), { key: 'ArrowDown' })
  fireEvent.click(await screen.findByRole('option', { name: secenek }))
}

async function duzenle(ad: string) {
  fireEvent.click(within(await satir(ad)).getByRole('button', { name: 'Düzenle' }))

  return screen.findByRole('dialog')
}

describe('YedekPlanlariPage', () => {
  beforeEach(() => {
    sessionStorage.clear()
    localStorage.clear()
    api.planlar.mockResolvedValue([AYLIK])
    api.hedefler.mockResolvedValue([hedef(1, 'Yerel', 'yerel'), hedef(3, 'Ofis FTP', 'ftp')])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('kullanıcının örneği: 3 günde bir ikinci hedefe, son 10 yedek saklanarak kurulur', async () => {
    api.kaydet.mockResolvedValue({ ...AYLIK, id: 6 })
    ciz()
    await satir('Aylık arşiv')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Plan' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Ad *'), { target: { value: 'FTP' } })
    await sec(dialog, 'Sıklık', 'N günde bir')
    fireEvent.change(within(dialog).getByLabelText('Kaç günde bir *'), { target: { value: '3' } })
    await sec(dialog, 'Hedef', 'Ofis FTP (FTP)')
    fireEvent.change(within(dialog).getByLabelText('Son kaç yedek'), { target: { value: '10' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.kaydet).toHaveBeenCalledWith(null, {
        ad: 'FTP',
        aktif: true,
        siklik_turu: 'n_gunluk',
        saat: '02:00',
        siklik: { aralik: 3 },
        hedefler: [{ hedef_id: 3, sakla_son_adet: 10, sakla_gun: null }],
      }),
    )
  })

  it('son çalışmanın sonucu başarılı, yedek hatalı ve hedef hatalı olarak ayrı gösterilir (BY-017-R1)', async () => {
    const calismis = (id: number, ad: string, son_sonuc: Plan['son_sonuc']): Plan => ({
      ...AYLIK,
      id,
      ad,
      son_calisma: '2026-11-20T23:00:00+00:00',
      son_sonuc,
    })
    api.planlar.mockResolvedValue([
      calismis(1, 'Başarılı plan', { durum: 'basarili', hata_kodu: null, basarisiz_hedefler: [] }),
      calismis(2, 'Dump hatalı plan', {
        durum: 'yedek_basarisiz',
        hata_kodu: 'HEDEF_ERISILEMEDI',
        basarisiz_hedefler: [],
      }),
      calismis(3, 'Hedef hatalı plan', {
        durum: 'hedef_basarisiz',
        hata_kodu: null,
        basarisiz_hedefler: [{ hedef_id: 3, ad: 'Ofis FTP', hata_kodu: 'HEDEF_YAZILAMADI' }],
      }),
    ])
    ciz()

    const basarili = await satir('Başarılı plan')
    expect(within(basarili).getByText('Başarılı')).toBeInTheDocument()
    expect(basarili).not.toHaveTextContent('Yedek alınamadı')
    expect(basarili).not.toHaveTextContent('Kopya başarısız')

    const dump = await satir('Dump hatalı plan')
    expect(dump).toHaveTextContent('Yedek alınamadı')
    expect(dump).toHaveTextContent('Hedefe ulaşılamadı')

    const hedefHatali = await satir('Hedef hatalı plan')
    expect(hedefHatali).toHaveTextContent('Kopya başarısız')
    expect(hedefHatali).toHaveTextContent('Ofis FTP: Hedefe yazılamadı')
    expect(hedefHatali).not.toHaveTextContent('Yedek alınamadı')
  })

  it('saklama kuralı boş ya da hedefsiz plan kaydedilmez', async () => {
    ciz()
    await satir('Aylık arşiv')
    fireEvent.click(screen.getByRole('button', { name: 'Yeni Plan' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Ad *'), { target: { value: 'Günlük' } })

    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))
    expect(await within(dialog).findByText(/Saklama kuralı boş olamaz/)).toBeInTheDocument()
    expect(within(dialog).getByText('Hedef seçin.')).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: '1. hedefi kaldır' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))
    expect(await within(dialog).findByText('En az bir hedef ekleyin.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })

  it('önizleme sıradaki çalışmaları, uyarıları ve silinecekleri kaydetmeden gösterir; form değişince düşer', async () => {
    api.onizle.mockResolvedValue({
      siradakiler: ['2026-11-30T23:00:00+00:00', '2026-12-30T23:00:00+00:00'],
      ay_sonu_uyarisi: true,
      cakisan_planlar: [{ id: 2, ad: 'Günlük yerel', an: '2026-11-30T23:00:00+00:00' }],
      silinecekler: [
        {
          tur: 'bag',
          yedek_id: 40,
          hedef_id: 1,
          kopya_id: 90,
          plan_id: 5,
          neden: 'son_adet',
          dosya_adi: 'erp_plan_20261001_0200.dump',
          alinma_zamani: '2026-09-30T23:00:00+00:00',
        },
      ],
    })
    ciz()
    const dialog = await duzenle('Aylık arşiv')

    // Ayın 31'i: önizlemeden önce de formda uyarı var
    expect(within(dialog).getByRole('alert')).toHaveTextContent('bazı aylarda yok')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Önizle' }))

    const onizleme = await within(dialog).findByRole('region', { name: 'Önizleme' })
    expect(api.onizle).toHaveBeenCalledWith(5, {
      ad: 'Aylık arşiv',
      aktif: true,
      siklik_turu: 'aylik',
      saat: '02:00',
      siklik: { ayin_gunu: 31 },
      hedefler: [{ hedef_id: 1, sakla_son_adet: 14, sakla_gun: null }],
    })
    expect(api.kaydet).not.toHaveBeenCalled()
    // İstanbul saati (UTC+3)
    expect(onizleme).toHaveTextContent('01.12.2026 02:00')
    expect(onizleme).toHaveTextContent('Günlük yerel')
    expect(onizleme).toHaveTextContent('erp_plan_20261001_0200.dump')
    expect(onizleme).toHaveTextContent('son yedek sayısı sınırının dışında')

    fireEvent.change(within(dialog).getByLabelText('Son kaç yedek'), { target: { value: '7' } })
    await waitFor(() =>
      expect(within(dialog).queryByRole('region', { name: 'Önizleme' })).not.toBeInTheDocument(),
    )
  })

  it('kopyası olan hedef plandan çıkarılırken nedenini açıklar (F6)', async () => {
    api.kaydet.mockRejectedValue(
      hata(409, {
        kod: 'PLAN_HEDEFI_KOPYALI',
        mesaj: 'x',
        hedefler: [{ hedef_id: 1, ad: 'Yerel', kopya_sayisi: 4 }],
      }),
    )
    ciz()
    const dialog = await duzenle('Aylık arşiv')

    await sec(dialog, 'Hedef', 'Ofis FTP (FTP)')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    const uyari = await within(dialog).findByText('Bazı hedefler plandan çıkarılamaz')
    const kutu = uyari.closest('[role="alert"]') as HTMLElement
    expect(kutu).toHaveTextContent('Yerel: bu plana bağlı 4 kopya')
    expect(kutu).toHaveTextContent('saklama kuralıyla temizlendikten sonra')
  })

  it('"Şimdi Çalıştır" planın isteğini bırakır; iş sürerken düğme kapalıdır', async () => {
    api.calistir.mockResolvedValue({
      istek_kimligi: '33333333-3333-4333-8333-333333333333',
      durum: 'kuyrukta',
    })
    api.istek.mockResolvedValue({
      kimlik: '33333333-3333-4333-8333-333333333333',
      durum: 'calisiyor',
    })
    ciz()

    const calistir = within(await satir('Aylık arşiv')).getByRole('button', {
      name: 'Şimdi Çalıştır',
    })
    fireEvent.click(calistir)

    await waitFor(() => expect(api.calistir).toHaveBeenCalledWith(5, expect.any(String)))
    expect(await screen.findByRole('status')).toHaveTextContent('Planı çalıştırma — çalışıyor')
    expect(calistir).toBeDisabled()
  })

  it('"Şimdi Çalıştır" yanıtı beklenirken ikinci tıklama (başka planda da) ikinci iş bırakmaz (F5, YF-11)', async () => {
    api.planlar.mockResolvedValue([AYLIK, { ...AYLIK, id: 6, ad: 'Günlük yerel' }])
    let bitir: (kabul: { istek_kimligi: string; durum: 'kuyrukta' }) => void = () => undefined
    api.calistir.mockReturnValue(new Promise((coz) => (bitir = coz)))
    api.istek.mockResolvedValue({ kimlik: ISTEK, durum: 'calisiyor' })
    ciz()

    const aylik = within(await satir('Aylık arşiv')).getByRole('button', { name: 'Şimdi Çalıştır' })
    const gunluk = within(await satir('Günlük yerel')).getByRole('button', {
      name: 'Şimdi Çalıştır',
    })
    fireEvent.click(aylik)
    fireEvent.click(aylik)
    fireEvent.click(gunluk)

    await waitFor(() => expect(gunluk).toBeDisabled())
    expect(api.calistir).toHaveBeenCalledTimes(1)
    bitir({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    expect(await screen.findByRole('status')).toHaveTextContent('Planı çalıştırma')
    expect(api.calistir).toHaveBeenCalledTimes(1)
  })

  it('yanıtı alınamayan "Şimdi Çalıştır" yeniden denenince aynı istek anahtarını taşır (YF-11)', async () => {
    api.calistir
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK, durum: 'calisiyor' })
    ciz()
    const calistir = within(await satir('Aylık arşiv')).getByRole('button', {
      name: 'Şimdi Çalıştır',
    })

    fireEvent.click(calistir)
    await waitFor(() => expect(api.calistir).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(calistir).toBeEnabled())
    fireEvent.click(calistir)
    await waitFor(() => expect(api.calistir).toHaveBeenCalledTimes(2))

    const ilk = api.calistir.mock.calls[0][1] as string
    expect(ilk).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(api.calistir.mock.calls[1]).toEqual([5, ilk])
  })

  it('istek durumu kalıcı hata (403) verince yoklama durur: bir kez bildirilir, şerit kalkar, düğme açılır (YF-09)', async () => {
    saatiSahtele()
    api.calistir.mockResolvedValue({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    api.istek.mockRejectedValue(hata(403, { kod: 'ERISIM_ENGELLI', mesaj: 'x' }))
    ciz()
    const calistir = within(await satir('Aylık arşiv')).getByRole('button', {
      name: 'Şimdi Çalıştır',
    })

    fireEvent.click(calistir)

    expect(await screen.findByText(/^Planı çalıştırma başarısız:/)).toBeInTheDocument()
    await waitFor(() => expect(calistir).toBeEnabled())
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    await vi.advanceTimersByTimeAsync(25000)
    expect(api.istek).toHaveBeenCalledTimes(1)
    expect(sessionStorage.getItem(DEPO)).toBeNull()
  })

  it('istek durumu geçici hata (503 / ağ) verince yoklama seyrekleşerek sürer; iş bitince bildirir (YF-09)', async () => {
    saatiSahtele()
    sessionStorage.setItem(DEPO, JSON.stringify([{ kimlik: ISTEK, tur: 'plan_calistir' }]))
    api.istek
      .mockRejectedValueOnce(hata(503, { kod: 'BAKIM', mesaj: 'x' }))
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ kimlik: ISTEK, durum: 'tamam' })
    ciz()

    await waitFor(() => expect(api.istek).toHaveBeenCalledTimes(1))
    // Hatadan sonra 2 sn'de değil, daha seyrek yoklanır
    await vi.advanceTimersByTimeAsync(3000)
    expect(api.istek).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('status')).toHaveTextContent('Planı çalıştırma')

    await vi.advanceTimersByTimeAsync(8000)
    expect(api.istek).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(10500)

    expect(await screen.findByText('Plan çalıştırıldı.')).toBeInTheDocument()
    expect(api.istek).toHaveBeenCalledTimes(3)
    await waitFor(() => expect(screen.queryByRole('status')).not.toBeInTheDocument())
  })

  it('bırakılan iş aynı sekmede giren başka kullanıcıya geçmez; ilk kullanıcı dönünce yoklama sürer (YF-09)', async () => {
    api.calistir.mockResolvedValue({ istek_kimligi: ISTEK, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK, durum: 'calisiyor' })
    ciz()
    fireEvent.click(
      within(await satir('Aylık arşiv')).getByRole('button', { name: 'Şimdi Çalıştır' }),
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Planı çalıştırma')
    cleanup()
    api.istek.mockClear()

    kullaniciIleCiz(2)
    const calistir = within(await satir('Aylık arşiv')).getByRole('button', {
      name: 'Şimdi Çalıştır',
    })
    expect(calistir).toBeEnabled()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(api.istek).not.toHaveBeenCalled()
    cleanup()

    ciz()
    expect(await screen.findByRole('status')).toHaveTextContent('Planı çalıştırma')
    expect(api.istek).toHaveBeenCalledWith(ISTEK)
  })

  it('hedef listesi okunamazsa hatayı gösterir; plan formu açılamaz (YF-64)', async () => {
    api.hedefler.mockRejectedValue(hata(500, { kod: 'SUNUCU_HATASI', mesaj: 'x' }))
    ciz()

    expect(await screen.findByRole('button', { name: 'Tekrar Dene' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Yeni Plan' })).toBeDisabled()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()

    api.hedefler.mockResolvedValue([hedef(1, 'Yerel', 'yerel')])
    fireEvent.click(screen.getByRole('button', { name: 'Tekrar Dene' }))
    fireEvent.click(within(await satir('Aylık arşiv')).getByRole('button', { name: 'Düzenle' }))
    expect(await screen.findByRole('dialog')).toHaveTextContent('Planı Düzenle')
  })

  it('kopyası olan plan silinemeyince pasife almayı önerir', async () => {
    api.sil.mockRejectedValue(hata(409, { kod: 'PLAN_KOPYALI', mesaj: 'x', kopya_sayisi: 12 }))
    ciz()

    fireEvent.click(within(await satir('Aylık arşiv')).getByRole('button', { name: 'Sil' }))
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Sil' }))

    expect(
      await screen.findByText(/12 kopyası olduğu için silinemez. Planı pasife alabilirsiniz/),
    ).toBeInTheDocument()
  })
})
