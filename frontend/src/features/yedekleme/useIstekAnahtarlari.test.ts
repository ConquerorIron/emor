import { cleanup, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ANAHTAR_OMRU_MS, useIstekAnahtarlari } from './useIstekAnahtarlari'

const DEPO = 'erp.yedekleme.istekAnahtarlari.v2.kullanici.1'
const OZET_1 = 'a'.repeat(64)
const OZET_2 = 'b'.repeat(64)
const ANAHTAR_1 = '11111111-1111-4111-8111-111111111111'
const ANAHTAR_2 = '22222222-2222-4222-8222-222222222222'
/** sha256('{"aciklama":"gizli not","tur":"yedek_al"}') */
const OZET_GIZLI_NOT = 'cb2b1774d14c83ed1d991ce8a54fa1f5c7819bc9cbe811eb29d70ba4e2008f6a'
/** sha256('{"aciklama":"eski not","tur":"yedek_al"}') */
const OZET_ESKI_NOT = 'a1cbeff644345eae79243f3e313b86c7f872b4e8e0207562a1bcd913c99ab80b'

/**
 * İlk `crypto.subtle.digest` çağrısının sonucunu, dönen işlev çağrılana dek
 * bekletir (gerçek özet); sonraki çağrılar beklemez.
 */
function ilkOzetiGeciktir(): () => void {
  const gercek = crypto.subtle.digest.bind(crypto.subtle)
  let serbestBirak: () => void = () => {}
  const engel = new Promise<void>((coz) => {
    serbestBirak = coz
  })
  vi.spyOn(crypto.subtle, 'digest').mockImplementationOnce(async (...argumanlar) => {
    await engel

    return gercek(...argumanlar)
  })

  return serbestBirak
}

