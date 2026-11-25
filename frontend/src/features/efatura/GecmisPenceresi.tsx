import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { DataTable } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { zamanGoster } from '@/utils/tarih'

import type { EFatura } from './efaturaApi'
import { faturaGecmisiGetir, type FaturaEkrani } from './faturaIslemApi'
import { islemAyrintisi } from './islemMetni'
import { IkonDugmesi } from './ListeParcalari'

/** Faturanın elle işlemleri (RED, İşlendi, gizleme): kim, ne zaman, ne yaptı (EK-03). */
function GecmisListesi({ fatura, ekran }: { fatura: EFatura; ekran: FaturaEkrani }) {
  const { t } = useTranslation()
  const gecmis = useQuery({
    queryKey: queryKeys.efaturaGecmis.fatura(ekran, fatura.id),
    queryFn: () => faturaGecmisiGetir(ekran, fatura.id),
  })

  if (gecmis.isError) {
    return (
      <ErrorState mesaj={t(apiErrorKey(gecmis.error))} tekrarDene={() => void gecmis.refetch()} />
    )
  }

  return (
    <DataTable
      kolonlar={[
        {
          anahtar: 'zaman',
          baslik: t('efatura.islem.zaman'),
          render: (k) => <span className="whitespace-nowrap">{zamanGoster(k.zaman)}</span>,
        },
        {
          anahtar: 'kullanici',
          baslik: t('efatura.islem.kullanici'),
          render: (k) => k.kullanici_adi,
        },
        {
          anahtar: 'islem',
          baslik: t('efatura.islem.islem'),
          render: (k) => (
            <span className="font-semibold whitespace-nowrap">
              {t(`efatura.islem.tur.${k.islem}`)}
            </span>
          ),
        },
        {
          anahtar: 'ayrinti',
          baslik: t('efatura.islem.ayrinti'),
          render: (k) => islemAyrintisi(t, k) || '—',
        },
      ]}
      satirlar={gecmis.data ?? []}
      satirAnahtari={(k) => k.id}
      yukleniyor={gecmis.isPending}
    />
  )
}

/** Satırdaki "Geçmiş" düğmesi: saat ikonu, faturanın işlem geçmişini pencerede açar. */
export function GecmisDugmesi({ fatura, ekran }: { fatura: EFatura; ekran: FaturaEkrani }) {
  const { t } = useTranslation()
  const [acik, setAcik] = useState(false)

  return (
    <>
      <IkonDugmesi etiket={t('efatura.islem.gecmis')} onClick={() => setAcik(true)}>
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          className="size-5 text-slate-600 dark:text-slate-300"
          fill="none"
          stroke="currentColor"
          strokeWidth={1.6}
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
          />
        </svg>
      </IkonDugmesi>
      <Modal
        acik={acik}
        kapat={() => setAcik(false)}
        baslik={t('efatura.islem.gecmisBaslik', { no: fatura.belge_no })}
        boyut="genis"
      >
        {acik ? <GecmisListesi fatura={fatura} ekran={ekran} /> : null}
      </Modal>
    </>
  )
}
