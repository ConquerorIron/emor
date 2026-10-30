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
import {
  isgucuYuklemeleriniGetir,
  isgucuYuklemesiniGeriAl,
  type KesintiYuklemesi,
} from '@/features/kesinti/isgucuApi'
import { zamanGoster } from '@/utils/tarih'

function YuklemeListesi({ geriAlabilir }: { geriAlabilir: boolean }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [geriAlinacak, setGeriAlinacak] = useState<KesintiYuklemesi | null>(null)

  const yuklemeler = useQuery({
    queryKey: queryKeys.kesinti.isgucuYuklemeleri,
    queryFn: isgucuYuklemeleriniGetir,
  })

  const geriAl = useMutation({
    mutationFn: (yukleme: KesintiYuklemesi) => isgucuYuklemesiniGeriAl(yukleme.id),
    onSuccess: async (_, yukleme) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuYuklemeleri }),
        queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuListeleri }),
      ])
      toast.success(t('kesinti.isgucu.yuklemeler.geriAlindi', { sayi: yukleme.satir_sayisi }))
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
      baslik: t('kesinti.isgucu.yuklemeler.zaman'),
      render: (yukleme) => zamanGoster(yukleme.olusturma_zamani),
    },
    {
      anahtar: 'yukleyen',
      baslik: t('kesinti.isgucu.yuklemeler.yukleyen'),
      render: (yukleme) => yukleme.yukleyen ?? '—',
    },
    {
      anahtar: 'kaynak',
      baslik: t('kesinti.isgucu.yuklemeler.kaynak'),
      render: (yukleme) =>
        yukleme.dosya_adi ??
        t(`kesinti.isgucu.iceAktarim.kaynak.${yukleme.kaynak === 'excel' ? 'dosya' : 'metin'}`),
    },
    {
      anahtar: 'satir_sayisi',
      baslik: t('kesinti.isgucu.yuklemeler.satirSayisi'),
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
                {t('kesinti.isgucu.yuklemeler.geriAl')}
              </Button>
            ),
          } satisfies DataTableKolonu<KesintiYuklemesi>,
        ]
      : []),
  ]

  return (
    <>
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.isgucu.yuklemeler.aciklama')}
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
        baslik={t('kesinti.isgucu.yuklemeler.geriAlOnayBaslik')}
        mesaj={t('kesinti.isgucu.yuklemeler.geriAlOnayMesaj', {
          zaman: geriAlinacak ? zamanGoster(geriAlinacak.olusturma_zamani) : '',
          sayi: geriAlinacak?.satir_sayisi ?? 0,
        })}
        onayEtiketi={t('kesinti.isgucu.yuklemeler.geriAl')}
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
export function YuklemelerPenceresi({
  acik,
  kapat,
  geriAlabilir,
}: {
  acik: boolean
  kapat: () => void
  geriAlabilir: boolean
}) {
  const { t } = useTranslation()

  return (
    <Modal acik={acik} kapat={kapat} baslik={t('kesinti.isgucu.yuklemeler.baslik')} boyut="genis">
      {acik ? <YuklemeListesi geriAlabilir={geriAlabilir} /> : null}
    </Modal>
  )
}
