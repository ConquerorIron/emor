import type { DuzenAlani, EkranDuzeni, KatalogAlani } from './types'

/**
 * Düzen üzerinde saf (yan etkisiz) işlemler — sürükle-bırak editörünün
 * çekirdeği. UI'dan ayrı tutulur ki davranış testlerle kilitlenebilsin.
 * Her işlem YENİ bir düzen döner; girdiyi değiştirmez.
 */

function bolumleriKopyala(duzen: EkranDuzeni): EkranDuzeni {
  return {
    bolumler: duzen.bolumler.map((bolum) => ({
      ...bolum,
      alanlar: bolum.alanlar.map((alan) => ({ ...alan })),
    })),
  }
}

/** Alanın hangi bölümde ve kaçıncı sırada olduğu; yoksa null. */
export function alanKonumu(
  duzen: EkranDuzeni,
  alanAnahtari: string,
): { bolum: number; indeks: number } | null {
  for (let bolum = 0; bolum < duzen.bolumler.length; bolum += 1) {
    const indeks = duzen.bolumler[bolum].alanlar.findIndex((a) => a.alan === alanAnahtari)
    if (indeks !== -1) {
      return { bolum, indeks }
    }
  }

  return null
}

/** Tasarımda yeri olmayan katalog alanları (paletten sürüklenecekler). */
export function kullanilmayanAlanlar(katalog: KatalogAlani[], duzen: EkranDuzeni): KatalogAlani[] {
  const yerlesik = new Set<string>()
  for (const bolum of duzen.bolumler) {
    for (const alan of bolum.alanlar) {
      yerlesik.add(alan.alan)
    }
  }

  return katalog.filter((alan) => !yerlesik.has(alan.anahtar))
}

/**
 * Alanı hedef bölümün verilen sırasına yerleştirir. Alan başka bir yerdeyse
 * önce oradan çıkarılır (taşıma); hiç yoksa eklenir (paletten sürükleme).
 * hedefIndeks null ise sona eklenir.
 */
export function alanYerlestir(
  duzen: EkranDuzeni,
  alanAnahtari: string,
  hedefBolumAnahtari: string,
  hedefIndeks: number | null,
  varsayilanGenislik = 6,
): EkranDuzeni {
  const yeni = bolumleriKopyala(duzen)
  const hedefBolum = yeni.bolumler.find((b) => b.anahtar === hedefBolumAnahtari)
  if (!hedefBolum) {
    return duzen
  }

  // Mevcut kaydı bul ve çıkar (ayarları korunsun diye taşınır, yeniden kurulmaz)
  let tasinan: DuzenAlani = { alan: alanAnahtari, genislik: varsayilanGenislik }
  const konum = alanKonumu(yeni, alanAnahtari)
  if (konum) {
    tasinan = yeni.bolumler[konum.bolum].alanlar[konum.indeks]
    yeni.bolumler[konum.bolum].alanlar.splice(konum.indeks, 1)
  }

  const sinir = hedefBolum.alanlar.length
  const yer = hedefIndeks === null ? sinir : Math.max(0, Math.min(hedefIndeks, sinir))
  hedefBolum.alanlar.splice(yer, 0, tasinan)

  return yeni
}

/** Alanı tasarımdan çıkarır (palete geri döner). */
export function alanKaldir(duzen: EkranDuzeni, alanAnahtari: string): EkranDuzeni {
  const yeni = bolumleriKopyala(duzen)
  const konum = alanKonumu(yeni, alanAnahtari)
  if (konum) {
    yeni.bolumler[konum.bolum].alanlar.splice(konum.indeks, 1)
  }

  return yeni
}

/** Alanın kurallarını günceller (genişlik, zorunlu, salt okunur, textarea…). */
export function alanGuncelle(
  duzen: EkranDuzeni,
  alanAnahtari: string,
  degisiklik: Partial<DuzenAlani>,
): EkranDuzeni {
  const yeni = bolumleriKopyala(duzen)
  const konum = alanKonumu(yeni, alanAnahtari)
  if (!konum) {
    return duzen
  }

  const mevcut = yeni.bolumler[konum.bolum].alanlar[konum.indeks]
  const guncel: DuzenAlani = { ...mevcut, ...degisiklik }

  // Kapatılan seçenekler JSON'da iz bırakmasın
  if (guncel.gizli !== true) {
    delete guncel.gizli
  } else {
    // Gizli alanı kullanıcı dolduramaz; zorunluluk anlamsızlaşır
    delete guncel.zorunlu
  }
  if (guncel.zorunlu !== true) {
    delete guncel.zorunlu
  }
  if (guncel.salt_okunur !== true) {
    delete guncel.salt_okunur
  }
  if (guncel.gorunum !== 'textarea') {
    delete guncel.gorunum
    delete guncel.satir
  }
  if (!guncel.varsayilan) {
    delete guncel.varsayilan
  }

  yeni.bolumler[konum.bolum].alanlar[konum.indeks] = guncel

  return yeni
}

export function bolumGenisligiDegistir(
  duzen: EkranDuzeni,
  bolumAnahtari: string,
  genislik: number,
): EkranDuzeni {
  return {
    bolumler: duzen.bolumler.map((bolum) =>
      bolum.anahtar === bolumAnahtari ? { ...bolum, genislik } : bolum,
    ),
  }
}
