import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Yedek, YedekListesi, YedekOzeti } from '@/features/yedekleme/yedekApi'
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
}))

vi.mock('@/features/yedekleme/ustuneGeriYuklemeApi', async (asil) => ({
  ...(await asil<typeof import('@/features/yedekleme/ustuneGeriYuklemeApi')>()),
  ustuneGeriYukle: api.ustune,
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

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(YEDEK.kimlik))
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
    await waitFor(() => expect(api.sabitCoz).toHaveBeenCalledWith(SABIT.kimlik))

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

    await waitFor(() => expect(api.yedekAl).toHaveBeenCalledWith('yıl sonu kapanışı öncesi'))
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
      'erp.yedekleme.istekler.v1.yedekler',
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
      expect(sessionStorage.getItem('erp.yedekleme.istekler.v1.yedekler')).toBeNull(),
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

    await waitFor(() => expect(api.kopyaGeriYukle).toHaveBeenCalledWith(YEDEK.kimlik, 'mart1'))
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
