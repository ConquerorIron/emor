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
  tanimla: vi.fn(),
  guncelle: vi.fn(),
}))

vi.mock('@/features/ayarlar/yetkiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/ayarlar/yetkiApi')>()),
  kullanicilariGetir: api.kullanicilar,
  rolleriGetir: api.roller,
  erpKullanicisiTanimla: api.tanimla,
  kullaniciGuncelle: api.guncelle,
}))

/** Uygulamada tanımlı, giriş izni olan ERP kullanıcısı */
const TANIMLI: YonetilenKullanici = {
  id: 2,
  erp_kullanici_id: 40,
  ad: 'Ayşe Yılmaz',
  kullanici_adi: 'AYILMAZ',
  kaynak: 'erp',
  sistem_yoneticisi: false,
  aktif_mi: true,
  rol_idleri: [7],
  pasif_yapilamaz: false,
  erpde_yok: false,
}

/** ERP'de var, uygulamada henüz tanımlı değil */
const TANIMSIZ: YonetilenKullanici = {
  id: null,
  erp_kullanici_id: 41,
  ad: 'Özalp DOĞANALP',
  kullanici_adi: 'ozalp.doganalp',
  kaynak: 'erp',
  sistem_yoneticisi: false,
  aktif_mi: false,
  rol_idleri: [],
  pasif_yapilamaz: false,
  erpde_yok: false,
}

const YEDEK_ADMIN: YonetilenKullanici = {
  id: 1,
  erp_kullanici_id: null,
  ad: 'Yönetici',
  kullanici_adi: 'admin',
  kaynak: 'lokal',
  sistem_yoneticisi: true,
  aktif_mi: true,
  rol_idleri: [],
  pasif_yapilamaz: true,
  erpde_yok: false,
}

function ciz(
  izinler = ['kullanicilar.goruntule', 'kullanicilar.guncelle'],
  sistemYoneticisi = true,
) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler} sistemYoneticisi={sistemYoneticisi}>
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
    api.kullanicilar.mockResolvedValue({
      kullanicilar: [YEDEK_ADMIN, TANIMLI, TANIMSIZ],
      erpOkunamadi: false,
    })
    api.roller.mockResolvedValue([
      { id: 7, ad: 'Muhasebe', aciklama: null, izinler: [], kullanici_sayisi: 1 },
      { id: 8, ad: 'Denetim', aciklama: null, izinler: [], kullanici_sayisi: 0 },
    ])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('ERP kullanıcılarını giriş izni ve rolleriyle listeler', async () => {
    ciz()

    expect(await screen.findByText('Ayşe Yılmaz')).toBeInTheDocument()
    await waitFor(() => expect(within(satir('Ayşe Yılmaz')).getByText('Muhasebe')).toBeVisible())
    expect(within(satir('Ayşe Yılmaz')).getByText('Giriş izni var')).toBeInTheDocument()
    expect(within(satir('Özalp DOĞANALP')).getByText('Giriş izni yok')).toBeInTheDocument()
    expect(within(satir('Yönetici')).getByText('Sistem Yöneticisi')).toBeInTheDocument()
  })

  it('aramayla ve yalnız izinliler anahtarıyla listeyi süzer', async () => {
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.change(screen.getByLabelText('Ad veya kullanıcı adı ara'), {
      target: { value: 'özalp' },
    })
    expect(screen.queryByText('Ayşe Yılmaz')).not.toBeInTheDocument()
    expect(screen.getByText('Özalp DOĞANALP')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Ad veya kullanıcı adı ara'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('switch', { name: 'Yalnız giriş izni olanlar' }))
    expect(screen.queryByText('Özalp DOĞANALP')).not.toBeInTheDocument()
    expect(screen.getByText('Ayşe Yılmaz')).toBeInTheDocument()
  })

  it('tanımsız ERP kullanıcısına giriş izni ve rol verilince tanımlanır', async () => {
    api.tanimla.mockResolvedValue({ ...TANIMSIZ, id: 9, aktif_mi: true, rol_idleri: [8] })
    ciz()
    await screen.findByText('Özalp DOĞANALP')

    fireEvent.click(within(satir('Özalp DOĞANALP')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    // Amaç izin vermek: anahtar açık gelir
    expect(within(dialog).getByRole('switch', { name: 'Uygulamaya giriş yapabilir' })).toBeChecked()

    fireEvent.keyDown(within(dialog).getByLabelText('Roller'), { key: 'ArrowDown' })
    fireEvent.click(await screen.findByText('Denetim'))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.tanimla).toHaveBeenCalledWith({
        erp_kullanici_id: 41,
        aktif_mi: true,
        rol_idleri: [8],
      }),
    )
    expect(api.guncelle).not.toHaveBeenCalled()
  })

  it('tanımlı kullanıcının giriş izni kaldırılır; ad ve şifre alanı yoktur', async () => {
    api.guncelle.mockResolvedValue({ ...TANIMLI, aktif_mi: false })
    ciz()
    await screen.findByText('Ayşe Yılmaz')

    fireEvent.click(within(satir('Ayşe Yılmaz')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).queryByLabelText(/Şifre/)).not.toBeInTheDocument()
    expect(dialog).toHaveTextContent("ERP'den gelir")

    fireEvent.click(within(dialog).getByRole('switch', { name: 'Uygulamaya giriş yapabilir' }))
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() =>
      expect(api.guncelle).toHaveBeenCalledWith(2, { aktif_mi: false, rol_idleri: [7] }),
    )
  })

  it('giriş izni kaldırılamayan kullanıcıda anahtar kilitlidir', async () => {
    ciz()
    await screen.findByText('Yönetici')

    fireEvent.click(within(satir('Yönetici')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')

    expect(
      within(dialog).getByRole('switch', { name: 'Uygulamaya giriş yapabilir' }),
    ).toBeDisabled()
  })

  it('yönetici olmayan sistem yöneticisi hesabını düzenleyemez', async () => {
    ciz(undefined, false)
    await screen.findByText('Yönetici')

    expect(
      within(satir('Yönetici')).queryByRole('button', { name: 'Düzenle' }),
    ).not.toBeInTheDocument()
    expect(
      within(satir('Ayşe Yılmaz')).getByRole('button', { name: 'Düzenle' }),
    ).toBeInTheDocument()
  })

  it('backend hatasını formda gösterir', async () => {
    api.tanimla.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: {
          status: 422,
          data: {
            kod: 'DOGRULAMA_HATASI',
            mesaj: 'Geçersiz',
            hatalar: { rol_idleri: ['Yalnız kendinizde olan izinleri verebilirsiniz.'] },
          },
        },
      }),
    )
    ciz()
    await screen.findByText('Özalp DOĞANALP')

    fireEvent.click(within(satir('Özalp DOĞANALP')).getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByRole('alert')).toBeInTheDocument()
  })

  it('ERP okunamazsa uyarı gösterir', async () => {
    api.kullanicilar.mockResolvedValue({ kullanicilar: [YEDEK_ADMIN], erpOkunamadi: true })
    ciz()

    expect(await screen.findByText(/ERP'ye ulaşılamadı/)).toBeInTheDocument()
  })

  it('yalnız görüntüleme izninde düzenleme gizlidir', async () => {
    ciz(['kullanicilar.goruntule'])

    expect(await screen.findByText('Ayşe Yılmaz')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
  })
})
