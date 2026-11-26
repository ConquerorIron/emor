import { AxiosError, AxiosHeaders } from 'axios'
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { EFaturaListesi } from '@/features/efatura/efaturaApi'
import type { IceAktarimOnizlemesi } from '@/features/efatura/kaynakApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { LogoFaturalariPage } from './LogoFaturalariPage'

const api = vi.hoisted(() => ({
  faturalar: vi.fn(),
  kontrol: vi.fn(),
  aktar: vi.fn(),
  yuklemeler: vi.fn(),
  geriAl: vi.fn(),
  kolonlar: vi.fn(),
}))

const toastlar = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))
vi.mock('sonner', async (asil) => ({ ...(await asil<typeof import('sonner')>()), toast: toastlar }))

vi.mock('@/features/efatura/kaynakApi', () => ({
  kaynakFaturalariGetir: api.faturalar,
  kaynakPdfGetir: vi.fn(),
  kaynakExcelIndir: vi.fn(),
  iceAktarimKolonlari: api.kolonlar,
  iceAktarimKontrol: api.kontrol,
  iceAktar: api.aktar,
  yuklemeleriGetir: api.yuklemeler,
  yuklemeyiGeriAl: api.geriAl,
}))

const BOS_LISTE: EFaturaListesi = {
  data: [],
  meta: { current_page: 1, last_page: 1, total: 0, from: null },
  ozet: [],
  secenekler: {
    durumlar: [],
    para_birimleri: [],
    tipler: [],
    istisna_kodlari: [],
    gizlenen_adet: 0,
  },
  kapsam: { ortam: 'canli' },
}

const HATASIZ: IceAktarimOnizlemesi = {
  hata: null,
  eksik_kolonlar: [],
  belge_turu: null,
  hatalar: [],
  ozet: { okunan: 581, yeni: 581, guncellenecek: 0, degismeyen: 0, red: 266 },
}

function ciz(izinler: string[]) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <LogoFaturalariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

