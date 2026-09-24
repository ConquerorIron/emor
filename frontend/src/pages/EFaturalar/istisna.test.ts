import { describe, expect, it } from 'vitest'

import { istisnaKoduFarkli } from './istisna'

describe('istisnaKoduFarkli', () => {
  it('iki kaynak da biliniyor ve farklıysa true', () => {
    expect(istisnaKoduFarkli({ izibiz_istisna_kodu: '351', vergi_istisna_kodu: '318' })).toBe(true)
  })

  it('virgüllü kodları küme olarak karşılaştırır (sıra ve boşluk önemsiz)', () => {
    expect(
      istisnaKoduFarkli({ izibiz_istisna_kodu: '308,351', vergi_istisna_kodu: '351, 308' }),
    ).toBe(false)
  })

  it('bir kaynak bilinmiyorsa fark sayılmaz', () => {
    expect(istisnaKoduFarkli({ izibiz_istisna_kodu: null, vergi_istisna_kodu: '318' })).toBe(false)
    expect(istisnaKoduFarkli({ izibiz_istisna_kodu: '318', vergi_istisna_kodu: null })).toBe(false)
  })
})
