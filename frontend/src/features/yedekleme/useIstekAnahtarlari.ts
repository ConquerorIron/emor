import { useRef } from 'react'

/** Backend IstekAnahtari::BASLIK */
export const ISTEK_ANAHTARI_BASLIGI = 'Idempotency-Key'

/**
 * Bekleyen anahtarın ömrü (ms): yanıtı kaybolan isteğin sayfa yenilenerek
 * yeniden denenmesine yeter. Daha sonraki tekrar bilinçli yeni bir iştir;
 * sunucu aynı anahtarı istek dosyası durdukça (24 saate kadar) eşleştirdiği
 * için anahtar bundan çok daha kısa yaşar.
 */
export const ANAHTAR_OMRU_MS = 15 * 60 * 1000

const depoAnahtari = (kapsam: string) => `erp.yedekleme.istekAnahtarlari.v1.${kapsam}`

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/** Depoda yalnız bunlar durur: eylemin imzası, UUID anahtar, üretildiği an */
interface BekleyenAnahtar {
  imza: string
  anahtar: string
  zaman: number
}

/** Anahtar sırasından bağımsız imza: aynı seçimler hangi sırayla yapılırsa yapılsın aynı eylemdir */
function imzala(eylem: unknown): string {
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

const gecerliMi = (kayit: BekleyenAnahtar | undefined, simdi: number): kayit is BekleyenAnahtar =>
  kayit !== undefined && kayit.zaman <= simdi && simdi - kayit.zaman < ANAHTAR_OMRU_MS

/** Süresi geçmemiş geçerli kayıtlar; depo kapalıysa (gizli pencere, kota) boş */
function oku(kapsam: string, simdi: number): BekleyenAnahtar[] {
  try {
    const ham = localStorage.getItem(depoAnahtari(kapsam))
    const deger: unknown = ham === null ? [] : JSON.parse(ham)
    if (!Array.isArray(deger)) {
      return []
    }

    return deger.filter(
      (oge): oge is BekleyenAnahtar =>
        typeof oge === 'object' &&
        oge !== null &&
        typeof (oge as BekleyenAnahtar).imza === 'string' &&
        typeof (oge as BekleyenAnahtar).anahtar === 'string' &&
        UUID.test((oge as BekleyenAnahtar).anahtar) &&
        typeof (oge as BekleyenAnahtar).zaman === 'number' &&
        gecerliMi(oge as BekleyenAnahtar, simdi),
    )
  } catch {
    return []
  }
}

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
 * Aynı eylemin yeniden denemesi aynı `Idempotency-Key`'i taşır (BY-014-R2):
 * yanıtı alınamayan (ağ hatası, 5xx) istek sunucuda kabul edilmiş olabilir;
 * aynı anahtarla yeniden deneme ikinci iş bırakmaz, mevcut kabulü alır.
 * Anahtar eylem (tür + parametreler) başına tutulur ve kabul (202) gelince
 * unutulur: aynı eylemin sonraki bilinçli tekrarı yeni bir iştir.
 *
 * Bekleyen anahtarlar sayfa yenilenince de kaybolmasın diye `localStorage`'da
 * kapsam (kullanıcı ya da işlem) başına, ANAHTAR_OMRU_MS boyunca durur
 * (BY-014-R3, BY-030-R4). Depoya yalnız imza, UUID anahtar ve zaman girer;
 * erişim anahtarı ya da kanıt girmez. Depo erişilemezse bellekte çalışır.
 *
 * Çift tıklama kilidinin (F5) yerine geçmez; onunla birlikte kullanılır.
 *
 * @param kapsam Anahtarların sahibi (ör. `kullanici.7`, `ustune.<işlem kimliği>`)
 */
export function useIstekAnahtarlari(kapsam: string) {
  const bellek = useRef(new Map<string, BekleyenAnahtar>())

  return {
    /** Eylemin bekleyen anahtarı; yoksa yenisi (istek gönderilmeden ÖNCE depoya yazılır) */
    anahtar(eylem: unknown): string {
      const imza = imzala(eylem)
      const simdi = Date.now()
      const kayitlar = oku(kapsam, simdi)
      const mevcut = kayitlar.find((kayit) => kayit.imza === imza) ?? bellek.current.get(imza)
      if (gecerliMi(mevcut, simdi)) {
        return mevcut.anahtar
      }

      const yeni = { imza, anahtar: crypto.randomUUID(), zaman: simdi }
      bellek.current.set(imza, yeni)
      yaz(kapsam, [...kayitlar, yeni])

      return yeni.anahtar
    },
    /** Sunucu kabul etti: aynı eylemin bir sonraki gönderimi yeni anahtar alır */
    kabulEdildi(eylem: unknown): void {
      const imza = imzala(eylem)
      bellek.current.delete(imza)
      yaz(
        kapsam,
        oku(kapsam, Date.now()).filter((kayit) => kayit.imza !== imza),
      )
    },
  }
}
