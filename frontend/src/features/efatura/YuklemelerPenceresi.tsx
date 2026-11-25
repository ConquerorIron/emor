import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { zamanGoster } from '@/utils/tarih'

import { faturaListeleriniTazele } from './faturaOnbellegi'
import { type StatikKaynak, type Yukleme, yuklemeleriGetir, yuklemeyiGeriAl } from './kaynakApi'

function YuklemeListesi({ onEk, geriAlabilir }: { onEk: StatikKaynak; geriAlabilir: boolean }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [silinecek, setSilinecek] = useState<Yukleme | null>(null)

  const yuklemeler = useQuery({
    queryKey: queryKeys.kaynak.yuklemeler(onEk),
    queryFn: () => yuklemeleriGetir(onEk),
  })

  const geriAl = useMutation({
    mutationFn: (parti: Yukleme) => yuklemeyiGeriAl(onEk, parti.id),
    onSuccess: () => {
      faturaListeleriniTazele(queryClient)
      void queryClient.invalidateQueries({ queryKey: queryKeys.kaynak.yuklemeler(onEk) })
      toast.success(t('efatura.yuklemeler.geriAlindi'))
      setSilinecek(null)
    },
    onError: (error) => {
      toast.error(t(apiErrorKey(error)))
      setSilinecek(null)
    },
  })

  if (yuklemeler.isError) {
    return (
      <ErrorState
        mesaj={t(apiErrorKey(yuklemeler.error))}
        tekrarDene={() => void yuklemeler.refetch()}
      />
    )
  }

  return (
    <>
      <p className="mb-3 text-sm text-slate-600 dark:text-slate-400">
        {t('efatura.yuklemeler.aciklama')}
      </p>
      <DataTable
        kolonlar={[
          {
            anahtar: 'zaman',
            baslik: t('efatura.islem.zaman'),
            render: (y) => <span className="whitespace-nowrap">{zamanGoster(y.zaman)}</span>,
          },
          { anahtar: 'dosya', baslik: t('efatura.yuklemeler.dosya'), render: (y) => y.dosya_adi },
          {
            anahtar: 'tur',
            baslik: t('efatura.kolon.belgeTuru'),
            render: (y) => (y.belge_turu === null ? '—' : t(`efatura.belgeTuru.${y.belge_turu}`)),
          },
          {
            anahtar: 'kullanici',
            baslik: t('efatura.islem.kullanici'),
            render: (y) => y.kullanici_adi,
          },
          {
            anahtar: 'sayilar',
            baslik: t('efatura.yuklemeler.sayilar'),
            render: (y) =>
              t('efatura.yuklemeler.sayiMetni', {
                yeni: y.yeni,
                guncellenen: y.guncellenen,
                degismeyen: y.degismeyen,
              }),
          },
          ...(geriAlabilir
            ? [
                {
                  anahtar: 'islem',
                  baslik: '',
                  render: (y: Yukleme) => (
                    <Button
                      variant="danger"
                      onClick={() => setSilinecek(y)}
                      disabled={geriAl.isPending}
                    >
                      {t('efatura.yuklemeler.geriAl')}
                    </Button>
                  ),
                },
              ]
            : []),
        ]}
        satirlar={yuklemeler.data ?? []}
        satirAnahtari={(y) => y.id}
        yukleniyor={yuklemeler.isPending}
      />
      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('efatura.yuklemeler.geriAlBaslik')}
        mesaj={
          silinecek === null
            ? ''
            : t('efatura.yuklemeler.geriAlSorusu', {
                dosya: silinecek.dosya_adi,
                yeni: silinecek.yeni,
              })
        }
        onayEtiketi={t('efatura.yuklemeler.geriAl')}
        yukleniyor={geriAl.isPending}
        onayla={() => {
          if (silinecek !== null && !geriAl.isPending) {
            geriAl.mutate(silinecek)
          }
        }}
      />
    </>
  )
}

/** İçe aktarım partileri: kim, ne zaman, hangi dosya; partiyi geri alma (EK-06) */
export function YuklemelerDugmesi({
  onEk,
  geriAlabilir,
}: {
  onEk: StatikKaynak
  geriAlabilir: boolean
}) {
  const { t } = useTranslation()
  const [acik, setAcik] = useState(false)

  return (
    <>
      <Button variant="secondary" onClick={() => setAcik(true)}>
        {t('efatura.yuklemeler.dugme')}
      </Button>
      <Modal
        acik={acik}
        kapat={() => setAcik(false)}
        baslik={t('efatura.yuklemeler.baslik')}
        boyut="genis"
      >
        {acik ? <YuklemeListesi onEk={onEk} geriAlabilir={geriAlabilir} /> : null}
      </Modal>
    </>
  )
}
