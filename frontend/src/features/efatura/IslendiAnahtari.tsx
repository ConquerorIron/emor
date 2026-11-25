import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { Switch } from '@/components/Switch'

import type { EFatura } from './efaturaApi'
import { faturaSatiriniGuncelle } from './faturaOnbellegi'
import { faturaIslendiDegistir, type IslenebilirEkran } from './faturaIslemApi'
import { RedRozeti } from './RedRozeti'

/**
 * Satırdaki "İşlendi" (SAP) anahtarı (Protel: kullanıcı isteği 2026-11-23;
 * statik kaynaklar: EK-07): tıklanınca hemen kaydedilir. İşaret yalnız eMOR'da
 * tutulur; kaynağın kendisinde hiçbir şey değişmez. Satır sunucunun döndürdüğü
 * faturayla yerinde güncellenir (liste yeniden çekilmez). RED faturada anahtar
 * yerine "Red" yazar.
 */
export function IslendiAnahtari({
  fatura,
  izinli,
  ekran,
}: {
  fatura: EFatura
  izinli: boolean
  ekran: IslenebilirEkran
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // Bekleme durumu bir sonraki çizimde gelir: art arda iki tıklama tek istek (F5)
  const suruyor = useRef(false)

  const degistir = useMutation({
    mutationFn: (islendi: boolean) => faturaIslendiDegistir(ekran, fatura.id, islendi),
    onSuccess: (guncel) => {
      faturaSatiriniGuncelle(queryClient, guncel)
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

  if (fatura.red.var) {
    return <RedRozeti red={fatura.red} />
  }

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
