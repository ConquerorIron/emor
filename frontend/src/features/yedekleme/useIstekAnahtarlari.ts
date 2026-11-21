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
 * eylemin sıralanmış JSON'unu — yedek açıklaması gibi serbest metni — düz
 * taşıyordu; açılışta silinir.
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
  const bayt = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(kanonikJson(eylem)))

  return Array.from(new Uint8Array(bayt), (b) => b.toString(16).padStart(2, '0')).join('')
}

const gecerliMi = (kayit: BekleyenAnahtar | undefined, simdi: number): kayit is BekleyenAnahtar =>
  kayit !== undefined && kayit.zaman <= simdi && simdi - kayit.zaman < ANAHTAR_OMRU_MS

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

/** Eski sürüm kayıtları (hangi kapsamda olursa olsun) depodan silinir */
function eskiSurumuTemizle(): void {
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const ad = localStorage.key(i)
      if (ad?.startsWith(ESKI_SURUM_ONEKI)) {
        localStorage.removeItem(ad)
      }
    }
  } catch {
    // depo kapalı
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
 * kayıtları ve bu kapsamın süresi geçmiş kayıtları silinir. Depo erişilemezse
 * bellekte (özetle) çalışır.
 *
 * Özet eşzamansızdır: `hazirla` beklenir, anahtar depoya istek gönderilmeden
 * ÖNCE yazılır. Çift tıklama kilidinin (F5) yerine geçmez; onunla birlikte
 * kullanılır.
 *
 * @param kapsam Anahtarların sahibi (ör. `kullanici.7`, `ustune.<işlem kimliği>`)
 */
export function useIstekAnahtarlari(kapsam: string) {
  const bellek = useRef(new Map<string, BekleyenAnahtar>())

  useEffect(() => {
    eskiSurumuTemizle()
    oku(kapsam, Date.now())
  }, [kapsam])

  return {
    /** Eylemin bekleyen anahtarı; yoksa yenisi — depoya yazıldıktan sonra döner */
    async hazirla(eylem: unknown): Promise<BekleyenIstek> {
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
