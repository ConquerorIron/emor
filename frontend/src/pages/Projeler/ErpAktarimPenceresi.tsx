import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import {
  AKTARILABILIR_DURUMLAR,
  erpProjeleriniAktar,
  erpProjeleriniGetir,
  type ErpProjeDurumu,
  type ErpProjeSatiri,
} from '@/features/kesinti/projeApi'

const DURUM_ROZETLERI: Record<ErpProjeDurumu, string> = {
  yeni: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  guncellenecek: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  eslesecek: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  ayni: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
  cakisma: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  gecersiz: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
}

function aktarilabilirMi(satir: ErpProjeSatiri): boolean {
  return AKTARILABILIR_DURUMLAR.includes(satir.durum)
}

/** Değişen değer "eski → yeni" gösterilir */
function Degisim({ eski, yeni }: { eski: string | null; yeni: string }) {
  if (eski === null || eski === yeni) {
    return <>{yeni}</>
  }

  return (
    <>
      <span className="text-slate-400 line-through">{eski}</span> → {yeni}
    </>
  )
}

/**
 * Önizlemedeki seçim ve kayıt. Ayrı bileşen: seçim, veri geldiğinde
 * aktarılabilir satırların tümüyle başlar (effect gerekmez).
 */
function AktarimListesi({ satirlar, kapat }: { satirlar: ErpProjeSatiri[]; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const aktarilabilirler = satirlar.filter(aktarilabilirMi)
  // Değişikliği olmayanlar listeyi kalabalıklaştırmasın: yalnız sayısı gösterilir
  const gosterilenler = satirlar.filter((satir) => satir.durum !== 'ayni')
  const ayniSayisi = satirlar.length - gosterilenler.length
  const [secili, setSecili] = useState<Set<string | number>>(
    () => new Set(aktarilabilirler.map((satir) => satir.sira)),
  )
  const seciliSatirlar = aktarilabilirler.filter((satir) => secili.has(satir.sira))

  const aktar = useMutation({
    mutationFn: () => erpProjeleriniAktar(seciliSatirlar.map((satir) => satir.guid)),
    onSuccess: async (sonuc) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.projeler })
      toast.success(t('kesinti.projeler.erp.aktarildi', { ...sonuc }))
      kapat()
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const sayilar = {
    yeni: satirlar.filter((satir) => satir.durum === 'yeni').length,
    guncellenecek: satirlar.filter((satir) => satir.durum === 'guncellenecek').length,
    eslesecek: satirlar.filter((satir) => satir.durum === 'eslesecek').length,
    aktarilamaz: gosterilenler.length - aktarilabilirler.length,
    ayni: ayniSayisi,
  }

  const kolonlar: DataTableKolonu<ErpProjeSatiri>[] = [
    {
      anahtar: 'durum',
      baslik: t('kesinti.projeler.erp.durumBaslik'),
      render: (satir) => (
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${DURUM_ROZETLERI[satir.durum]}`}
        >
          {t(`kesinti.projeler.erp.durum.${satir.durum}`)}
        </span>
      ),
    },
    {
      anahtar: 'kod',
      baslik: t('kesinti.projeler.kod'),
      render: (satir) => (
        <strong>
          <Degisim eski={satir.mevcut_kod} yeni={satir.kod} />
        </strong>
      ),
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.projeler.ad'),
      render: (satir) => <Degisim eski={satir.mevcut_ad} yeni={satir.ad} />,
    },
    {
      anahtar: 'neden',
      baslik: t('kesinti.projeler.erp.aciklamaBaslik'),
      render: (satir) =>
        satir.neden === null ? null : (
          <span className="text-red-700 dark:text-red-300">
            {t(`kesinti.projeler.erp.neden.${satir.neden}`)}
          </span>
        ),
    },
  ]

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.projeler.erp.ozet', sayilar)}
      </p>

      {gosterilenler.length === 0 ? (
        <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
          {t('kesinti.projeler.erp.hepsiGuncel')}
        </p>
      ) : (
        <div className="max-h-[60vh] overflow-y-auto">
          <DataTable
            kolonlar={kolonlar}
            satirlar={gosterilenler}
            satirAnahtari={(satir) => satir.sira}
            seciliAnahtarlar={secili}
            secimDegistir={(anahtar) =>
              setSecili((onceki) => {
                const yeni = new Set(onceki)
                if (!yeni.delete(anahtar)) {
                  yeni.add(anahtar)
                }

                return yeni
              })
            }
            tumunuSec={(hepsi) =>
              setSecili(new Set(hepsi ? aktarilabilirler.map((satir) => satir.sira) : []))
            }
            secilebilir={aktarilabilirMi}
          />
        </div>
      )}

      <div className="flex justify-end gap-3">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button
          type="button"
          onClick={() => aktar.mutate()}
          disabled={seciliSatirlar.length === 0}
          yukleniyor={aktar.isPending}
        >
          {t('kesinti.projeler.erp.aktar', { sayi: seciliSatirlar.length })}
        </Button>
      </div>
    </div>
  )
}

function AktarimIcerigi({ kapat }: { kapat: () => void }) {
  const { t } = useTranslation()
  // Her açılışta ERP taze okunur; ERP hatası tekrar denenmez (kullanıcı "Tekrar Dene" ile ister)
  const onizleme = useQuery({
    queryKey: queryKeys.kesinti.erpProjeleri,
    queryFn: erpProjeleriniGetir,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  })

  if (onizleme.isPending) {
    return (
      <p className="py-6 text-center text-sm text-slate-500">
        {t('kesinti.projeler.erp.okunuyor')}
      </p>
    )
  }

  if (onizleme.error) {
    return (
      <ErrorState
        mesaj={t(apiErrorKey(onizleme.error))}
        tekrarDene={() => void onizleme.refetch()}
      />
    )
  }

  return <AktarimListesi satirlar={onizleme.data} kapat={kapat} />
}

/** Projeler → "ERP'den Getir": canlı ERP projelerini önizler, seçilenleri aktarır. */
export function ErpAktarimPenceresi({ acik, kapat }: { acik: boolean; kapat: () => void }) {
  const { t } = useTranslation()

  return (
    <Modal acik={acik} kapat={kapat} baslik={t('kesinti.projeler.erp.baslik')} boyut="devasa">
      {acik ? <AktarimIcerigi kapat={kapat} /> : null}
    </Modal>
  )
}
