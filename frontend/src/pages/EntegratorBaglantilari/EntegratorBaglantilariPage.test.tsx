import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type {
  EntegratorBaglantilar,
  EntegratorSinamaSonucu,
} from '@/features/ayarlar/entegratorApi'
import { AppProviders } from '@/providers/AppProviders'

import { EntegratorBaglantilariPage } from './EntegratorBaglantilariPage'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  guncelle: vi.fn(),
  sina: vi.fn(),
  aktifYap: vi.fn(),
}))

vi.mock('@/features/ayarlar/entegratorApi', () => ({
  entegratorBaglantilariGetir: api.getir,
  entegratorBaglantiGuncelle: api.guncelle,
  entegratorBaglantiSina: api.sina,
  entegratorAktifOrtamDegistir: api.aktifYap,
}))

const TEST_TANIMI = {
  id: 1,
  saglayici: 'izibiz' as const,
  ortam: 'test' as const,
  api_url: 'https://apitest.izibiz.com.tr',
  api_url_ozel: false,
  portal_url: 'https://portaltest.izibiz.com.tr',
  kullanici_adi: 'deneme-kullanici',
  vkn: '1234567890',
  posta_kutusu: 'urn:mail:deneme-pk@ornek.test',
  gonderici_birim: null,
  aktif: true,
  sifre_dolu: true,
  updated_at: '2026-09-23T10:00:00Z',
}

function veri(degisen: Partial<EntegratorBaglantilar> = {}): EntegratorBaglantilar {
  return {
    test: TEST_TANIMI,
    canli: null,
    aktif_ortam: 'test',
    sql_aktif_ortam: 'test',
    ortam_uyumsuz: false,
    varsayilan_api_url: {
      test: 'https://apitest.izibiz.com.tr',
      canli: 'https://api.izibiz.com.tr',
    },
    ...degisen,
  }
}

function testKartiAdresi(): HTMLInputElement {
  return document.getElementById('entegrator-test-api-adresi') as HTMLInputElement
}

function ciz() {
  render(
    <AppProviders>
      <EntegratorBaglantilariPage />
    </AppProviders>,
  )
}

/** Test kartı sayfadaki ilk formdur (Test, Canlı sırasıyla çizilir). */
function testKartiKullanicisi(): HTMLInputElement {
  return document.getElementById('entegrator-test-kullanici') as HTMLInputElement
}

