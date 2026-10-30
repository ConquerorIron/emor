import { useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { trBuyukHarf } from '@/utils/metin'

import {
  AKTARILABILIR_DURUMLAR,
  type ErpAktarimDurumu,
  type ErpAktarimSatiri,
  type ErpAktarimSonucu,
} from './erpAktarim'

const DURUM_ROZETLERI: Record<ErpAktarimDurumu, string> = {
  yeni: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  guncellenecek: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  eslesecek: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  ayni: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
  cakisma: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  gecersiz: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
}

function aktarilabilirMi(satir: ErpAktarimSatiri): boolean {
  return AKTARILABILIR_DURUMLAR.includes(satir.durum)
}

/**
 * Değişen değer "eski → yeni" gösterilir. `eski` undefined: eşleşen kayıt
 * yok (yalnız ERP değeri yazılır).
 */
export function Degisim({ eski, yeni }: { eski?: string | null; yeni: string | null }) {
  if (eski === undefined || eski === yeni) {
    return <>{yeni ?? ''}</>
  }

  return (
    <>
      <span className="text-slate-400 line-through">{eski ?? '—'}</span> → {yeni ?? '—'}
    </>
  )
}

interface AktarimTanimi<T extends ErpAktarimSatiri> {
  /** Önizleme: her açılışta ERP'den taze okunur */
  sorguAnahtari: QueryKey
  getir: () => Promise<T[]>
  aktar: (guidler: string[]) => Promise<ErpAktarimSonucu>
  /** Aktarımdan sonra tazelenecek liste */
  listeAnahtari: QueryKey
  /** Satıra özgü kolonlar; Durum başa, Açıklama sona eklenir */
  kolonlar: DataTableKolonu<T>[]
  /** Arama kutusunun süzdüğü metin */
  aramaMetni: (satir: T) => string
}

/**
 * Önizlemedeki seçim ve kayıt. Seçim BOŞ başlar (kullanıcı isteği
 * 2026-10-30): varsayılan "hepsi seçili" iken arama ile tek kayıt süzülüp
 * aktarılınca görünmeyen tüm kayıtlar da aktarılmıştı.
 */
function AktarimListesi<T extends ErpAktarimSatiri>({
  satirlar,
  tanim,
  kapat,
}: {
  satirlar: T[]
  tanim: AktarimTanimi<T>
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [ara, setAra] = useState('')

  const aktarilabilirler = satirlar.filter(aktarilabilirMi)
  // Değişikliği olmayanlar listeyi kalabalıklaştırmasın: yalnız sayısı gösterilir
  const degisenler = satirlar.filter((satir) => satir.durum !== 'ayni')
  const aranan = trBuyukHarf(ara.trim())
  const gosterilenler =
    aranan === ''
      ? degisenler
      : degisenler.filter((satir) => trBuyukHarf(tanim.aramaMetni(satir)).includes(aranan))
  const [secili, setSecili] = useState<Set<string | number>>(() => new Set())
  const seciliSatirlar = aktarilabilirler.filter((satir) => secili.has(satir.sira))

  const aktar = useMutation({
    mutationFn: () => tanim.aktar(seciliSatirlar.map((satir) => satir.guid)),
    onSuccess: async (sonuc) => {
      await queryClient.invalidateQueries({ queryKey: tanim.listeAnahtari })
      toast.success(t('kesinti.erpAktarim.aktarildi', { ...sonuc }))
      kapat()
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const sayilar = {
    yeni: satirlar.filter((satir) => satir.durum === 'yeni').length,
    guncellenecek: satirlar.filter((satir) => satir.durum === 'guncellenecek').length,
    eslesecek: satirlar.filter((satir) => satir.durum === 'eslesecek').length,
    aktarilamaz: degisenler.length - aktarilabilirler.length,
    ayni: satirlar.length - degisenler.length,
  }

  // Tümünü seç/kaldır yalnız görünen (aramaya uyan) satırlara uygulanır
  const gorunenSecilebilirler = gosterilenler.filter(aktarilabilirMi).map((satir) => satir.sira)

  const kolonlar: DataTableKolonu<T>[] = [
    {
      anahtar: 'durum',
      baslik: t('kesinti.erpAktarim.durumBaslik'),
      render: (satir) => (
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${DURUM_ROZETLERI[satir.durum]}`}
        >
          {t(`kesinti.erpAktarim.durum.${satir.durum}`)}
        </span>
      ),
    },
    ...tanim.kolonlar,
    {
      anahtar: 'neden',
      baslik: t('kesinti.erpAktarim.aciklamaBaslik'),
      render: (satir) =>
        satir.neden === null ? null : (
          <span className="text-red-700 dark:text-red-300">
            {t(`kesinti.erpAktarim.neden.${satir.neden}`)}
          </span>
        ),
    },
  ]

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.erpAktarim.ozet', sayilar)}
      </p>

      {degisenler.length === 0 ? (
        <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
          {t('kesinti.erpAktarim.hepsiGuncel')}
        </p>
      ) : (
        <>
          <div className="sm:w-80">
            <Input
              id="erp-aktarim-ara"
              type="search"
              label={t('kesinti.erpAktarim.ara')}
              value={ara}
              onChange={(olay) => setAra(olay.target.value)}
            />
          </div>
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
                setSecili((onceki) => {
                  const yeni = new Set(onceki)
                  for (const sira of gorunenSecilebilirler) {
                    if (hepsi) {
                      yeni.add(sira)
                    } else {
                      yeni.delete(sira)
                    }
                  }

                  return yeni
                })
              }
              secilebilir={aktarilabilirMi}
            />
          </div>
        </>
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
          {t('kesinti.erpAktarim.aktar', { sayi: seciliSatirlar.length })}
        </Button>
      </div>
    </div>
  )
}

function AktarimIcerigi<T extends ErpAktarimSatiri>({
  tanim,
  kapat,
}: {
  tanim: AktarimTanimi<T>
  kapat: () => void
}) {
  const { t } = useTranslation()
  // Her açılışta ERP taze okunur; ERP hatası tekrar denenmez (kullanıcı "Tekrar Dene" ile ister)
  const onizleme = useQuery({
    queryKey: tanim.sorguAnahtari,
    queryFn: tanim.getir,
    staleTime: 0,
    gcTime: 0,
    retry: false,
  })

  if (onizleme.isPending) {
    return (
      <p className="py-6 text-center text-sm text-slate-500">{t('kesinti.erpAktarim.okunuyor')}</p>
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

  return <AktarimListesi satirlar={onizleme.data} tanim={tanim} kapat={kapat} />
}

/**
 * "ERP'den Getir" penceresi (Projeler, Firmalar): canlı ERP kayıtlarını
 * önizler, seçilenleri aktarır. Ekrana özgü kısım `tanim`dadır.
 */
export function ErpAktarimPenceresi<T extends ErpAktarimSatiri>({
  acik,
  kapat,
  baslik,
  tanim,
}: {
  acik: boolean
  kapat: () => void
  baslik: string
  tanim: AktarimTanimi<T>
}) {
  return (
    <Modal acik={acik} kapat={kapat} baslik={baslik} boyut="devasa">
      {acik ? <AktarimIcerigi tanim={tanim} kapat={kapat} /> : null}
    </Modal>
  )
}
