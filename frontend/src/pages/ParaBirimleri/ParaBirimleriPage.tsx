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
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import {
  paraBirimiKaydet,
  paraBirimiSil,
  paraBirimleriniGetir,
  type ParaBirimi,
} from '@/features/kesinti/paraBirimiApi'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { buyukHarfKaydi } from '@/utils/metin'

/** Backend sınırlarıyla aynı (ParaBirimiKaydetRequest) */
const paraSemasi = z.object({
  kod: z
    .string()
    .trim()
    .min(1, 'kesinti.paraBirimleri.dogrulama.kodZorunlu')
    .max(10, 'kesinti.paraBirimleri.dogrulama.kodCokUzun'),
  ad: z
    .string()
    .trim()
    .min(1, 'kesinti.paraBirimleri.dogrulama.adZorunlu')
    .max(50, 'kesinti.paraBirimleri.dogrulama.adCokUzun'),
})

type ParaGirdisi = z.infer<typeof paraSemasi>

function ParaBirimiFormu({ para, kapat }: { para: ParaBirimi | null; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ParaGirdisi>({
    resolver: standardSchemaResolver(paraSemasi),
    defaultValues: { kod: para?.kod ?? '', ad: para?.ad ?? '' },
  })

  const kaydet = useMutation({
    mutationFn: (girdi: ParaGirdisi) => paraBirimiKaydet(para?.id ?? null, girdi),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.paraBirimleri })
      toast.success(t('kesinti.paraBirimleri.kaydedildi'))
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
      <div className="grid gap-4 sm:grid-cols-3">
        <Input
          id="para-kod"
          label={t('kesinti.paraBirimleri.kod')}
          maxLength={10}
          hata={errors.kod?.message ? t(errors.kod.message) : undefined}
          // Kod büyük/küçük harf ayırmaz: yazarken Türkçe kurallı büyük harfe döner
          {...register('kod', buyukHarfKaydi)}
        />
        <div className="sm:col-span-2">
          <Input
            id="para-ad"
            label={t('kesinti.paraBirimleri.ad')}
            maxLength={50}
            hata={errors.ad?.message ? t(errors.ad.message) : undefined}
            {...register('ad')}
          />
        </div>
      </div>

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

export function ParaBirimleriPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('para_birimleri.guncelle')
  // null: kapalı; 'yeni': yeni kayıt; ParaBirimi: düzenleme
  const [formdaki, setFormdaki] = useState<ParaBirimi | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<ParaBirimi | null>(null)

  const paralar = useQuery({
    queryKey: queryKeys.kesinti.paraBirimleri,
    queryFn: paraBirimleriniGetir,
  })
  // Kolon başlığına tıklayınca sıralanır; tercih tarayıcıda hatırlanır
  const { siralama, siralamaDegistir } = useKaliciSiralama('paraBirimleri')

  const sil = useMutation({
    mutationFn: (para: ParaBirimi) => paraBirimiSil(para.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.paraBirimleri })
      toast.success(t('kesinti.paraBirimleri.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  const tumKolonlar: DataTableKolonu<ParaBirimi>[] = [
    {
      anahtar: 'kod',
      baslik: t('kesinti.paraBirimleri.kod'),
      siralamaAnahtari: 'kod',
      render: (para) => <strong>{para.kod}</strong>,
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.paraBirimleri.ad'),
      siralamaAnahtari: 'ad',
      render: (para) => para.ad,
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (para) => (
        <div className="flex justify-end gap-2">
          <Button variant="turuncu" onClick={() => setFormdaki(para)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(para)}>
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

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.paraBirimleri.baslik')}</h2>
        {guncelleyebilir ? (
          <Button variant="mor" onClick={() => setFormdaki('yeni')}>
            {t('kesinti.paraBirimleri.yeni')}
          </Button>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.paraBirimleri.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4">
        {paralar.error ? (
          <ErrorState
            mesaj={t(apiErrorKey(paralar.error))}
            tekrarDene={() => void paralar.refetch()}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={siralaUygula(paralar.data ?? [], siralama)}
            satirAnahtari={(para) => para.id}
            yukleniyor={paralar.isPending}
            siralama={siralama}
            siralamaDegistir={siralamaDegistir}
          />
        )}
      </div>

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={
          formdaki === 'yeni' ? t('kesinti.paraBirimleri.yeni') : t('kesinti.paraBirimleri.duzenle')
        }
      >
        {formdaki !== null ? (
          <ParaBirimiFormu
            para={formdaki === 'yeni' ? null : formdaki}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.paraBirimleri.silOnayBaslik')}
        mesaj={t('kesinti.paraBirimleri.silOnayMesaj', {
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
