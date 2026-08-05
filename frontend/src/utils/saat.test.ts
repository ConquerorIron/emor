import { describe, expect, it } from 'vitest'

import { saatiCoz } from './saat'

// Codex #19: geçersiz saat asla null'a dönüşüp planlanana düşmemeli
describe('saatiCoz', () => {
  it('boş girdi bilinçli null döner (backend planlanandan türetir)', () => {
    expect(saatiCoz('')).toEqual({ gecerli: true, deger: null })
    expect(saatiCoz('   ')).toEqual({ gecerli: true, deger: null })
  })

  it('Türkçe ondalık virgülü noktaya normalize edilir', () => {
    expect(saatiCoz('1,5')).toEqual({ gecerli: true, deger: 1.5 })
    expect(saatiCoz('7.25')).toEqual({ gecerli: true, deger: 7.25 })
    expect(saatiCoz('9')).toEqual({ gecerli: true, deger: 9 })
  })

  it('sayı olmayan girdi geçersizdir — null değil', () => {
    expect(saatiCoz('abc')).toEqual({ gecerli: false })
    expect(saatiCoz('1.2.3')).toEqual({ gecerli: false })
    expect(saatiCoz('7,5 saat')).toEqual({ gecerli: false })
  })
})
