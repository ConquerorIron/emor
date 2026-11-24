import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Yedek, YedekListesi, YedekOzeti } from '@/features/yedekleme/yedekApi'
// Uçtan uca: form → gerçek ilerleme sayfası (yalnız testte; sayfalar birbirini içe aktarmaz)
import { UstuneGeriYuklemePage } from '@/pages/UstuneGeriYukleme/UstuneGeriYuklemePage'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { YedeklerPage } from './YedeklerPage'

const api = vi.hoisted(() => ({
  liste: vi.fn(),
  ozet: vi.fn(),
  istek: vi.fn(),
  yedekAl: vi.fn(),
  tara: vi.fn(),
  dogrula: vi.fn(),
  aciklama: vi.fn(),
  sabitle: vi.fn(),
  sabitCoz: vi.fn(),
  sil: vi.fn(),
  kopyaGeriYukle: vi.fn(),
  ustune: vi.fn(),
  ustuneDurum: vi.fn(),
}))

vi.mock('@/features/yedekleme/ustuneGeriYuklemeApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/ustuneGeriYuklemeApi')>()),
  ustuneGeriYukle: api.ustune,
  ustuneDurumGetir: api.ustuneDurum,
}))

vi.mock('@/features/yedekleme/geriYuklemeApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/geriYuklemeApi')>()),
  kopyaGeriYukle: api.kopyaGeriYukle,
}))

vi.mock('@/features/yedekleme/yedekApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/yedekApi')>()),
  yedekleriGetir: api.liste,
  yedekOzetiGetir: api.ozet,
  istekDurumuGetir: api.istek,
  yedekAl: api.yedekAl,
  klasoruTara: api.tara,
  yedegiDogrula: api.dogrula,
  aciklamaKaydet: api.aciklama,
  yedegiSabitle: api.sabitle,
  sabitlemeyiKaldir: api.sabitCoz,
  yedegiSil: api.sil,
}))

const ISTEK_1 = '11111111-1111-4111-8111-111111111111'
const ISTEK_2 = '22222222-2222-4222-8222-222222222222'

const YEDEK: Yedek = {
  kimlik: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  dosya_adi: 'erp_plan_20261121_0200.dump',
  kaynak: 'plan',
  durum: 'tamam',
  dogrulama_durumu: 'ozet_tamam',
  imzali: true,
  ice_alindi: false,
  geri_yuklenebilir: true,
  webden_geri_yuklenebilir: true,
  geri_yukleme_engeli: null,
  boyut: 1677722,
  alinma_zamani: '2026-11-20T23:00:00+00:00',
  sabit: false,
  aciklama: null,
  alan_kullanici: null,
  hata_kodu: null,
  yerel_dosya_var: true,
  kopyalar: [],
}

const SABIT: Yedek = {
  ...YEDEK,
  kimlik: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
  dosya_adi: 'erp_elle_20261231_1800.dump',
  kaynak: 'elle',
  sabit: true,
}

const GUVENILMEYEN: Yedek = {
  ...YEDEK,
  kimlik: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  dosya_adi: 'erp_eski_20250101.dump',
  imzali: false,
  ice_alindi: true,
  geri_yuklenebilir: false,
  webden_geri_yuklenebilir: false,
  geri_yukleme_engeli: 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ',
}

/** Yerel dosyası silinmiş, şifresiz uzak kopyası olan: ekrandan geri yüklenebilir */
const UZAKTA: Yedek = {
  ...YEDEK,
  kimlik: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
  dosya_adi: 'erp_plan_20261110_0200.dump',
  yerel_dosya_var: false,
  kopyalar: [
    {
      hedef_id: 2,
      hedef_ad: 'İkinci disk',
      hedef_tur: 'yerel',
      durum: 'tamam',
      sifreli: false,
      kurtarma_hazir: true,
      tamamlanma_zamani: '2026-11-09T23:10:00+00:00',
      hata_kodu: null,
    },
  ],
}

/** Yerel dosyası silinmiş, yalnız şifreli bulut kopyası olan: özel anahtar gerekir */
const YALNIZ_SIFRELI: Yedek = {
  ...UZAKTA,
  kimlik: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
  dosya_adi: 'erp_plan_20261101_0200.dump',
  webden_geri_yuklenebilir: false,
  geri_yukleme_engeli: 'GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI',
  kopyalar: [
    { ...UZAKTA.kopyalar[0], hedef_id: 3, hedef_ad: 'Bulut', hedef_tur: 's3', sifreli: true },
  ],
}

