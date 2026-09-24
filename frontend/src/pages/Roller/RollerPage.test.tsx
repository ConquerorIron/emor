import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { EkranIzni, Rol } from '@/features/ayarlar/yetkiApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { RollerPage } from './RollerPage'

const api = vi.hoisted(() => ({
  izinler: vi.fn(),
  roller: vi.fn(),
  kaydet: vi.fn(),
  sil: vi.fn(),
}))

vi.mock('@/features/ayarlar/yetkiApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/ayarlar/yetkiApi')>()),
  izinleriGetir: api.izinler,
  rolleriGetir: api.roller,
  rolKaydet: api.kaydet,
  rolSil: api.sil,
}))

const MUHASEBE: Rol = {
  id: 7,
  ad: 'Muhasebe',
  aciklama: null,
  izinler: ['efatura.goruntule', 'efatura.pdf'],
  kullanici_sayisi: 3,
}

function ciz(izinler = ['roller.goruntule', 'roller.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <RollerPage />
      </SahteOturum>
    </AppProviders>,
  )
}

const KATALOG: EkranIzni[] = [
  {
    ekran: 'efatura',
    goruntule: 'efatura.goruntule',
    guncelle: 'efatura.senkron',
    ekler: ['efatura.pdf', 'efatura.disari_aktar'],
  },
  {
    ekran: 'sql_baglantilari',
    goruntule: 'sql_baglantilari.goruntule',
    guncelle: 'sql_baglantilari.guncelle',
    ekler: [],
  },
  { ekran: 'roller', goruntule: 'roller.goruntule', guncelle: 'roller.guncelle', ekler: [] },
]

describe('RollerPage', () => {
  beforeEach(() => {
    api.izinler.mockResolvedValue(KATALOG)
    api.roller.mockResolvedValue([MUHASEBE])
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('rolün izinlerini ekran başına özetler ve kullanıcı sayısını gösterir', async () => {
    ciz()

    expect(await screen.findByText('Muhasebe')).toBeInTheDocument()
    expect(
      await screen.findByText('e-Faturalar (Gelen/Giden): Görüntüle (e-Fatura PDF görüntüleme)'),
    ).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('güncelleme açılınca görüntüleme de açılır ve ikisi birlikte kaydedilir', async () => {
    api.kaydet.mockResolvedValue({ ...MUHASEBE, id: 8, ad: 'Denetçi' })
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Rol' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Rol Adı'), { target: { value: 'Denetçi' } })
    fireEvent.click(within(dialog).getByRole('switch', { name: 'SQL Bağlantıları — Güncelle' }))

    expect(
      within(dialog).getByRole('switch', { name: 'SQL Bağlantıları — Görüntüle' }),
    ).toBeChecked()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, {
      ad: 'Denetçi',
      aciklama: null,
      izinler: ['sql_baglantilari.goruntule', 'sql_baglantilari.guncelle'],
    })
  })

  it('ek izin açılınca ekranın görüntüleme izni de açılır', async () => {
    api.kaydet.mockResolvedValue(MUHASEBE)
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Rol' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Rol Adı'), { target: { value: 'Denetçi' } })
    fireEvent.click(
      within(dialog).getByRole('switch', { name: "e-Fatura listesini Excel'e aktarma" }),
    )
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(null, {
      ad: 'Denetçi',
      aciklama: null,
      izinler: ['efatura.goruntule', 'efatura.disari_aktar'],
    })
  })

  it('boş adla istek atmadan hata gösterir', async () => {
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Yeni Rol' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByText('Rol adı zorunludur.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })

  it('görüntüleme kapatılınca ekranın ek izinleri de kapanır', async () => {
    api.kaydet.mockResolvedValue(MUHASEBE)
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    const pdf = within(dialog).getByRole('switch', { name: 'e-Fatura PDF görüntüleme' })
    expect(pdf).toBeChecked()

    fireEvent.click(
      within(dialog).getByRole('switch', { name: 'e-Faturalar (Gelen/Giden) — Görüntüle' }),
    )
    expect(pdf).not.toBeChecked()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith(7, { ad: 'Muhasebe', aciklama: null, izinler: [] })
  })

  it('backend reddederse doğrulama mesajını formda gösterir', async () => {
    api.kaydet.mockRejectedValue(
      Object.assign(new Error('422'), {
        isAxiosError: true,
        response: {
          status: 422,
          data: {
            hatalar: { izinler: ['Sahip olmadığınız bir izni veremezsiniz.'] },
          },
        },
      }),
    )
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Düzenle' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Kaydet' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Sahip olmadığınız bir izni veremezsiniz.',
    )
  })

  it('silmeyi onaydan sonra yapar', async () => {
    api.sil.mockResolvedValue(undefined)
    ciz()
    await screen.findByText('Muhasebe')

    fireEvent.click(screen.getByRole('button', { name: 'Sil' }))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent('3 kullanıcı bu rolün izinlerini kaybedecek')
    expect(api.sil).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Sil' }))

    await waitFor(() => expect(api.sil).toHaveBeenCalledWith(7))
  })

  it('yalnız görüntüleme izninde ekleme, düzenleme ve silme gizlidir', async () => {
    ciz(['roller.goruntule'])

    expect(await screen.findByText('Muhasebe')).toBeInTheDocument()
    expect(screen.getByText('Bu ekranı yalnız görüntüleme yetkiniz var.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Yeni Rol' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Düzenle' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Sil' })).not.toBeInTheDocument()
  })
})