describe('EntegratorBaglantilariPage', () => {
  beforeEach(() => {
    api.getir.mockResolvedValue(veri())
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('kayıtlı tanımı gösterir; varsayılan adreste adres alanı boş ve ipucu varsayılandır, şifre gösterilmez', async () => {
    ciz()

    expect(await screen.findByDisplayValue('deneme-kullanici')).toBeInTheDocument()
    expect(testKartiAdresi()).toHaveValue('')
    expect(testKartiAdresi()).toHaveAttribute('placeholder', 'https://apitest.izibiz.com.tr')
    expect(document.getElementById('entegrator-canli-api-adresi')).toHaveAttribute(
      'placeholder',
      'https://api.izibiz.com.tr',
    )
    expect(document.getElementById('entegrator-test-sifre')).toHaveValue('')
  })

  it('ekrandan tanımlanmış adresi alanda gösterir ve kaydederken gönderir', async () => {
    const ozel = { ...TEST_TANIMI, api_url: 'https://apitest2.izibiz.com.tr', api_url_ozel: true }
    api.getir.mockResolvedValue(veri({ test: ozel }))
    api.guncelle.mockResolvedValue(ozel)
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    expect(testKartiAdresi()).toHaveValue('https://apitest2.izibiz.com.tr')
    fireEvent.click(screen.getAllByRole('button', { name: 'Kaydet' })[0])

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    expect(api.guncelle).toHaveBeenCalledWith(
      'test',
      expect.objectContaining({ api_url: 'https://apitest2.izibiz.com.tr' }),
    )
  })

  it('https olmayan adresle istek atmadan hata gösterir', async () => {
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.change(testKartiAdresi(), { target: { value: 'http://apitest.izibiz.com.tr' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Kaydet' })[0])

    expect(
      await screen.findByText('Adres https://alan-adı biçiminde olmalı (yol veya sorgu içermez).'),
    ).toBeInTheDocument()
    expect(api.guncelle).not.toHaveBeenCalled()
  })

  it('SQL ve entegratör farklı ortamdaysa uyarı gösterir', async () => {
    api.getir.mockResolvedValue(
      veri({ aktif_ortam: 'test', sql_aktif_ortam: 'canli', ortam_uyumsuz: true }),
    )
    ciz()

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'ERP (SQL) ve entegratör farklı ortamlarda',
    )
  })

  it('boş şifreyle kaydederken şifreyi göndermez', async () => {
    api.guncelle.mockResolvedValue(TEST_TANIMI)
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.click(screen.getAllByRole('button', { name: 'Kaydet' })[0])

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    expect(api.guncelle).toHaveBeenCalledWith('test', {
      // Boş adres = ortamın varsayılanı
      api_url: null,
      kullanici_adi: 'deneme-kullanici',
      vkn: '1234567890',
      posta_kutusu: 'urn:mail:deneme-pk@ornek.test',
      gonderici_birim: null,
    })
  })

  it('kayıttan sonra girilen şifreyi formdan temizler', async () => {
    api.guncelle.mockResolvedValue({ ...TEST_TANIMI, updated_at: '2026-09-23T11:00:00Z' })
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    const sifre = document.getElementById('entegrator-test-sifre') as HTMLInputElement
    fireEvent.change(sifre, { target: { value: 'yeni-sifre' } })
    fireEvent.click(screen.getAllByRole('button', { name: 'Kaydet' })[0])

    await waitFor(() => expect(api.guncelle).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(document.getElementById('entegrator-test-sifre')).toHaveValue(''))
  })

  it('geçersiz VKN ile istek atmadan alan hatası gösterir', async () => {
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.change(document.getElementById('entegrator-test-vkn') as HTMLInputElement, {
      target: { value: '12AB' },
    })
    fireEvent.click(screen.getAllByRole('button', { name: 'Kaydet' })[0])

    expect(
      await screen.findByText('VKN 10, TCKN 11 haneli olmalı ve yalnız rakam içermelidir.'),
    ).toBeInTheDocument()
    expect(api.guncelle).not.toHaveBeenCalled()
  })

  it('sınama sonucunu gösterir, form değişince sonucu kaldırır', async () => {
    api.sina.mockResolvedValue({
      musteri_tipi: 'C',
      gecerlilik_bitis: '2026-09-23T23:24:41+00:00',
    })
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.click(screen.getAllByRole('button', { name: 'Bağlantıyı Sına' })[0])

    expect(await screen.findByText('Entegratöre giriş başarılı')).toBeInTheDocument()
    expect(api.sina).toHaveBeenCalledWith('test', {
      api_url: null,
      kullanici_adi: 'deneme-kullanici',
    })

    fireEvent.change(testKartiKullanicisi(), { target: { value: 'baska-kullanici' } })

    expect(screen.queryByText('Entegratöre giriş başarılı')).not.toBeInTheDocument()
  })

  it('sınama sürerken form değişirse eski isteğin sonucunu göstermez', async () => {
    let sinamayiBitir!: (sonuc: EntegratorSinamaSonucu) => void
    api.sina.mockImplementation(
      () => new Promise<EntegratorSinamaSonucu>((resolve) => (sinamayiBitir = resolve)),
    )
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.click(screen.getAllByRole('button', { name: 'Bağlantıyı Sına' })[0])
    await waitFor(() => expect(api.sina).toHaveBeenCalledTimes(1))
    fireEvent.change(testKartiKullanicisi(), { target: { value: 'baska-kullanici' } })

    await act(async () => {
      sinamayiBitir({ musteri_tipi: 'C', gecerlilik_bitis: '2026-09-23T23:24:41+00:00' })
    })

    expect(screen.queryByText('Entegratöre giriş başarılı')).not.toBeInTheDocument()
  })

  it('aktif ortam değişince formdaki kaydedilmemiş alanı korur', async () => {
    api.getir
      .mockResolvedValueOnce(veri({ test: { ...TEST_TANIMI, aktif: false }, aktif_ortam: null }))
      .mockResolvedValue(veri({ test: { ...TEST_TANIMI, updated_at: '2026-09-23T11:00:00Z' } }))
    api.aktifYap.mockResolvedValue(TEST_TANIMI)
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    const vkn = document.getElementById('entegrator-test-vkn') as HTMLInputElement
    fireEvent.change(vkn, { target: { value: '9876543210' } })
    fireEvent.click(
      screen.getByRole('button', { name: /TestFaturalar entegratörün test hesabından okunur/ }),
    )

    await waitFor(() => expect(api.getir).toHaveBeenCalledTimes(2))
    expect(document.getElementById('entegrator-test-vkn')).toHaveValue('9876543210')
  })

  it('sınama kimlik hatasında çevrilmiş mesajı gösterir', async () => {
    api.sina.mockRejectedValue(
      Object.assign(new Error('istek'), {
        isAxiosError: true,
        response: { status: 422, data: { kod: 'ENTEGRATOR_KIMLIK_HATALI' } },
      }),
    )
    ciz()
    await screen.findByDisplayValue('deneme-kullanici')

    fireEvent.click(screen.getAllByRole('button', { name: 'Bağlantıyı Sına' })[0])

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Entegratör kullanıcı adı veya şifresi hatalı.',
    )
  })
})