const OZET: YedekOzeti = {
  son_yerel: '2026-11-20T23:00:00+00:00',
  son_uzak: null,
  son_sinama: null,
  sessizlik_saat: 7,
  siradaki: '2026-11-21T23:00:00+00:00',
  toplam_adet: 3,
  toplam_boyut: 5033166,
  bos_alan: 10737418240,
  toplam_alan: 53687091200,
  canli_veritabani: 'erp',
  ustune_geri_yukleme_hazir: true,
}

function liste(yedekler: Yedek[]): YedekListesi {
  return {
    data: yedekler,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: yedekler.length },
  }
}

function ciz(
  izinler = ['yedekler.goruntule', 'yedekler.al', 'yedekler.sil'],
  sistemYoneticisi = false,
) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} sistemYoneticisi={sistemYoneticisi} yedeklemeAktif>
        <MemoryRouter initialEntries={['/ayarlar/yedekler']}>
          <Routes>
            <Route path="/ayarlar/yedekler" element={<YedeklerPage />} />
            <Route
              path="/ayarlar/geri-yuklemeler/ustune/:islemKimligi"
              element={<p>İlerleme sayfası</p>}
            />
          </Routes>
        </MemoryRouter>
      </SahteOturum>
    </AppProviders>,
  )
}

const satir = async (dosya: string) => (await screen.findByText(dosya)).closest('tr') as HTMLElement

