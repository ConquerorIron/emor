import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { zamanGoster } from '@/utils/tarih'

import type { IcmalSatiri, KesintiYuklemesi } from './icmalApi'
import type { IcmalTanimi } from './icmalTanimi'

interface YuklemelerProps<T extends IcmalSatiri> {
  tanim: IcmalTanimi<T>
  geriAlabilir: boolean
}

function YuklemeListesi<T extends IcmalSatiri>({ tanim, geriAlabilir }: YuklemelerProps<T>) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [geriAlinacak, setGeriAlinacak] = useState<KesintiYuklemesi | null>(null)

  const yuklemeler = useQuery({
    queryKey: queryKeys.kesinti.icmalYuklemeleri(tanim.anahtar),
    queryFn: () => tanim.api.yuklemeler(),
  })

  const geriAl = useMutation({
    mutationFn: (yukleme: KesintiYuklemesi) => tanim.api.geriAl(yukleme.id),
    onSuccess: async (_, yukleme) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: queryKeys.kesinti.icmalYuklemeleri(tanim.anahtar),
        }),
        queryClient.invalidateQueries({
          queryKey: queryKeys.kesinti.icmalListeleri(tanim.anahtar),
        }),
      ])
      toast.success(t('kesinti.icmal.yuklemeler.geriAlindi', { sayi: yukleme.satir_sayisi }))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setGeriAlinacak(null),
  })

  if (yuklemeler.error) {
    return (
      <ErrorState
        mesaj={t(apiErrorKey(yuklemeler.error))}
        tekrarDene={() => void yuklemeler.refetch()}
      />
    )
  }

  const kolonlar: DataTableKolonu<KesintiYuklemesi>[] = [
    {
      anahtar: 'zaman',
      baslik: t('kesinti.icmal.yuklemeler.zaman'),
      render: (yukleme) => zamanGoster(yukleme.olusturma_zamani),
    },
    {
      anahtar: 'yukleyen',
      baslik: t('kesinti.icmal.yuklemeler.yukleyen'),
      render: (yukleme) => yukleme.yukleyen ?? '—',
    },
    {
      anahtar: 'kaynak',
      baslik: t('kesinti.icmal.yuklemeler.kaynak'),
      render: (yukleme) =>
        yukleme.dosya_adi ??
        t(`kesinti.icmal.iceAktarim.kaynak.${yukleme.kaynak === 'excel' ? 'dosya' : 'metin'}`),
    },
    {
      anahtar: 'satir_sayisi',
      baslik: t('kesinti.icmal.yuklemeler.satirSayisi'),
      hizala: 'sag',
      render: (yukleme) => yukleme.satir_sayisi.toLocaleString('tr-TR'),
    },
    ...(geriAlabilir
      ? [
          {
            anahtar: 'islemler',
            baslik: '',
            hizala: 'sag',
            render: (yukleme) => (
              <Button variant="danger" onClick={() => setGeriAlinacak(yukleme)}>
                {t('kesinti.icmal.yuklemeler.geriAl')}
              </Button>
            ),
          } satisfies DataTableKolonu<KesintiYuklemesi>,
        ]
      : []),
  ]

  return (
    <>
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.icmal.yuklemeler.aciklama')}
      </p>
      <DataTable
        kolonlar={kolonlar}
        satirlar={yuklemeler.data ?? []}
        satirAnahtari={(yukleme) => yukleme.id}
        yukleniyor={yuklemeler.isPending}
      />
      <ConfirmDialog
        acik={geriAlinacak !== null}
        kapat={() => setGeriAlinacak(null)}
        baslik={t('kesinti.icmal.yuklemeler.geriAlOnayBaslik')}
        mesaj={t('kesinti.icmal.yuklemeler.geriAlOnayMesaj', {
          zaman: geriAlinacak ? zamanGoster(geriAlinacak.olusturma_zamani) : '',
          sayi: geriAlinacak?.satir_sayisi ?? 0,
        })}
        onayEtiketi={t('kesinti.icmal.yuklemeler.geriAl')}
        yukleniyor={geriAl.isPending}
        onayla={() => {
          if (geriAlinacak !== null) {
            geriAl.mutate(geriAlinacak)
          }
        }}
      />
    </>
  )
}

/** Yapılan içe aktarımlar (partiler); yanlış yükleme satırlarıyla birlikte geri alınır */
export function IcmalYuklemelerPenceresi<T extends IcmalSatiri>({
  acik,
  kapat,
  ...props
}: YuklemelerProps<T> & { acik: boolean; kapat: () => void }) {
  const { t } = useTranslation()

  return (
    <Modal acik={acik} kapat={kapat} baslik={t('kesinti.icmal.yuklemeler.baslik')} boyut="genis">
      {acik ? <YuklemeListesi {...props} /> : null}
    </Modal>
  )
}