function dosyaSec(pencere: HTMLElement, ad: string): File {
  const dosya = new File(['xlsx'], ad, {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  fireEvent.change(within(pencere).getByLabelText('Excel dosyası (.xlsx)'), {
    target: { files: [dosya] },
  })

  return dosya
}

describe('LogoFaturalariPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-25T09:00:00'))
    localStorage.clear()
    api.faturalar.mockResolvedValue(BOS_LISTE)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('içe aktarım izni yoksa düğmesi yok; liste Logo ucundan istenir', async () => {
    ciz(['logo_faturalari.goruntule'])

    await waitFor(() => expect(api.faturalar).toHaveBeenCalled())
    expect(api.faturalar.mock.calls[0][0]).toBe('logo')
    expect(screen.queryByRole('button', { name: "Excel'den İçe Aktar" })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Logo Gelen Faturalar' })).toBeInTheDocument()
  })

  it('Kaydet yalnız hatasız kontrolden sonra açılır; hatalar nedenine göre gruplu görünür, dosya değişince kontrol düşer', async () => {
    api.kontrol
      .mockResolvedValueOnce({
        ...HATASIZ,
        hatalar: [
          { alan: 'ettn', deger: 'GECERSIZ', kod: 'ettn_gecersiz', adet: 2, satirlar: [4, 9] },
        ],
      })
      .mockResolvedValueOnce(HATASIZ)
    api.aktar.mockResolvedValue({
      id: 7,
      belge_turu: null,
      dosya_adi: 'logo.xlsx',
      kullanici_adi: 'Ayşe',
      yeni: 581,
      guncellenen: 0,
      degismeyen: 0,
      zaman: '2026-11-25T06:00:00+00:00',
    })
    ciz(['logo_faturalari.goruntule', 'logo_faturalari.ice_aktar'])

    fireEvent.click(await screen.findByRole('button', { name: "Excel'den İçe Aktar" }))
    const pencere = await screen.findByRole('dialog')
    const kaydet = within(pencere).getByRole('button', { name: 'Kaydet' })
    expect(kaydet).toBeDisabled()

    dosyaSec(pencere, 'hatali.xlsx')
    fireEvent.click(within(pencere).getByRole('button', { name: 'Kontrol Et' }))
    expect(await within(pencere).findByText(/Dosyada hatalı satırlar var/)).toBeInTheDocument()
    expect(within(pencere).getByText('ETTN geçerli değil')).toBeInTheDocument()
    expect(within(pencere).getByText('4, 9')).toBeInTheDocument()
    expect(kaydet).toBeDisabled()

    const dosya = dosyaSec(pencere, 'logo.xlsx')
    // Yeni dosya: önceki sonuç geçersiz
    expect(within(pencere).queryByText(/Dosyada hatalı satırlar var/)).not.toBeInTheDocument()
    fireEvent.click(within(pencere).getByRole('button', { name: 'Kontrol Et' }))
    expect(await within(pencere).findByText('581 satır hatasız okundu.')).toBeInTheDocument()
    expect(within(pencere).getByText(/RED \/ iptal: 266/)).toBeInTheDocument()
    expect(kaydet).toBeEnabled()

    fireEvent.click(kaydet)
    fireEvent.click(kaydet)
    await waitFor(() => expect(api.aktar).toHaveBeenCalledTimes(1))
    expect(api.aktar).toHaveBeenCalledWith('logo', { dosya })
    await waitFor(() =>
      expect(toastlar.success).toHaveBeenCalledWith(
        'Liste kaydedildi: 581 yeni, 0 güncellenen, 0 değişmeyen.',
      ),
    )
    // Yeni faturalar listede görünsün
    await waitFor(() => expect(api.faturalar).toHaveBeenCalledTimes(2))
  })

  it("Excel'den kopyala-yapıştır: başlıkla yapıştırılan kolonlar adlarından eşlenir, standart sırayla kontrol edilir; hatalı satır işaretlenir, Kaydet aynı metni gönderir", async () => {
    api.kolonlar.mockResolvedValue([
      {
        belge_turu: null,
        kolonlar: [
          { alan: 'ettn', basliklar: ['ETTN'] },
          { alan: 'belge_no', basliklar: ['Fatura No'] },
          { alan: 'tutar', basliklar: ['Toplam Tutar'] },
        ],
      },
    ])
    api.kontrol
      .mockResolvedValueOnce({
        ...HATASIZ,
        hatalar: [{ alan: 'tutar', deger: 'abc', kod: 'tutar_gecersiz', adet: 1, satirlar: [2] }],
      })
      .mockResolvedValueOnce({ ...HATASIZ, ozet: { ...HATASIZ.ozet, okunan: 2, yeni: 2 } })
    api.aktar.mockResolvedValue({
      id: 8,
      belge_turu: null,
      dosya_adi: 'Kopyala-yapıştır',
      kullanici_adi: 'Ayşe',
      yeni: 2,
      guncellenen: 0,
      degismeyen: 0,
      zaman: '2026-11-26T06:00:00+00:00',
    })
    ciz(['logo_faturalari.goruntule', 'logo_faturalari.ice_aktar'])

    fireEvent.click(await screen.findByRole('button', { name: "Excel'den İçe Aktar" }))
    const pencere = await screen.findByRole('dialog')
    fireEvent.click(within(pencere).getByRole('tab', { name: 'Kopyala-yapıştır' }))
    const tablo = await within(pencere).findByRole('grid', { name: 'Yapıştırma tablosu' })
    expect(
      within(tablo)
        .getAllByRole('columnheader')
        .map((th) => th.textContent),
    ).toEqual(['#', 'ETTN', 'Fatura No', 'Toplam Tutar'])

    // Excel'in başlık satırıyla, başka sırada ve fazla kolonla kopyalanmış iki satır
    fireEvent.paste(tablo, {
      clipboardData: {
        getData: () =>
          'Fatura No\tGönderici Adı\tETTN\tToplam Tutar\r\nAYD1\tAydoğan\tE-1\t1.500,25\r\nAYD2\tMetro\tE-2\tabc\r\n',
      },
    })
    const metin = 'E-1\tAYD1\t1.500,25\nE-2\tAYD2\tabc'
    fireEvent.click(within(pencere).getByRole('button', { name: 'Kontrol Et' }))
    await waitFor(() =>
      expect(api.kontrol).toHaveBeenCalledWith('logo', { metin, belge_turu: null }),
    )
    expect(await within(pencere).findByText(/Dosyada hatalı satırlar var/)).toBeInTheDocument()
    expect(within(tablo).getByRole('rowheader', { name: '2' })).toHaveAttribute(
      'title',
      expect.stringMatching(/abc/),
    )
    expect(within(pencere).getByRole('button', { name: 'Kaydet' })).toBeDisabled()

    fireEvent.click(within(pencere).getByRole('button', { name: 'Kontrol Et' }))
    expect(await within(pencere).findByText('2 satır hatasız okundu.')).toBeInTheDocument()
    fireEvent.click(within(pencere).getByRole('button', { name: 'Kaydet' }))
    await waitFor(() => expect(api.aktar).toHaveBeenCalledWith('logo', { metin, belge_turu: null }))
  })

  it('yükleme onayla geri alınır; elle işlenmiş fatura varsa sunucunun hatası gösterilir', async () => {
    api.yuklemeler.mockResolvedValue([
      {
        id: 7,
        belge_turu: null,
        dosya_adi: 'logo.xlsx',
        kullanici_adi: 'Ayşe',
        yeni: 581,
        guncellenen: 0,
        degismeyen: 0,
        zaman: '2026-11-25T06:00:00+00:00',
      },
    ])
    api.geriAl.mockRejectedValueOnce(
      new AxiosError('Conflict', '409', undefined, undefined, {
        status: 409,
        statusText: 'Conflict',
        headers: {},
        config: { headers: new AxiosHeaders() },
        data: { kod: 'YUKLEME_ELLE_ISLENMIS' },
      }),
    )
    ciz(['logo_faturalari.goruntule', 'logo_faturalari.ice_aktar'])

    fireEvent.click(await screen.findByRole('button', { name: 'Yüklemeler' }))
    const pencere = await screen.findByRole('dialog')
    expect(await within(pencere).findByText('logo.xlsx')).toBeInTheDocument()
    fireEvent.click(within(pencere).getByRole('button', { name: 'Geri Al' }))
    const onay = await screen.findByRole('dialog', { name: 'Yüklemeyi Geri Al' })
    expect(within(onay).getByText(/eklediği 581 belge silinecek/)).toBeInTheDocument()
    fireEvent.click(within(onay).getByRole('button', { name: 'Geri Al' }))

    await waitFor(() => expect(api.geriAl).toHaveBeenCalledWith('logo', 7))
    await waitFor(() =>
      expect(toastlar.error).toHaveBeenCalledWith(expect.stringMatching(/geri alınamaz/)),
    )
  })
})
