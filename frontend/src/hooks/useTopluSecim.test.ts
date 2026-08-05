import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { useTopluSecim } from './useTopluSecim'

// Codex #9: bağlam değişiminde görünmeyen kayıtlar seçili kalmamalı
describe('useTopluSecim', () => {
  it('bağlam (arama/filtre/sıralama) değişince seçimi temizler', () => {
    const { result, rerender } = renderHook(({ baglam }) => useTopluSecim(baglam), {
      initialProps: { baglam: ['', true] as readonly unknown[] },
    })

    act(() => {
      result.current.secimDegistir(1)
      result.current.secimDegistir(2)
    })
    expect(result.current.secili.size).toBe(2)

    rerender({ baglam: ['ahmet', true] as readonly unknown[] })

    expect(result.current.secili.size).toBe(0)
  })

  it('aynı bağlamda seçim korunur (sayfa değişimi bağlama dahil değildir)', () => {
    const { result, rerender } = renderHook(({ baglam }) => useTopluSecim(baglam), {
      initialProps: { baglam: ['', true] as readonly unknown[] },
    })

    act(() => {
      result.current.secimDegistir(7)
    })
    rerender({ baglam: ['', true] as readonly unknown[] })

    expect(result.current.secili.has(7)).toBe(true)
  })

  it('aynı anahtara ikinci tıklama seçimi kaldırır', () => {
    const { result } = renderHook(() => useTopluSecim([]))

    act(() => {
      result.current.secimDegistir(3)
    })
    act(() => {
      result.current.secimDegistir(3)
    })

    expect(result.current.secili.size).toBe(0)
  })
})
