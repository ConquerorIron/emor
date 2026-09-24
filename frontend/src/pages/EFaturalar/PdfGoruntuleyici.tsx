import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { ErrorState } from '@/components/ErrorState'
import { type EFatura, pdfGetir, xmlIndir } from '@/features/efatura/efaturaApi'

/**
 * Fatura aslı (PDF) — önce ERP havuzundan, yoksa entegratörden sunucu
 * üzerinden anlık okunur ve önbelleğe alınmaz. Blob adresi kapanınca
 * serbest bırakılır. UBL XML aynı kaynak sırasıyla indirilir.
 */
export function PdfGoruntuleyici({ fatura }: { fatura: EFatura }) {
  const { t } = useTranslation()

  const pdf = useQuery({
    queryKey: queryKeys.efatura.pdf(fatura.id),
    queryFn: () => pdfGetir(fatura.id),
    gcTime: 0,
    staleTime: 0,
    retry: false,
  })

  const xml = useMutation({
    mutationFn: () => xmlIndir(fatura),
    onError: (error) => toast.error(t(apiErrorKey(error))),
  })

  // Adres effect içinde üretilip AYNI effect'in temizliğinde bırakılır:
  // StrictMode'un kur-temizle-kur döngüsünde iframe bırakılmış adrese düşmez
  const [adres, setAdres] = useState<string | null>(null)
  const pdfBlob = pdf.data?.pdf
  useEffect(() => {
    if (!pdfBlob) {
      return
    }
    const yeni = URL.createObjectURL(pdfBlob)
    setAdres(yeni)

    return () => {
      URL.revokeObjectURL(yeni)
      setAdres(null)
    }
  }, [pdfBlob])

  if (pdf.isError) {
    return <ErrorState mesaj={t(apiErrorKey(pdf.error))} tekrarDene={() => void pdf.refetch()} />
  }

  if (adres === null) {
    return (
      <p className="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
        {t('efatura.pdfYukleniyor')}
      </p>
    )
  }

  const baglantiSinifi =
    'rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800'
  const kaynak = pdf.data?.kaynak

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {kaynak ? (
          <span className="mr-auto text-xs text-slate-500 dark:text-slate-400">
            {t(`efatura.belgeKaynagi.${kaynak}`)}
          </span>
        ) : null}
        <button
          type="button"
          className={baglantiSinifi}
          disabled={xml.isPending}
          onClick={() => xml.mutate()}
        >
          {t('efatura.xmlIndir')}
        </button>
        <a href={adres} download={`${fatura.belge_no}.pdf`} className={baglantiSinifi}>
          {t('efatura.pdfIndir')}
        </a>
        <a href={adres} target="_blank" rel="noopener noreferrer" className={baglantiSinifi}>
          {t('efatura.pdfYeniSekme')}
        </a>
      </div>
      <iframe
        src={adres}
        title={t('efatura.pdfBaslik', { no: fatura.belge_no })}
        className="h-[75vh] w-full rounded-lg border border-slate-200 dark:border-slate-700"
      />
    </div>
  )
}
