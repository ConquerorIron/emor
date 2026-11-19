import { describe, expect, it } from 'vitest'

import { tutarGoster } from './bicim'

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
