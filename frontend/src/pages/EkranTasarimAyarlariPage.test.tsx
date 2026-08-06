import { cleanup, fireEvent, render as rtlRender, screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '../i18n/i18n'
import { AppProviders } from '../providers/AppProviders'
import { EkranTasarimAyarlariPage } from './EkranTasarimAyarlariPage'

const kaydedilen = vi.fn()

vi.mock('@/features/ekranTasarim/api', () => ({
  ekranTaslaginiGetir: () => Promise.resolve(SAHTE_TASLAK),
  ekranTaslaginiKaydet: (_ekran: string, duzen: unknown) => {
    kaydedilen(duzen)

    return Promise.resolve(duzen)
  },
  ekranTasariminiYayinla: () => Promise.resolve(SAHTE_TASLAK.duzen),
}))

function katalogAlani(anahtar: string, ekstra: Record<string, unknown> = {}) {
  return {
    anahtar,
    etiket_anahtari: `satinalma.alan.${anahtar}`,
    giris_tipi: 'metin',
    veri_anahtarlari: [anahtar],
    proc_parametresi: '',
    varsayilan_genislik: 6,
    kaldirilamaz: false,
    salt_okunur_sabit: false,
    zorunlu_secilebilir: true,
    metin_alani: false,
    metin_limiti: 0,
    ...ekstra,
  }
}

const SAHTE_TASLAK = {
  ekran: 'satinalma.talep',
  baslik_anahtari: 'satinalma.baslik',
  surum: 3,
  yayinda_surum: 2,
  bolumler: [{ anahtar: 'talep', baslik_anahtari: 'satinalma.talepBilgileri' }],
  katalog: [
    katalogAlani('personelAdi', { kaldirilamaz: true }),
    katalogAlani('tarih'),
    // Tasarımda yeri yok → palette görünmeli
    katalogAlani('oncelik'),
  ],
  duzen: {
    bolumler: [
      {
        anahtar: 'talep',
        genislik: 6,
        alanlar: [
          { alan: 'personelAdi', genislik: 6 },
          { alan: 'tarih', genislik: 6 },
        ],
      },
    ],
  },
}

function render() {
  return rtlRender(
    <AppProviders>
      <EkranTasarimAyarlariPage />
    </AppProviders>,
  )
}

describe('EkranTasarimAyarlariPage', () => {
  afterEach(() => {
    cleanup()
    kaydedilen.mockClear()
  })

  it('tuval ve kullanılmayan alan paleti yüklenir', async () => {
    render()

    expect(await screen.findByDisplayValue('Talep Bilgileri')).toBeInTheDocument()
    expect(screen.getByText('Yayında: sürüm 2')).toBeInTheDocument()

    // Tasarımda yeri olmayan alan palette
    expect(screen.getByText('Kullanılmayan Alanlar')).toBeInTheDocument()
    expect(screen.getByText('Öncelik')).toBeInTheDocument()
  })

  it('alan seçilince ayar paneli açılır ve genişlik değiştirilir', async () => {
    render()
    await screen.findByDisplayValue('Talep Bilgileri')

    fireEvent.click(screen.getByText('Tarih'))

    // Panel açıldı: zorunluluk anahtarı görünür
    expect(await screen.findByText('Genişlik')).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Zorunlu alan' })).toBeInTheDocument()

    // Alan genişliğini 12'ye çek (bölüm genişliği düğmeleriyle karışmasın diye
    // ikisi de ayrı aria-label taşır)
    fireEvent.click(screen.getByRole('button', { name: 'Genişlik 12' }))
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Genişlik 12' })).toHaveAttribute(
        'aria-pressed',
        'true',
      )
    })
  })

  it('gizlenen alanda zorunluluk anahtarı kalkar, açıklama görünür', async () => {
    render()
    await screen.findByDisplayValue('Talep Bilgileri')

    fireEvent.click(screen.getByText('Tarih'))
    expect(await screen.findByRole('switch', { name: 'Zorunlu alan' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('switch', { name: 'Formda gizle' }))

    await waitFor(() => {
      expect(screen.queryByRole('switch', { name: 'Zorunlu alan' })).not.toBeInTheDocument()
    })
    expect(screen.getByText(/varsayılan değer kayda gönderilir/)).toBeInTheDocument()
  })

  it('kaldırılamaz alanda çıkarma düğmesi yerine uyarı gösterilir', async () => {
    render()
    await screen.findByDisplayValue('Talep Bilgileri')

    fireEvent.click(screen.getByText('Personel adı'))

    expect(
      await screen.findByText('Bu alan kayıt için zorunludur, formdan çıkarılamaz.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Alanı formdan çıkar' })).not.toBeInTheDocument()
  })

  it('bölüm başlığı düzenlenebilir ve boş bırakılabilir', async () => {
    render()
    const baslik = await screen.findByDisplayValue('Talep Bilgileri')

    fireEvent.change(baslik, { target: { value: 'Genel' } })
    await waitFor(() => {
      expect(screen.getByDisplayValue('Genel')).toBeInTheDocument()
    })

    // Boşaltmak "başlık gösterme" demek; alan kaybolmaz
    fireEvent.change(screen.getByDisplayValue('Genel'), { target: { value: '' } })
    await waitFor(() => {
      expect(screen.getByLabelText(/Bölüm başlığı/)).toHaveValue('')
    })
  })

  it('taslak kaydedilir', async () => {
    render()
    await screen.findByDisplayValue('Talep Bilgileri')

    fireEvent.click(screen.getByRole('button', { name: 'Taslağı Kaydet' }))

    await waitFor(() => {
      expect(kaydedilen).toHaveBeenCalledTimes(1)
    })
  })
})
