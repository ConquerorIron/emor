import { describe, expect, it } from 'vitest'

import {
  BIRIM_AY,
  BIRIM_GUN,
  BIRIM_HAFTA,
  sureyiTariheCevir,
  tarihiSureyeCevir,
} from './teslimatSuresi'

describe('sureyiTariheCevir', () => {
  it('gün ekler (ERP ekranı: 05.08.2026 + 21 gün = 26.08.2026)', () => {
    expect(sureyiTariheCevir('2026-08-05', '21', BIRIM_GUN)).toBe('2026-08-26')
  })

  it('haftayı 7 günle çarpar', () => {
    expect(sureyiTariheCevir('2026-08-05', '2', BIRIM_HAFTA)).toBe('2026-08-19')
  })

  it('takvim ayı ekler', () => {
    expect(sureyiTariheCevir('2026-08-05', '1', BIRIM_AY)).toBe('2026-09-05')
  })

  it('ay sonu taşmasını kırpar (31 Ocak + 1 ay = 28 Şubat)', () => {
    expect(sureyiTariheCevir('2026-01-31', '1', BIRIM_AY)).toBe('2026-02-28')
  })

  it('boş süre boş tarih üretir', () => {
    expect(sureyiTariheCevir('2026-08-05', '', BIRIM_GUN)).toBe('')
    expect(sureyiTariheCevir('', '5', BIRIM_GUN)).toBe('')
  })
})

describe('tarihiSureyeCevir', () => {
  it('gün farkını hesaplar', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-08-26', BIRIM_GUN)).toEqual({
      sure: '21',
      birim: BIRIM_GUN,
    })
  })

  it('hafta seçiliyken tam bölünüyorsa haftayı korur', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-08-19', BIRIM_HAFTA)).toEqual({
      sure: '2',
      birim: BIRIM_HAFTA,
    })
  })

  it('hafta seçiliyken tam bölünmüyorsa güne düşer', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-08-15', BIRIM_HAFTA)).toEqual({
      sure: '10',
      birim: BIRIM_GUN,
    })
  })

  it('ay seçiliyken tarihe birebir oturuyorsa ayı korur', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-11-05', BIRIM_AY)).toEqual({
      sure: '3',
      birim: BIRIM_AY,
    })
  })

  it('ay seçiliyken oturmuyorsa güne düşer', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-09-10', BIRIM_AY)).toEqual({
      sure: '36',
      birim: BIRIM_GUN,
    })
  })

  it('geçmiş tarih boş süre üretir', () => {
    expect(tarihiSureyeCevir('2026-08-05', '2026-08-01', BIRIM_GUN).sure).toBe('')
  })

  it('gidiş-dönüş tutarlıdır', () => {
    const tarih = sureyiTariheCevir('2026-08-05', '3', BIRIM_HAFTA)
    expect(tarihiSureyeCevir('2026-08-05', tarih, BIRIM_HAFTA)).toEqual({
      sure: '3',
      birim: BIRIM_HAFTA,
    })
  })
})
