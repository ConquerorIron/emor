import type { ToplamKonumu } from '@/components/DataTable'

import type { ErpRaporApi, RaporSatiri } from './erpRaporApi'

export type KolonTuru = 'metin' | 'tarih' | 'tutar'

/**
 * Rapor gruplarının ve seçeneklerinin sırası: Türkçe, sayılar sayı gibi
 * (ZONE 7, ZONE 10'dan önce). Tek nesne: localeCompare'in her çağrıda kurduğu
 * karşılaştırıcı binlerce satırda tıklamayı kasıyordu.
 */
export const raporSiralayici = new Intl.Collator('tr', { numeric: true })

/** Para kodları (TRY, USD…): önceki localeCompare(…, 'tr') ile aynı sıra */
const paraSiralayici = new Intl.Collator('tr')

/** Tablonun üstünde ve altında tekrarlanan toplam satırı */
export interface RaporToplami {
  anahtar: string
  /** i18n anahtarı; Sıra No kolonuna yazılır */
  etiket: string
  /** Etiketin çeviri parametreleri (ör. dışarıda kalan fatura sayısı) */
  etiketParametreleri?: Record<string, string | number>
  /** Kolon alanı → değer; olmayan kolon boş kalır */
  degerler: Record<string, string | number>
}

/**
 * "Hepsi" (0) sayfa boyutu raporda bu kadar satırla karşılanır, sayfalama
 * sürer: liste ekranlarında backend'in yaptığının eşleniği (tam yıl ~12.000
 * satır × 41 kolon tek seferde çizilince sekme kasıyordu).
 */
const HEPSI_SINIRI = 1000

/** Ortak sayfa boyutu tercihinin rapordaki karşılığı (0 = "Hepsi" → HEPSI_SINIRI) */
export function raporSayfaBoyutu(boyut: number): number {
  return boyut === 0 ? HEPSI_SINIRI : boyut
}

/** Türkçe biçimli sayı metni ("50,123456") → sayı; boşsa −∞ (en küçük) */
export function metinSayisi(deger: string | number | null | undefined): number {
  const metin = String(deger ?? '').trim()
  const sayi = Number(metin.replace(/\./g, '').replace(',', '.'))

  return metin === '' || !Number.isFinite(sayi) ? -Infinity : sayi
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
  /** projeAlani süzgecinin etiketi (i18n); verilmezse "Projeye göre filtrele" */
  projeSuzgeciEtiketi?: string
  /**
   * Parametre formundaki proje kodu / firma adı seçicileri (ERP proc girdileri).
   * ERP'ye gitmeyen raporda false: seçiciler çizilmez, seçenekler istenmez.
   */
  erpParametreleri?: boolean
  /** Rapor beklenirken gösterilen metin (i18n); verilmezse "Rapor ERP'den okunuyor…" */
  hazirlaniyorMetni?: string
  /** Sabit genişlikli metin kolonları (Tailwind genişlik sınıfı): tek satır, sığmayan kısım "…" */
  sabitGenislik?: Partial<Record<keyof T & string, string>>
  /** Ortalanan metin kolonları (tarihler zaten ortalı) */
  ortali?: (keyof T & string)[]
  /** Toplam satırları — süzülen satırların tamamından (sayfa değil); özet sekmelerinde de */
  toplamlar: (satirlar: T[]) => RaporToplami[]
  /** Toplam satırlarının yeri (DataTable); verilmezse başlığın altında ve tablonun sonunda */
  toplamKonumu?: ToplamKonumu
  /** Özet (pivot) sekmeleri, Data'dan sonra — backend ErpRaporu::PIVOTLAR ile aynı; yoksa sekme şeridi çizilmez */
  pivotlar: RaporPivotu[]
  /** Pivotlardaki Yıl / Ay'ın türediği tarih kolonu (YYYY-MM-DD); Yıl / Ay pivotu olmayan raporda gerekmez */
  tarihAlani?: keyof T & string
  /** Gelen veride switch'le açılan süzgeçler; açık olanların hepsini tutan satırlar kalır */
  kosulSuzgecleri?: KosulSuzgeci<T>[]
  /** Parametre formundaki ilk tarih (YYYY-MM-DD, açılışta hesaplanır); verilmezse 2000-01-01 (tüm geçmiş) */
  varsayilanIlkTarih?: () => string
  /**
   * Satırda boş (null = bilinmiyor) olunca özet grubunun toplamını da boş yapan
   * tutar kolonları — backend ErpRaporu::BOS_YAYILAN_TUTARLAR ile aynı
   */
  bosYayilanTutarlar?: readonly (keyof T & string)[]
  /** Değeri Türkçe biçimli sayı olan metin kolonları ("50,123456"): başlıktan sayı gibi sıralanır */
  sayiGibiSiralanan?: (keyof T & string)[]
}

/** Switch süzgeci: açıkken yalnız koşulu tutan satırlar; etiket i18n raporlar.<rapor>.suzgec.<anahtar> */
export interface KosulSuzgeci<T extends RaporSatiri> {
  anahtar: string
  kosul: (satir: T) => boolean
}

/** Özet sekmesi: grup kolonları ("yil" / "ay" tarih alanından türer); i18n raporlar.pivot.<anahtar> */
export interface RaporPivotu {
  anahtar: string
  gruplar: string[]
}

/** Pivot satırı: gruplar + satir_sayisi + tutar kolonları (bilinmeyen toplam null) */
export type PivotSatiri = Record<string, string | number | null>

/**
 * Satırlar sekmenin gruplarına göre toplanır (süzülen satırlardan, tarayıcıda);
 * gruplar soldan sağa sıralı (sayılar sayı gibi, metinler Türkçe). Backend
 * ErpRaporu::pivot ile aynı hesap (Excel'in özet sayfaları): bosYayilanlar'dan
 * biri grubun bir satırında boşsa grubun o hücresi de boş.
 */
export function pivotla<T extends RaporSatiri>(
  satirlar: T[],
  pivot: RaporPivotu,
  tarihAlani: string,
  tutarlar: readonly string[],
  bosYayilanlar: readonly string[] = [],
): PivotSatiri[] {
  const gruplar = new Map<string, PivotSatiri>()
  const bosYayilan = new Set(bosYayilanlar)

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
      if (grup[alan] === null) {
        continue
      }
      grup[alan] =
        satir[alan] === null && bosYayilan.has(alan)
          ? null
          : Number(grup[alan]) + Number(satir[alan] ?? 0)
    }
  }

  return [...gruplar.values()].sort((a, b) => {
    for (const alan of pivot.gruplar) {
      const x = a[alan]
      const y = b[alan]
      const fark =
        typeof x === 'number' && typeof y === 'number'
          ? x - y
          : raporSiralayici.compare(String(x), String(y))
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
    .sort(([a], [b]) => paraSiralayici.compare(a, b))
    .map(([para, grup]) => ({
      anahtar: `para-${para}`,
      etiket,
      degerler: { para_kodu: para, ...topla(grup, alanlar) },
    }))
}
