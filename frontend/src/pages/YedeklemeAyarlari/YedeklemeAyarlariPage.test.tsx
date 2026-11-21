import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import type { YedekAyarlari } from './ayarApi'
import { YedeklemeAyarlariPage } from './YedeklemeAyarlariPage'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  kaydet: vi.fn(),
  anahtarYukle: vi.fn(),
  anahtarKaldir: vi.fn(),
}))

vi.mock('./ayarApi', () => ({
  yedekAyarlariniGetir: api.getir,
  yedekAyarlariniKaydet: api.kaydet,
  sifrelemeAnahtariYukle: api.anahtarYukle,
  sifrelemeAnahtariniKaldir: api.anahtarKaldir,
}))

const AYAR: YedekAyarlari = {
  yayin_oncesi_son_adet: 20,
  yayin_oncesi_gun: 30,
  elle_gun: 30,
  geri_yukleme_oncesi_gun: 30,
  enaz_adet: 3,
  enaz_bos_alan_yuzde: 15,
  bildirim_alicilari: ['bt@ornek.test'],
  sinyal_yerel_adresi: 'https://izleme.ornek.test/yerel',
  sinyal_uzak_adresi: null,
  sinyal_sinama_adresi: null,
  sifreleme: {
    parmak_izi: 'abcd1234ef567890abcd1234ef567890abcd1234',
    kullanici: 'Yedek <yedek@ornek.test>',
    bitis: null,
  },
  ayar_kopyasi_guncel: true,
}

function ciz(izinler = ['yedek_planlari.goruntule', 'yedek_planlari.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} yedeklemeAktif>
        <YedeklemeAyarlariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

const alan = (id: string) => document.getElementById(id) as HTMLInputElement

describe('YedeklemeAyarlariPage', () => {
  beforeEach(() => {
    api.getir.mockResolvedValue(AYAR)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kayıttan sonra dosya kopyası güncel değilse bunu bildirir (BY-018-R2)', async () => {
    api.kaydet.mockResolvedValue({ ...AYAR, ayar_kopyasi_guncel: false })
    ciz()
    await screen.findByDisplayValue('bt@ornek.test')
    expect(screen.queryByText(/dosya kopyası henüz güncel değil/)).not.toBeInTheDocument()

    fireEvent.change(alan('ayar-elle_gun'), { target: { value: '45' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    expect(await screen.findByText(/dosya kopyası henüz güncel değil/)).toBeInTheDocument()
  })

  it("en az yedek sayısı 1'in altına indirilemez", async () => {
    ciz()
    await screen.findByDisplayValue('bt@ornek.test')

    fireEvent.change(alan('ayar-enaz_adet'), { target: { value: '0' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    expect(await screen.findByText('1 ile 100 arasında bir tam sayı girin.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })

  it('alıcıları ayırıp boş sinyali null gönderir; kayıt sürerken alanlar salt okunurdur (F3)', async () => {
    let bitir: (ayar: YedekAyarlari) => void = () => undefined
    api.kaydet.mockReturnValue(new Promise<YedekAyarlari>((coz) => (bitir = coz)))
    ciz()
    await screen.findByDisplayValue('bt@ornek.test')

    fireEvent.change(alan('ayar-bildirim-alicilari'), {
      target: { value: 'bt@ornek.test; yonetici@ornek.test' },
    })
    fireEvent.change(alan('ayar-sinyal_yerel_adresi'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith({
      yayin_oncesi_son_adet: 20,
      yayin_oncesi_gun: 30,
      elle_gun: 30,
      geri_yukleme_oncesi_gun: 30,
      enaz_adet: 3,
      enaz_bos_alan_yuzde: 15,
      bildirim_alicilari: ['bt@ornek.test', 'yonetici@ornek.test'],
      sinyal_yerel_adresi: null,
      sinyal_uzak_adresi: null,
      sinyal_sinama_adresi: null,
    })
    expect(alan('ayar-bildirim-alicilari')).toHaveAttribute('readonly')
    expect(alan('ayar-enaz_adet')).toHaveAttribute('readonly')

    bitir({
      ...AYAR,
      bildirim_alicilari: ['bt@ornek.test', 'yonetici@ornek.test'],
      sinyal_yerel_adresi: null,
    })

    await waitFor(() => expect(alan('ayar-bildirim-alicilari')).not.toHaveAttribute('readonly'))
    expect(alan('ayar-bildirim-alicilari')).toHaveValue('bt@ornek.test, yonetici@ornek.test')
  })

  it('anahtar dörderli parmak iziyle gösterilir; özel anahtar yüklenirse nedeni gösterilir', async () => {
    api.anahtarYukle.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: { status: 422, data: { kod: 'YEDEK_ANAHTAR_OZEL', hatalar: {} } },
      }),
    )
    ciz()

    expect(
      await screen.findByText('ABCD 1234 EF56 7890 ABCD 1234 EF56 7890 ABCD 1234'),
    ).toBeInTheDocument()
    const metin = screen.getByLabelText('Yeni açık anahtar (değiştirmek için)')
    // Kayıtlı anahtar alana doldurulmaz
    expect(metin).toHaveValue('')

    fireEvent.change(metin, { target: { value: '-----BEGIN PGP PRIVATE KEY BLOCK-----' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anahtarı Yükle' }))

    expect(await screen.findByText(/Bu bir ÖZEL anahtar/)).toBeInTheDocument()
    expect(api.anahtarYukle).toHaveBeenCalledWith('-----BEGIN PGP PRIVATE KEY BLOCK-----')
  })

  it('anahtarı kaldırma, sunucu dışı kopyaların duracağını söyleyen onaydan sonra yapılır', async () => {
    api.anahtarKaldir.mockResolvedValue({ ...AYAR, sifreleme: null })
    ciz()

    fireEvent.click(await screen.findByRole('button', { name: 'Anahtarı Kaldır' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('sunucu dışı hedeflere kopya gönderimi durur')
    expect(api.anahtarKaldir).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Anahtarı Kaldır' }))

    await waitFor(() => expect(api.anahtarKaldir).toHaveBeenCalledTimes(1))
    expect(await screen.findByText(/Açık anahtar tanımlı değil/)).toBeInTheDocument()
  })

  it('yalnız görüntüleme izninde alanlar ve düğmeler pasiftir', async () => {
    ciz(['yedek_planlari.goruntule'])
    await screen.findByDisplayValue('bt@ornek.test')

    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(alan('ayar-enaz_adet')).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Anahtarı Kaldır' })).toBeDisabled()
  })
})
