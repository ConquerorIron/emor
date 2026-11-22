import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
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
  googleSecici: vi.fn(),
  googleKlasor: vi.fn(),
  pencere: vi.fn(),
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
  googleSeciciBilgisi: api.googleSecici,
  googleKlasorKaydet: api.googleKlasor,
}))

// Google'ın klasör seçme penceresi (dış betik) testte açılmaz
vi.mock('./googleKlasorSecici', () => ({ googleKlasorSec: api.pencere }))

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
  oauth_hazir: false,
}

const GOOGLE: HedefTuruTanimi = {
  tur: 'google_drive',
  alanlar: [],
  sunucu_disi: true,
  uyari: null,
  oauth: true,
  oauth_hazir: true,
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
  oauth_hazir: false,
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
  konum_kilitli_alanlar: [],
  son_kopya: { durum: 'tamam', zaman: '2026-11-20T23:05:00+00:00', hata_kodu: null },
}

const GOOGLE_HEDEFI: Hedef = {
  id: 4,
  ad: 'Drive',
  tur: 'google_drive',
  aktif: true,
  sunucu_disi: true,
  baglanti: { durum: 'yeniden_baglan', eposta: 'yedek@ornek.test' },
  gizli_kayitli: {},
  plan_sayisi: 1,
  konum_kilitli_alanlar: [],
  son_kopya: { durum: 'basarisiz', zaman: null, hata_kodu: 'HEDEF_YENIDEN_BAGLAN' },
}

function hata(status: number, data: unknown) {
  return Object.assign(new Error('istek'), { isAxiosError: true, response: { status, data } })
}

