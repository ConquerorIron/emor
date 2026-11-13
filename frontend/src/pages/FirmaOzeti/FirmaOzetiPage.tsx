import { keepPreviousData, skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu, type ToplamSatiri } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import { tutarVeyaTire } from '@/features/kesinti/bicim'
import { KesintiRaporuParametreleri } from '@/features/kesinti/KesintiRaporuParametreleri'
import { useKesintiRaporuParametreleri } from '@/features/kesinti/kesintiRaporu'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'

import { firmaOzetiApi } from './firmaOzetiApi'

/** Tablo satırı: kesinti türlerinin kalanı `tur_<id>` kolonlarında (sayı: sıralanabilir) */
type TabloSatiri = Record<string, string | number> & {
  anahtar: string
  taseron: string
  taseron_ad: string
  proje: string
}

const turKolonu = (id: number) => `tur_${id}`

function tutarHucresi(deger: string | number, kalin = false) {
  return (
    <span className={`whitespace-nowrap tabular-nums ${kalin ? 'font-semibold' : ''}`}>
      {tutarVeyaTire(deger)}
    </span>
  )
}

/**
 * Kesinti Yönetimi → Firma Özeti (kullanıcı isteği 2026-11-13): taşeron +
 * proje başına her kesinti türünden kalan tutar yan yana ve toplam kalan.
 * Yalnız taşerona yansıtılan kesintiler (Kesinti Özeti'nin "Kalan"ı ile
 * aynı); kalanı olmayan satırlar gelmez. Parametreler Kesinti Özeti'nin aynısı.
 */
export function FirmaOzetiPage() {
  const { t } = useTranslation()
  const parametreDurumu = useKesintiRaporuParametreleri('firmaOzeti', firmaOzetiApi.secenekler)
  const { parametreler, secenekler } = parametreDurumu
  const { siralama, siralamaDegistir } = useKaliciSiralama('firmaOzeti')

  const ozet = useQuery({
    queryKey: queryKeys.kesinti.firmaOzeti(parametreler),
    queryFn: parametreler ? () => firmaOzetiApi.getir(parametreler) : skipToken,
    placeholderData: keepPreviousData,
  })

  const excel = useMutation({
    mutationFn: firmaOzetiApi.excel,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const veri = ozet.data
  const turler = veri?.kesinti_turleri ?? []
  const satirlar: TabloSatiri[] = (veri?.satirlar ?? []).map((satir) => ({
    anahtar: `${satir.firma_id}-${satir.proje_id}`,
    taseron: satir.taseron,
    taseron_ad: satir.taseron_ad,
    proje: satir.proje,
    ...Object.fromEntries(turler.map((tur, i) => [turKolonu(tur.id), Number(satir.kalanlar[i])])),
    toplam_kalan: Number(satir.toplam_kalan),
  }))

  const kolonlar: DataTableKolonu<TabloSatiri>[] = [
    {
      anahtar: 'taseron',
      baslik: t('kesinti.firmaOzeti.kolon.taseron'),
      siralamaAnahtari: 'taseron',
      render: (satir) => (
        <span
          className="font-semibold whitespace-nowrap"
          title={satir.taseron_ad !== satir.taseron ? satir.taseron_ad : undefined}
        >
          {satir.taseron}
        </span>
      ),
    },
    {
      anahtar: 'proje',
      baslik: t('kesinti.firmaOzeti.kolon.proje'),
      siralamaAnahtari: 'proje',
      render: (satir) => <span className="whitespace-nowrap">{satir.proje}</span>,
    },
    ...turler.map((tur): DataTableKolonu<TabloSatiri> => ({
      anahtar: turKolonu(tur.id),
      baslik: tur.kod,
      hizala: 'sag',
      siralamaAnahtari: turKolonu(tur.id),
      render: (satir) => tutarHucresi(satir[turKolonu(tur.id)]),
    })),
    {
      anahtar: 'toplam_kalan',
      baslik: t('kesinti.firmaOzeti.kolon.toplamKalan'),
      hizala: 'sag',
      siralamaAnahtari: 'toplam_kalan',
      render: (satir) => tutarHucresi(satir.toplam_kalan, true),
    },
  ]

  const toplamSatirlari: ToplamSatiri[] = veri
    ? [
        {
          anahtar: 'toplam',
          etiket: t('kesinti.firmaOzeti.toplam'),
          hucreler: {
            ...Object.fromEntries(
              turler.map((tur, i) => [turKolonu(tur.id), tutarHucresi(veri.toplam.kalanlar[i])]),
            ),
            toplam_kalan: tutarHucresi(veri.toplam.toplam_kalan, true),
          },
        },
      ]
    : []

  const bos = veri !== undefined && satirlar.length === 0

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.firmaOzeti.baslik')}</h2>
        <Button
          variant="secondary"
          yukleniyor={excel.isPending}
          disabled={parametreler === null || veri === undefined || bos}
          onClick={() => {
            if (parametreler) {
              excel.mutate(parametreler)
            }
          }}
        >
          {t('kesinti.icmal.excelAktar')}
        </Button>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.firmaOzeti.aciklamaMetni')}
      </p>

      <KesintiRaporuParametreleri durum={parametreDurumu} idOneki="firma-ozeti" />

      {secenekler.error || ozet.error ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(secenekler.error ?? ozet.error))}
            tekrarDene={() => {
              void secenekler.refetch()
              void ozet.refetch()
            }}
          />
        </div>
      ) : veri === undefined || bos ? (
        <div
          role="status"
          className="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
        >
          {bos
            ? t('kesinti.firmaOzeti.kalanYok')
            : secenekler.data?.para_birimleri.length === 0
              ? t('kesinti.ozet.kesintiYok')
              : t('ortak.yukleniyor')}
        </div>
      ) : (
        <div className={ozet.isPlaceholderData ? 'opacity-50' : ''}>
          <p className="mt-4 text-sm text-slate-600 dark:text-slate-400">
            {t('kesinti.firmaOzeti.satirSayisi', { sayi: satirlar.length, para: veri.para_birimi })}
          </p>
          <div className="mt-2">
            <DataTable
              kolonlar={kolonlar}
              satirlar={siralaUygula(satirlar, siralama)}
              satirAnahtari={(satir) => satir.anahtar}
              siralama={siralama}
              siralamaDegistir={siralamaDegistir}
              ustKaydirma
              toplamSatirlari={toplamSatirlari}
            />
          </div>
        </div>
      )}
    </>
  )
}
