import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { Button } from '@/components/Button'
import { Modal } from '@/components/Modal'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { zamanGoster } from '@/utils/tarih'

import type { EFatura } from './efaturaApi'
import { faturaListeleriniTazele } from './faturaOnbellegi'
import { faturaRedDegistir, type FaturaEkrani } from './faturaIslemApi'
import { IkonDugmesi } from './ListeParcalari'

/** Sunucudaki sınırla aynı (EFaturaRedRequest::NEDEN_AZAMI) */
const NEDEN_AZAMI = 1000

/**
 * RED'e çekme formu ya da RED'i geri alma onayı. Neden zorunlu; eMOR'da
 * işlenmiş faturada uyarı gösterilir (eMOR bilgisi ERP'nindir, değişmez).
 */
function RedFormu({
  fatura,
  ekran,
  kapat,
}: {
  fatura: EFatura
  ekran: FaturaEkrani
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [neden, setNeden] = useState('')
  const [hata, setHata] = useState<string | undefined>()
  const geriAl = fatura.red.var
  // Bekleme durumu bir sonraki çizimde gelir: art arda iki tıklama tek istek (F5)
  const suruyor = useRef(false)

  const kaydet = useMutation({
    mutationFn: () =>
      faturaRedDegistir(ekran, fatura.id, !geriAl, neden.trim() === '' ? null : neden.trim()),
    onSuccess: (guncel) => {
      faturaListeleriniTazele(queryClient)
      toast.success(
        t(guncel.red.var ? 'efatura.red.cekildi' : 'efatura.red.geriAlindi', {
          no: guncel.belge_no,
        }),
      )
      kapat()
    },
    onError: (error) => {
      const alanHatasi = dogrulamaMesaji(error)
      if (alanHatasi !== null) {
        setHata(alanHatasi)
      } else {
        toast.error(t(apiErrorKey(error)))
      }
    },
    onSettled: () => {
      suruyor.current = false
    },
  })

  const gonder = () => {
    if (!geriAl && neden.trim() === '') {
      setHata(t('efatura.red.nedenZorunlu'))

      return
    }
    if (suruyor.current) {
      return
    }
    suruyor.current = true
    setHata(undefined)
    kaydet.mutate()
  }

  const islenmis = fatura.emor_durumu === 'islendi' || fatura.emor_durumu === 'elle_islendi'

  return (
    <div className="space-y-4">
      {geriAl ? (
        <p className="text-sm">
          {t('efatura.red.geriAlSorusu', {
            eden: fatura.red.eden ?? '—',
            zaman: zamanGoster(fatura.red.zaman),
            neden: fatura.red.neden ?? '—',
          })}
        </p>
      ) : (
        <>
          <p className="text-sm text-slate-600 dark:text-slate-400">{t('efatura.red.aciklama')}</p>
          {islenmis ? (
            <p
              role="alert"
              className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
              {t('efatura.red.islenmisUyari')}
            </p>
          ) : null}
          <SinirliMetinInput
            id={`red-nedeni-${fatura.id}`}
            label={t('efatura.red.neden')}
            value={neden}
            onChange={(deger) => {
              setNeden(deger)
              setHata(undefined)
            }}
            limit={NEDEN_AZAMI}
            rows={3}
            hata={hata}
            disabled={kaydet.isPending}
          />
        </>
      )}
      {geriAl && hata !== undefined ? (
        <p role="alert" className="text-sm text-red-700 dark:text-red-300">
          {hata}
        </p>
      ) : null}
      <div className="flex justify-end gap-2">
        <Button variant="secondary" onClick={kapat} disabled={kaydet.isPending}>
          {t('ortak.iptal')}
        </Button>
        <Button
          variant={geriAl ? 'primary' : 'danger'}
          onClick={gonder}
          yukleniyor={kaydet.isPending}
        >
          {t(geriAl ? 'efatura.red.geriAl' : 'efatura.red.cek')}
        </Button>
      </div>
    </div>
  )
}

/**
 * Satırdaki RED düğmesi (EK-04): RED değilse "RED'e Çek", yetkili
 * kullanıcının çektiği RED'de "RED'i Geri Al". Kaynakta RED olmuş faturada
 * düğme yoktur (kaynağın kararı değiştirilmez).
 */
export function RedDugmesi({ fatura, ekran }: { fatura: EFatura; ekran: FaturaEkrani }) {
  const { t } = useTranslation()
  const [acik, setAcik] = useState(false)

  if (fatura.red.kaynak === 'kaynak' || fatura.yon !== 'gelen') {
    return null
  }

  const geriAl = fatura.red.var
  const etiket = t(geriAl ? 'efatura.red.geriAl' : 'efatura.red.cek')

  return (
    <>
      <IkonDugmesi etiket={etiket} onClick={() => setAcik(true)}>
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          className={`size-5 ${geriAl ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}
          fill="none"
          stroke="currentColor"
          strokeWidth={1.6}
        >
          {geriAl ? (
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"
            />
          ) : (
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"
            />
          )}
        </svg>
      </IkonDugmesi>
      <Modal
        acik={acik}
        kapat={() => setAcik(false)}
        baslik={t(geriAl ? 'efatura.red.geriAlBaslik' : 'efatura.red.cekBaslik', {
          no: fatura.belge_no,
        })}
      >
        {acik ? <RedFormu fatura={fatura} ekran={ekran} kapat={() => setAcik(false)} /> : null}
      </Modal>
    </>
  )
}
