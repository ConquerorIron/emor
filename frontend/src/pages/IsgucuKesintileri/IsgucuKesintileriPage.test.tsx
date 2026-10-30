import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { IceAktarimOnizlemesi, KesintiSecenekleri } from '@/features/kesinti/icmalApi'
import type { IsgucuKesintisi } from '@/features/kesinti/isgucuApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { IsgucuKesintileriPage } from './IsgucuKesintileriPage'

const api = vi.hoisted(() => ({
  secenekler: vi.fn(),
  liste: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
  onizle: vi.fn(),
  aktar: vi.fn(),
  yuklemeler: vi.fn(),
  geriAl: vi.fn(),
  kolonlar: vi.fn(),
}))

vi.mock('@/features/kesinti/icmalApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/icmalApi')>()),
  kesintiSecenekleriniGetir: api.secenekler,
}))

vi.mock('@/features/kesinti/isgucuApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/kesinti/isgucuApi')>()),
  isgucuApi: {
    listele: api.liste,
    kaydet: api.kaydet,
    sil: api.sil,
    onizle: api.onizle,
    iceAktar: api.aktar,
    yuklemeler: api.yuklemeler,
    geriAl: api.geriAl,
    kolonlar: api.kolonlar,
  },
}))

const SECENEKLER: KesintiSecenekleri = {
  kesinti_turleri: [{ id: 1, kod: 'İŞGÜCÜ', ad: 'İşgücü' }],
  firmalar: [{ id: 3, kod: 'HAB', ad: 'HAB' }],
  projeler: [{ id: 5, kod: 'ZONE 10', ad: 'Zone 10' }],
  para_birimleri: [{ id: 7, kod: 'TL', ad: 'Türk Lirası' }],
}

const SATIR: IsgucuKesintisi = {
  id: 11,
  yukleme_id: 2,
  kesinti_turu: SECENEKLER.kesinti_turleri[0],
  firma: SECENEKLER.firmalar[0],
  proje: SECENEKLER.projeler[0],
  para_birimi: SECENEKLER.para_birimleri[0],
  tarih: '2026-03-27',
  mahal: 'ZONE 10',
  kaynak_firma: 'MFK',
  kaynak_fis_no: '10397',
  adi_soyadi: 'FIRAT AKYILDIZ',
  vasif: 'niteliksiz',
  mesai_turu: 'normal',
  yansitma_turu: 'hab',
  tanim: 'DÜZ İŞÇİ',
  gorev: 'KABA',
  departman: 'KABA YAPI',
  aktivite: null,
  miktar_saat: '0.00',
  miktar_yevmiye: '1.00',
  birim_fiyat: '2562.1521',
  toplam_tutar: '2562.15',
  iptal_edilen_tutar: '0.00',
  yansitilan_tutar: '2562.15',
  kesilen_tutar: '2000.00',
  kalan_tutar: '562.15',
}

const LISTE = {
  data: [SATIR],
  meta: { current_page: 1, last_page: 1, total: 1 },
  ozet: [
    {
      para_birimi: 'TL',
      satir_sayisi: 1,
      toplam_tutar: '2562.15',
      iptal_edilen_tutar: '0.00',
      yansitilan_tutar: '2562.15',
      kesilen_tutar: '2000.00',
      kalan_tutar: '562.15',
    },
  ],
}

function ciz(izinler = ['isgucu_kesintileri.goruntule', 'isgucu_kesintileri.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <IsgucuKesintileriPage />
      </SahteOturum>
    </AppProviders>,
  )
}

/** Kolon tanımı: standart sıra + kabul edilen başlık adları (backend'den) */
const KOLONLAR = [
  { alan: 'kesinti_turu', basliklar: ['KESİNTİ TÜRÜ'] },
  { alan: 'taseron', basliklar: ['TAŞERON ADI', 'KESİNTİ YAPILACAK FİRMA'] },
  { alan: 'adi_soyadi', basliklar: ['ADI SOYADI'] },
]

