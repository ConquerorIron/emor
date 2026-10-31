import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
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
import { SayiAlani } from '@/components/SayiAlani'
import { SelectField } from '@/components/SelectField'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { ICMALLER } from '@/features/kesinti/icmalApi'
import {
  kesintiTuruKaydet,
  kesintiTuruSil,
  kesintiTurleriniGetir,
  type KesintiTuru,
} from '@/features/kesinti/kesintiTuruApi'
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { buyukHarfKaydi } from '@/utils/metin'

/** Backend sınırlarıyla aynı (KesintiTuruKaydetRequest) */
const ACIKLAMA_LIMITI = 500

const turSemasi = z.object({
  kod: z
    .string()
    .trim()
    .min(1, 'kesinti.turler.dogrulama.kodZorunlu')
    .max(20, 'kesinti.turler.dogrulama.kodCokUzun'),
  ad: z
    .string()
    .trim()
    .min(1, 'kesinti.turler.dogrulama.adZorunlu')
    .max(100, 'kesinti.turler.dogrulama.adCokUzun'),
  // SayiAlani noktalı biçim yazar ("20.00"); eksi işareti girilemez
  kdv_orani: z
    .string()
    .refine((deger) => Number(deger) <= 100, 'kesinti.turler.dogrulama.kdvAralik'),
  aciklama: z.string(),
  // Boş: tür hiçbir icmal ekranında kullanılamaz
  icmal: z.string(),
})

type TurGirdisi = z.infer<typeof turSemasi>

/** "20.00" → "%20", "18.50" → "%18,5" */
function kdvGoster(oran: string): string {
  return `%${Number(oran).toLocaleString('tr-TR', { maximumFractionDigits: 2 })}`
}

