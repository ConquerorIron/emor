import { useEffect, useRef } from 'react'

/** Backend IstekAnahtari::BASLIK */
export const ISTEK_ANAHTARI_BASLIGI = 'Idempotency-Key'

/**
 * Bekleyen anahtarın ömrü (ms): yanıtı kaybolan isteğin sayfa yenilenerek
 * yeniden denenmesine yeter. Daha sonraki tekrar bilinçli yeni bir iştir;
 * sunucu aynı anahtarı istek dosyası durdukça (24 saate kadar) eşleştirdiği
 * için anahtar bundan çok daha kısa yaşar.
 */
export const ANAHTAR_OMRU_MS = 15 * 60 * 1000

const DEPO_ONEKI = 'erp.yedekleme.istekAnahtarlari'

/**
 * v2: kayıtta eylemin ham hali yerine özeti durur (BY-014-R4). v1 kayıtları
 * (`{imza, anahtar, zaman}`; imza = kanonikJson(eylem)) yedek açıklaması gibi
 * serbest metni düz taşıyordu; açılışta v2'ye taşınıp silinir (eskiSurumuTasi).
 */
const depoAnahtari = (kapsam: string) => `${DEPO_ONEKI}.v2.${kapsam}`
const ESKI_SURUM_ONEKI = `${DEPO_ONEKI}.v1.`

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const OZET = /^[0-9a-f]{64}$/

/** Depoda yalnız bunlar durur: eylemin SHA-256 özeti, UUID anahtar, üretildiği an */
interface BekleyenAnahtar {
  ozet: string
  anahtar: string
  zaman: number
}

/** Gönderilecek eylemin istek anahtarı; sunucu kabul edince (202) `kabulEdildi` */
export interface BekleyenIstek {
  anahtar: string
  kabulEdildi: () => void
}

/** Anahtar sırasından bağımsız JSON: aynı seçimler hangi sırayla yapılırsa yapılsın aynı eylemdir */
function kanonikJson(eylem: unknown): string {
  return JSON.stringify(eylem, (_anahtar, deger: unknown) =>
    deger !== null && typeof deger === 'object' && !Array.isArray(deger)
      ? Object.fromEntries(
          Object.entries(deger as Record<string, unknown>).sort(([a], [b]) =>
            a < b ? -1 : a > b ? 1 : 0,
          ),
        )
      : deger,
  )
}

/**
 * Kanonik eylem JSON'unun SHA-256 özeti (onaltılık). Depoya ve belleğe ham
 * eylem (serbest metin dahil) girmez; aynı eylem yine aynı özeti verir.
 * Web Crypto güvenli bağlam ister (HTTPS / localhost) — `crypto.randomUUID`
 * ile aynı koşul.
 */
async function ozetle(eylem: unknown): Promise<string> {
  return metninOzeti(kanonikJson(eylem))
}

async function metninOzeti(metin: string): Promise<string> {
  const bayt = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(metin))

  return Array.from(new Uint8Array(bayt), (b) => b.toString(16).padStart(2, '0')).join('')
}

const gecerliMi = <T extends { zaman: number }>(kayit: T | undefined, simdi: number): kayit is T =>
  kayit !== undefined && kayit.zaman <= simdi && simdi - kayit.zaman < ANAHTAR_OMRU_MS

/** v1 kaydı (taşınmak için okunur): `imza` eylemin kanonik JSON'u, yani ham metin */
interface EskiBekleyenAnahtar {
  imza: string
  anahtar: string
  zaman: number
}

const eskiBicimliMi = (oge: unknown): oge is EskiBekleyenAnahtar =>
  typeof oge === 'object' &&
  oge !== null &&
  typeof (oge as EskiBekleyenAnahtar).imza === 'string' &&
  typeof (oge as EskiBekleyenAnahtar).anahtar === 'string' &&
  UUID.test((oge as EskiBekleyenAnahtar).anahtar) &&
  typeof (oge as EskiBekleyenAnahtar).zaman === 'number'

const bicimliMi = (oge: unknown): oge is BekleyenAnahtar =>
  typeof oge === 'object' &&
  oge !== null &&
  typeof (oge as BekleyenAnahtar).ozet === 'string' &&
  OZET.test((oge as BekleyenAnahtar).ozet) &&
  typeof (oge as BekleyenAnahtar).anahtar === 'string' &&
  UUID.test((oge as BekleyenAnahtar).anahtar) &&
  typeof (oge as BekleyenAnahtar).zaman === 'number'

