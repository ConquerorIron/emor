import { useRef } from 'react'

/** Backend IstekAnahtari::BASLIK */
export const ISTEK_ANAHTARI_BASLIGI = 'Idempotency-Key'

/**
 * Aynı eylemin yeniden denemesi aynı `Idempotency-Key`'i taşır (BY-014-R2):
 * yanıtı alınamayan (ağ hatası, 5xx) istek sunucuda kabul edilmiş olabilir;
 * aynı anahtarla yeniden deneme ikinci iş bırakmaz, mevcut kabulü alır.
 * Anahtar eylem (tür + parametreler) başına tutulur ve kabul (202) gelince
 * unutulur: aynı eylemin sonraki bilinçli tekrarı yeni bir iştir.
 *
 * Çift tıklama kilidinin (F5) yerine geçmez; onunla birlikte kullanılır.
 */
export function useIstekAnahtarlari() {
  const anahtarlar = useRef(new Map<string, string>())

  return {
    /** Eylemin bekleyen anahtarı; yoksa yenisi */
    anahtar(eylem: unknown): string {
      const imza = JSON.stringify(eylem)
      let anahtar = anahtarlar.current.get(imza)
      if (anahtar === undefined) {
        anahtar = crypto.randomUUID()
        anahtarlar.current.set(imza, anahtar)
      }

      return anahtar
    },
    /** Sunucu kabul etti: aynı eylemin bir sonraki gönderimi yeni anahtar alır */
    kabulEdildi(eylem: unknown): void {
      anahtarlar.current.delete(JSON.stringify(eylem))
    },
  }
}
