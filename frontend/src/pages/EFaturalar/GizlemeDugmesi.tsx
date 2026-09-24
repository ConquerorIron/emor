import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { type EFatura, gizliligiDegistir } from '@/features/efatura/efaturaApi'

/**
 * Detay penceresinde faturayı gizler ya da listeye geri alır (kullanıcı isteği
 * 2026-09-24). Fatura silinmez; yalnız bu uygulamanın listesinde gizlenir.
 * Sunucunun döndürdüğü güncel fatura pencereye geri verilir.
 */
export function GizlemeDugmesi({
  fatura,
  degisti,
}: {
  fatura: EFatura
  degisti: (fatura: EFatura) => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const gizlilik = useMutation({
    mutationFn: () => gizliligiDegistir(fatura.id, !fatura.gizli),
    onSuccess: (guncel) => {
      toast.success(t(guncel.gizli ? 'efatura.gizleme.gizlendi' : 'efatura.gizleme.geriAlindi'))
      void queryClient.invalidateQueries({ queryKey: queryKeys.efatura.yonListeleri(fatura.yon) })
      degisti(guncel)
    },
    onError: (error) => toast.error(t(apiErrorKey(error))),
  })

  return (
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
      <p className="text-xs text-slate-500 dark:text-slate-400">
        {t(fatura.gizli ? 'efatura.gizleme.gizliAciklama' : 'efatura.gizleme.aciklama')}
      </p>
      <Button
        variant={fatura.gizli ? 'secondary' : 'turuncu'}
        onClick={() => gizlilik.mutate()}
        yukleniyor={gizlilik.isPending}
      >
        {t(fatura.gizli ? 'efatura.gizleme.geriAl' : 'efatura.gizleme.gizle')}
      </Button>
    </div>
  )
}
