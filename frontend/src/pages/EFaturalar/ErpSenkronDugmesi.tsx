import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { Button } from '@/components/Button'
import { type FaturaYonu, erpSenkronla } from '@/features/efatura/efaturaApi'
import { faturaListeleriniTazele } from '@/features/efatura/faturaOnbellegi'

/**
 * "ERP Senkronla": bu yönün eMOR kolonunu hemen tazeler — ERP'deki faturalar
 * (gelen: TOHOM_FATURA, giden: gönderilen e-fatura listesi) ile entegratör
 * ETTN'leri eşleştirilir. Aynı iş 5 dakikada bir kendiliğinden de çalışır.
 * eMOR durumu faturanın sınıfını değiştirir: listeler, pano ve geçmiş tazelenir (F-9).
 */
export function ErpSenkronDugmesi({ yon }: { yon: FaturaYonu }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const eslestir = useMutation({
    mutationFn: () => erpSenkronla(yon),
    onSuccess: async (sonuc) => {
      toast.success(t('efatura.erpSenkron.tamam', { ...sonuc }))
      await faturaListeleriniTazele(queryClient)
    },
    onError: (error: unknown) => {
      toast.error(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
  })

  return (
    <Button
      variant="secondary"
      onClick={() => eslestir.mutate()}
      yukleniyor={eslestir.isPending}
      title={t('efatura.erpSenkron.aciklama')}
    >
      {t('efatura.erpSenkron.ac')}
    </Button>
  )
}