describe('useIstekAnahtarlari', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('açılışta geçerli v1 kaydı yalnız özeti, UUID ve zamanıyla kendi kapsamının v2 deposuna taşınır, ham kayıt silinir; aynı eylemin yeniden denemesi aynı anahtarı alır (BY-014-R4, R5)', async () => {
    const zaman = Date.now() - 60 * 1000
    // 208746f sürümünün kaydı: imza = eylemin kanonik (anahtarları sıralı) JSON'u
    const v1 = JSON.stringify([
      { imza: '{"aciklama":"gizli not","tur":"yedek_al"}', anahtar: ANAHTAR_1, zaman },
    ])
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1', v1)
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.ustune.islem-9', v1)
    localStorage.setItem('erp.tema', 'koyu')

    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    // Taşıma bitmesi beklenmeden yeniden deneme: v1'deki anahtar kullanılır
    const bekleyen = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'gizli not' })

    expect(bekleyen.anahtar).toBe(ANAHTAR_1)
    expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1')).toBeNull()
    expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.ustune.islem-9')).toBeNull()
    // Özet `sha256sum` ile bağımsız hesaplandı
    const tasinan = [{ ozet: OZET_GIZLI_NOT, anahtar: ANAHTAR_1, zaman }]
    expect(JSON.parse(localStorage.getItem(DEPO) ?? '[]')).toEqual(tasinan)
    expect(
      JSON.parse(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v2.ustune.islem-9') ?? '[]'),
    ).toEqual(tasinan)
    for (let i = 0; i < localStorage.length; i++) {
      const ad = localStorage.key(i) ?? ''
      expect(ad).not.toContain('gizli not')
      expect(localStorage.getItem(ad)).not.toContain('gizli not')
    }
    expect(localStorage.getItem('erp.tema')).toBe('koyu')
  })

  it('süresi geçmiş ya da biçimsiz v1 kaydı (başka kapsamda da) taşınmadan silinir; aynı eylem yeni anahtar alır (BY-014-R5)', async () => {
    const v1 = JSON.stringify([
      {
        imza: '{"aciklama":"eski not","tur":"yedek_al"}',
        anahtar: ANAHTAR_2,
        zaman: Date.now() - ANAHTAR_OMRU_MS - 1000,
      },
      {
        imza: '{"aciklama":"gizli not","tur":"yedek_al"}',
        anahtar: 'uuid-degil',
        zaman: Date.now(),
      },
    ])
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1', v1)
    // Açılan kapsam dışındaki kapsamın v2 deposunu açılıştaki okuma temizlemez
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.ustune.islem-9', v1)
    localStorage.setItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.2', '{bozuk')

    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))

    await waitFor(() =>
      expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1')).toBeNull(),
    )
    for (const kapsam of ['ustune.islem-9', 'kullanici.2']) {
      expect(localStorage.getItem(`erp.yedekleme.istekAnahtarlari.v1.${kapsam}`)).toBeNull()
      expect(localStorage.getItem(`erp.yedekleme.istekAnahtarlari.v2.${kapsam}`)).toBeNull()
    }
    expect(localStorage.getItem(DEPO)).toBeNull()

    const bekleyen = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'eski not' })
    expect(bekleyen.anahtar).not.toBe(ANAHTAR_2)
    expect(JSON.parse(localStorage.getItem(DEPO) ?? '[]')).toEqual([
      expect.objectContaining({ ozet: OZET_ESKI_NOT, anahtar: bekleyen.anahtar }),
    ])
  })

  it('özeti gecikmeli ikinci taşıma, başka örnekte taşınıp kabul edilen v1 kaydını eski kopyasından geri yazmaz; bilinçli yeni iş yeni anahtar alır (BY-014-R6)', async () => {
    const eylem = { tur: 'yedek_al', aciklama: 'gizli not' }
    localStorage.setItem(
      'erp.yedekleme.istekAnahtarlari.v1.kullanici.1',
      JSON.stringify([
        {
          imza: '{"aciklama":"gizli not","tur":"yedek_al"}',
          anahtar: ANAHTAR_1,
          zaman: Date.now() - 60 * 1000,
        },
      ]),
    )
    const serbestBirak = ilkOzetiGeciktir()

    // Yavaş örnek v1'i okuyup özet beklerken hızlı örnek taşır, gönderir, 202 alır
    const yavas = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const hizli = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const ilk = await hizli.result.current.hazirla(eylem)
    expect(ilk.anahtar).toBe(ANAHTAR_1)
    ilk.kabulEdildi()
    expect(localStorage.getItem(DEPO)).toBeNull()

    serbestBirak()
    const yeni = await yavas.result.current.hazirla(eylem)

    expect(yeni.anahtar).not.toBe(ANAHTAR_1)
    expect(JSON.parse(localStorage.getItem(DEPO) ?? '[]')).toEqual([
      expect.objectContaining({ ozet: OZET_GIZLI_NOT, anahtar: yeni.anahtar }),
    ])
    expect(localStorage.getItem('erp.yedekleme.istekAnahtarlari.v1.kullanici.1')).toBeNull()
  })

  it('özet beklenirken v1 girdisi değişirse eski kopyası değil güncel hali taşınır, ham girdi silinir (BY-014-R6)', async () => {
    const zaman = Date.now() - 60 * 1000
    const v1 = 'erp.yedekleme.istekAnahtarlari.v1.kullanici.1'
    localStorage.setItem(
      v1,
      JSON.stringify([
        { imza: '{"aciklama":"eski not","tur":"yedek_al"}', anahtar: ANAHTAR_2, zaman },
      ]),
    )
    const serbestBirak = ilkOzetiGeciktir()

    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    // Eski sürümün açık kalmış sekmesi girdiyi bu arada yeniden yazar
    localStorage.setItem(
      v1,
      JSON.stringify([
        { imza: '{"aciklama":"gizli not","tur":"yedek_al"}', anahtar: ANAHTAR_1, zaman },
      ]),
    )
    serbestBirak()
    const bekleyen = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'gizli not' })

    expect(bekleyen.anahtar).toBe(ANAHTAR_1)
    expect(JSON.parse(localStorage.getItem(DEPO) ?? '[]')).toEqual([
      { ozet: OZET_GIZLI_NOT, anahtar: ANAHTAR_1, zaman },
    ])
    expect(localStorage.getItem(v1)).toBeNull()
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
