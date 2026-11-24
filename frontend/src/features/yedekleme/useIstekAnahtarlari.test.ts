import { cleanup, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { type SahteKilitYoneticisi, sahteKilitleriKur } from '@/test/sahteKilitler'

import { ANAHTAR_OMRU_MS, useIstekAnahtarlari } from './useIstekAnahtarlari'

const DEPO = 'erp.yedekleme.istekAnahtarlari.v2.kullanici.1'
const V1 = 'erp.yedekleme.istekAnahtarlari.v1.kullanici.1'
/** Depoyu okuyup yazan her sekmenin aldığı ortak kilidin adı (sözleşme) */
const KILIT = 'erp.yedekleme.istekAnahtarlari'
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

type Kayit = { ozet: string; anahtar: string; zaman: number }

const depoyuOku = (): Kayit[] => JSON.parse(localStorage.getItem(DEPO) ?? '[]') as Kayit[]

function depoyaYaz(kayitlar: Kayit[]): void {
  if (kayitlar.length === 0) {
    localStorage.removeItem(DEPO)
  } else {
    localStorage.setItem(DEPO, JSON.stringify(kayitlar))
  }
}

/** Açılıştaki taşıma ve okuma bitsin (kilit kuyruğu ve özet sözleri boşalsın) */
const bekle = () => new Promise((coz) => setTimeout(coz, 20))

describe('useIstekAnahtarlari', () => {
  let kilitler: SahteKilitYoneticisi

  beforeEach(() => {
    localStorage.clear()
    kilitler = sahteKilitleriKur()
  })

  /**
   * Bu örneğin depodan (v2) bir SONRAKİ okumasıyla yazımı arasına, ayrı
   * süreçteki başka bir sekmenin aynı kilit altındaki işini koyar: kilit
   * boşsa o iş okumanın hemen ardından çalışır, tutuluyorsa sıraya girer.
   */
  function sonrakiOkumadaBaskaSekme(is: () => void): void {
    const gercek = Storage.prototype.getItem
    let tetiklendi = false
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(function (this: Storage, ad: string) {
      const deger = gercek.call(this, ad)
      if (!tetiklendi && ad === DEPO) {
        tetiklendi = true
        kilitler.baskaSekmedenCalistir(KILIT, is)
      }

      return deger
    })
  }

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

  it('özeti gecikmeli taşıma bitene dek başka örnek depoya bakmaz; taşınıp kabul edilen v1 kaydı eski kopyasından geri yazılmaz, bilinçli yeni iş yeni anahtar alır (BY-014-R6, R7)', async () => {
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

    // Yavaş örnek v1'i okuyup özet beklerken (kilit onda) hızlı örneğin
    // taşıması ve anahtar araması sıraya girer
    const yavas = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const hizli = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    let ilkGeldi = false
    const ilkSozu = hizli.result.current.hazirla(eylem).then((bekleyen) => {
      ilkGeldi = true

      return bekleyen
    })
    await bekle()
    expect(ilkGeldi).toBe(false)

    serbestBirak()
    // Hızlı örnek taşınan anahtarla gönderir, 202 alır
    const ilk = await ilkSozu
    expect(ilk.anahtar).toBe(ANAHTAR_1)
    await ilk.kabulEdildi()
    expect(localStorage.getItem(DEPO)).toBeNull()

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
    // Eski sürümün (kilit almayan) açık kalmış sekmesi girdiyi bu arada yeniden yazar
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

  it('taşımanın son okumasıyla yazımı arasına başka sekmenin taşıma ve 202 temizliği giremez; kabul edilen eski anahtar geri yazılmaz (BY-014-R7)', async () => {
    const zaman = Date.now() - 60 * 1000
    const eylem = { tur: 'yedek_al', aciklama: 'gizli not' }
    localStorage.setItem(
      V1,
      JSON.stringify([
        { imza: '{"aciklama":"gizli not","tur":"yedek_al"}', anahtar: ANAHTAR_1, zaman },
      ]),
    )
    const bGonderdi: Array<string | undefined> = []
    // B sekmesi: v1 duruyorsa taşır; aynı eylemi bekleyen anahtarıyla gönderir, 202 → siler
    sonrakiOkumadaBaskaSekme(() => {
      const depo = depoyuOku()
      if (localStorage.getItem(V1) !== null) {
        depo.push({ ozet: OZET_GIZLI_NOT, anahtar: ANAHTAR_1, zaman })
        localStorage.removeItem(V1)
      }
      bGonderdi.push(depo.find((kayit) => kayit.ozet === OZET_GIZLI_NOT)?.anahtar)
      depoyaYaz(depo.filter((kayit) => kayit.ozet !== OZET_GIZLI_NOT))
    })

    // A sekmesi açılır, taşır; ardından aynı eylemi bilinçli yeniden başlatır
    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const yeni = await result.current.hazirla(eylem)

    expect(bGonderdi).toEqual([ANAHTAR_1])
    expect(yeni.anahtar).not.toBe(ANAHTAR_1)
    expect(depoyuOku()).toEqual([
      expect.objectContaining({ ozet: OZET_GIZLI_NOT, anahtar: yeni.anahtar }),
    ])
    expect(localStorage.getItem(V1)).toBeNull()
  })

  it('yeni anahtarın okumasıyla yazımı arasına başka sekmenin 202 temizliği giremez; kabul edilen anahtar geri yazılmaz (BY-014-R7)', async () => {
    depoyaYaz([{ ozet: OZET_ESKI_NOT, anahtar: ANAHTAR_2, zaman: Date.now() - 60 * 1000 }])
    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    await bekle()
    // B sekmesi "eski not" işini ANAHTAR_2 ile gönderdi, 202 aldı: kaydı siler
    sonrakiOkumadaBaskaSekme(() =>
      depoyaYaz(depoyuOku().filter((kayit) => kayit.ozet !== OZET_ESKI_NOT)),
    )

    const gizli = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'gizli not' })

    expect(depoyuOku()).toEqual([
      expect.objectContaining({ ozet: OZET_GIZLI_NOT, anahtar: gizli.anahtar }),
    ])
    const eski = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'eski not' })
    expect(eski.anahtar).not.toBe(ANAHTAR_2)
  })

  it('kabul temizliğinin okumasıyla yazımı arasına başka sekmenin yeni anahtarı giremez; o anahtar kaybolmaz (BY-014-R7)', async () => {
    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const gizli = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'gizli not' })
    // B sekmesi "eski not" işi için ANAHTAR_2'yi üretip istekten önce yazar
    const bKaydi = { ozet: OZET_ESKI_NOT, anahtar: ANAHTAR_2, zaman: Date.now() }
    sonrakiOkumadaBaskaSekme(() => depoyaYaz([...depoyuOku(), bKaydi]))

    await gizli.kabulEdildi()

    expect(depoyuOku()).toEqual([bKaydi])
    // B'nin yanıtı kaybolan isteğinin yeniden denemesi aynı anahtarı taşır
    const eski = await result.current.hazirla({ tur: 'yedek_al', aciklama: 'eski not' })
    expect(eski.anahtar).toBe(ANAHTAR_2)
  })

  it('eski isteğin geciken kabulü aynı eylemin sonradan üretilen yeni anahtarını silmez; yenileme sonrası yeniden deneme yeni anahtarı taşır (BY-014-R8)', async () => {
    const eylem = { tur: 'yedek_al', aciklama: 'gizli not' }
    const a = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const b = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    await bekle()
    const aIlk = await a.result.current.hazirla(eylem)
    const bIlk = await b.result.current.hazirla(eylem)
    expect(bIlk.anahtar).toBe(aIlk.anahtar)

    // A'nın 202'si geldi; A aynı eylemi bilinçli olarak yeniden başlatıyor
    await aIlk.kabulEdildi()
    const aYeni = await a.result.current.hazirla(eylem)
    expect(aYeni.anahtar).not.toBe(aIlk.anahtar)
    // B'nin aynı (eski) anahtarlı isteğinin 202'si gecikerek geliyor
    await bIlk.kabulEdildi()

    expect(depoyuOku()).toEqual([
      expect.objectContaining({ ozet: OZET_GIZLI_NOT, anahtar: aYeni.anahtar }),
    ])
    // A'nın yeni isteğinin yanıtı kayboldu, sayfa yenilendi: yeniden deneme aynı anahtarı taşır
    a.unmount()
    const aYenilenmis = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    await bekle()
    expect((await aYenilenmis.result.current.hazirla(eylem)).anahtar).toBe(aYeni.anahtar)
    // B'nin belleğindeki yeni anahtar da eski kabulle silinmez
    expect((await b.result.current.hazirla(eylem)).anahtar).toBe(aYeni.anahtar)
  })

  it('başka sekmede kabul edilip depodan silinen anahtar bu sekmenin belleğinden yeniden kullanılmaz; bilinçli tekrar yeni anahtar alır (YF-59)', async () => {
    const eylem = { tur: 'yedek_al', aciklama: null }
    const a = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const b = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    await bekle()

    // A gönderdi, yanıt alamadı (kabul yok)
    const aIlk = await a.result.current.hazirla(eylem)
    // B aynı eylemi aynı (depodaki) anahtarla gönderdi ve 202 aldı
    const bIlk = await b.result.current.hazirla(eylem)
    expect(bIlk.anahtar).toBe(aIlk.anahtar)
    await bIlk.kabulEdildi()
    expect(localStorage.getItem(DEPO)).toBeNull()

    // A yeniden basar: eski kabul değil yeni iş
    const aYeni = await a.result.current.hazirla(eylem)

    expect(aYeni.anahtar).not.toBe(aIlk.anahtar)
    expect(depoyuOku()).toEqual([expect.objectContaining({ anahtar: aYeni.anahtar })])
  })

  it('depo okunabildiği hâlde anahtar yazılamadıysa (kota) yeniden deneme bellekteki anahtarı taşır (YF-59)', async () => {
    const eylem = { tur: 'tara' }
    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    await bekle()
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('dolu', 'QuotaExceededError')
    })

    const ilk = await result.current.hazirla(eylem)
    const tekrar = await result.current.hazirla(eylem)

    expect(localStorage.getItem(DEPO)).toBeNull()
    expect(tekrar.anahtar).toBe(ilk.anahtar)
    setItem.mockRestore()
  })

  it('Web Locks yoksa v1 kaydı taşınmadan silinir; anahtar depoya yazılmaz, yalnız bu örneğin belleğinde yaşar ve durum günlüğe yazılır (BY-014-R7)', async () => {
    Reflect.deleteProperty(navigator, 'locks')
    const uyari = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const eylem = { tur: 'yedek_al', aciklama: 'gizli not' }
    localStorage.setItem(
      V1,
      JSON.stringify([
        {
          imza: '{"aciklama":"gizli not","tur":"yedek_al"}',
          anahtar: ANAHTAR_1,
          zaman: Date.now() - 60 * 1000,
        },
      ]),
    )

    const { result } = renderHook(() => useIstekAnahtarlari('kullanici.1'))
    const ilk = await result.current.hazirla(eylem)

    expect(ilk.anahtar).not.toBe(ANAHTAR_1)
    expect(localStorage.length).toBe(0)
    expect(uyari).toHaveBeenCalledWith(expect.stringContaining('BY-014-R7'))
    // Yeniden deneme (aynı örnek) aynı anahtarı, kabulden sonraki iş yenisini alır
    const tekrar = await result.current.hazirla(eylem)
    expect(tekrar.anahtar).toBe(ilk.anahtar)
    await tekrar.kabulEdildi()
    const yeni = await result.current.hazirla(eylem)
    expect(yeni.anahtar).not.toBe(ilk.anahtar)
    expect(localStorage.length).toBe(0)
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
