import { describe, expect, it } from 'vitest'

import {
  alanGuncelle,
  alanKaldir,
  alanKonumu,
  alanYerlestir,
  bolumGenisligiDegistir,
  kullanilmayanAlanlar,
} from './duzenIslemleri'
import type { EkranDuzeni, KatalogAlani } from './types'

const KATALOG: KatalogAlani[] = ['a', 'b', 'c', 'd'].map((anahtar) => ({
  anahtar,
  etiket_anahtari: `etiket.${anahtar}`,
  giris_tipi: 'metin',
  veri_anahtarlari: [anahtar],
  proc_parametresi: '',
  varsayilan_genislik: 6,
  kaldirilamaz: false,
  salt_okunur_sabit: false,
  zorunlu_secilebilir: true,
  metin_alani: true,
}))

function duzen(): EkranDuzeni {
  return {
    bolumler: [
      {
        anahtar: 'sol',
        genislik: 6,
        alanlar: [
          { alan: 'a', genislik: 6 },
          { alan: 'b', genislik: 6 },
        ],
      },
      { anahtar: 'sag', genislik: 6, alanlar: [{ alan: 'c', genislik: 12 }] },
    ],
  }
}

describe('kullanilmayanAlanlar', () => {
  it('yalnız tasarımda yeri olmayanları döner', () => {
    expect(kullanilmayanAlanlar(KATALOG, duzen()).map((a) => a.anahtar)).toEqual(['d'])
  })
})

describe('alanYerlestir', () => {
  it('paletten sürüklenen alanı hedef sıraya ekler', () => {
    const sonuc = alanYerlestir(duzen(), 'd', 'sol', 1)

    expect(sonuc.bolumler[0].alanlar.map((a) => a.alan)).toEqual(['a', 'd', 'b'])
  })

  it('hedef sıra verilmezse sona ekler', () => {
    const sonuc = alanYerlestir(duzen(), 'd', 'sag', null)

    expect(sonuc.bolumler[1].alanlar.map((a) => a.alan)).toEqual(['c', 'd'])
  })

  it('bölümler arası taşırken alanın ayarlarını korur', () => {
    const kaynak = alanGuncelle(duzen(), 'c', { genislik: 4, zorunlu: true })
    const sonuc = alanYerlestir(kaynak, 'c', 'sol', 0)

    expect(sonuc.bolumler[0].alanlar[0]).toEqual({ alan: 'c', genislik: 4, zorunlu: true })
    expect(sonuc.bolumler[1].alanlar).toHaveLength(0)
  })

  it('aynı bölümde yeniden sıralar (kopya oluşturmaz)', () => {
    const sonuc = alanYerlestir(duzen(), 'a', 'sol', 2)

    expect(sonuc.bolumler[0].alanlar.map((a) => a.alan)).toEqual(['b', 'a'])
  })

  it('girdiyi değiştirmez', () => {
    const kaynak = duzen()
    alanYerlestir(kaynak, 'd', 'sol', 0)

    expect(kaynak.bolumler[0].alanlar).toHaveLength(2)
  })
})

describe('alanKaldir', () => {
  it('alanı çıkarır ve palete geri düşürür', () => {
    const sonuc = alanKaldir(duzen(), 'b')

    expect(alanKonumu(sonuc, 'b')).toBeNull()
    expect(kullanilmayanAlanlar(KATALOG, sonuc).map((a) => a.anahtar)).toEqual(['b', 'd'])
  })
})

describe('alanGuncelle', () => {
  it('genişlik ve zorunluluğu günceller', () => {
    const sonuc = alanGuncelle(duzen(), 'a', { genislik: 12, zorunlu: true })

    expect(sonuc.bolumler[0].alanlar[0]).toEqual({ alan: 'a', genislik: 12, zorunlu: true })
  })

  it('kapatılan seçenekleri JSON’dan temizler', () => {
    const acik = alanGuncelle(duzen(), 'a', {
      zorunlu: true,
      salt_okunur: true,
      gorunum: 'textarea',
      satir: 4,
      varsayilan: 'x',
    })
    const kapali = alanGuncelle(acik, 'a', {
      zorunlu: false,
      salt_okunur: false,
      gorunum: undefined,
      varsayilan: '',
    })

    expect(kapali.bolumler[0].alanlar[0]).toEqual({ alan: 'a', genislik: 6 })
  })

  it('gizli işaretlenince zorunluluk düşer (kullanıcı dolduramaz)', () => {
    const zorunlu = alanGuncelle(duzen(), 'a', { zorunlu: true })
    expect(zorunlu.bolumler[0].alanlar[0].zorunlu).toBe(true)

    const gizli = alanGuncelle(zorunlu, 'a', { gizli: true, varsayilan: '7' })
    expect(gizli.bolumler[0].alanlar[0]).toEqual({
      alan: 'a',
      genislik: 6,
      gizli: true,
      varsayilan: '7',
    })
  })

  it('textarea kapanınca satır sayısı da düşer', () => {
    const acik = alanGuncelle(duzen(), 'a', { gorunum: 'textarea', satir: 5 })
    expect(acik.bolumler[0].alanlar[0].satir).toBe(5)

    const kapali = alanGuncelle(acik, 'a', { gorunum: undefined })
    expect(kapali.bolumler[0].alanlar[0].satir).toBeUndefined()
  })
})

describe('bolumGenisligiDegistir', () => {
  it('yalnız hedef bölümü değiştirir', () => {
    const sonuc = bolumGenisligiDegistir(duzen(), 'sol', 12)

    expect(sonuc.bolumler[0].genislik).toBe(12)
    expect(sonuc.bolumler[1].genislik).toBe(6)
  })
})
