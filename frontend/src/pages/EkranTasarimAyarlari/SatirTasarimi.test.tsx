import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { DuzenSatirKolonu, SatirKatalogAlani } from '@/features/ekranTasarim/types'

import { SatirTasarimi } from './SatirTasarimi'

afterEach(cleanup)

function alan(anahtar: string, etiket: string, ekstra: Partial<SatirKatalogAlani> = {}) {
  return {
    anahtar,
    etiket_anahtari: `satinalma.alan.${anahtar}`,
    etiket,
    varsayilan_genislik: 120,
    kaldirilamaz: false,
    salt_okunur_sabit: false,
    ...ekstra,
  } satisfies SatirKatalogAlani
}

const KATALOG: SatirKatalogAlani[] = [
  alan('urunKodu', 'Ürün kodu', { kaldirilamaz: true }),
  alan('miktar', 'Miktar'),
  alan('barkod', 'Barkod'),
]

/** Sürükle-bırak veri taşıyıcısı — jsdom'da DataTransfer yok */
function tasiyici(veri: string) {
  return { getData: () => veri, setData: vi.fn() }
}

function ciz(satirlar: DuzenSatirKolonu[], degisti = vi.fn()) {
  render(
    <SatirTasarimi
      katalog={KATALOG}
      satirlar={satirlar}
      birim="px"
      degisti={degisti}
      birimDegisti={vi.fn()}
    />,
  )

  return degisti
}

describe('satır tasarımında kullanılmayan alanlar paleti', () => {
  it('tasarımda yeri olmayan kolonları palette listeler', () => {
    ciz([{ alan: 'urunKodu', genislik: 120 }])

    // Palet başlığı ile şeridi ayırt etmek için palet kabını hedefliyoruz
    const palet = screen.getByRole('heading', { name: 'Kullanılmayan Alanlar' })
      .parentElement as HTMLElement

    expect(screen.getAllByText('Barkod')).toHaveLength(1)
    expect(palet.textContent).toContain('Miktar')
    expect(palet.textContent).toContain('Barkod')
  })

  it('paletteki alan tıklanınca tasarıma eklenir', () => {
    const degisti = ciz([{ alan: 'urunKodu', genislik: 120 }])

    fireEvent.click(screen.getByRole('button', { name: 'Barkod' }))

    expect(degisti).toHaveBeenCalledWith([
      { alan: 'urunKodu', genislik: 120 },
      { alan: 'barkod', genislik: 120, gizli: false },
    ])
  })

  it('kolon palete sürüklenince tasarımdan çıkar', () => {
    const degisti = ciz([
      { alan: 'urunKodu', genislik: 120 },
      { alan: 'miktar', genislik: 90 },
    ])

    const palet = screen.getByRole('heading', { name: 'Kullanılmayan Alanlar' })
      .parentElement as HTMLElement

    fireEvent.drop(palet, {
      dataTransfer: tasiyici(JSON.stringify({ alan: 'miktar', kaynak: 'serit' })),
    })

    expect(degisti).toHaveBeenCalledWith([{ alan: 'urunKodu', genislik: 120 }])
  })

  it('kaldırılamaz kolon palete bırakılsa da tasarımda kalır', () => {
    const degisti = ciz([{ alan: 'urunKodu', genislik: 120 }])

    const palet = screen.getByRole('heading', { name: 'Kullanılmayan Alanlar' })
      .parentElement as HTMLElement

    fireEvent.drop(palet, {
      dataTransfer: tasiyici(JSON.stringify({ alan: 'urunKodu', kaynak: 'serit' })),
    })

    expect(degisti).not.toHaveBeenCalled()
  })
})