function ciz(
  izinler = ['yedek_planlari.goruntule', 'yedek_planlari.guncelle'],
  durum: unknown = null,
) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} yedeklemeAktif>
        <MemoryRouter initialEntries={[{ pathname: '/ayarlar/yedek-hedefleri', state: durum }]}>
          <YedekHedefleriPage />
        </MemoryRouter>
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
      silinme_korumasi: null,
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

  it('kopyası olan hedefte konum alanları (port dahil) salt okunur ve nedeni yazılı; parola yenilenerek kaydedilir (BY-016-R1, R3)', async () => {
    api.hedefler.mockResolvedValue([
      { ...FTP_HEDEFI, konum_kilitli_alanlar: ['sunucu', 'port', 'kullanici_adi', 'klasor'] },
    ])
    api.kaydet.mockResolvedValue(FTP_HEDEFI)
    ciz()
    const dialog = await duzenle('Ofis FTP')

    expect(within(dialog).getByRole('note')).toHaveTextContent(
      'kopyaların durduğu yeri belirleyen alanlar kilitli',
    )
    for (const etiket of ['Sunucu *', 'Port', 'Kullanıcı adı *', 'Klasör']) {
      expect(within(dialog).getByLabelText(etiket)).toHaveAttribute('readonly')
    }
    expect(within(dialog).getByLabelText('Parola')).not.toHaveAttribute('readonly')
    // Konum olmayan evet/hayır alanı (pasif kip) açık
    expect(within(dialog).getByRole('switch', { name: 'Pasif kip (passive mode)' })).toBeEnabled()

    fireEvent.change(within(dialog).getByLabelText('Parola'), { target: { value: 'Yeni-Parola' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.kaydet).toHaveBeenCalledWith(3, {
        ad: 'Ofis FTP',
        aktif: true,
        alanlar: {
          sunucu: 'ftp.ornek.test',
          port: 21,
          kullanici_adi: 'yedek',
          sifre: 'Yeni-Parola',
          klasor: '/yedek',
          pasif: true,
        },
      }),
    )
  })

  it('kilitli evet/hayır konum alanı (S3 yol stili) değiştirilemez, değeri gövdede kalır (BY-016-R3)', async () => {
    const S3: HedefTuruTanimi = {
      tur: 's3',
      alanlar: [
        {
          ad: 'kova',
          etiket: 'yedekleme.hedef.alan.kova',
          tur: 'metin',
          zorunlu: true,
          gizli: false,
          varsayilan: null,
        },
        {
          ad: 'yol_stili',
          etiket: 'yedekleme.hedef.alan.yol_stili',
          tur: 'evet_hayir',
          zorunlu: false,
          gizli: false,
          varsayilan: false,
        },
      ],
      sunucu_disi: true,
      uyari: null,
      oauth: false,
      oauth_hazir: false,
    }
    api.turler.mockResolvedValue([FTP, S3])
    api.hedefler.mockResolvedValue([
      {
        ...FTP_HEDEFI,
        id: 7,
        ad: 'Kova',
        tur: 's3',
        baglanti: { kova: 'emor', yol_stili: true },
        gizli_kayitli: {},
        konum_kilitli_alanlar: ['kova', 'yol_stili'],
      },
      FTP_HEDEFI,
    ])
    api.kaydet.mockResolvedValue(FTP_HEDEFI)
    ciz()
    const dialog = await duzenle('Kova')

    const yolStili = within(dialog).getByRole('switch', { name: 'Yol stili adres (path-style)' })
    expect(yolStili).toBeDisabled()
    fireEvent.click(yolStili)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.kaydet).toHaveBeenCalledWith(7, {
        ad: 'Kova',
        aktif: true,
        alanlar: { kova: 'emor', yol_stili: true },
      }),
    )
  })

  it.each([
    ['etkin', 'Silinmeye karşı koruma (nesne kilidi): etkin'],
    ['devre_disi', 'Silinmeye karşı koruma (nesne kilidi): devre dışı'],
    ['sorgulanamadi', 'Silinmeye karşı koruma sorgulanamadı'],
  ] as const)('S3 sınaması silinme korumasını gösterir: %s (BY-023-R2)', async (koruma, metin) => {
    api.sina.mockResolvedValue({
      basarili: true,
      adimlar: [{ adim: 'baglan', basarili: true, kod: null, mesaj: null }],
      kalan_alan: null,
      silinme_korumasi: koruma,
      kaydedildi: true,
      sunucu_parmak_izi: null,
    })
    ciz()
    const dialog = await duzenle('Ofis FTP')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Bağlantıyı Sına' }))

    expect(await within(dialog).findByRole('status')).toHaveTextContent(metin)
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

  describe('Google Drive klasör seçimi', () => {
    const BAGLI: Hedef = {
      ...GOOGLE_HEDEFI,
      id: 5,
      ad: 'Kişisel Drive',
      baglanti: { eposta: 'kisi@gmail.com' },
      son_kopya: null,
    }
    const SECICI = {
      erisim_anahtari: 'ya29.kisa',
      api_anahtari: 'AIza-anahtar',
      proje_numarasi: '1234',
      eposta: 'kisi@gmail.com',
    }

    beforeEach(() => {
      api.hedefler.mockResolvedValue([BAGLI])
      api.googleSecici.mockResolvedValue(SECICI)
    })

    it('klasör seçilmemişse belirtir; seçilen klasör sunucuya kimliğiyle gider ve adı bildirilir', async () => {
      api.pencere.mockResolvedValue({ id: 'klasor_9', ad: 'Muhasebe' })
      api.googleKlasor.mockResolvedValue({
        ...BAGLI,
        baglanti: { ...BAGLI.baglanti, klasor_kimligi: 'klasor_9', klasor_adi: 'Muhasebe' },
      })
      ciz()
      const drive = await satir('Kişisel Drive')
      expect(within(drive).getByText('Klasör seçilmedi')).toBeInTheDocument()

      fireEvent.click(within(drive).getByRole('button', { name: 'Klasör seç' }))

      await waitFor(() =>
        expect(api.googleKlasor).toHaveBeenCalledWith(5, { klasor_kimligi: 'klasor_9' }),
      )
      expect(api.googleSecici).toHaveBeenCalledWith(5)
      expect(api.pencere).toHaveBeenCalledWith(SECICI, {
        baslik: 'Yedeklerin gideceği klasörü seçin',
        dil: expect.any(String),
      })
      expect(await screen.findByText(/Yedek klasörü kaydedildi: Muhasebe/)).toBeInTheDocument()
    })

    it('pencere açıkken ikinci tıklama ikinci pencere açmaz (F5)', async () => {
      let sec: (deger: null) => void = () => {}
      api.pencere.mockReturnValue(
        new Promise((resolve) => {
          sec = resolve
        }),
      )
      ciz()
      const dugme = within(await satir('Kişisel Drive')).getByRole('button', {
        name: 'Klasör seç',
      })

      fireEvent.click(dugme)
      fireEvent.click(dugme)
      await waitFor(() => expect(api.pencere).toHaveBeenCalledTimes(1))
      sec(null)

      await waitFor(() => expect(dugme).toBeEnabled())
      expect(api.googleSecici).toHaveBeenCalledTimes(1)
    })

    it('pencerede vazgeçilirse hiçbir şey kaydedilmez', async () => {
      api.pencere.mockResolvedValue(null)
      ciz()

      fireEvent.click(
        within(await satir('Kişisel Drive')).getByRole('button', { name: 'Klasör seç' }),
      )

      await waitFor(() => expect(api.pencere).toHaveBeenCalled())
      expect(api.googleKlasor).not.toHaveBeenCalled()
    })

    it('pencere yüklenemezse nedenini söyler, sunucuya kayıt gitmez', async () => {
      api.pencere.mockRejectedValue(new Error('betik'))
      ciz()

      fireEvent.click(
        within(await satir('Kişisel Drive')).getByRole('button', { name: 'Klasör seç' }),
      )

      expect(await screen.findByText(/klasör seçme penceresi açılamadı/)).toBeInTheDocument()
      expect(api.googleKlasor).not.toHaveBeenCalled()
    })

    it('sunucu klasörü reddederse nedenini gösterir', async () => {
      api.pencere.mockResolvedValue({ id: 'paylasilan', ad: 'Ortak' })
      api.googleKlasor.mockRejectedValue(hata(422, { kod: 'HEDEF_KLASOR_GECERSIZ' }))
      ciz()

      fireEvent.click(
        within(await satir('Kişisel Drive')).getByRole('button', { name: 'Klasör seç' }),
      )

      expect(await screen.findByText(/Google Drive klasörü kullanılamıyor/)).toBeInTheDocument()
    })

    it('yeni klasör verilen adla açtırılır', async () => {
      api.googleKlasor.mockResolvedValue({
        ...BAGLI,
        baglanti: { ...BAGLI.baglanti, klasor_kimligi: 'yeni', klasor_adi: 'Şirket Yedekleri' },
      })
      ciz()

      fireEvent.click(
        within(await satir('Kişisel Drive')).getByRole('button', { name: 'Yeni klasör' }),
      )
      const pencere = await screen.findByRole('dialog')
      expect(within(pencere).getByText(/kisi@gmail.com/)).toBeInTheDocument()
      fireEvent.change(within(pencere).getByLabelText('Klasör adı'), {
        target: { value: '  Şirket Yedekleri ' },
      })
      fireEvent.click(within(pencere).getByRole('button', { name: 'Klasörü aç' }))

      await waitFor(() =>
        expect(api.googleKlasor).toHaveBeenCalledWith(5, { yeni_klasor_adi: 'Şirket Yedekleri' }),
      )
      expect(api.pencere).not.toHaveBeenCalled()
      await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    })

    it('seçili klasör Drive bağlantısıyla gösterilir; kopyası duran hedefte klasör düğmeleri kapalı', async () => {
      api.hedefler.mockResolvedValue([
        {
          ...BAGLI,
          baglanti: { ...BAGLI.baglanti, klasor_kimligi: 'klasor_9', klasor_adi: 'Muhasebe' },
          konum_kilitli_alanlar: ['eposta', 'klasor_kimligi'],
        },
      ])
      ciz()
      const drive = await satir('Kişisel Drive')

      expect(within(drive).getByRole('link', { name: 'Muhasebe' })).toHaveAttribute(
        'href',
        'https://drive.google.com/drive/folders/klasor_9',
      )
      expect(within(drive).getByRole('button', { name: 'Klasör seç' })).toBeDisabled()
      expect(within(drive).getByRole('button', { name: 'Yeni klasör' })).toBeDisabled()
    })

    it('Google dönüşünden gelinince klasör seçme penceresi kendiliğinden bir kez açılır', async () => {
      api.pencere.mockResolvedValue(null)
      ciz(undefined, { klasorSecilecek: 5 })

      await waitFor(() => expect(api.pencere).toHaveBeenCalledTimes(1))
      expect(api.googleSecici).toHaveBeenCalledWith(5)
    })

    it('klasörü zaten seçili hedefte dönüş pencereyi açmaz', async () => {
      api.hedefler.mockResolvedValue([
        { ...BAGLI, baglanti: { ...BAGLI.baglanti, klasor_kimligi: 'k', klasor_adi: 'K' } },
      ])
      ciz(undefined, { klasorSecilecek: 5 })

      await satir('Kişisel Drive')
      expect(api.googleSecici).not.toHaveBeenCalled()
      expect(api.pencere).not.toHaveBeenCalled()
    })

    it('Google Drive seçilince ad kendiliğinden gelir; "Google ile giriş yap" kaydedip Google girişine gider', async () => {
      const asil = window.location
      const assign = vi.fn()
      Object.defineProperty(window, 'location', { configurable: true, value: { ...asil, assign } })
      api.kaydet.mockResolvedValue({ ...BAGLI, id: 9, ad: 'Google Drive', baglanti: {} })
      api.googleAdres.mockResolvedValue('https://accounts.google.com/o/oauth2/v2/auth?state=yeni')
      try {
        ciz()
        await satir('Kişisel Drive')

        fireEvent.click(screen.getByRole('button', { name: 'Yeni Hedef' }))
        const pencere = await screen.findByRole('dialog')
        fireEvent.keyDown(within(pencere).getByLabelText('Hedef türü'), { key: 'ArrowDown' })
        fireEvent.click(await screen.findByRole('option', { name: 'Google Drive' }))

        expect(await within(pencere).findByLabelText('Ad *')).toHaveValue('Google Drive')
        expect(
          within(pencere).queryByRole('button', { name: 'Bağlantıyı Sına' }),
        ).not.toBeInTheDocument()
        expect(within(pencere).queryByRole('button', { name: 'Kaydet' })).not.toBeInTheDocument()
        fireEvent.click(within(pencere).getByRole('button', { name: 'Google ile giriş yap' }))

        await waitFor(() =>
          expect(assign).toHaveBeenCalledWith(
            'https://accounts.google.com/o/oauth2/v2/auth?state=yeni',
          ),
        )
        expect(api.kaydet).toHaveBeenCalledWith(null, {
          ad: 'Google Drive',
          aktif: true,
          tur: 'google_drive',
          alanlar: {},
        })
        expect(api.googleAdres).toHaveBeenCalledWith(9)
      } finally {
        Object.defineProperty(window, 'location', { configurable: true, value: asil })
      }
    })

    it("sunucuda Google girişi açılmamışsa form ve liste nedenini söyler; Google'a gidilmez", async () => {
      api.turler.mockResolvedValue([FTP, { ...GOOGLE, oauth_hazir: false }, YENI_TUR])
      api.hedefler.mockResolvedValue([{ ...BAGLI, baglanti: {} }])
      api.kaydet.mockResolvedValue({ ...BAGLI, id: 9, ad: 'Google Drive', baglanti: {} })
      ciz()
      const drive = await satir('Kişisel Drive')

      expect(within(drive).getByText('Sunucuda Google girişi henüz açılmadı.')).toBeInTheDocument()
      expect(within(drive).getByRole('button', { name: 'Google ile bağlan' })).toBeDisabled()

      fireEvent.click(screen.getByRole('button', { name: 'Yeni Hedef' }))
      const pencere = await screen.findByRole('dialog')
      fireEvent.keyDown(within(pencere).getByLabelText('Hedef türü'), { key: 'ArrowDown' })
      fireEvent.click(await screen.findByRole('option', { name: 'Google Drive' }))

      expect(await within(pencere).findByRole('alert')).toHaveTextContent(
        'Google girişi bu sunucuda henüz açılmadı',
      )
      fireEvent.click(within(pencere).getByRole('button', { name: 'Kaydet' }))

      await waitFor(() => expect(api.kaydet).toHaveBeenCalled())
      expect(api.googleAdres).not.toHaveBeenCalled()
    })
  })
})
