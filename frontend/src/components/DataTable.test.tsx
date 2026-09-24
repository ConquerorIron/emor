import { cleanup, render, screen } from '@testing-library/react'
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
})
