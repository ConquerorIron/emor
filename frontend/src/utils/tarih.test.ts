import { describe, expect, it } from 'vitest'

import { gosterimdenIso, tarihGoster, tarihMaskele, zamanGoster } from './tarih'

describe('tarihGoster', () => {
  it('ISO tarihi GG.AA.YYYY gösterir', () => {
    expect(tarihGoster('2026-09-27')).toBe('27.09.2026')
  })

  it('zaman damgasının tarih kısmını alır', () => {
    expect(tarihGoster('2026-09-27T10:30:00+03:00')).toBe('27.09.2026')
  })

  it('boş/null için tire, tanınmayan için girdiyi döndürür', () => {
    expect(tarihGoster(null)).toBe('—')
    expect(tarihGoster('')).toBe('—')
    expect(tarihGoster('takvim yok')).toBe('takvim yok')
  })
})

describe('zamanGoster', () => {
  it('zaman damgasını GG.AA.YYYY SS:DD gösterir (yerel saat)', () => {
    const beklenen = new Date('2026-09-27T10:30:00+03:00')
    const iki = (sayi: number) => String(sayi).padStart(2, '0')
    expect(zamanGoster('2026-09-27T10:30:00+03:00')).toBe(
      `${iki(beklenen.getDate())}.${iki(beklenen.getMonth() + 1)}.${beklenen.getFullYear()} ${iki(beklenen.getHours())}:${iki(beklenen.getMinutes())}`,
    )
  })

  it('boşta tire döner', () => {
    expect(zamanGoster(null)).toBe('—')
  })
})

describe('tarihMaskele', () => {
  it('rakamları yazarken noktaları otomatik ekler', () => {
    expect(tarihMaskele('2')).toBe('2')
    expect(tarihMaskele('27')).toBe('27')
    expect(tarihMaskele('279')).toBe('27.9')
    expect(tarihMaskele('27092026')).toBe('27.09.2026')
  })

  it('rakam dışını ayıklar ve 8 haneyle sınırlar', () => {
    expect(tarihMaskele('27.09.2026')).toBe('27.09.2026')
    expect(tarihMaskele('27/09/2026 fazlalık 99')).toBe('27.09.2026')
  })
})

describe('gosterimdenIso', () => {
  it('tam ve geçerli tarihi ISO çevirir', () => {
    expect(gosterimdenIso('27.09.2026')).toBe('2026-09-27')
    expect(gosterimdenIso('29.02.2024')).toBe('2024-02-29') // artık yıl
  })

  it('eksik veya takvimde olmayan tarihte boş döner', () => {
    expect(gosterimdenIso('27.09')).toBe('')
    expect(gosterimdenIso('31.02.2026')).toBe('') // Şubat 31 yok
    expect(gosterimdenIso('29.02.2026')).toBe('') // artık yıl değil
    expect(gosterimdenIso('00.01.2026')).toBe('')
  })
})
