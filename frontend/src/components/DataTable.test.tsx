import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'

import { DataTable } from './DataTable'

function genislikleriAyarla(scrollWidth: number, clientWidth: number) {
  vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(scrollWidth)
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(clientWidth)
}

function ciz() {
  render(
    <DataTable
      kolonlar={[{ anahtar: 'ad', baslik: 'Ad', render: (s: { ad: string }) => s.ad }]}
      satirlar={[{ ad: 'Birinci' }]}
      satirAnahtari={(s) => s.ad}
      ustKaydirma
    />,
  )
}

describe('DataTable üst kaydırma çubuğu', () => {
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('tablo taşıyorsa üstte de yatay kaydırma çubuğu çizer', () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        disconnect() {}
      },
    )
    genislikleriAyarla(2000, 800)

    ciz()

    expect(screen.getByTestId('ust-kaydirma').firstElementChild).toHaveStyle({ width: '2000px' })
  })

  it('tablo sığıyorsa üst çubuk çizilmez', () => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe() {}
        disconnect() {}
      },
    )
    genislikleriAyarla(800, 800)

    ciz()

    expect(screen.queryByTestId('ust-kaydirma')).not.toBeInTheDocument()
  })

  describe('iki çubuğun eşlenmesi', () => {
    /** jsdom kaydırmaz: konum, tarayıcı gibi [0, azami] aralığına kırpılan bir değerle taklit edilir. */
    function kaydirilabilir(oge: HTMLElement, azami: number) {
      let konum = 0
      Object.defineProperty(oge, 'scrollLeft', {
        configurable: true,
        get: () => konum,
        set: (deger: number) => {
          konum = Math.min(Math.max(deger, 0), azami)
        },
      })
    }

    function cubuklar(ustAzami: number, tabloAzami: number) {
      vi.stubGlobal(
        'ResizeObserver',
        class {
          observe() {}
          disconnect() {}
        },
      )
      genislikleriAyarla(2000, 800)
      ciz()
      const ust = screen.getByTestId('ust-kaydirma')
      const tablo = screen.getByRole('table').parentElement as HTMLElement
      kaydirilabilir(ust, ustAzami)
      kaydirilabilir(tablo, tabloAzami)

      return { ust, tablo }
    }

    it('tablo akarak kayarken üst çubuğun geç gelen olayı tabloyu geri çekmez', () => {
      const { ust, tablo } = cubuklar(1200, 1200)

      tablo.scrollLeft = 100
      fireEvent.scroll(tablo)
      expect(ust.scrollLeft).toBe(100)

      // Yumuşak kaydırma sürüyor: üst çubuğun 100'deki olayı gelmeden tablo 150'ye vardı
      tablo.scrollLeft = 150
      fireEvent.scroll(ust)
      expect(tablo.scrollLeft).toBe(150)

      fireEvent.scroll(tablo)
      expect(ust.scrollLeft).toBe(150)
    })

    it('üst çubuk sona daha erken dayanırsa tablo sondan geri itilmez', () => {
      const { ust, tablo } = cubuklar(1198, 1200)

      tablo.scrollLeft = 1200
      fireEvent.scroll(tablo)
      fireEvent.scroll(ust)

      expect(tablo.scrollLeft).toBe(1200)
    })

    it('kullanıcı üst çubuğu kaydırınca tablo da kayar', () => {
      const { ust, tablo } = cubuklar(1200, 1200)

      ust.scrollLeft = 300
      fireEvent.scroll(ust)

      expect(tablo.scrollLeft).toBe(300)
    })
  })
})
