import { keepPreviousData, skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
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

import {
  pivotApi,
  type EkstreParametreleri,
  type PivotVerisi,
  type FirmaSatiri,
  type KalanSatiri,
  type ProjeSatiri,
} from './pivotApi'

/** Özet: taşeron + proje; Firma Özeti: taşeron (projeler toplanır); Proje Özeti: proje (taşeronlar toplanır) */
type Sekme = 'ozet' | 'firma' | 'proje'

const SEKMELER: readonly Sekme[] = ['ozet', 'firma', 'proje']

/** Sekmenin kimlik kolonları, soldan */
const KIMLIKLER: Record<Sekme, readonly ('taseron' | 'proje')[]> = {
  ozet: ['taseron', 'proje'],
  firma: ['taseron'],
  proje: ['proje'],
}

/**
 * Tablo satırı: kesinti türlerinin kalanı `tur_<id>` (sayı: sıralanabilir),
 * satır sayısı `adet_<id>` kolonlarında. Sekmede olmayan kimlik boş
 * (firma_id / proje_id 0, metinler '').
 */
type TabloSatiri = Record<string, string | number> & {
  anahtar: string
  firma_id: number
  taseron: string
  taseron_ad: string
  proje_id: number
  proje: string
  proje_ad: string
}

type KesintiTuru = PivotVerisi['kesinti_turleri'][number]

type KaynakSatiri = KalanSatiri & Partial<FirmaSatiri> & Partial<ProjeSatiri>

const turKolonu = (id: number) => `tur_${id}`
const adetKolonu = (id: number) => `adet_${id}`

/** Ekstre hazırlanırken gösterilen bildirim (hata ya da bitişte aynı kimlikle değişir) */
const EKSTRE_BILDIRIMI = 'pivot-ekstre'

/** "-": o türde kesinti yok; kesinti varsa tutar (tamamı kesilmişse 0,00) */
function tutarHucresi(deger: string | number, kalin = false, kesintiVar = true) {
  return (
    <span className={`whitespace-nowrap tabular-nums ${kalin ? 'font-semibold' : ''}`}>
      {kesintiVar ? tutarGoster(deger) : '-'}
    </span>
  )
}

/** Sekmenin satırları, tablo biçiminde */
function tabloSatirlari(veri: PivotVerisi, sekme: Sekme): TabloSatiri[] {
  const kaynak: KaynakSatiri[] =
    sekme === 'ozet'
      ? veri.satirlar
      : sekme === 'firma'
        ? veri.firma_satirlari
        : veri.proje_satirlari

  return kaynak.map((satir) => ({
    anahtar: `${satir.firma_id ?? 0}-${satir.proje_id ?? 0}`,
    firma_id: satir.firma_id ?? 0,
    taseron: satir.taseron ?? '',
    taseron_ad: satir.taseron_ad ?? '',
    proje_id: satir.proje_id ?? 0,
    proje: satir.proje ?? '',
    proje_ad: satir.proje_ad ?? '',
    ...Object.fromEntries(
      veri.kesinti_turleri.flatMap((tur, i) => [
        [turKolonu(tur.id), Number(satir.kalanlar[i])],
        [adetKolonu(tur.id), satir.kayit_sayilari[i]],
      ]),
    ),
    toplam_kalan: Number(satir.toplam_kalan),
  }))
}

/**
 * Kesinti Yönetimi → Pivot (eski adı Firma Özeti; kullanıcı isteği 2026-11-13):
 * her kesinti türünden kalan tutar yan yana ve toplam kalan. Yalnız taşerona
 * yansıtılan kesintiler (Kesinti Özeti'nin "Kalan"ı ile aynı); kesintisi olan
 * her satır listelenir. Parametreler Kesinti Özeti'nin aynısı.
 *
 * 2026-11-16 (kullanıcı isteği): menüde "Pivot", üç sekme — Özet (taşeron +
 * proje), Firma Özeti (taşeron), Proje Özeti (proje). Hücreden ekstre yalnız
 * Özet'te (ekstre bir taşeron + projenin dökümüdür); Excel üç sekmeyi ayrı
 * sayfalarda indirir.
 */
export function PivotPage() {
  const { t } = useTranslation()
  const parametreDurumu = useKesintiRaporuParametreleri('pivot', pivotApi.secenekler)
  const { parametreler, secenekler } = parametreDurumu
  const [sekme, setSekme] = useState<Sekme>('ozet')
  // Sekme başına ayrı sıralama tercihi (Özet'in anahtarı eskisi: kayıtlı tercih korunur)
  const siralamalar = {
    ozet: useKaliciSiralama('firmaOzeti'),
    firma: useKaliciSiralama('pivotFirma'),
    proje: useKaliciSiralama('pivotProje'),
  }
  const { siralama, siralamaDegistir } = siralamalar[sekme]

  const ozet = useQuery({
    queryKey: queryKeys.kesinti.pivot(parametreler),
    queryFn: parametreler ? () => pivotApi.getir(parametreler) : skipToken,
    placeholderData: keepPreviousData,
  })

  const excel = useMutation({
    mutationFn: pivotApi.excel,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const ekstre = useMutation({
    mutationFn: ({ hucre, dosyaAdi }: { hucre: EkstreParametreleri; dosyaAdi: string }) =>
      pivotApi.ekstre(hucre, dosyaAdi),
    onMutate: () => {
      toast.loading(t('kesinti.pivot.ekstreHazirlaniyor'), { id: EKSTRE_BILDIRIMI })
    },
    onSuccess: () => toast.dismiss(EKSTRE_BILDIRIMI),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error)), { id: EKSTRE_BILDIRIMI }),
  })

  /**
   * Parametre değişince yeni yanıt gelene dek ÖNCEKİ tablo (soluk) görünür;
   * indirmeler yeni parametrelerle gideceğinden o arada kapalıdır — görülen
   * tutar ile inen ekstre/Excel'in kapsamı ayrışmasın.
   */
  const eskiVeri = ozet.isPlaceholderData

  /**
   * Kalan hücresi: Özet'te o türde kesinti varsa (kalanı sıfır olsa da)
   * tıklanınca hücrenin ekstresi (PDF) iner; tür yoksa (Toplam Kalan) taşeron +
   * projenin bütün türleri. Firma / Proje Özeti'nde yalnız tutar.
   */
  const kalanHucresi = (satir: TabloSatiri, tur: KesintiTuru | null, kalin = false) => {
    const deger = tur ? satir[turKolonu(tur.id)] : satir.toplam_kalan
    const kesintiVar = tur ? Number(satir[adetKolonu(tur.id)]) > 0 : true
    if (sekme !== 'ozet' || !kesintiVar || parametreler === null) {
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
        disabled={ekstre.isPending || eskiVeri}
        aria-label={t('kesinti.pivot.ekstreEtiketi', {
          taseron: satir.taseron,
          proje: satir.proje,
          tur: tur?.kod ?? t('kesinti.pivot.tumTurler'),
        })}
        title={t('kesinti.pivot.ekstreIpucu')}
        onClick={() => ekstre.mutate({ hucre, dosyaAdi })}
        className={`cursor-pointer whitespace-nowrap text-blue-700 tabular-nums underline decoration-dotted underline-offset-2 hover:decoration-solid disabled:cursor-wait dark:text-blue-300 ${kalin ? 'font-semibold' : ''}`}
      >
        {tutarGoster(deger)}
      </button>
    )
  }

  const veri = ozet.data
  const turler = veri?.kesinti_turleri ?? []
  const satirlar = veri ? tabloSatirlari(veri, sekme) : []

  const kimlikKolonlari: Record<'taseron' | 'proje', DataTableKolonu<TabloSatiri>> = {
    taseron: {
      anahtar: 'taseron',
      baslik: t('kesinti.pivot.kolon.taseron'),
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
    proje: {
      anahtar: 'proje',
      baslik: t('kesinti.pivot.kolon.proje'),
      siralamaAnahtari: 'proje',
      render: (satir) => (
        <span
          className={`whitespace-nowrap ${sekme === 'proje' ? 'font-semibold' : ''}`}
          title={satir.proje_ad && satir.proje_ad !== satir.proje ? satir.proje_ad : undefined}
        >
          {satir.proje}
        </span>
      ),
    },
  }

  const kolonlar: DataTableKolonu<TabloSatiri>[] = [
    ...KIMLIKLER[sekme].map((kimlik) => kimlikKolonlari[kimlik]),
    ...turler.map((tur): DataTableKolonu<TabloSatiri> => ({
      anahtar: turKolonu(tur.id),
      baslik: tur.kod,
      hizala: 'sag',
      siralamaAnahtari: turKolonu(tur.id),
      render: (satir) => kalanHucresi(satir, tur),
    })),
    {
      anahtar: 'toplam_kalan',
      baslik: t('kesinti.pivot.kolon.toplamKalan'),
      hizala: 'sag',
      siralamaAnahtari: 'toplam_kalan',
      render: (satir) => kalanHucresi(satir, null, true),
    },
  ]

  const toplamSatirlari: ToplamSatiri[] = veri
    ? [
        {
          anahtar: 'toplam',
          etiket: t('kesinti.pivot.toplam'),
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

  // Üç sekme aynı hücrelerden: biri boşsa hepsi boş
  const bos = veri !== undefined && veri.satirlar.length === 0

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.pivot.baslik')}</h2>
        <Button
          variant="secondary"
          yukleniyor={excel.isPending}
          disabled={parametreler === null || veri === undefined || bos || eskiVeri}
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
        {t('kesinti.pivot.aciklamaMetni')}
      </p>

      <KesintiRaporuParametreleri durum={parametreDurumu} idOneki="pivot" />

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
            ? t('kesinti.pivot.kalanYok')
            : secenekler.data?.para_birimleri.length === 0
              ? t('kesinti.ozet.kesintiYok')
              : t('ortak.yukleniyor')}
        </div>
      ) : (
        <>
          <div
            role="tablist"
            aria-label={t('kesinti.pivot.sekmeler')}
            className="mt-4 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-700"
          >
            {SEKMELER.map((anahtar) => (
              <button
                key={anahtar}
                type="button"
                role="tab"
                aria-selected={sekme === anahtar}
                onClick={() => setSekme(anahtar)}
                className={`-mb-px cursor-pointer border-b-2 px-3 py-2 text-sm font-semibold ${
                  sekme === anahtar
                    ? 'border-violet-600 text-violet-700 dark:text-violet-300'
                    : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                }`}
              >
                {t(`kesinti.pivot.sekme.${anahtar}`)}
              </button>
            ))}
          </div>
          <div className={eskiVeri ? 'opacity-50' : ''}>
            <p className="mt-4 text-sm text-slate-600 dark:text-slate-400">
              {t(`kesinti.pivot.satirSayisi.${sekme}`, {
                sayi: satirlar.length,
                para: veri.para_birimi,
              })}
            </p>
            <div className="mt-2">
              <DataTable
                key={sekme}
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
        </>
      )}
    </>
  )
}