function KesintiTuruFormu({ tur, kapat }: { tur: KesintiTuru | null; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<TurGirdisi>({
    resolver: standardSchemaResolver(turSemasi),
    defaultValues: {
      kod: tur?.kod ?? '',
      ad: tur?.ad ?? '',
      // Maskeli alan boş gösterilemez: yeni türde "0,00" görünür ve öyle kaydedilir
      kdv_orani: tur?.kdv_orani ?? '0.00',
      aciklama: tur?.aciklama ?? '',
      icmal: tur?.icmal ?? '',
    },
  })

  const ekranSecenekleri = ICMALLER.map((icmal) => ({
    value: icmal,
    label: t(`kesinti.icmaller.${icmal}`),
  }))

  const kaydet = useMutation({
    mutationFn: (girdi: TurGirdisi) =>
      kesintiTuruKaydet(tur?.id ?? null, {
        kod: girdi.kod,
        ad: girdi.ad,
        kdv_orani: girdi.kdv_orani,
        aciklama: girdi.aciklama.trim() === '' ? null : girdi.aciklama,
        icmal: ICMALLER.find((icmal) => icmal === girdi.icmal) ?? null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.turler })
      toast.success(t('kesinti.turler.kaydedildi'))
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
          id="kesinti-turu-kod"
          label={t('kesinti.turler.kod')}
          maxLength={20}
          hata={errors.kod?.message ? t(errors.kod.message) : undefined}
          // Kod büyük/küçük harf ayırmaz: yazarken Türkçe kurallı büyük harfe döner
          {...register('kod', buyukHarfKaydi)}
        />
        <div className="sm:col-span-2">
          <Input
            id="kesinti-turu-ad"
            label={t('kesinti.turler.ad')}
            maxLength={100}
            hata={errors.ad?.message ? t(errors.ad.message) : undefined}
            {...register('ad')}
          />
        </div>
        <Controller
          control={control}
          name="kdv_orani"
          render={({ field }) => (
            <SayiAlani
              id="kesinti-turu-kdv"
              label={t('kesinti.turler.kdvOrani')}
              value={field.value}
              onChange={field.onChange}
              yuzde
              ondalik={2}
              hata={errors.kdv_orani?.message ? t(errors.kdv_orani.message) : undefined}
            />
          )}
        />
        <div className="sm:col-span-2">
          <Controller
            control={control}
            name="icmal"
            render={({ field }) => (
              <SelectField
                id="kesinti-turu-ekran"
                label={t('kesinti.turler.ekran')}
                options={ekranSecenekleri}
                value={ekranSecenekleri.find((secenek) => secenek.value === field.value) ?? null}
                onChange={(secenek) => field.onChange(secenek?.value ?? '')}
                isClearable
                isSearchable={false}
                placeholder={t('kesinti.turler.ekranYok')}
              />
            )}
          />
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {t('kesinti.turler.ekranAciklamasi')}
          </p>
        </div>
      </div>

      <Controller
        control={control}
        name="aciklama"
        render={({ field }) => (
          <SinirliMetinInput
            id="kesinti-turu-aciklama"
            label={t('kesinti.turler.aciklama')}
            value={field.value}
            onChange={field.onChange}
            limit={ACIKLAMA_LIMITI}
            rows={3}
          />
        )}
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

export function KesintiTurleriPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('kesinti_turleri.guncelle')
  // null: kapalı; 'yeni': yeni tür; KesintiTuru: düzenleme
  const [formdaki, setFormdaki] = useState<KesintiTuru | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<KesintiTuru | null>(null)

  const turler = useQuery({ queryKey: queryKeys.kesinti.turler, queryFn: kesintiTurleriniGetir })
  // Kolon başlığına tıklayınca sıralanır; tercih tarayıcıda hatırlanır
  const { siralama, siralamaDegistir } = useKaliciSiralama('kesintiTurleri')
  const siraliTurler = siralaUygula(turler.data ?? [], siralama, {
    kdv_orani: (tur) => Number(tur.kdv_orani),
  })

  const sil = useMutation({
    mutationFn: (tur: KesintiTuru) => kesintiTuruSil(tur.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.turler })
      toast.success(t('kesinti.turler.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  const tumKolonlar: DataTableKolonu<KesintiTuru>[] = [
    // En solda satır numarası, ekrandaki sırayla (kullanıcı isteği)
    {
      anahtar: 'sira_no',
      baslik: t('kesinti.alan.sira_no'),
      hizala: 'sag',
      render: (_tur, sira) => (
        <span className="text-slate-500 tabular-nums dark:text-slate-400">{sira + 1}</span>
      ),
    },
    {
      anahtar: 'kod',
      baslik: t('kesinti.turler.kod'),
      siralamaAnahtari: 'kod',
      render: (tur) => <strong>{tur.kod}</strong>,
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.turler.ad'),
      siralamaAnahtari: 'ad',
      render: (tur) => tur.ad,
    },
    {
      anahtar: 'kdv_orani',
      baslik: t('kesinti.turler.kdvOrani'),
      siralamaAnahtari: 'kdv_orani',
      hizala: 'sag',
      render: (tur) => <span className="tabular-nums">{kdvGoster(tur.kdv_orani)}</span>,
    },
    {
      anahtar: 'icmal',
      baslik: t('kesinti.turler.ekran'),
      siralamaAnahtari: 'icmal',
      render: (tur) =>
        tur.icmal === null ? (
          <span className="text-slate-400">{t('kesinti.turler.ekranYok')}</span>
        ) : (
          t(`kesinti.icmaller.${tur.icmal}`)
        ),
    },
    {
      anahtar: 'aciklama',
      baslik: t('kesinti.turler.aciklama'),
      siralamaAnahtari: 'aciklama',
      render: (tur) =>
        tur.aciklama === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <span className="whitespace-pre-line">{tur.aciklama}</span>
        ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (tur) => (
        <div className="flex justify-end gap-2">
          <Button variant="turuncu" onClick={() => setFormdaki(tur)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(tur)}>
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
        <h2 className="text-2xl font-bold">{t('kesinti.turler.baslik')}</h2>
        {guncelleyebilir ? (
          <Button variant="mor" onClick={() => setFormdaki('yeni')}>
            {t('kesinti.turler.yeni')}
          </Button>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.turler.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4">
        {turler.error ? (
          <ErrorState
            mesaj={t(apiErrorKey(turler.error))}
            tekrarDene={() => void turler.refetch()}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={siraliTurler}
            satirAnahtari={(tur) => tur.id}
            yukleniyor={turler.isPending}
            siralama={siralama}
            siralamaDegistir={siralamaDegistir}
          />
        )}
      </div>

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('kesinti.turler.yeni') : t('kesinti.turler.duzenle')}
      >
        {formdaki !== null ? (
          <KesintiTuruFormu
            tur={formdaki === 'yeni' ? null : formdaki}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.turler.silOnayBaslik')}
        mesaj={t('kesinti.turler.silOnayMesaj', {
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
