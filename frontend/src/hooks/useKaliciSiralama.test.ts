import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it } from 'vitest'

import { useKaliciSiralama } from './useKaliciSiralama'

describe('useKaliciSiralama', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it("kayıt yoksa null başlar; değişiklik localStorage'a yazılır", () => {
    const { result } = renderHook(() => useKaliciSiralama('gorevler'))

    expect(result.current.siralama).toBeNull()

    act(() => result.current.siralamaDegistir('ad'))

    expect(result.current.siralama).toEqual({ anahtar: 'ad', yon: 'asc' })
    expect(JSON.parse(localStorage.getItem('erp.siralama.gorevler') ?? '')).toEqual({
      anahtar: 'ad',
      yon: 'asc',
    })
  })

  it('aynı kolona ikinci tıklama yönü çevirir ve saklar', () => {
    const { result } = renderHook(() => useKaliciSiralama('gorevler'))

    act(() => result.current.siralamaDegistir('ad'))
    act(() => result.current.siralamaDegistir('ad'))

    expect(result.current.siralama).toEqual({ anahtar: 'ad', yon: 'desc' })
  })

  it('yeniden açılışta kayıtlı tercih geri yüklenir (kullanıcı notu)', () => {
    localStorage.setItem('erp.siralama.projeler', JSON.stringify({ anahtar: 'kod', yon: 'desc' }))

    const { result } = renderHook(() => useKaliciSiralama('projeler'))

    expect(result.current.siralama).toEqual({ anahtar: 'kod', yon: 'desc' })
  })

  it('bozuk/geçersiz kayıt sessizce yok sayılır', () => {
    localStorage.setItem('erp.siralama.firmalar', '{bozuk json')
    localStorage.setItem('erp.siralama.vardiyalar', JSON.stringify({ anahtar: 5, yon: 'yukari' }))

    expect(renderHook(() => useKaliciSiralama('firmalar')).result.current.siralama).toBeNull()
    expect(renderHook(() => useKaliciSiralama('vardiyalar')).result.current.siralama).toBeNull()
  })

  it('sayfalar birbirinin tercihine karışmaz', () => {
    const gorevler = renderHook(() => useKaliciSiralama('gorevler'))
    act(() => gorevler.result.current.siralamaDegistir('kod'))

    const departmanlar = renderHook(() => useKaliciSiralama('departmanlar'))

    expect(departmanlar.result.current.siralama).toBeNull()
  })
})
