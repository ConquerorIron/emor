import { afterEach, describe, expect, it } from 'vitest'

import { sayfaBoyutuOku, sayfaBoyutuYaz } from './sayfaBoyutu'

describe('sayfaBoyutuOku', () => {
  afterEach(() => localStorage.clear())

  it('tercih yokken varsayılan 50 döner ("Hepsi"ye düşmez)', () => {
    expect(sayfaBoyutuOku()).toBe(50)
  })

  it('kayıtlı geçerli tercihi, "Hepsi" (0) dahil, döndürür', () => {
    sayfaBoyutuYaz(200)
    expect(sayfaBoyutuOku()).toBe(200)

    sayfaBoyutuYaz(0)
    expect(sayfaBoyutuOku()).toBe(0)
  })

  it('bozuk ya da listede olmayan değerde varsayılana döner', () => {
    localStorage.setItem('erp.sayfaBoyutu', '37')
    expect(sayfaBoyutuOku()).toBe(50)

    localStorage.setItem('erp.sayfaBoyutu', 'abc')
    expect(sayfaBoyutuOku()).toBe(50)
  })
})