function yaz(kapsam: string, kayitlar: BekleyenAnahtar[]): void {
  try {
    if (kayitlar.length === 0) {
      localStorage.removeItem(depoAnahtari(kapsam))
    } else {
      localStorage.setItem(depoAnahtari(kapsam), JSON.stringify(kayitlar))
    }
  } catch {
    // depo kapalı: anahtar yalnız bu çizimin belleğinde yaşar
  }
}

/**
 * Süresi geçmemiş geçerli kayıtlar; depo kapalıysa (gizli pencere, kota) boş.
 * Süresi geçen ya da biçimsiz kayıt okurken depodan da silinir: bir sonraki
 * yazımı beklemez.
 */
function oku(kapsam: string, simdi: number): BekleyenAnahtar[] {
  try {
    const ham = localStorage.getItem(depoAnahtari(kapsam))
    if (ham === null) {
      return []
    }
    const deger: unknown = JSON.parse(ham)
    const tumu: unknown[] = Array.isArray(deger) ? deger : []
    const gecerli = tumu.filter(
      (oge): oge is BekleyenAnahtar => bicimliMi(oge) && gecerliMi(oge, simdi),
    )
    if (!Array.isArray(deger) || gecerli.length !== tumu.length) {
      yaz(kapsam, gecerli)
    }

    return gecerli
  } catch {
    try {
      localStorage.removeItem(depoAnahtari(kapsam))
    } catch {
      // depo kapalı
    }

    return []
  }
}

/**
 * Eski sürüm (v1) kayıtlarını, hangi kapsamda olursa olsun, v2'ye taşır
 * (BY-014-R5). v1 sürümünde kabul edilip yanıtı kaybolan isteğin anahtarı hâlâ
 * geçerli olabilir; yeni sürümde aynı eylem yeni anahtar alırsa ikinci iş
 * açılır. Hiçbir zaman reddetmez; girdi başına eskiKaydiTasi.
 */
async function eskiSurumuTasi(): Promise<void> {
  const adlar: string[] = []
  try {
    for (let i = 0; i < localStorage.length; i++) {
      const ad = localStorage.key(i)
      if (ad?.startsWith(ESKI_SURUM_ONEKI)) {
        adlar.push(ad)
      }
    }
  } catch {
    return // depo kapalı
  }

  for (const ad of adlar) {
    await eskiKaydiTasi(ad)
  }
}

function eskiKaydiSil(ad: string): void {
  try {
    localStorage.removeItem(ad)
  } catch {
    // depo kapalı
  }
}

/**
 * Tek v1 girdisini taşır. Süresi geçmemiş, biçimli kaydın yalnız imzasının
 * özeti (v2 ile AYNI kanonik metin → aynı özet), UUID'si ve zamanı aynı
 * kapsamın v2 deposuna yazılır; v2'de aynı özetli kayıt varsa o (daha yeni
 * deneme) kalır. Ham v1 girdisi her durumda — süresi geçmiş, biçimsiz, özet ya
 * da yazım başarısız — silinir: düz metin depoda kalmaz.
 *
 * Özet eşzamansızdır; beklerken başka bir örnek (aynı sayfada başka bileşen ya
 * da başka sekme) aynı girdiyi taşımış, taşınan anahtarla istek kabul edilip
 * v2'den silinmiş olabilir (BY-014-R6). Bu yüzden v2'ye yazım, kaynağın hâlâ
 * okunan HAM metinle aynı olduğu doğrulanarak ve v1 silinerek, aradaki
 * beklemesiz tek eşzamanlı adımda yapılır: girdi artık yoksa (taşınmış)
 * eski kopyadan yeniden yazılmaz; değişmişse güncel hali baştan taşınır.
 */
