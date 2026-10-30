import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { Degisim, ErpAktarimPenceresi } from '@/features/kesinti/ErpAktarimPenceresi'
import {
  erpProjeleriniAktar,
  erpProjeleriniGetir,
  projeKaydet,
  projeleriGetir,
  projeSil,
  type ErpProjeSatiri,
  type Proje,
} from '@/features/kesinti/projeApi'
import { useIzin } from '@/hooks/useIzin'
import { buyukHarfKaydi } from '@/utils/metin'

/** Backend sınırlarıyla aynı (ProjeKaydetRequest) */
const projeSemasi = z.object({
  kod: z
    .string()
    .trim()
    .min(1, 'kesinti.projeler.dogrulama.kodZorunlu')
    .max(50, 'kesinti.projeler.dogrulama.kodCokUzun'),
  ad: z
    .string()
    .trim()
    .min(1, 'kesinti.projeler.dogrulama.adZorunlu')
    .max(200, 'kesinti.projeler.dogrulama.adCokUzun'),
})

type ProjeGirdisi = z.infer<typeof projeSemasi>

function ProjeFormu({ proje, kapat }: { proje: Proje | null; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ProjeGirdisi>({
    resolver: standardSchemaResolver(projeSemasi),
    defaultValues: { kod: proje?.kod ?? '', ad: proje?.ad ?? '' },
  })

  const kaydet = useMutation({
    mutationFn: (girdi: ProjeGirdisi) => projeKaydet(proje?.id ?? null, girdi),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.projeler })
      toast.success(t('kesinti.projeler.kaydedildi'))
      kapat()
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      {proje?.erp_guid ? (
        <p className="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-950 dark:text-sky-300">
          {t('kesinti.projeler.erpProjesiUyarisi')}
        </p>
      ) : null}
      <Input
        id="proje-kod"
        label={t('kesinti.projeler.kod')}
        maxLength={50}
        hata={errors.kod?.message ? t(errors.kod.message) : undefined}
        // Kod büyük/küçük harf ayırmaz: yazarken Türkçe kurallı büyük harfe döner
        {...register('kod', buyukHarfKaydi)}
      />
      <Input
        id="proje-ad"
        label={t('kesinti.projeler.ad')}
        maxLength={200}
        hata={errors.ad?.message ? t(errors.ad.message) : undefined}
        {...register('ad')}
      />

      {errors.root?.message ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}

      <div className="flex justify-end gap-3">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

export function ProjelerPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('projeler.guncelle')
  // null: kapalı; 'yeni': yeni proje; Proje: düzenleme
  const [formdaki, setFormdaki] = useState<Proje | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<Proje | null>(null)
  const [erpAcik, setErpAcik] = useState(false)

  const projeler = useQuery({ queryKey: queryKeys.kesinti.projeler, queryFn: projeleriGetir })

  const sil = useMutation({
    mutationFn: (proje: Proje) => projeSil(proje.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.projeler })
      toast.success(t('kesinti.projeler.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  const tumKolonlar: DataTableKolonu<Proje>[] = [
    {
      anahtar: 'kod',
      baslik: t('kesinti.projeler.kod'),
      render: (proje) => <strong>{proje.kod}</strong>,
    },
    { anahtar: 'ad', baslik: t('kesinti.projeler.ad'), render: (proje) => proje.ad },
    {
      anahtar: 'kaynak',
      baslik: t('kesinti.projeler.kaynak'),
      render: (proje) =>
        proje.erp_guid === null ? (
          <span className="text-slate-500 dark:text-slate-400">
            {t('kesinti.projeler.kaynakElle')}
          </span>
        ) : (
          <span className="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
            {t('kesinti.projeler.kaynakErp')}
          </span>
        ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (proje) => (
        <div className="flex justify-end gap-2">
          <Button variant="turuncu" onClick={() => setFormdaki(proje)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(proje)}>
            {t('ortak.sil')}
          </Button>
        </div>
      ),
    },
  ]
  // Yalnız görüntüleme izninde düzenle/sil kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  const erpKolonlari: DataTableKolonu<ErpProjeSatiri>[] = [
    {
      anahtar: 'kod',
      baslik: t('kesinti.projeler.kod'),
      render: (satir) => (
        <strong>
          <Degisim eski={satir.mevcut?.kod} yeni={satir.kod} />
        </strong>
      ),
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.projeler.ad'),
      render: (satir) => <Degisim eski={satir.mevcut?.ad} yeni={satir.ad} />,
    },
  ]

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.projeler.baslik')}</h2>
        {guncelleyebilir ? (
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => setErpAcik(true)}>
              {t('kesinti.projeler.erpdenGetir')}
            </Button>
            <Button variant="mor" onClick={() => setFormdaki('yeni')}>
              {t('kesinti.projeler.yeni')}
            </Button>
          </div>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.projeler.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4">
        {projeler.error ? (
          <ErrorState
            mesaj={t(apiErrorKey(projeler.error))}
            tekrarDene={() => void projeler.refetch()}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={projeler.data ?? []}
            satirAnahtari={(proje) => proje.id}
            yukleniyor={projeler.isPending}
          />
        )}
      </div>

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('kesinti.projeler.yeni') : t('kesinti.projeler.duzenle')}
      >
        {formdaki !== null ? (
          <ProjeFormu
            proje={formdaki === 'yeni' ? null : formdaki}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ErpAktarimPenceresi
        acik={erpAcik}
        kapat={() => setErpAcik(false)}
        baslik={t('kesinti.projeler.erpBaslik')}
        tanim={{
          sorguAnahtari: queryKeys.kesinti.erpProjeleri,
          getir: erpProjeleriniGetir,
          aktar: erpProjeleriniAktar,
          listeAnahtari: queryKeys.kesinti.projeler,
          kolonlar: erpKolonlari,
          aramaMetni: (satir) => `${satir.kod} ${satir.ad}`,
        }}
      />

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.projeler.silOnayBaslik')}
        mesaj={t('kesinti.projeler.silOnayMesaj', {
          kod: silinecek?.kod ?? '',
          ad: silinecek?.ad ?? '',
        })}
        onayEtiketi={t('ortak.sil')}
        yukleniyor={sil.isPending}
        onayla={() => {
          if (silinecek !== null) {
            sil.mutate(silinecek)
          }
        }}
      />
    </>
  )
}
