import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { YonetilenKullanici } from '@/features/ayarlar/yetkiApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { KullanicilarPage } from './KullanicilarPage'

const api = vi.hoisted(() => ({
  kullanicilar: vi.fn(),
  roller: vi.fn(),
  olustur: vi.fn(),
  guncelle: vi.fn(),
}))

vi.mock('@/features/ayarlar/yetkiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/ayarlar/yetkiApi')>()),
  kullanicilariGetir: api.kullanicilar,
  rolleriGetir: api.roller,
  lokalKullaniciOlustur: api.olustur,
  kullaniciGuncelle: api.guncelle,
}))

const ERP_KULLANICISI: YonetilenKullanici = {
  id: 2,
  ad: 'Ayşe Yılmaz',
  kullanici_adi: 'AYILMAZ',
  email: 'ayse@ornek.test',
  kaynak: 'erp',
  sistem_yoneticisi: false,
  aktif_mi: true,
  rol_idleri: [7],
  pasif_yapilamaz: false,
}

const YEDEK_ADMIN: YonetilenKullanici = {
  id: 1,
  ad: 'Yönetici',
  kullanici_adi: 'admin',
  email: null,
  kaynak: 'lokal',
  sistem_yoneticisi: true,
  aktif_mi: true,
  rol_idleri: [],
  pasif_yapilamaz: true,
}

const LOKAL: YonetilenKullanici = {
  id: 3,
  ad: 'Dış Denetçi',
  kullanici_adi: 'denetci',
  email: null,
  kaynak: 'lokal',
  sistem_yoneticisi: false,
  aktif_mi: false,
  rol_idleri: [],
  pasif_yapilamaz: false,
}

function ciz(izinler = ['kullanicilar.goruntule', 'kullanicilar.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <KullanicilarPage />
      </SahteOturum>
    </AppProviders>,
  )
}

function satir(ad: string): HTMLElement {
  return screen.getByText(ad).closest('tr') as HTMLElement
}

