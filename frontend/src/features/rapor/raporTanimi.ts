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
  /** Genel aramanın baktığı metin kolonları (ipucu: raporlar.<anahtar>.aramaIpucu) */
  aramaAlanlari: (keyof T & string)[]
  /** Gelen veride anlık süzme kolonları */
  projeAlani: keyof T & string
  firmaAlani: keyof T & string
  /** Sabit genişlikli metin kolonları (Tailwind genişlik sınıfı): tek satır, sığmayan kısım "…" */
  sabitGenislik?: Partial<Record<keyof T & string, string>>
  /** Ortalanan metin kolonları (tarihler zaten ortalı) */
  ortali?: (keyof T & string)[]
  /** Toplam satırları — süzülen satırların tamamından (sayfa değil); özet sekmelerinde de */
  toplamlar: (satirlar: T[]) => RaporToplami[]
  /** Özet (pivot) sekmeleri, Data'dan sonra — backend ErpRaporu::PIVOTLAR ile aynı; yoksa sekme şeridi çizilmez */
  pivotlar: RaporPivotu[]
  /** Pivotlardaki Yıl / Ay'ın türediği tarih kolonu (YYYY-MM-DD); Yıl / Ay pivotu olmayan raporda gerekmez */
  tarihAlani?: keyof T & string
}

/** Özet sekmesi: grup kolonları ("yil" / "ay" tarih alanından türer); i18n raporlar.pivot.<anahtar> */
export interface RaporPivotu {
  anahtar: string
  gruplar: string[]
}

/** Pivot satırı: gruplar + satir_sayisi + tutar kolonları */
export type PivotSatiri = Record<string, string | number>

/**
 * Satırlar sekmenin gruplarına göre toplanır (süzülen satırlardan, tarayıcıda);
 * gruplar soldan sağa sıralı (sayılar sayı gibi, metinler Türkçe). Backend
 * ErpRaporu::pivot ile aynı hesap (Excel'in özet sayfaları).
 */
export function pivotla<T extends RaporSatiri>(
  satirlar: T[],
  pivot: RaporPivotu,
  tarihAlani: string,
  tutarlar: readonly string[],
): PivotSatiri[] {
  const gruplar = new Map<string, PivotSatiri>()

  for (const satir of satirlar) {
    const tarih = String(satir[tarihAlani] ?? '')
    const degerler = pivot.gruplar.map((alan): string | number => {
      if (alan === 'yil') {
        return Number(tarih.slice(0, 4))
      }
      if (alan === 'ay') {
        return Number(tarih.slice(5, 7))
      }

      return String(satir[alan] ?? '')
    })
    const anahtar = degerler.join('\u0000')
    let grup = gruplar.get(anahtar)
    if (!grup) {
      grup = {
        ...Object.fromEntries(pivot.gruplar.map((alan, i) => [alan, degerler[i]])),
        satir_sayisi: 0,
        ...Object.fromEntries(tutarlar.map((alan) => [alan, 0])),
      }
      gruplar.set(anahtar, grup)
    }
    grup.satir_sayisi = Number(grup.satir_sayisi) + 1
    for (const alan of tutarlar) {
      grup[alan] = Number(grup[alan]) + Number(satir[alan] ?? 0)
    }
  }

  return [...gruplar.values()].sort((a, b) => {
    for (const alan of pivot.gruplar) {
      const x = a[alan]
      const y = b[alan]
      const fark =
        typeof x === 'number' && typeof y === 'number'
          ? x - y
          : String(x).localeCompare(String(y), 'tr', { numeric: true })
      if (fark !== 0) {
        return fark
      }
    }

    return 0
  })
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
