import { izinEtiketAnahtari, type EkranIzni } from '@/features/ayarlar/yetkiApi'
import type { NavGrubu } from '@/layouts/navGruplari'

/** Matristeki katlanır bölüm: bir menü grubunun ekranları */
export interface IzinGrubu {
  /** i18n: menü grubunun başlığı (nav.*) ya da yetki.digerEkranlar */
  baslikAnahtari: string
  ekranlar: EkranIzni[]
}

/**
 * Katalogdaki ekranlar menüdeki gruplarına göre, menü sırasıyla (ekran,
 * görüntüleme izniyle eşleşir). Menüde olmayan ekranlar sondaki "Diğer"
 * grubunda: yeni bir ekran matristen düşmez.
 */
export function izinGruplari(katalog: EkranIzni[], menu: NavGrubu[]): IzinGrubu[] {
  const kalanlar = new Map(katalog.map((ekran) => [ekran.goruntule, ekran]))
  const gruplar: IzinGrubu[] = []

  for (const { baslikAnahtari, ogeler } of menu) {
    if (baslikAnahtari === null) {
      continue
    }
    const ekranlar: EkranIzni[] = []
    for (const { izin } of ogeler) {
      const ekran = izin === undefined ? undefined : kalanlar.get(izin)
      if (ekran) {
        ekranlar.push(ekran)
        kalanlar.delete(ekran.goruntule)
      }
    }
    if (ekranlar.length > 0) {
      gruplar.push({ baslikAnahtari, ekranlar })
    }
  }
  if (kalanlar.size > 0) {
    gruplar.push({ baslikAnahtari: 'yetki.digerEkranlar', ekranlar: [...kalanlar.values()] })
  }

  return gruplar
}

/** Ekranların hepsinde aynı düzeyi açar / kapar (izinDegistir kurallarıyla); güncellemesi olmayan ekran atlanır */
export function grupDegistir(
  katalog: EkranIzni[],
  secili: string[],
  ekranlar: EkranIzni[],
  duzey: 'goruntule' | 'guncelle',
  acik: boolean,
): string[] {
  return ekranlar.reduce((sonuc, ekran) => {
    const izin = ekran[duzey]

    return izin === null ? sonuc : izinDegistir(katalog, sonuc, ekran, izin, acik)
  }, secili)
}

/** Ekranın tüm izin kodları: görüntüleme, güncelleme ve ek izinler. */
function ekranKodlari(ekran: EkranIzni): string[] {
  return [ekran.goruntule, ...(ekran.guncelle ? [ekran.guncelle] : []), ...ekran.ekler]
}

/**
 * Matristeki bir anahtarın yeni izin listesi. Backend'in kayıt kuralını
 * arayüzde de uygular: güncelleme ya da ek izin açılınca ekranın görüntüleme
 * izni de açılır; görüntüleme kapanınca ekranın tüm izinleri kapanır.
 * Sonuç katalog sırasındadır; katalogda olmayan (eskimiş) kodlar düşer.
 */
export function izinDegistir(
  katalog: EkranIzni[],
  secili: string[],
  ekran: EkranIzni,
  izin: string,
  acik: boolean,
): string[] {
  const yeni = new Set(secili)

  if (acik) {
    yeni.add(izin)
    yeni.add(ekran.goruntule)
  } else if (izin === ekran.goruntule) {
    for (const kod of ekranKodlari(ekran)) {
      yeni.delete(kod)
    }
  } else {
    yeni.delete(izin)
  }

  return katalog.flatMap(ekranKodlari).filter((kod) => yeni.has(kod))
}

/**
 * Rol listesindeki özet: izni olan her ekran için bir satır, ör.
 * "SQL Bağlantıları: Güncelle" ya da "e-Faturalar: Görüntüle (e-Fatura PDF/XML görüntüleme)".
 */
export function rolOzeti(
  izinler: string[],
  katalog: EkranIzni[],
  t: (anahtar: string) => string,
): string[] {
  const secili = new Set(izinler)

  return katalog.flatMap((ekran) => {
    if (!secili.has(ekran.goruntule)) {
      return []
    }
    const duzey =
      ekran.guncelle !== null && secili.has(ekran.guncelle)
        ? t('yetki.guncelle')
        : t('yetki.goruntule')
    const ekler = ekran.ekler.filter((ek) => secili.has(ek)).map((ek) => t(izinEtiketAnahtari(ek)))

    return [
      `${t(`yetki.ekran.${ekran.ekran}`)}: ${duzey}${ekler.length > 0 ? ` (${ekler.join(', ')})` : ''}`,
    ]
  })
}
