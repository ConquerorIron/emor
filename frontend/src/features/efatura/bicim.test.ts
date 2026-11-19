import { describe, expect, it } from 'vitest'

import { tutarGoster, varsayilanBaslangic } from './bicim'

describe('e-Fatura tutar gösterimi', () => {
  it('büyük numeric tutarların kuruşlarını korur', () => {
    expect(tutarGoster('1234567890123456.78')).toBe('1.234.567.890.123.456,78')
    expect(tutarGoster('-1234567890123456.7850')).toBe('-1.234.567.890.123.456,79')
  })

  it('kuruşu yarımdan yukarı yuvarlar', () => {
    expect(tutarGoster('1250.5049')).toBe('1.250,50')
    expect(tutarGoster('1250.5050')).toBe('1.250,51')
    expect(tutarGoster(null)).toBe('—')
  })
})

describe('liste açılış başlangıcı (PRT-R4)', () => {
  it('aralık 366 günü aşmayacak kadar ilerler, ilk tarama tarihinden geriye gitmez', () => {
    expect(varsayilanBaslangic('2026-11-19')).toBe('2026-01-01')
    expect(varsayilanBaslangic('2027-01-01')).toBe('2026-01-01')
    expect(varsayilanBaslangic('2027-01-02')).toBe('2026-01-02')
    // Artık yıl: 2028-02-29 dahil 366 gün
    expect(varsayilanBaslangic('2028-12-31')).toBe('2028-01-01')
  })
})
