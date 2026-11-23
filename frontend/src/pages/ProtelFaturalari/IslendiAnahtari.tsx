import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Switch } from '@/components/Switch'
import type { EFatura, EFaturaListesi } from '@/features/efatura/efaturaApi'
import { protelIslendiDegistir } from '@/features/efatura/protelApi'

/**
 * Satırdaki "İşlendi" anahtarı (kullanıcı isteği 2026-11-23): tıklanınca hemen
 * kaydedilir. İşaret yalnız eMOR'da tutulur ve Tüm Faturalar'ın SAP kolonunda
 * "İşlendi" görünür; Protel'de hiçbir şey değişmez. Satır sunucunun döndürdüğü
 * faturayla yerinde güncellenir (liste yeniden çekilmez).
 */
export function IslendiAnahtari({ fatura, izinli }: { fatura: EFatura; izinli: boolean }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // Bekleme durumu bir sonraki çizimde gelir: art arda iki tıklama tek istek (F5)
  const suruyor = useRef(false)

  const degistir = useMutation({
    mutationFn: (islendi: boolean) => protelIslendiDegistir(fatura.id, islendi),
    onSuccess: (guncel) => {
      queryClient.setQueriesData<EFaturaListesi>({ queryKey: queryKeys.protel.listeler }, (eski) =>
        eski === undefined
          ? eski
          : { ...eski, data: eski.data.map((f) => (f.id === guncel.id ? guncel : f)) },
      )
      void queryClient.invalidateQueries({ queryKey: queryKeys.tumFaturalar.hepsi })
      toast.success(
        t(
          guncel.sap_islendi === true ? 'protel.islendi.isaretlendi' : 'protel.islendi.kaldirildi',
          {
            no: guncel.belge_no,
          },
        ),
      )
    },
    onError: (error) => toast.error(t(apiErrorKey(error))),
    onSettled: () => {
      suruyor.current = false
    },
  })

  return (
    <span className="inline-flex" title={izinli ? undefined : t('protel.islendi.izinYok')}>
      <Switch
        label={t('protel.islendi.etiket', { no: fatura.belge_no })}
        etiketGizli
        checked={fatura.sap_islendi === true}
        disabled={!izinli || degistir.isPending}
        onChange={(islendi) => {
          if (suruyor.current) {
            return
          }
          suruyor.current = true
          degistir.mutate(islendi)
        }}
      />
    </span>
  )
}
