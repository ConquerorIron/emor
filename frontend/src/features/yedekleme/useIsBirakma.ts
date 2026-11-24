import { useMutation } from '@tanstack/react-query'
import { useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { useAuth } from '@/hooks/useAuth'

import { useIstekAnahtarlari } from './useIstekAnahtarlari'
import type { IstekKabulu } from './yedekApi'

/**
 * Çalıştırıcıya iş bırakan düğmelerin ortak gönderim akışı (Yedekler, Planlar,
 * Geri Yüklemeler; YF-11):
 *
 * - İstek gönderilirken (yanıt gelip yoklama başlamadan) yeni iş bırakılmaz:
 *   `isPending` düğmeyi bir sonraki çizimde kapatır, aradaki tıklamayı
 *   eşzamanlı bayrak yakalar (BY-015-R1, F5).
 * - Yanıtı alınamayan iş sunucuda kabul edilmiş olabilir: aynı eylemin yeniden
 *   denemesi — sayfa yenilendikten sonra da — aynı `Idempotency-Key`'i taşır,
 *   ikinci iş bırakılmaz (BY-014-R2, R3). Depoda eylemin kendisi değil özeti
 *   durur (BY-014-R4). Anahtarlar kullanıcı başınadır.
 *
 * `eylem` anahtarın kimliğidir: iki farklı iş aynı eylem nesnesini vermemeli
 * (tür + hedef kimliği gibi ayırt edici alanlar taşımalı).
 *
 * @param gonder İşi verilen istek anahtarıyla bırakır
 * @param kabulde Sunucu kabul etti (202): genellikle takibe al + pencereyi kapat
 */
export function useIsBirakma<E>(
  gonder: (eylem: E, istekAnahtari: string) => Promise<IstekKabulu>,
  kabulde: (kabul: IstekKabulu, eylem: E) => void,
) {
  const { t } = useTranslation()
  const kullanici = useAuth().user
  const gonderiliyor = useRef(false)
  const istekAnahtarlari = useIstekAnahtarlari(`kullanici.${kullanici?.id ?? 'yok'}`)
  const istek = useMutation({
    mutationFn: async (eylem: E) => {
      const bekleyen = await istekAnahtarlari.hazirla(eylem)
      const kabul = await gonder(eylem, bekleyen.anahtar)
      await bekleyen.kabulEdildi()

      return kabul
    },
    onSuccess: kabulde,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => {
      gonderiliyor.current = false
    },
  })

  return {
    birak(eylem: E): void {
      if (gonderiliyor.current) {
        return
      }
      gonderiliyor.current = true
      istek.mutate(eylem)
    },
    /** İstek yanıtı bekleniyor */
    gonderiliyor: istek.isPending,
    /** Yanıtı beklenen eylem (düğmede yükleniyor göstermek için) */
    gonderilen: istek.isPending ? istek.variables : undefined,
  }
}
