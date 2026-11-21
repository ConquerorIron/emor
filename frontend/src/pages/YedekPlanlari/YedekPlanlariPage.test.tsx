import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Hedef } from '@/features/yedekleme/hedefApi'
import { AppProviders } from '@/providers/AppProviders'
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

const hedef = (id: number, ad: string, tur: string): Hedef => ({
  id,
  ad,
  tur,
  aktif: true,
  sunucu_disi: tur !== 'yerel',
  baglanti: {},
  gizli_kayitli: {},
  plan_sayisi: 0,
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
    api.planlar.mockResolvedValue([AYLIK])
    api.hedefler.mockResolvedValue([hedef(1, 'Yerel', 'yerel'), hedef(3, 'Ofis FTP', 'ftp')])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
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

    await waitFor(() => expect(api.calistir).toHaveBeenCalledWith(5))
    expect(await screen.findByRole('status')).toHaveTextContent('Planı çalıştırma — çalışıyor')
    expect(calistir).toBeDisabled()
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
