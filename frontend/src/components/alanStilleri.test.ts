import { describe, expect, it } from 'vitest'

import { ALAN_KUTUSU, ALAN_KUTUSU_ESNEK, ALAN_YUKSEKLIGI } from './alanStilleri'
import { selectStilleri } from './selectStilleri'

/**
 * Yükseklik iki ayrı dilde yazılıyor: Tailwind sınıfı (metin girişleri) ve
 * JS sayısı (react-select inline stili). İkisi ayrı yerde tutulduğunda
 * sessizce ayrışıyordu — bu test aynı sayıyı taşıdıklarını garanti eder.
 */
describe('alan ölçüleri tek kaynaktan gelir', () => {
  it('Tailwind yükseklik sınıfları ALAN_YUKSEKLIGI ile aynı sayıyı taşır', () => {
    expect(ALAN_KUTUSU).toBe(`h-[${ALAN_YUKSEKLIGI}px]`)
    expect(ALAN_KUTUSU_ESNEK).toBe(`min-h-[${ALAN_YUKSEKLIGI}px]`)
  })

  it('react-select kutusu metin girişleriyle aynı yüksekliği ve puntoyu tutar', () => {
    const kutu = selectStilleri<false>(false).control?.(
      {} as never,
      {
        isFocused: false,
        isDisabled: false,
      } as never,
    )

    expect(kutu).toMatchObject({ minHeight: ALAN_YUKSEKLIGI, fontSize: 14 })
  })
})