describe('YedeklerPage', () => {
  beforeEach(() => {
    sessionStorage.clear()
    localStorage.clear()
    api.liste.mockResolvedValue(liste([YEDEK, SABIT, GUVENILMEYEN]))
    api.ozet.mockResolvedValue(OZET)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('yalnız görüntüleme izninde işlem düğmesi yoktur', async () => {
    ciz(['yedekler.goruntule'])
    await satir(YEDEK.dosya_adi)

    expect(screen.queryByRole('button', { name: 'Şimdi Yedek Al' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Klasörü Tara' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Doğrula' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sabitle' })).not.toBeInTheDocument()
  })

  it('güvenilmeyen yedek belirgin işaretlidir ve doğrulama / geri yükleme seçeneği yoktur', async () => {
    ciz()

    const guvenilmeyen = await satir(GUVENILMEYEN.dosya_adi)
    expect(within(guvenilmeyen).getByText('Güvenilmeyen')).toBeInTheDocument()
    expect(within(guvenilmeyen).getByText('Eski (içe alındı)')).toBeInTheDocument()
    expect(within(guvenilmeyen).queryByRole('button', { name: 'Doğrula' })).not.toBeInTheDocument()
    expect(
      within(guvenilmeyen).queryByRole('button', { name: /geri yükle/i }),
    ).not.toBeInTheDocument()
    // İmzalı yedekte doğrulama var
    expect(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Doğrula' }),
    ).toBeInTheDocument()
  })

  it('silme onay ister; onaydan sonra doğru yedek için istek bırakılır', async () => {
    api.sil.mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK_1, durum: 'kuyrukta' })
    ciz()

    fireEvent.click(within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Sil' }))

    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent(YEDEK.dosya_adi)
    expect(api.sil).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(YEDEK.kimlik, expect.any(String)))
    expect(await screen.findByRole('status')).toHaveTextContent('Yedek silme')
  })

  it('sabitleme onaysız, sabitlemeyi kaldırma onayla yapılır', async () => {
    api.sabitle.mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.sabitCoz.mockResolvedValue({ istek_kimligi: ISTEK_2, durum: 'kuyrukta' })
    api.istek.mockImplementation((kimlik: string) => Promise.resolve({ kimlik, durum: 'kuyrukta' }))
    ciz()

    const sabit = await satir(SABIT.dosya_adi)
    // Sabit yedek silinemez: Sil kapalı
    expect(within(sabit).getByRole('button', { name: 'Sil' })).toBeDisabled()
    fireEvent.click(within(sabit).getByRole('button', { name: 'Sabitlemeyi kaldır' }))
    const dialog = await screen.findByRole('dialog')
    expect(api.sabitCoz).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Sabitlemeyi kaldır' }))
    await waitFor(() => expect(api.sabitCoz).toHaveBeenCalledWith(SABIT.kimlik, expect.any(String)))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    // İlk istek sürerken yeni işlem başlatılmaz
    const yedek = await satir(YEDEK.dosya_adi)
    expect(within(yedek).getByRole('button', { name: 'Sabitle' })).toBeDisabled()
    expect(api.sabitle).not.toHaveBeenCalled()
  })

  it('yedek alınırken düğmeler kapalı; iş bitince bildirir ve liste ile özeti tazeler', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval'], shouldAdvanceTime: true })
    api.yedekAl.mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.istek
      .mockResolvedValueOnce({ kimlik: ISTEK_1, durum: 'calisiyor' })
      .mockResolvedValue({ kimlik: ISTEK_1, durum: 'tamam', yedek_kimligi: YEDEK.kimlik })
    ciz()
    await satir(YEDEK.dosya_adi)

    fireEvent.click(screen.getByRole('button', { name: 'Şimdi Yedek Al' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Not (isteğe bağlı)'), {
      target: { value: '  yıl sonu kapanışı öncesi ' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Yedek Al' }))

    await waitFor(() =>
      expect(api.yedekAl).toHaveBeenCalledWith('yıl sonu kapanışı öncesi', expect.any(String)),
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Yedek alma — çalışıyor')
    expect(screen.getByRole('button', { name: 'Klasörü Tara' })).toBeDisabled()
    expect(within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Sil' })).toBeDisabled()
    const listeIstegi = api.liste.mock.calls.length
    const ozetIstegi = api.ozet.mock.calls.length

    await vi.advanceTimersByTimeAsync(2100)

    expect(await screen.findByText('Yedek alındı.')).toBeInTheDocument()
    await waitFor(() => expect(api.liste.mock.calls.length).toBeGreaterThan(listeIstegi))
    expect(api.ozet.mock.calls.length).toBeGreaterThan(ozetIstegi)
    await waitFor(() => expect(screen.getByRole('button', { name: 'Klasörü Tara' })).toBeEnabled())
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('sayfadan ayrılıp dönünce süren işi yoklamaya devam eder; hata kodunu çevirerek bildirir', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval'], shouldAdvanceTime: true })
    sessionStorage.setItem(
      'erp.yedekleme.istekler.v2.kullanici.1.yedekler',
      JSON.stringify([{ kimlik: ISTEK_2, tur: 'sil' }]),
    )
    api.istek
      .mockResolvedValueOnce({ kimlik: ISTEK_2, durum: 'calisiyor' })
      .mockResolvedValue({ kimlik: ISTEK_2, durum: 'basarisiz', kod: 'YEDEK_KILIT_MESGUL' })
    ciz()

    expect(await screen.findByRole('status')).toHaveTextContent('Yedek silme — çalışıyor')
    expect(api.istek).toHaveBeenCalledWith(ISTEK_2)
    expect(screen.getByRole('button', { name: 'Şimdi Yedek Al' })).toBeDisabled()

    await vi.advanceTimersByTimeAsync(2100)

    expect(
      await screen.findByText(
        'Yedek silme başarısız: Başka bir yedekleme işlemi sürüyor; bittikten sonra tekrar deneyin.',
      ),
    ).toBeInTheDocument()
    await waitFor(() =>
      expect(sessionStorage.getItem('erp.yedekleme.istekler.v2.kullanici.1.yedekler')).toBeNull(),
    )
    expect(screen.getByRole('button', { name: 'Şimdi Yedek Al' })).toBeEnabled()
  })

  it('süzgeç değişip yeni liste beklenirken eski satırlarda işlem düğmeleri kapalıdır (F1)', async () => {
    ciz()
    const eski = await satir(YEDEK.dosya_adi)
    expect(within(eski).getByRole('button', { name: 'Sil' })).toBeEnabled()

    api.liste.mockReturnValue(new Promise(() => undefined))
    fireEvent.click(screen.getByRole('switch', { name: 'Silinenleri göster' }))

    await waitFor(() =>
      expect(api.liste).toHaveBeenLastCalledWith(
        expect.objectContaining({ silinenler: true }),
        { anahtar: 'alinma_zamani', yon: 'desc' },
        1,
      ),
    )
    // Eski satır ekranda kalır ama üzerinde işlem başlatılamaz
    const bekleyen = await satir(YEDEK.dosya_adi)
    expect(within(bekleyen).getByRole('button', { name: 'Sil' })).toBeDisabled()
    expect(within(bekleyen).getByRole('button', { name: 'Doğrula' })).toBeDisabled()
  })

  it('istek yanıtı beklenirken ardışık tıklamalar yalnız bir istek gönderir (BY-015-R1)', async () => {
    let taraBitir: (kabul: { istek_kimligi: string; durum: 'kuyrukta' }) => void = () => undefined
    api.tara.mockReturnValue(new Promise((coz) => (taraBitir = coz)))
    api.istek.mockResolvedValue({ kimlik: ISTEK_1, durum: 'kuyrukta' })
    ciz()
    const yedek = await satir(YEDEK.dosya_adi)

    const tara = screen.getByRole('button', { name: 'Klasörü Tara' })
    fireEvent.click(tara)
    fireEvent.click(tara)
    // Yanıt gelmeden satır işlemi de başlatılamaz
    fireEvent.click(within(yedek).getByRole('button', { name: 'Sabitle' }))
    fireEvent.click(within(yedek).getByRole('button', { name: 'Doğrula' }))

    await waitFor(() => expect(tara).toBeDisabled())
    expect(within(yedek).getByRole('button', { name: 'Sabitle' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Şimdi Yedek Al' })).toBeDisabled()
    expect(api.tara).toHaveBeenCalledTimes(1)
    expect(api.sabitle).not.toHaveBeenCalled()
    expect(api.dogrula).not.toHaveBeenCalled()

    taraBitir({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    expect(await screen.findByRole('status')).toHaveTextContent('Klasör tarama')
  })

  it('yanıtı alınamayan iş yeniden denenince aynı istek anahtarını taşır; kabulden sonra yeni iş yeni anahtar alır (BY-014-R2)', async () => {
    api.tara
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.sabitle.mockRejectedValueOnce(new Error('Network Error'))
    api.istek.mockResolvedValue({ kimlik: ISTEK_1, durum: 'tamam', tarama: { eklenen: 0 } })
    ciz()
    const yedek = await satir(YEDEK.dosya_adi)
    const tara = screen.getByRole('button', { name: 'Klasörü Tara' })

    fireEvent.click(tara)
    await waitFor(() => expect(api.tara).toHaveBeenCalledTimes(1))
    // Arada başka bir iş: kendi anahtarını alır, taramanınkini bozmaz
    await waitFor(() =>
      expect(within(yedek).getByRole('button', { name: 'Sabitle' })).toBeEnabled(),
    )
    fireEvent.click(within(yedek).getByRole('button', { name: 'Sabitle' }))
    await waitFor(() => expect(api.sabitle).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(tara).toBeEnabled())
    fireEvent.click(tara)
    await waitFor(() => expect(api.tara).toHaveBeenCalledTimes(2))

    const ilk = api.tara.mock.calls[0][0] as string
    expect(ilk).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(api.tara.mock.calls[1][0]).toBe(ilk)
    expect(api.sabitle.mock.calls[0][1]).not.toBe(ilk)

    // Kabul geldi ve iş bitti: aynı işin yeni gönderimi yeni bir iştir
    expect(await screen.findByText(/Yedek klasörü tarandı/)).toBeInTheDocument()
    await waitFor(() => expect(tara).toBeEnabled())
    fireEvent.click(tara)
    await waitFor(() => expect(api.tara).toHaveBeenCalledTimes(3))
    expect(api.tara.mock.calls[2][0]).not.toBe(ilk)
  })

  it('yanıtı kaybolan iş sayfa yenilendikten sonra da aynı istek anahtarını taşır; kabulden sonra yeni iş yeni anahtar alır (BY-014-R3)', async () => {
    // İlk istek sunucuda kabul edildi ama yanıt ulaşmadı
    api.yedekAl
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK_1, durum: 'tamam', yedek_kimligi: YEDEK.kimlik })
    const yedekAl = async () => {
      await satir(YEDEK.dosya_adi)
      await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Şimdi Yedek Al' })).toBeEnabled(),
      )
      fireEvent.click(screen.getByRole('button', { name: 'Şimdi Yedek Al' }))
      fireEvent.click(
        within(await screen.findByRole('dialog')).getByRole('button', { name: 'Yedek Al' }),
      )
    }
    const bekleyenDepo = 'erp.yedekleme.istekAnahtarlari.v2.kullanici.1'

    ciz()
    await yedekAl()
    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledTimes(1))
    const ilk = api.yedekAl.mock.calls[0][1] as string
    expect(localStorage.getItem(bekleyenDepo)).toContain(ilk)

    // Sayfa yenilendi (bileşen yeniden kuruldu): aynı iş aynı anahtarla
    cleanup()
    ciz()
    await yedekAl()
    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledTimes(2))
    expect(api.yedekAl.mock.calls[1][1]).toBe(ilk)

    // Kabul geldi: bekleyen anahtar silinir; aynı işin yeni gönderimi yeni bir iştir
    await waitFor(() => expect(localStorage.getItem(bekleyenDepo)).toBeNull())
    expect(await screen.findByText('Yedek alındı.')).toBeInTheDocument()
    await yedekAl()
    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledTimes(3))
    expect(api.yedekAl.mock.calls[2][1]).not.toBe(ilk)
  })

  it('açıklamalı işin yanıtı kaybolunca depoda açıklama metni değil eylemin SHA-256 özeti durur; yenilemeden sonra anahtar aynı kalır (BY-014-R4)', async () => {
    const aciklama = 'Yil sonu kapanisi oncesi GIZLI-NOT-42'
    api.yedekAl.mockRejectedValue(new Error('Network Error'))
    const yedekAl = async () => {
      await satir(YEDEK.dosya_adi)
      await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Şimdi Yedek Al' })).toBeEnabled(),
      )
      fireEvent.click(screen.getByRole('button', { name: 'Şimdi Yedek Al' }))
      const dialog = await screen.findByRole('dialog')
      fireEvent.change(within(dialog).getByLabelText('Not (isteğe bağlı)'), {
        target: { value: aciklama },
      })
      fireEvent.click(within(dialog).getByRole('button', { name: 'Yedek Al' }))
    }
    const depodakiDegerler = () =>
      Array.from({ length: localStorage.length }, (_, i) =>
        localStorage.getItem(localStorage.key(i) ?? ''),
      ).join('\n')

    ciz()
    await yedekAl()
    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledTimes(1))
    expect(api.yedekAl.mock.calls[0][0]).toBe(aciklama)
    const ilk = api.yedekAl.mock.calls[0][1] as string

    expect(depodakiDegerler()).not.toContain('GIZLI-NOT-42')
    expect(
      JSON.parse(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v2.kullanici.1') ?? '[]'),
    ).toEqual([
      {
        // SHA-256('{"aciklama":"Yil sonu kapanisi oncesi GIZLI-NOT-42","tur":"yedek_al"}') — sha256sum ile
        ozet: '65dfa33d9941bf0b75d6a64ef877160809acb6f5115f78ce7a49c99e84c22d88',
        anahtar: ilk,
        zaman: expect.any(Number),
      },
    ])

    // Sayfa yenilendi: aynı açıklamayla aynı iş aynı anahtarı taşır
    cleanup()
    ciz()
    await yedekAl()
    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledTimes(2))
    expect(api.yedekAl.mock.calls[1][1]).toBe(ilk)
    expect(depodakiDegerler()).not.toContain('GIZLI-NOT-42')
  })

  it('süresi geçmiş bekleyen anahtar kullanılmaz; depo erişilemezse anahtar bellekte yaşar (BY-014-R3)', async () => {
    const eski = '33333333-3333-4333-8333-333333333333'
    localStorage.setItem(
      'erp.yedekleme.istekAnahtarlari.v2.kullanici.1',
      JSON.stringify([
        // ozet: SHA-256('{"tur":"tara"}') — sha256sum ile hesaplandı
        {
          ozet: '0cb83e4560026319f10e3ae06cd2704efdf2d3b37ed3c14477f1c04d428cb94d',
          anahtar: eski,
          zaman: Date.now() - 16 * 60 * 1000,
        },
      ]),
    )
    api.tara.mockRejectedValue(new Error('Network Error'))
    ciz()
    const tara = await screen.findByRole('button', { name: 'Klasörü Tara' })
    await satir(YEDEK.dosya_adi)

    fireEvent.click(tara)
    await waitFor(() => expect(api.tara).toHaveBeenCalledTimes(1))
    expect(api.tara.mock.calls[0][0]).not.toBe(eski)

    // Gizli pencere / kota: depo hata verir, aynı çizimde yeniden deneme yine aynı anahtarı taşır
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('kapalı', 'SecurityError')
    })
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('dolu', 'QuotaExceededError')
    })
    try {
      await waitFor(() => expect(tara).toBeEnabled())
      fireEvent.click(tara)
      await waitFor(() => expect(api.tara).toHaveBeenCalledTimes(2))
      expect(api.tara.mock.calls[1][0]).toBe(api.tara.mock.calls[0][0])
    } finally {
      getItem.mockRestore()
      setItem.mockRestore()
    }
  })

  it('yalnız şifreli kopyası olan yedekte geri yükleme yerine kurtarma yolu yazılır; yerel ve şifresiz uzak kopyada geri yükleme vardır (BY-027-R1)', async () => {
    api.liste.mockResolvedValue(liste([YEDEK, UZAKTA, YALNIZ_SIFRELI]))
    ciz(['yedekler.goruntule', 'yedekler.al', 'geri_yukleme.kopya'], true)

    for (const yedek of [YEDEK, UZAKTA]) {
      const satiri = await satir(yedek.dosya_adi)
      expect(within(satiri).getByRole('button', { name: 'Farklı adla geri yükle' })).toBeEnabled()
      expect(within(satiri).getByRole('button', { name: 'Üstüne geri yükle' })).toBeEnabled()
      expect(within(satiri).getByRole('button', { name: 'Doğrula' })).toBeEnabled()
    }

    // Doğrulama da geri yükleme motorunu kullanır: yalnız şifreli kopyada o da yok
    const sifreli = await satir(YALNIZ_SIFRELI.dosya_adi)
    expect(within(sifreli).queryByRole('button', { name: /geri yükle/i })).not.toBeInTheDocument()
    expect(within(sifreli).queryByRole('button', { name: 'Doğrula' })).not.toBeInTheDocument()
    expect(sifreli).toHaveTextContent('yedek:sifre-coz')
    expect(sifreli).toHaveTextContent('docs/yedekleme/kurtarma.md')
  })

  it('aktarımı tamam ama kurtarma paketi gitmemiş şifreli kopyada "kurtarma paketi bekleniyor" yazar (BY-010-R2)', async () => {
    const paketsiz: Yedek = {
      ...YALNIZ_SIFRELI,
      kimlik: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
      dosya_adi: 'erp_plan_20261121_0200.dump',
      kopyalar: [{ ...YALNIZ_SIFRELI.kopyalar[0], kurtarma_hazir: false }],
    }
    api.liste.mockResolvedValue(liste([YALNIZ_SIFRELI, paketsiz]))
    ciz()

    expect(await satir(paketsiz.dosya_adi)).toHaveTextContent('kurtarma paketi bekleniyor')
    expect(await satir(YALNIZ_SIFRELI.dosya_adi)).not.toHaveTextContent(
      'kurtarma paketi bekleniyor',
    )
  })

  it('hedefi tanımsız kopyalar olaydaki adlarıyla ve "konumu doğrulanamadı" uyarısıyla ayrı ayrı görünür (BY-028-R9)', async () => {
    const tanimsiz = {
      ...UZAKTA.kopyalar[0],
      hedef_id: null,
      hedef_tur: null,
      durum: 'konum_belirsiz' as const,
      hata_kodu: 'KOPYA_HEDEFI_TANIMSIZ',
    }
    const yedek: Yedek = {
      ...UZAKTA,
      webden_geri_yuklenebilir: false,
      geri_yukleme_engeli: 'GERI_YUKLEME_YEDEK_DOSYASI_YOK',
      kopyalar: [
        { ...tanimsiz, hedef_ad: 'Eski NAS' },
        { ...tanimsiz, hedef_ad: 'Eski FTP' },
      ],
    }
    api.liste.mockResolvedValue(liste([yedek]))
    const konsolHatasi = vi.spyOn(console, 'error').mockImplementation(() => {})
    ciz()

    const s = await satir(yedek.dosya_adi)
    expect(s).toHaveTextContent('Eski NAS')
    expect(s).toHaveTextContent('Eski FTP')
    const rozetler = within(s).getAllByText('Konumu doğrulanamadı')
    expect(rozetler).toHaveLength(2)
    for (const rozet of rozetler) {
      expect(rozet).toHaveAttribute('title', expect.stringContaining('hedefin tanımı yok'))
    }
    expect(konsolHatasi.mock.calls.flat().join(' ')).not.toContain('same key')
    konsolHatasi.mockRestore()
  })

  it('"Farklı adla geri yükle" yalnız geri_yukleme.kopya izniyle ve geri yüklenebilir satırda vardır', async () => {
    ciz(['yedekler.goruntule', 'yedekler.al', 'yedekler.sil'])
    await satir(YEDEK.dosya_adi)
    expect(screen.queryByRole('button', { name: 'Farklı adla geri yükle' })).not.toBeInTheDocument()
    cleanup()

    ciz(['yedekler.goruntule', 'geri_yukleme.kopya'])
    expect(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Farklı adla geri yükle' }),
    ).toBeInTheDocument()
    expect(
      within(await satir(GUVENILMEYEN.dosya_adi)).queryByRole('button', {
        name: 'Farklı adla geri yükle',
      }),
    ).not.toBeInTheDocument()
    // Yalnız kopya izninde diğer işlemler yok
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })

  it('farklı adla geri yükleme canlıya dokunmadığını söyler; geçersiz eki göndermez', async () => {
    api.kopyaGeriYukle.mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({ kimlik: ISTEK_1, durum: 'kuyrukta' })
    ciz(['yedekler.goruntule', 'geri_yukleme.kopya'])

    fireEvent.click(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Farklı adla geri yükle' }),
    )
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('canlı veritabanına (erp) hiçbir şekilde dokunulmaz')
    expect(dialog).toHaveTextContent('otomatik silinir')

    const ek = within(dialog).getByLabelText('Ad eki (isteğe bağlı)')
    fireEvent.change(ek, { target: { value: 'Kopya-1' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Geri Yükle' }))
    expect(await within(dialog).findByText(/yalnız küçük harf/)).toBeInTheDocument()
    expect(api.kopyaGeriYukle).not.toHaveBeenCalled()

    fireEvent.change(ek, { target: { value: 'mart1' } })
    expect(dialog).toHaveTextContent('erp_geri_YYYYAAGG_SSDDss_mart1')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Geri Yükle' }))

    await waitFor(() =>
      expect(api.kopyaGeriYukle).toHaveBeenCalledWith(YEDEK.kimlik, 'mart1', expect.any(String)),
    )
    expect(await screen.findByRole('status')).toHaveTextContent('Farklı adla geri yükleme')
  })

  it('klasör taraması bitince sonuçtaki sayıları bildirir', async () => {
    api.tara.mockResolvedValue({ istek_kimligi: ISTEK_1, durum: 'kuyrukta' })
    api.istek.mockResolvedValue({
      kimlik: ISTEK_1,
      durum: 'tamam',
      tarama: { eklenen: 2, guvenilmeyen: 1, guvenilir: 5 },
    })
    ciz()
    await satir(YEDEK.dosya_adi)

    fireEvent.click(screen.getByRole('button', { name: 'Klasörü Tara' }))

    expect(
      await screen.findByText(
        'Yedek klasörü tarandı: 2 yeni kayıt, 1 güvenilmeyen; liste güncellendi.',
      ),
    ).toBeInTheDocument()
  })

  it('"Üstüne geri yükle" yalnız sistem yöneticisinde ve içe alınmamış, geri yüklenebilir yedekte vardır', async () => {
    const eskiImzali: Yedek = {
      ...YEDEK,
      kimlik: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
      dosya_adi: 'erp_ice_alinan_20250601.dump',
      ice_alindi: true,
    }
    api.liste.mockResolvedValue(liste([YEDEK, GUVENILMEYEN, eskiImzali]))
    ciz(['yedekler.goruntule', 'yedekler.al', 'yedekler.sil', 'geri_yukleme.kopya'])
    await satir(YEDEK.dosya_adi)
    expect(screen.queryByRole('button', { name: 'Üstüne geri yükle' })).not.toBeInTheDocument()
    cleanup()

    ciz(['yedekler.goruntule'], true)
    expect(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Üstüne geri yükle' }),
    ).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: 'Üstüne geri yükle' })).toHaveLength(1)
  })

  it('servis çalıştırıcısı yokken (geçiş kipi) "Üstüne geri yükle" kapalıdır ve nedenini söyler (YF-63)', async () => {
    api.ozet.mockResolvedValue({ ...OZET, ustune_geri_yukleme_hazir: false })
    ciz(['yedekler.goruntule'], true)

    const dugme = within(await satir(YEDEK.dosya_adi)).getByRole('button', {
      name: 'Üstüne geri yükle',
    })
    await waitFor(() => expect(dugme).toHaveAttribute('title', expect.stringContaining('servis')))
    expect(dugme).toBeDisabled()
    fireEvent.click(dugme)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('üstüne geri yükleme yanıtı beklenirken ikinci gönderim ikinci işlem başlatmaz (F5, YF-10)', async () => {
    api.ustune.mockReturnValue(new Promise(() => undefined))
    ciz(['yedekler.goruntule'], true)

    fireEvent.click(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Üstüne geri yükle' }),
    )
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(await within(dialog).findByLabelText(/canlı veritabanının adını/), {
      target: { value: 'erp' },
    })
    const baslat = within(dialog).getByRole('button', { name: 'Üstüne geri yüklemeyi başlat' })
    fireEvent.click(baslat)
    fireEvent.click(baslat)

    await waitFor(() => expect(api.ustune).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(baslat).toBeDisabled())
    expect(api.ustune).toHaveBeenCalledTimes(1)
  })

  it('tarayıcı deposu yazılamasa da ilerleme sayfası başlatılan işlemi anahtarıyla izler (YF-10)', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-21T10:00:00Z'))
    api.ustune.mockResolvedValue({
      istek_kimligi: ISTEK_1,
      islem_kimligi: ISTEK_1,
      erisim_anahtari: 'a'.repeat(64),
      erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
      durum: 'kuyrukta',
    })
    api.ustuneDurum.mockReturnValue(new Promise(() => undefined))
    render(
      <AppProviders>
        <SahteOturum izinler={['yedekler.goruntule']} sistemYoneticisi yedeklemeAktif>
          <MemoryRouter initialEntries={['/ayarlar/yedekler']}>
            <Routes>
              <Route path="/ayarlar/yedekler" element={<YedeklerPage />} />
              <Route
                path="/ayarlar/geri-yuklemeler/ustune/:islemKimligi"
                element={<UstuneGeriYuklemePage />}
              />
            </Routes>
          </MemoryRouter>
        </SahteOturum>
      </AppProviders>,
    )
    fireEvent.click(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Üstüne geri yükle' }),
    )
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(await within(dialog).findByLabelText(/canlı veritabanının adını/), {
      target: { value: 'erp' },
    })
    const kota = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('dolu', 'QuotaExceededError')
    })

    try {
      fireEvent.click(within(dialog).getByRole('button', { name: 'Üstüne geri yüklemeyi başlat' }))

      await waitFor(() =>
        expect(api.ustuneDurum).toHaveBeenCalledWith(ISTEK_1, 'a'.repeat(64), null),
      )
      expect(localStorage.getItem('erp.yedekleme.ustuneGeriYukleme.v1')).toBeNull()
      expect(screen.queryByText(/erişim anahtarı bu tarayıcıda yok/)).not.toBeInTheDocument()
    } finally {
      kota.mockRestore()
    }
  })

  it('üstüne geri yükleme yedeğin tarihini ve kaybı yazar; canlı adı aynen yazılmadan başlamaz', async () => {
    api.ustune.mockResolvedValue({
      istek_kimligi: ISTEK_1,
      islem_kimligi: ISTEK_1,
      erisim_anahtari: 'a'.repeat(64),
      erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
      durum: 'kuyrukta',
    })
    localStorage.clear()
    ciz(['yedekler.goruntule'], true)

    fireEvent.click(
      within(await satir(YEDEK.dosya_adi)).getByRole('button', { name: 'Üstüne geri yükle' }),
    )
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent(
      "Bu yedek 21.11.2026 02:00'da alındı (erp_plan_20261121_0200.dump). O andan sonra girilen her şey silinecek.",
    )
    expect(dialog).toHaveTextContent('erp_onceki_*')
    const baslat = within(dialog).getByRole('button', { name: 'Üstüne geri yüklemeyi başlat' })
    expect(baslat).toBeDisabled()

    const ad = await within(dialog).findByLabelText(/canlı veritabanının adını aynen yazın: erp/)
    fireEvent.change(ad, { target: { value: 'ERP' } })
    expect(baslat).toBeDisabled()
    fireEvent.change(ad, { target: { value: 'erp ' } })
    expect(baslat).toBeDisabled()
    fireEvent.change(ad, { target: { value: 'erp' } })
    expect(baslat).toBeEnabled()
    fireEvent.click(baslat)

    await waitFor(() => expect(api.ustune).toHaveBeenCalledWith(YEDEK.kimlik, 'erp'))
    // Anahtar yalnız bu yanıtta gelir: tarayıcıya kaydedilir, ilerleme sayfasına gidilir
    expect(await screen.findByText('İlerleme sayfası')).toBeInTheDocument()
    expect(JSON.parse(localStorage.getItem('erp.yedekleme.ustuneGeriYukleme.v1') ?? '{}')).toEqual({
      islem_kimligi: ISTEK_1,
      erisim_anahtari: 'a'.repeat(64),
      erisim_anahtari_bitis: '2026-11-21T18:00:00+00:00',
      karar_istegi: null,
    })
  })
})