/** Excel'den kopyalanmış metni yapıştırma tablosuna Ctrl+V ile yapıştırır */
async function aktarimiAc(pano: string) {
  fireEvent.click(await screen.findByRole('button', { name: "Excel'den Aktar" }))
  const dialog = await screen.findByRole('dialog')
  fireEvent.click(within(dialog).getByRole('tab', { name: 'Kopyala-Yapıştır' }))
  const tablo = await within(dialog).findByRole('grid', { name: 'Yapıştırma tablosu' })
  fireEvent.paste(tablo, { clipboardData: { getData: () => pano } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Önizle' }))

  return dialog
}

// Geniş tablo + form: tüm takım yük altındayken 5 sn varsayılan sınırı aşılabiliyor
describe('IsgucuKesintileriPage', { timeout: 15_000 }, () => {
  beforeEach(() => {
    api.secenekler.mockResolvedValue(SECENEKLER)
    api.liste.mockResolvedValue(LISTE)
    api.yuklemeler.mockResolvedValue([])
    api.kolonlar.mockResolvedValue(KOLONLAR)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('satırları ve para birimine göre toplamları Türkçe biçimde gösterir; kolona göre sıralar', async () => {
    ciz()

    expect(await screen.findByText('FIRAT AKYILDIZ')).toBeInTheDocument()
    expect(screen.getByText('27.03.2026')).toBeInTheDocument()
    expect(screen.getAllByText('2.562,15').length).toBeGreaterThan(0)
    expect(screen.getByLabelText('Para birimine göre toplamlar')).toHaveTextContent('562,15')
    // Yansıtma türü taşeronun hemen sağında (kullanıcı kararı)
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.indexOf('Yansıtma Türü')).toBe(basliklar.indexOf('Taşeron') + 1)
    // Varsayılan sıra: tarih, yeniden eskiye
    expect(api.liste).toHaveBeenLastCalledWith(
      expect.anything(),
      { anahtar: 'tarih', yon: 'desc' },
      1,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Adı Soyadı' }))

    await waitFor(() =>
      expect(api.liste).toHaveBeenLastCalledWith(
        expect.anything(),
        { anahtar: 'adi_soyadi', yon: 'asc' },
        1,
      ),
    )
  })

  it('düzenlemede yansıtılan ve kalan tutarı hesaplar, bunları göndermez', async () => {
    api.kaydet.mockResolvedValue(SATIR)
    ciz()
    await screen.findByText('FIRAT AKYILDIZ')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('İptal Edilen Tutar'), {
      target: { value: '100' },
    })

    expect(within(dialog).getByLabelText('Yansıtılan Tutar')).toHaveValue('2462,15')
    expect(within(dialog).getByLabelText('Kalan Tutar')).toHaveValue('462,15')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    const [id, govde] = api.kaydet.mock.calls[0] as [number, Record<string, unknown>]
    expect(id).toBe(11)
    expect(govde).toMatchObject({
      kesinti_turu_id: 1,
      firma_id: 3,
      proje_id: 5,
      para_birimi_id: 7,
      iptal_edilen_tutar: '100.00',
      aktivite: null,
    })
    expect(govde).not.toHaveProperty('yansitilan_tutar')
    expect(govde).not.toHaveProperty('kalan_tutar')
  })

  it('hatalı yapıştırmada hataları nedenine göre gösterir ve kaydetmez', async () => {
    const onizleme: IceAktarimOnizlemesi = {
      hata: null,
      eksik_kolonlar: [],
      satirlar: [{ satir: 6, hatali: true, taseron: 'YOK' }],
      hatalar: [{ alan: 'taseron', deger: 'YOK', kod: 'bulunamadi', adet: 2207, satirlar: [6, 7] }],
      toplamlar: [],
    }
    api.onizle.mockResolvedValue(onizleme)
    ciz()

    const dialog = await aktarimiAc('İŞGÜCÜ\tYOK')

    expect(await within(dialog).findByText('Tanımlarda bu kod yok')).toBeInTheDocument()
    expect(within(dialog).getByText('2207')).toBeInTheDocument()
    // Başlıksız yapıştırma ilk hücreden standart sırayla yerleşir, sunucuya başlıksız gider
    expect(api.onizle).toHaveBeenCalledWith({ metin: 'İŞGÜCÜ\tYOK\t' })
    expect(within(dialog).getByRole('button', { name: 'Kaydet (1 satır)' })).toBeDisabled()
  })

  it('başlıklı yapıştırmada kolonlar adlarından eşlenir; hatasız önizlemeden sonra aynı girdiyle kaydeder', async () => {
    api.onizle.mockResolvedValue({
      hata: null,
      eksik_kolonlar: [],
      satirlar: [{ satir: 6, hatali: false, adi_soyadi: 'FIRAT AKYILDIZ' }],
      hatalar: [],
      toplamlar: LISTE.ozet,
    } satisfies IceAktarimOnizlemesi)
    api.aktar.mockResolvedValue({
      id: 3,
      kaynak: 'yapistirma',
      dosya_adi: null,
      satir_sayisi: 1,
      yukleyen: 'Deneme',
      olusturma_zamani: '2026-10-30T09:00:00Z',
    })
    ciz()

    // Bugünkü Excel'in sırası ve adlarıyla, fazladan HAKEDİŞ kolonu ve Windows satır sonuyla
    const dialog = await aktarimiAc(
      'ADI - SOYADI\tKESİNTİ YAPILACAK FİRMA\tHAKEDİŞ\tKESİNTİ TÜRÜ\r\nFIRAT AKYILDIZ\tHAB\t3-ARA\tİŞGÜCÜ\r\n',
    )
    expect(within(dialog).getByText('FIRAT AKYILDIZ')).toBeInTheDocument()
    expect(
      await within(dialog).findByText('1 satır hatasız, kaydedilmeye hazır.'),
    ).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet (1 satır)' }))

    await waitFor(() =>
      expect(api.aktar).toHaveBeenCalledWith({ metin: 'İŞGÜCÜ\tHAB\tFIRAT AKYILDIZ' }),
    )
  })

  it('yapıştırılan hücreye tıklanıp düzeltilen değer önizlemeye gider', async () => {
    api.onizle.mockResolvedValue({
      hata: null,
      eksik_kolonlar: [],
      satirlar: [],
      hatalar: [],
      toplamlar: [],
    } satisfies IceAktarimOnizlemesi)
    ciz()

    const dialog = await aktarimiAc('İŞGÜCÜ\tYOK')
    await waitFor(() => expect(api.onizle).toHaveBeenCalledTimes(1))

    fireEvent.click(within(dialog).getByText('YOK'))
    fireEvent.change(within(dialog).getByLabelText('Taşeron 1'), { target: { value: 'HAB' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Önizle' }))

    await waitFor(() => expect(api.onizle).toHaveBeenLastCalledWith({ metin: 'İŞGÜCÜ\tHAB\t' }))
  })

  it('yalnız görüntüleme izninde ekleme, aktarım, düzenleme ve geri alma sunulmaz', async () => {
    api.yuklemeler.mockResolvedValue([
      {
        id: 2,
        kaynak: 'excel',
        dosya_adi: 'isgucu.xlsx',
        satir_sayisi: 2379,
        yukleyen: 'Deneme',
        olusturma_zamani: '2026-10-30T09:00:00Z',
      },
    ])
    ciz(['isgucu_kesintileri.goruntule'])

    expect(await screen.findByText('FIRAT AKYILDIZ')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Kayıt' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: "Excel'den Aktar" })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Yüklemeler' }))
    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText('isgucu.xlsx')).toBeInTheDocument()
    expect(within(dialog).queryByRole('button', { name: 'Geri Al' })).not.toBeInTheDocument()
  })

  it('yükleme onaylanınca satırlarıyla geri alınır', async () => {
    api.yuklemeler.mockResolvedValue([
      {
        id: 2,
        kaynak: 'excel',
        dosya_adi: 'isgucu.xlsx',
        satir_sayisi: 2379,
        yukleyen: 'Deneme',
        olusturma_zamani: '2026-10-30T09:00:00Z',
      },
    ])
    api.geriAl.mockResolvedValue(undefined)
    ciz()
    await screen.findByText('FIRAT AKYILDIZ')

    fireEvent.click(screen.getByRole('button', { name: 'Yüklemeler' }))
    const pencere = await screen.findByRole('dialog')
    fireEvent.click(await within(pencere).findByRole('button', { name: 'Geri Al' }))

    const onaylar = await screen.findAllByRole('dialog')
    const onay = onaylar[onaylar.length - 1]
    expect(within(onay).getByText(/2379 satırı silinecek/)).toBeInTheDocument()
    fireEvent.click(within(onay).getByRole('button', { name: 'Geri Al' }))

    await waitFor(() => expect(api.geriAl).toHaveBeenCalledWith(2))
  })
})
