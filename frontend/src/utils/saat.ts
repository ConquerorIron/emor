/**
 * Saat girdisi ayrıştırma (Codex #19 — YÜKSEK): `Number('abc')`/`Number('1,5')`
 * NaN üretir; JSON'da null'a dönüşüp backend'de "planlanan saat" varsayılanına
 * düşerdi — bordro verisinde sessiz bozulma. Boş girdi BİLİNÇLİ null'dur
 * (backend planlanandan türetir); virgül Türkçe ondalık olarak noktaya çevrilir;
 * sayı olmayan her şey geçersizdir ve mutasyona hiç gitmemelidir.
 */
export type SaatSonucu = { gecerli: true; deger: number | null } | { gecerli: false }

export function saatiCoz(girdi: string): SaatSonucu {
  const temiz = girdi.trim()

  if (temiz === '') {
    return { gecerli: true, deger: null }
  }

  const normal = temiz.replace(',', '.')
  const deger = Number(normal)

  if (!Number.isFinite(deger)) {
    return { gecerli: false }
  }

  return { gecerli: true, deger }
}
