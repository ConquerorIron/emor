import type { ErpRaporApi, RaporSatiri } from './erpRaporApi'

export type KolonTuru = 'metin' | 'tarih' | 'tutar'

/** Tablonun üstünde ve altında tekrarlanan toplam satırı */
export interface RaporToplami {
  anahtar: string
  /** i18n anahtarı; Sıra No kolonuna yazılır */
  etiket: string
  /** Kolon alanı → değer; olmayan kolon boş kalır */
  degerler: Record<string, string | number>
}

/**
 * Raporlar menüsündeki bir ERP raporunun ekranı (ErpRaporSayfasi bunu çizer).
 * Backend karşılığı ErpRaporu alt sınıfı: kolonlar ve satır anahtarı aynı.
 */
export interface ErpRaporTanimi<T extends RaporSatiri> {
  /** i18n: raporlar.<anahtar>.baslik / aciklamaMetni / kolon.*; element kimlikleri ve sorgu anahtarı */
  anahtar: string
  api: ErpRaporApi<T>
  /** Sıra No'dan sonra, sırasıyla */
  kolonlar: { alan: keyof T & string; tur: KolonTuru }[]
  /** Satır kimliği — Excel'e ekranda süzülen satırlar bununla gider (backend ErpRaporu::ANAHTAR) */
  satirAnahtari: (satir: T) => string
  /** Gelen veride anlık süzme kolonları */
  projeAlani: keyof T & string
  firmaAlani: keyof T & string
  /** Sabit genişlikli metin kolonları (Tailwind genişlik sınıfı): tek satır, sığmayan kısım "…" */
  sabitGenislik?: Partial<Record<keyof T & string, string>>
  /** Ortalanan metin kolonları (tarihler zaten ortalı) */
  ortali?: (keyof T & string)[]
  /** Toplam satırları — süzülen satırların tamamından (sayfa değil) */
  toplamlar: (satirlar: T[]) => RaporToplami[]
}

/** Verilen tutar alanlarının toplamı */
export function topla<T extends RaporSatiri>(
  satirlar: T[],
  alanlar: readonly string[],
): Record<string, number> {
  const toplam: Record<string, number> = Object.fromEntries(alanlar.map((alan) => [alan, 0]))

  for (const satir of satirlar) {
    for (const alan of alanlar) {
      toplam[alan] += Number(satir[alan] ?? 0)
    }
  }

  return toplam
}

/** Para kodu (`para_kodu` kolonu) başına verilen tutar alanlarının toplamı, para koduna göre sıralı */
export function paraKodunaGoreTopla<T extends RaporSatiri>(
  satirlar: T[],
  alanlar: readonly string[],
  etiket = 'raporlar.toplam',
): RaporToplami[] {
  const gruplar = new Map<string, T[]>()

  for (const satir of satirlar) {
    const para = String(satir.para_kodu ?? '')
    const grup = gruplar.get(para)
    if (grup) {
      grup.push(satir)
    } else {
      gruplar.set(para, [satir])
    }
  }

  return [...gruplar.entries()]
    .sort(([a], [b]) => a.localeCompare(b, 'tr'))
    .map(([para, grup]) => ({
      anahtar: `para-${para}`,
      etiket,
      degerler: { para_kodu: para, ...topla(grup, alanlar) },
    }))
}
