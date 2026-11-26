import { trBuyukHarf } from '@/utils/metin'

/** Yapıştırma tablosunun bir kolonu (backend'in içe aktarım kolon tanımından) */
export interface YapistirmaKolonu {
  alan: string
  /** Ekranda görünen başlık */
  etiket: string
  /** Kabul edilen başlık adları: başlıkla kopyalanınca kolon bunlardan tanınır */
  basliklar: string[]
}

/** Başlangıçta boş satır sayısı */
export const BOS_SATIR_SAYISI = 20

/** Başlık satırı sayılması için tanınan en az kolon (backend ile aynı) */
const ENAZ_BASLIK_ESLESMESI = 3

/** Başlık karşılaştırma anahtarı — backend IceAktarimTablosu::anahtar ile aynı */
function anahtar(metin: string): string {
  return trBuyukHarf(metin).replace(/[^\p{L}\p{N}]+/gu, '')
}

/**
 * Excel panosu: satırlar yeni satırla, hücreler sekmeyle ayrılır. İçinde
 * satır sonu (Alt+Enter) olan hücreyi Excel tırnak içine alır ve hücredeki
 * tırnağı ikiler ("" → "); tırnağın ardından sekme / satır sonu / metin sonu
 * gelmiyorsa tırnak hücrenin kendi içeriğidir. Hücre içindeki satır sonları
 * ayrı satır sayılmaz, boşluğa döner (tek satırlık hücreye sığsın; sunucuya
 * da böyle gider).
 */
export function panoyuCoz(metin: string): string[][] {
  const pano = metin.replace(/\r\n?/g, '\n').replace(/\n$/, '')
  const hucreDeseni = /"((?:[^"]|"")*)"(?=[\t\n]|$)|[^\t\n]*/y
  const satirlar: string[][] = []
  let satir: string[] = []

  for (;;) {
    // İkinci seçenek boş metni de eşlediği için her konumda eşleşme vardır
    const hucre = hucreDeseni.exec(pano) as RegExpExecArray
    const deger = hucre[1] === undefined ? hucre[0] : hucre[1].replace(/""/g, '"')
    satir.push(deger.replace(/\s*[\t\n]\s*/g, ' ').trim())

    const ayirac = pano[hucreDeseni.lastIndex]
    if (ayirac === undefined) {
      break
    }
    hucreDeseni.lastIndex += 1
    if (ayirac === '\n') {
      satirlar.push(satir)
      satir = []
    }
  }
  satirlar.push(satir)

  return satirlar
}

/** İlk satır başlıksa: tablo kolonu sırası => panodaki kolon sırası (-1: yok) */
export function baslikEslesmesi(ilkSatir: string[], kolonlar: YapistirmaKolonu[]): number[] | null {
  const anahtarlar = ilkSatir.map(anahtar)
  const eslesme = kolonlar.map((kolon) =>
    anahtarlar.findIndex((a) => kolon.basliklar.some((baslik) => anahtar(baslik) === a)),
  )
  const taninan = eslesme.filter((sira) => sira !== -1).length

  return taninan >= Math.min(ENAZ_BASLIK_ESLESMESI, kolonlar.length) ? eslesme : null
}

export function bosSatirlar(kolonSayisi: number, adet = BOS_SATIR_SAYISI): string[][] {
  return Array.from({ length: adet }, () => Array<string>(kolonSayisi).fill(''))
}

/**
 * Tablo içeriği sunucuya başlıksız, standart kolon sırasıyla gönderilir:
 * satır numaraları tablodakilerle aynı kalsın diye aradaki boş satırlar da
 * gider, sondaki boş satırlar gitmez. Hücredeki sekme/yeni satır boşluk olur.
 */
export function sekmeliMetin(satirlar: string[][]): string {
  const dolu = satirlar.map((satir) => satir.some((hucre) => hucre.trim() !== ''))
  const son = dolu.lastIndexOf(true)

  return satirlar
    .slice(0, son + 1)
    .map((satir) => satir.map((hucre) => hucre.replace(/[\t\r\n]+/g, ' ')).join('\t'))
    .join('\n')
}