async function eskiKaydiTasi(ad: string): Promise<void> {
  const kapsam = ad.slice(ESKI_SURUM_ONEKI.length)
  try {
    const ham = localStorage.getItem(ad)
    if (ham === null) {
      return // başka bir örnek taşıdı
    }
    const deger: unknown = JSON.parse(ham)
    const simdi = Date.now()
    const tasinacak = (Array.isArray(deger) ? deger : []).filter(
      (oge): oge is EskiBekleyenAnahtar => eskiBicimliMi(oge) && gecerliMi(oge, simdi),
    )
    if (tasinacak.length === 0) {
      eskiKaydiSil(ad)

      return
    }
    const yeniler = await Promise.all(
      tasinacak.map(async ({ imza, anahtar, zaman }): Promise<BekleyenAnahtar> => ({
        ozet: await metninOzeti(imza),
        anahtar,
        zaman,
      })),
    )
    // Buradan sona kadar await yok: denetim, yazım ve silme bölünmez
    if (localStorage.getItem(ad) !== ham) {
      return eskiKaydiTasi(ad)
    }
    const mevcut = oku(kapsam, Date.now())
    yaz(kapsam, [
      ...mevcut,
      ...yeniler.filter((yeni) => !mevcut.some((kayit) => kayit.ozet === yeni.ozet)),
    ])
    eskiKaydiSil(ad)
  } catch {
    // biçimsiz JSON ya da özet alınamadı: taşınmaz
    eskiKaydiSil(ad)
  }
}

/**
 * Aynı eylemin yeniden denemesi aynı `Idempotency-Key`'i taşır (BY-014-R2):
 * yanıtı alınamayan (ağ hatası, 5xx) istek sunucuda kabul edilmiş olabilir;
 * aynı anahtarla yeniden deneme ikinci iş bırakmaz, mevcut kabulü alır.
 * Anahtar eylem (tür + parametreler) başına tutulur ve kabul (202) gelince
 * unutulur: aynı eylemin sonraki bilinçli tekrarı yeni bir iştir.
 *
 * Bekleyen anahtarlar sayfa yenilenince de kaybolmasın diye `localStorage`'da
 * kapsam (kullanıcı ya da işlem) başına, ANAHTAR_OMRU_MS boyunca durur
 * (BY-014-R3, BY-030-R4). Depoya yalnız eylemin SHA-256 özeti, UUID anahtar
 * ve zaman girer; eylemin parametreleri (yedek açıklaması gibi serbest metin),
 * erişim anahtarı ya da kanıt girmez (BY-014-R4). Açılışta eski sürüm
 * kayıtlarının geçerli olanları v2'ye taşınır, hepsi silinir (BY-014-R5); bu
 * kapsamın süresi geçmiş kayıtları silinir. Depo erişilemezse bellekte
 * (özetle) çalışır.
 *
 * Özet eşzamansızdır: `hazirla` beklenir; açılıştaki taşıma bitmeden anahtar
 * aranmaz, anahtar depoya istek gönderilmeden ÖNCE yazılır. Çift tıklama
 * kilidinin (F5) yerine geçmez; onunla birlikte kullanılır.
 *
 * @param kapsam Anahtarların sahibi (ör. `kullanici.7`, `ustune.<işlem kimliği>`)
 */
export function useIstekAnahtarlari(kapsam: string) {
  const bellek = useRef(new Map<string, BekleyenAnahtar>())
  const acilis = useRef<Promise<void> | null>(null)

  useEffect(() => {
    acilis.current = eskiSurumuTasi().then(() => {
      oku(kapsam, Date.now())
    })
  }, [kapsam])

  return {
    /** Eylemin bekleyen anahtarı; yoksa yenisi — depoya yazıldıktan sonra döner */
    async hazirla(eylem: unknown): Promise<BekleyenIstek> {
      // v1'den taşınan geçerli anahtar aranmadan yeni anahtar üretilmesin
      await acilis.current
      const ozet = await ozetle(eylem)
      const simdi = Date.now()
      const kayitlar = oku(kapsam, simdi)
      const mevcut = kayitlar.find((kayit) => kayit.ozet === ozet) ?? bellek.current.get(ozet)
      let kayit: BekleyenAnahtar
      if (gecerliMi(mevcut, simdi)) {
        kayit = mevcut
      } else {
        kayit = { ozet, anahtar: crypto.randomUUID(), zaman: simdi }
        yaz(kapsam, [...kayitlar, kayit])
      }
      bellek.current.set(ozet, kayit)

      return {
        anahtar: kayit.anahtar,
        // Sunucu kabul etti: aynı eylemin bir sonraki gönderimi yeni anahtar alır
        kabulEdildi: () => {
          bellek.current.delete(ozet)
          yaz(
            kapsam,
            oku(kapsam, Date.now()).filter((bekleyen) => bekleyen.ozet !== ozet),
          )
        },
      }
    },
  }
}