describe('KullanicilarPage', () => {
  beforeEach(() => {
    api.kullanicilar.mockResolvedValue([YEDEK_ADMIN, ERP_KULLANICISI, LOKAL])
    api.roller.mockResolvedValue([
      { id: 7, ad: 'Muhasebe', aciklama: null, izinler: [], kullanici_sayisi: 1 },
      { id: 8, ad: 'Denetim', aciklama: null, izinler: [], kullanici_sayisi: 0 },
    ])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kullanıcıları kaynak, rol ve durum bilgisiyle listeler', async () => {
    ciz()

    expect(await screen.findByText('Ayşe Yılmaz')).toBeInTheDocument()
    await waitFor(() => expect(within(satir('Ayşe Yılmaz')).getByText('Muhasebe')).toBeVisible())
    expect(within(satir('Ayşe Yılmaz')).getByText('ERP')).toBeInTheDocument()
    expect(within(satir('Yönetici')).getByText('Sistem Yöneticisi')).toBeInTheDocument()
    expect(within(satir('Dış Denetçi')).getByText('Pasif')).toBeInTheDocument()
  })

  it('aramayla listeyi süzer', async () => {
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.change(screen.getByLabelText('Ad veya kullanıcı adı ara'), {
      target: { value: 'denet' },
    })

    expect(screen.queryByText('Ayşe Yılmaz')).not.toBeInTheDocument()
    expect(screen.getByText('Dış Denetçi')).toBeInTheDocument()
  })

  it('ERP kullanıcısında ad/e-posta/şifre alanı göstermez, yalnız rol ve durumu gönderir', async () => {
    api.guncelle.mockResolvedValue(ERP_KULLANICISI)
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(within(satir('Ayşe Yılmaz')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByLabelText('Ad Soyad')).not.toBeInTheDocument()
    expect(dialog).toHaveTextContent("ERP'den gelir")

    fireEvent.click(within(dialog).getByRole('switch', { name: 'Aktif' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    expect(api.guncelle).toHaveBeenCalledWith(2, { aktif_mi: false, rol_idleri: [7] })
  })

  it('pasif yapılamayan kullanıcıda aktif anahtarı kilitlidir', async () => {
    ciz()
    await screen.findByText('Yönetici')

    fireEvent.click(within(satir('Yönetici')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByRole('switch', { name: 'Aktif' })).toBeDisabled()
  })

  it('lokal kullanıcıda boş şifreyi göndermez', async () => {
    api.guncelle.mockResolvedValue(LOKAL)
    ciz()
    await screen.findByText('Dış Denetçi')

    fireEvent.click(within(satir('Dış Denetçi')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('E-posta'), {
      target: { value: 'denetci@ornek.test' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    expect(api.guncelle).toHaveBeenCalledWith(3, {
      ad: 'Dış Denetçi',
      email: 'denetci@ornek.test',
      aktif_mi: false,
      rol_idleri: [],
    })
  })

  it('yeni lokal kullanıcıyı seçilen rolle oluşturur', async () => {
    api.olustur.mockResolvedValue(LOKAL)
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(screen.getByRole('button', { name: 'Lokal Kullanıcı Ekle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kullanıcı Adı'), {
      target: { value: 'yeni.kisi' },
    })
    fireEvent.change(within(dialog).getByLabelText('Ad Soyad'), { target: { value: 'Yeni Kişi' } })
    fireEvent.change(within(dialog).getByLabelText('Şifre'), { target: { value: 'guclu1sifre' } })

    const roller = within(dialog).getByLabelText('Roller')
    fireEvent.keyDown(roller, { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('Denetim'))

    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.olustur).toHaveBeenCalledTimes(1))
    expect(api.olustur).toHaveBeenCalledWith({
      kullanici_adi: 'yeni.kisi',
      ad: 'Yeni Kişi',
      email: null,
      sifre: 'guclu1sifre',
      rol_idleri: [8],
    })
  })

  it('boş şifre ve kurala uymayan kullanıcı adıyla istek atmaz', async () => {
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(screen.getByRole('button', { name: 'Lokal Kullanıcı Ekle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kullanıcı Adı'), {
      target: { value: 'a b' },
    })
    fireEvent.change(within(dialog).getByLabelText('Ad Soyad'), { target: { value: 'Kişi' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByText('Şifre zorunludur.')).toBeInTheDocument()
    expect(within(dialog).getByText(/Kullanıcı adı 3-64 karakter olmalı/)).toBeInTheDocument()
    expect(api.olustur).not.toHaveBeenCalled()
  })

  it('şifre serbesttir: tek karakterlik şifre kabul edilir ve gönderilir', async () => {
    api.olustur.mockResolvedValue(LOKAL)
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(screen.getByRole('button', { name: 'Lokal Kullanıcı Ekle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kullanıcı Adı'), {
      target: { value: 'kisa.sifre' },
    })
    fireEvent.change(within(dialog).getByLabelText('Ad Soyad'), { target: { value: 'Kişi' } })
    fireEvent.change(within(dialog).getByLabelText('Şifre'), { target: { value: 'a' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.olustur).toHaveBeenCalledTimes(1))
    expect(api.olustur).toHaveBeenCalledWith({
      kullanici_adi: 'kisa.sifre',
      ad: 'Kişi',
      email: null,
      sifre: 'a',
      rol_idleri: [],
    })
  })

  it('backend hatasını formda çevrilmiş gösterir', async () => {
    api.olustur.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: {
          status: 422,
          data: { kod: 'DOGRULAMA_HATASI', mesaj: "Bu kullanıcı adı ERP'de zaten var." },
        },
      }),
    )
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(screen.getByRole('button', { name: 'Lokal Kullanıcı Ekle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Kullanıcı Adı'), {
      target: { value: 'ayilmaz' },
    })
    fireEvent.change(within(dialog).getByLabelText('Ad Soyad'), { target: { value: 'Kişi' } })
    fireEvent.change(within(dialog).getByLabelText('Şifre'), { target: { value: 'guclu1sifre' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByRole('alert')).toBeInTheDocument()
  })

  it('yalnız görüntüleme izninde ekleme ve düzenleme gizlidir', async () => {
    ciz(['kullanicilar.goruntule'])

    expect(await screen.findByText('Ayşe Yılmaz')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Lokal Kullanıcı Ekle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
  })
})
