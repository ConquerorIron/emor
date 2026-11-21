import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { Hedef, HedefTuruTanimi } from '@/features/yedekleme/hedefApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { YedekHedefleriPage } from './YedekHedefleriPage'

const api = vi.hoisted(() => ({
  hedefler: vi.fn(),
  turler: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
  sina: vi.fn(),
  sifirla: vi.fn(),
  googleAdres: vi.fn(),
  googleKes: vi.fn(),
}))

vi.mock('@/features/yedekleme/hedefApi', () => ({
  hedefleriGetir: api.hedefler,
  hedefTurleriGetir: api.turler,
  hedefKaydet: api.kaydet,
  hedefSil: api.sil,
  hedefSina: api.sina,
  sunucuAnahtariniSifirla: api.sifirla,
  googleBaglantiAdresi: api.googleAdres,
  googleBaglantisiniKes: api.googleKes,
}))

const FTP: HedefTuruTanimi = {
  tur: 'ftp',
  alanlar: [
    {
      ad: 'sunucu',
      etiket: 'yedekleme.hedef.alan.sunucu',
      tur: 'metin',
      zorunlu: true,
      gizli: false,
      varsayilan: null,
    },
    {
      ad: 'port',
      etiket: 'yedekleme.hedef.alan.port',
      tur: 'sayi',
      zorunlu: false,
      gizli: false,
      varsayilan: 21,
    },
    {
      ad: 'kullanici_adi',
      etiket: 'yedekleme.hedef.alan.kullanici_adi',
      tur: 'metin',
      zorunlu: true,
      gizli: false,
      varsayilan: null,
    },
    {
      ad: 'sifre',
      etiket: 'yedekleme.hedef.alan.sifre',
      tur: 'parola',
      zorunlu: true,
      gizli: true,
      varsayilan: null,
    },
    {
      ad: 'klasor',
      etiket: 'yedekleme.hedef.alan.klasor',
      tur: 'metin',
      zorunlu: false,
      gizli: false,
      varsayilan: null,
    },
    {
      ad: 'pasif',
      etiket: 'yedekleme.hedef.alan.pasif',
      tur: 'evet_hayir',
      zorunlu: false,
      gizli: false,
      varsayilan: true,
    },
  ],
  sunucu_disi: true,
  uyari: 'yedekleme.hedef.uyari.duz_ftp',
  oauth: false,
}

const GOOGLE: HedefTuruTanimi = {
  tur: 'google_drive',
  alanlar: [
    {
      ad: 'klasor_adi',
      etiket: 'yedekleme.hedef.alan.klasor_adi',
      tur: 'metin',
      zorunlu: false,
      gizli: false,
      varsayilan: 'eMOR',
    },
  ],
  sunucu_disi: true,
  uyari: null,
  oauth: true,
}

/** Ekranın hiç bilmediği bir tür: form yalnız tanımdan çizilmeli */
const YENI_TUR: HedefTuruTanimi = {
  tur: 'webdav',
  alanlar: [
    {
      ad: 'adres',
      etiket: 'yedekleme.hedef.alan.adres',
      tur: 'metin',
      zorunlu: true,
      gizli: false,
      varsayilan: null,
    },
  ],
  sunucu_disi: true,
  uyari: null,
  oauth: false,
}

const FTP_HEDEFI: Hedef = {
  id: 3,
  ad: 'Ofis FTP',
  tur: 'ftp',
  aktif: true,
  sunucu_disi: true,
  baglanti: {
    sunucu: 'ftp.ornek.test',
    port: 21,
    kullanici_adi: 'yedek',
    klasor: '/yedek',
    pasif: true,
  },
  gizli_kayitli: { sifre: true },
  plan_sayisi: 2,
  son_kopya: { durum: 'tamam', zaman: '2026-11-20T23:05:00+00:00', hata_kodu: null },
}

const GOOGLE_HEDEFI: Hedef = {
  id: 4,
  ad: 'Drive',
  tur: 'google_drive',
  aktif: true,
  sunucu_disi: true,
  baglanti: { klasor_adi: 'eMOR', durum: 'yeniden_baglan', eposta: 'yedek@ornek.test' },
  gizli_kayitli: {},
  plan_sayisi: 1,
  son_kopya: { durum: 'basarisiz', zaman: null, hata_kodu: 'HEDEF_YENIDEN_BAGLAN' },
}

function hata(status: number, data: unknown) {
  return Object.assign(new Error('istek'), { isAxiosError: true, response: { status, data } })
}

function ciz(izinler = ['yedek_planlari.goruntule', 'yedek_planlari.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} yedeklemeAktif>
        <YedekHedefleriPage />
      </SahteOturum>
    </AppProviders>,
  )
}

const satir = async (ad: string) => (await screen.findByText(ad)).closest('tr') as HTMLElement

async function duzenle(ad: string) {
  fireEvent.click(within(await satir(ad)).getByRole('button', { name: 'Düzenle' }))

  return screen.findByRole('dialog')
}

