import { keepPreviousData, skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu, type ToplamSatiri } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import { tutarGoster } from '@/features/kesinti/bicim'
import { KesintiRaporuParametreleri } from '@/features/kesinti/KesintiRaporuParametreleri'
import { useKesintiRaporuParametreleri } from '@/features/kesinti/kesintiRaporu'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'

import { firmaOzetiApi, type EkstreParametreleri, type FirmaOzeti } from './firmaOzetiApi'

/**
 * Tablo satırı: kesinti türlerinin kalanı `tur_<id>` (sayı: sıralanabilir),
 * satır sayısı `adet_<id>` kolonlarında
 */
type TabloSatiri = Record<string, string | number> & {
  anahtar: string
  firma_id: number
  taseron: string
  taseron_ad: string
  proje_id: number
  proje: string
}

type KesintiTuru = FirmaOzeti['kesinti_turleri'][number]

const turKolonu = (id: number) => `tur_${id}`
const adetKolonu = (id: number) => `adet_${id}`

/** Ekstre hazırlanırken gösterilen bildirim (hata ya da bitişte aynı kimlikle değişir) */
const EKSTRE_BILDIRIMI = 'firma-ozeti-ekstre'

/** "-": o türde kesinti yok; kesinti varsa tutar (tamamı kesilmişse 0,00) */
function tutarHucresi(deger: string | number, kalin = false, kesintiVar = true) {
  return (
    <span className={`whitespace-nowrap tabular-nums ${kalin ? 'font-semibold' : ''}`}>
      {kesintiVar ? tutarGoster(deger) : '-'}
    </span>
  )
}

/**
 * Kesinti Yönetimi → Firma Özeti (kullanıcı isteği 2026-11-13): taşeron +
 * proje başına her kesinti türünden kalan tutar yan yana ve toplam kalan.
 * Yalnız taşerona yansıtılan kesintiler (Kesinti Özeti'nin "Kalan"ı ile
 * aynı); kesintisi olan her satır listelenir. Parametreler Kesinti Özeti'nin aynısı.
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

  const ekstre = useMutation({
    mutationFn: ({ hucre, dosyaAdi }: { hucre: EkstreParametreleri; dosyaAdi: string }) =>
      firmaOzetiApi.ekstre(hucre, dosyaAdi),
    onMutate: () => {
      toast.loading(t('kesinti.firmaOzeti.ekstreHazirlaniyor'), { id: EKSTRE_BILDIRIMI })
    },
    onSuccess: () => toast.dismiss(EKSTRE_BILDIRIMI),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error)), { id: EKSTRE_BILDIRIMI }),
  })

  /**
   * Kalan hücresi: o türde kesinti varsa (kalanı sıfır olsa da) tıklanınca
   * hücrenin ekstresi (PDF) iner; tür yoksa (Toplam Kalan) taşeron + projenin
   * bütün türleri — satır yalnız kesintisi olan taşeron + proje için gelir.
   */
  const kalanHucresi = (satir: TabloSatiri, tur: KesintiTuru | null, kalin = false) => {
    const deger = tur ? satir[turKolonu(tur.id)] : satir.toplam_kalan
    const kesintiVar = tur ? Number(satir[adetKolonu(tur.id)]) > 0 : true
    if (!kesintiVar || parametreler === null) {
      return tutarHucresi(deger, kalin, kesintiVar)
    }
    const hucre: EkstreParametreleri = {
      tarih: parametreler.tarih,
      para_birimi_id: parametreler.para_birimi_id,
      firma_id: satir.firma_id,
      proje_id: satir.proje_id,
      ...(tur ? { kesinti_turu_id: tur.id } : {}),
    }
    const dosyaAdi = `kesinti-ekstresi-${[satir.taseron, satir.proje, tur?.kod]
      .filter(Boolean)
      .join('-')
      .replace(/\s+/g, '-')}.pdf`

    return (
      <button
        type="button"
        disabled={ekstre.isPending}
        aria-label={t('kesinti.firmaOzeti.ekstreEtiketi', {
          taseron: satir.taseron,
          proje: satir.proje,
          tur: tur?.kod ?? t('kesinti.firmaOzeti.tumTurler'),
        })}
        title={t('kesinti.firmaOzeti.ekstreIpucu')}
        onClick={() => ekstre.mutate({ hucre, dosyaAdi })}
        className={`cursor-pointer whitespace-nowrap text-blue-700 tabular-nums underline decoration-dotted underline-offset-2 hover:decoration-solid disabled:cursor-wait dark:text-blue-300 ${kalin ? 'font-semibold' : ''}`}
      >
        {tutarGoster(deger)}
      </button>
    )
  }

  const veri = ozet.data
  const turler = veri?.kesinti_turleri ?? []
  const satirlar: TabloSatiri[] = (veri?.satirlar ?? []).map((satir) => ({
    anahtar: `${satir.firma_id}-${satir.proje_id}`,
    firma_id: satir.firma_id,
    taseron: satir.taseron,
    taseron_ad: satir.taseron_ad,
    proje_id: satir.proje_id,
    proje: satir.proje,
    ...Object.fromEntries(
      turler.flatMap((tur, i) => [
        [turKolonu(tur.id), Number(satir.kalanlar[i])],
        [adetKolonu(tur.id), satir.kayit_sayilari[i]],
      ]),
    ),
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
      render: (satir) => kalanHucresi(satir, tur),
    })),
    {
      anahtar: 'toplam_kalan',
      baslik: t('kesinti.firmaOzeti.kolon.toplamKalan'),
      hizala: 'sag',
      siralamaAnahtari: 'toplam_kalan',
      render: (satir) => kalanHucresi(satir, null, true),
    },
  ]

  const toplamSatirlari: ToplamSatiri[] = veri
    ? [
        {
          anahtar: 'toplam',
          etiket: t('kesinti.firmaOzeti.toplam'),
          hucreler: {
            ...Object.fromEntries(
              turler.map((tur, i) => [
                turKolonu(tur.id),
                tutarHucresi(veri.toplam.kalanlar[i], false, veri.toplam.kayit_sayilari[i] > 0),
              ]),
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
              // 14 kolon: bir boy küçük (kullanıcı isteği 2026-11-13)
              yaziBoyutu="kucuk"
            />
          </div>
        </div>
      )}
    </>
  )
}
