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
import { Switch } from '@/components/Switch'
import {
  izinEtiketAnahtari,
  izinleriGetir,
  rolKaydet,
  rolleriGetir,
  rolSil,
  type Rol,
} from '@/features/ayarlar/yetkiApi'

const rolSemasi = z.object({
  ad: z
    .string()
    .trim()
    .min(1, 'ayarlar.roller.dogrulama.adZorunlu')
    .max(64, 'ayarlar.roller.dogrulama.adCokUzun'),
  aciklama: z.string().max(255, 'ayarlar.roller.dogrulama.aciklamaCokUzun'),
  izinler: z.array(z.string()),
})

type RolGirdisi = z.infer<typeof rolSemasi>

function RolFormu({
  rol,
  izinKatalogu,
  kapat,
}: {
  rol: Rol | null
  izinKatalogu: string[]
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RolGirdisi>({
    resolver: standardSchemaResolver(rolSemasi),
    defaultValues: {
      ad: rol?.ad ?? '',
      aciklama: rol?.aciklama ?? '',
      izinler: rol?.izinler ?? [],
    },
  })

  const kaydet = useMutation({
    mutationFn: (girdi: RolGirdisi) =>
      rolKaydet(rol?.id ?? null, {
        ad: girdi.ad,
        aciklama: girdi.aciklama === '' ? null : girdi.aciklama,
        izinler: girdi.izinler,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.roller })
      toast.success(t('ayarlar.roller.kaydedildi'))
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
      <Input
        id="rol-ad"
        label={t('ayarlar.roller.ad')}
        hata={errors.ad?.message ? t(errors.ad.message) : undefined}
        {...register('ad')}
      />
      <Input
        id="rol-aciklama"
        label={t('ayarlar.roller.aciklama')}
        hata={errors.aciklama?.message ? t(errors.aciklama.message) : undefined}
        {...register('aciklama')}
      />

      <fieldset>
        <legend className="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          {t('ayarlar.roller.izinler')}
        </legend>
        <Controller
          control={control}
          name="izinler"
          render={({ field }) => (
            <div className="space-y-2">
              {izinKatalogu.map((izin) => (
                <Switch
                  key={izin}
                  id={`izin-${izin}`}
                  label={t(izinEtiketAnahtari(izin))}
                  checked={field.value.includes(izin)}
                  onChange={(acik) =>
                    field.onChange(
                      acik ? [...field.value, izin] : field.value.filter((deger) => deger !== izin),
                    )
                  }
                />
              ))}
            </div>
          )}
        />
      </fieldset>

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

export function RollerPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // null: kapalı; 'yeni': yeni rol; Rol: düzenleme
  const [formdaki, setFormdaki] = useState<Rol | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<Rol | null>(null)

  const roller = useQuery({ queryKey: queryKeys.ayarlar.roller, queryFn: rolleriGetir })
  const izinKatalogu = useQuery({ queryKey: queryKeys.ayarlar.izinler, queryFn: izinleriGetir })

  const sil = useMutation({
    mutationFn: (rol: Rol) => rolSil(rol.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.roller })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.kullanicilar })
      toast.success(t('ayarlar.roller.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  const kolonlar: DataTableKolonu<Rol>[] = [
    { anahtar: 'ad', baslik: t('ayarlar.roller.ad'), render: (rol) => <strong>{rol.ad}</strong> },
    {
      anahtar: 'izinler',
      baslik: t('ayarlar.roller.izinler'),
      render: (rol) =>
        rol.izinler.length === 0 ? (
          <span className="text-slate-400">{t('ayarlar.roller.izinYok')}</span>
        ) : (
          rol.izinler.map((izin) => t(izinEtiketAnahtari(izin))).join(', ')
        ),
    },
    {
      anahtar: 'kullanici_sayisi',
      baslik: t('ayarlar.roller.kullaniciSayisi'),
      hizala: 'sag',
      render: (rol) => rol.kullanici_sayisi,
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (rol) => (
        <div className="flex justify-end gap-2">
          <Button variant="turuncu" onClick={() => setFormdaki(rol)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(rol)}>
            {t('ortak.sil')}
          </Button>
        </div>
      ),
    },
  ]

  const hata = roller.error ?? izinKatalogu.error

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('ayarlar.roller.baslik')}</h2>
        <Button
          variant="mor"
          onClick={() => setFormdaki('yeni')}
          disabled={!izinKatalogu.isSuccess}
        >
          {t('ayarlar.roller.yeni')}
        </Button>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.roller.aciklamaMetni')}
      </p>

      {hata ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(hata))}
            tekrarDene={() => {
              void roller.refetch()
              void izinKatalogu.refetch()
            }}
          />
        </div>
      ) : (
        <div className="mt-4">
          <DataTable
            kolonlar={kolonlar}
            satirlar={roller.data ?? []}
            satirAnahtari={(rol) => rol.id}
            yukleniyor={roller.isPending}
          />
        </div>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('ayarlar.roller.yeni') : t('ayarlar.roller.duzenle')}
      >
        {formdaki !== null && izinKatalogu.isSuccess ? (
          <RolFormu
            rol={formdaki === 'yeni' ? null : formdaki}
            izinKatalogu={izinKatalogu.data}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('ayarlar.roller.silOnayBaslik')}
        mesaj={t('ayarlar.roller.silOnayMesaj', {
          ad: silinecek?.ad ?? '',
          sayi: silinecek?.kullanici_sayisi ?? 0,
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