describe('YedekHedefleriPage', () => {
  beforeEach(() => {
    api.turler.mockResolvedValue([FTP, GOOGLE, YENI_TUR])
    api.hedefler.mockResolvedValue([FTP_HEDEFI, GOOGLE_HEDEFI])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('düzenlemede kayıtlı parola gösterilmez; boş bırakılınca gönderilmez', async () => {
    api.kaydet.mockResolvedValue(FTP_HEDEFI)
    ciz()
    const dialog = await duzenle('Ofis FTP')

    expect(within(dialog).getByLabelText('Sunucu *')).toHaveValue('ftp.ornek.test')
    const sifre = within(dialog).getByLabelText('Parola')
    expect(sifre).toHaveValue('')
    expect(sifre).toHaveAttribute('placeholder', 'kayıtlı — değiştirmek için yazın')
    // Düz FTP uyarısı belirgin
    expect(within(dialog).getByRole('alert')).toHaveTextContent('Düz FTP şifresizdir')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(3, {
      ad: 'Ofis FTP',
      aktif: true,
      alanlar: {
        sunucu: 'ftp.ornek.test',
        port: 21,
        kullanici_adi: 'yedek',
        klasor: '/yedek',
        pasif: true,
      },
    })
  })

  it('ekranın bilmediği yeni bir tür yalnız tanımdan çizilir ve kaydedilir', async () => {
    api.kaydet.mockResolvedValue({ ...FTP_HEDEFI, id: 9, tur: 'webdav' })
    ciz()
    await satir('Ofis FTP')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Hedef' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.keyDown(within(dialog).getByLabelText('Hedef türü'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByRole('option', { name: 'webdav' }))

    // Çevirisi olmayan alan adıyla çizilir; zorunluluk tanımdan
    const adres = await within(dialog).findByLabelText('adres *')
    fireEvent.change(within(dialog).getByLabelText('Ad *'), { target: { value: 'Arşiv' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))
    expect(await within(dialog).findByText('Bu alan zorunlu.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()

    fireEvent.change(adres, { target: { value: 'https://dav.ornek.test/yedek' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.kaydet).toHaveBeenCalledWith(null, {
        ad: 'Arşiv',
        aktif: true,
        tur: 'webdav',
        alanlar: { adres: 'https://dav.ornek.test/yedek' },
      }),
    )
  })

  it('"Sına" sonucunu adım adım gösterir; kayıtlı hedefin kimliğiyle ve sırsız gider', async () => {
    api.sina.mockResolvedValue({
      basarili: false,
      adimlar: [
        { adim: 'baglan', basarili: true, kod: null, mesaj: null },
        { adim: 'yaz', basarili: false, kod: 'HEDEF_YAZILAMADI', mesaj: 'izin yok' },
        { adim: 'oku', basarili: null, kod: null, mesaj: null },
      ],
      kalan_alan: null,
      kaydedildi: true,
      sunucu_parmak_izi: null,
    })
    ciz()
    const dialog = await duzenle('Ofis FTP')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Bağlantıyı Sına' }))

    const sonuc = await within(dialog).findByRole('status')
    expect(api.sina).toHaveBeenCalledWith({
      id: 3,
      tur: 'ftp',
      alanlar: {
        sunucu: 'ftp.ornek.test',
        port: 21,
        kullanici_adi: 'yedek',
        klasor: '/yedek',
        pasif: true,
      },
    })
    expect(sonuc).toHaveTextContent('Bağlan: geçti')
    expect(sonuc).toHaveTextContent('Yaz: Hedefe yazılamadı (izin ya da klasör).')
    expect(sonuc).toHaveTextContent('Oku: atlandı')
  })

  it('sunucu sır alanını reddedince mesajı o alanın altında gösterir', async () => {
    api.kaydet.mockRejectedValue(
      hata(422, {
        kod: 'DOGRULAMA',
        hatalar: { 'alanlar.sifre': ['Sunucu değiştiği için parolayı yeniden girin.'] },
      }),
    )
    ciz()
    const dialog = await duzenle('Ofis FTP')

    fireEvent.change(within(dialog).getByLabelText('Sunucu *'), {
      target: { value: 'yeni.ornek.test' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    const sifre = within(dialog).getByLabelText('Parola')
    await waitFor(() => expect(sifre).toHaveAttribute('aria-invalid', 'true'))
    expect(
      within(dialog).getByText('Sunucu değiştiği için parolayı yeniden girin.'),
    ).toBeInTheDocument()
  })

  it('planda kullanılan hedef silinirken onay ister ve 409 nedenini gösterir', async () => {
    api.sil.mockRejectedValue(
      hata(409, { kod: 'HEDEF_KULLANILIYOR', mesaj: 'x', plan_sayisi: 2, kopya_sayisi: 5 }),
    )
    ciz()

    fireEvent.click(within(await satir('Ofis FTP')).getByRole('button', { name: 'Sil' }))
    const dialog = await screen.findByRole('dialog')
    expect(api.sil).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(3))
    expect(await screen.findByText(/2 planda tanımlı, 5 kopyası var/)).toBeInTheDocument()
  })

  it('Google bağlantısı koptuysa belirgin uyarı gösterir; yeniden bağlan Google adresine gider', async () => {
    const asil = window.location
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...asil, assign },
    })
    api.googleAdres.mockResolvedValue('https://accounts.google.com/o/oauth2/v2/auth?state=abc')
    try {
      ciz()
      const drive = await satir('Drive')

      expect(within(drive).getByRole('alert')).toHaveTextContent('Google bağlantısı koptu')
      expect(within(drive).getByText('yedek@ornek.test')).toBeInTheDocument()

      fireEvent.click(within(drive).getByRole('button', { name: 'Yeniden bağlan' }))

      await waitFor(() =>
        expect(assign).toHaveBeenCalledWith(
          'https://accounts.google.com/o/oauth2/v2/auth?state=abc',
        ),
      )
      expect(api.googleAdres).toHaveBeenCalledWith(4)
    } finally {
      Object.defineProperty(window, 'location', { configurable: true, value: asil })
    }
  })

  it('yalnız görüntüleme izninde ekleme / düzenleme / silme düğmesi yoktur', async () => {
    ciz(['yedek_planlari.goruntule'])
    await satir('Ofis FTP')

    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Hedef' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })
})
