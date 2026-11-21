import { cleanup, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'

import { ANAHTAR_OMRU_MS, useIstekAnahtarlari } from './useIstekAnahtarlari'

const DEPO = 'erp.yedekleme.istekAnahtarlari.v2.kullanici.1'
const OZET_1 = 'a'.repeat(64)
const OZET_2 = 'b'.repeat(64)
const ANAHTAR_1 = '11111111-1111-4111-8111-111111111111'
const ANAHTAR_2 = '22222222-2222-4222-8222-222222222222'

describe('useIstekAnahtarlari', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    cleanup()
  })

  it('açılışta eski sürümün (v1, ham eylem metni taşıyan) kayıtlarını bütün kapsamlarda siler; başka kayıtlara dokunmaz (BY-014-R4)', async () => {
    const v1 = JSON.stringify([
      { imza: '{"aciklama":"gizli not","tur":"yedek_al"}', anahtar: ANAHTAR_1, zaman: Date.now() },
    ])
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1', v1)
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.ustune.islem-9', v1)
    localStorage.setItem('erp.tema', 'koyu')

    renderHook(() => useIstekAnahtarlari('kullanici.1'))

    await waitFor(() =>
      expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1')).toBeNull(),
    )
    expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.ustune.islem-9')).toBeNull()
    expect(localStorage.getItem('erp.tema')).toBe('koyu')
  })

  it('süresi geçmiş kayıt yeni bir yazım beklemeden açılışta depodan silinir; geçerli kayıt kalır (BY-014-R4)', async () => {
    const gecerli = { ozet: OZET_1, anahtar: ANAHTAR_1, zaman: Date.now() - 60 * 1000 }
    localStorage.setItem(
      DEPO,
      JSON.stringify([
        gecerli,
        { ozet: OZET_2, anahtar: ANAHTAR_2, zaman: Date.now() - ANAHTAR_OMRU_MS - 1000 },
      ]),
    )

    renderHook(() => useIstekAnahtarlari('kullanici.1'))

    await waitFor(() => expect(JSON.parse(localStorage.getItem(DEPO) ?? '[]')).toEqual([gecerli]))
  })
})
