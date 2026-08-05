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
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import {
  aktifOrtamDegistir,
  sqlBaglantiGuncelle,
  sqlBaglantiSina,
  sqlBaglantilariGetir,
  type SinamaSonucu,
  type SqlBaglanti,
  type SqlBaglantiGovdesi,
  type SqlOrtam,
} from '@/features/ayarlar/sqlApi'

function baglantiSchemaOlustur(sifreZorunlu: boolean) {
  return z.object({
    sunucu: z
      .string()
      .min(1, 'ayarlar.sql.dogrulama.sunucuZorunlu')
      .max(255, 'ayarlar.sql.dogrulama.sunucuUzun'),
    // Boş bırakılabilir (named instance); doluysa 1–65535
    port: z.string().refine((deger) => {
      if (deger === '') {
        return true
      }
      if (!/^\d+$/.test(deger)) {
        return false
      }
      const sayi = Number(deger)

      return sayi >= 1 && sayi <= 65535
    }, 'ayarlar.sql.dogrulama.portGecersiz'),
    veritabani: z.string().min(1, 'ayarlar.sql.dogrulama.veritabaniZorunlu').max(128),
    kullanici_adi: z.string().min(1, 'ayarlar.sql.dogrulama.kullaniciZorunlu').max(128),
    sifre: z
      .string()
      .refine((deger) => !sifreZorunlu || deger !== '', 'ayarlar.sql.dogrulama.sifreZorunlu'),
  })
}

type BaglantiGirdisi = z.infer<ReturnType<typeof baglantiSchemaOlustur>>

function girdidenGovde(girdi: BaglantiGirdisi): SqlBaglantiGovdesi {
  return {
    sunucu: girdi.sunucu,
    port: girdi.port === '' ? null : Number(girdi.port),
    veritabani: girdi.veritabani,
    kullanici_adi: girdi.kullanici_adi,
    // Boş bırakılırsa gönderilmez — backend mevcut şifreyi korur
    ...(girdi.sifre !== '' ? { sifre: girdi.sifre } : {}),
  }
}

function BaglantiFormu({ ortam, tanim }: { ortam: SqlOrtam; tanim: SqlBaglanti | null }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [sinamaSonucu, setSinamaSonucu] = useState<SinamaSonucu | null>(null)
  const [sinamaHatasi, setSinamaHatasi] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    getValues,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<BaglantiGirdisi>({
    resolver: standardSchemaResolver(baglantiSchemaOlustur(!tanim?.sifre_dolu)),
    defaultValues: {
      sunucu: tanim?.sunucu ?? '',
      port: tanim?.port === null || tanim === null ? '' : String(tanim.port),
      veritabani: tanim?.veritabani ?? '',
      kullanici_adi: tanim?.kullanici_adi ?? '',
      sifre: '',
    },
  })

  const kaydet = useMutation({
    mutationFn: (girdi: BaglantiGirdisi) => sqlBaglantiGuncelle(ortam, girdidenGovde(girdi)),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.sqlBaglantilari })
      toast.success(t('ayarlar.sql.kaydedildi', { ortam: t(`ayarlar.sql.ortam.${ortam}`) }))
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  // Sınama form değerleriyle yapılır — kaydetmeden önce de denenebilir;
  // boş şifre backend'de kayıtlı şifreye düşer
  const sina = useMutation({
    mutationFn: () => sqlBaglantiSina(ortam, girdidenGovde(getValues())),
    onSuccess: (sonuc) => {
      setSinamaHatasi(null)
      setSinamaSonucu(sonuc)
    },
    onError: (error: unknown) => {
      setSinamaSonucu(null)
      setSinamaHatasi(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      className="rounded-xl border border-slate-200 p-5 dark:border-slate-700"
      noValidate
    >
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100">
          {t(`ayarlar.sql.ortam.${ortam}`)}
        </h3>
        {tanim?.aktif ? (
          <span
            className={`rounded-full px-2.5 py-0.5 text-xs font-bold uppercase ${
              ortam === 'canli'
                ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
            }`}
          >
            {t('ayarlar.sql.aktifRozet')}
          </span>
        ) : null}
      </div>

      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <Input
          id={`${ortam}-sunucu`}
          label={t('ayarlar.sql.sunucu')}
          placeholder={t('ayarlar.sql.sunucuOrnek')}
          hata={errors.sunucu?.message ? t(errors.sunucu.message) : undefined}
          {...register('sunucu')}
        />
        <Input
          id={`${ortam}-port`}
          label={t('ayarlar.sql.port')}
          inputMode="numeric"
          maxLength={5}
          placeholder="1433"
          hata={errors.port?.message ? t(errors.port.message) : undefined}
          {...register('port')}
        />
        <Input
          id={`${ortam}-veritabani`}
          label={t('ayarlar.sql.veritabani')}
          autoComplete="off"
          hata={errors.veritabani?.message ? t(errors.veritabani.message) : undefined}
          {...register('veritabani')}
        />
        <Input
          id={`${ortam}-kullanici`}
          label={t('ayarlar.sql.kullaniciAdi')}
          autoComplete="off"
          hata={errors.kullanici_adi?.message ? t(errors.kullanici_adi.message) : undefined}
          {...register('kullanici_adi')}
        />
        <Input
          id={`${ortam}-sifre`}
          type="password"
          label={t('ayarlar.sql.sifre')}
          autoComplete="new-password"
          placeholder={tanim?.sifre_dolu ? '••••••••' : undefined}
          hata={errors.sifre?.message ? t(errors.sifre.message) : undefined}
          {...register('sifre')}
        />
      </div>

      {tanim?.sifre_dolu ? (
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.sql.sifreNotu')}
        </p>
      ) : null}

      {errors.root?.message ? (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {errors.root.message}
        </p>
      ) : null}

      {sinamaSonucu ? (
        <div className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
          <p className="font-semibold">{t('ayarlar.sql.sinamaBasarili')}</p>
          <p className="mt-1">{sinamaSonucu.surum}</p>
          <p>
            {t('ayarlar.sql.veritabani')}: {sinamaSonucu.veritabani} —{' '}
            {t('ayarlar.sql.kullaniciAdi')}: {sinamaSonucu.kullanici}
          </p>
        </div>
      ) : null}

      {sinamaHatasi ? (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {sinamaHatasi}
        </p>
      ) : null}

      <div className="mt-4 flex justify-end gap-3">
        <Button
          type="button"
          variant="secondary"
          yukleniyor={sina.isPending}
          onClick={() => sina.mutate()}
        >
          {t('ayarlar.sql.sinaButon')}
        </Button>
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

function AktifOrtamBolumu({
  aktifOrtam,
  testVar,
  canliVar,
}: {
  aktifOrtam: SqlOrtam | null
  testVar: boolean
  canliVar: boolean
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // Canlıya geçiş onay ister (yanlışlıkla canlı ERP'ye kayıt atılmasın)
  const [onayBekleyen, setOnayBekleyen] = useState<SqlOrtam | null>(null)

  const degistir = useMutation({
    mutationFn: (ortam: SqlOrtam) => aktifOrtamDegistir(ortam),
    onSuccess: async (tanim) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.sqlBaglantilari })
      toast.success(t('ayarlar.sql.aktifYapildi', { ortam: t(`ayarlar.sql.ortam.${tanim.ortam}`) }))
    },
    onError: (error: unknown) => {
      toast.error(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
    onSettled: () => setOnayBekleyen(null),
  })

  const sec = (ortam: SqlOrtam) => {
    if (ortam === aktifOrtam) {
      return
    }
    if (ortam === 'canli') {
      setOnayBekleyen(ortam)
      return
    }
    degistir.mutate(ortam)
  }

  const secimSinifi = (ortam: SqlOrtam, tanimli: boolean): string => {
    const aktif = aktifOrtam === ortam
    const renk =
      ortam === 'canli'
        ? 'border-red-500 bg-red-50 text-red-700 dark:border-red-500 dark:bg-red-950 dark:text-red-300'
        : 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950 dark:text-emerald-300'

    return `flex-1 cursor-pointer rounded-xl border-2 px-5 py-4 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
      aktif
        ? renk
        : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
    } ${!tanimli ? 'opacity-50' : ''}`
  }

  return (
    <div className="mt-4 rounded-xl border border-slate-200 p-5 dark:border-slate-700">
      <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
        {t('ayarlar.sql.aktifOrtamBaslik')}
      </p>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {t('ayarlar.sql.aktifOrtamAciklama')}
      </p>

      <div className="mt-4 flex flex-col gap-3 sm:flex-row">
        <button
          type="button"
          disabled={!testVar || degistir.isPending}
          onClick={() => sec('test')}
          className={secimSinifi('test', testVar)}
        >
          <span className="block text-base font-bold">{t('ayarlar.sql.ortam.test')}</span>
          <span className="mt-0.5 block text-xs">
            {testVar ? t('ayarlar.sql.testSecimAciklama') : t('ayarlar.sql.onceTanimla')}
          </span>
        </button>
        <button
          type="button"
          disabled={!canliVar || degistir.isPending}
          onClick={() => sec('canli')}
          className={secimSinifi('canli', canliVar)}
        >
          <span className="block text-base font-bold">{t('ayarlar.sql.ortam.canli')}</span>
          <span className="mt-0.5 block text-xs">
            {canliVar ? t('ayarlar.sql.canliSecimAciklama') : t('ayarlar.sql.onceTanimla')}
          </span>
        </button>
      </div>

      <ConfirmDialog
        acik={onayBekleyen !== null}
        kapat={() => setOnayBekleyen(null)}
        baslik={t('ayarlar.sql.canliOnayBaslik')}
        mesaj={t('ayarlar.sql.canliOnayMesaj')}
        onayEtiketi={t('ayarlar.sql.canliOnayEtiket')}
        yukleniyor={degistir.isPending}
        onayla={() => {
          if (onayBekleyen !== null) {
            degistir.mutate(onayBekleyen)
          }
        }}
      />
    </div>
  )
}

export function SqlBaglantilariPage() {
  const { t } = useTranslation()

  const baglantilar = useQuery({
    queryKey: queryKeys.ayarlar.sqlBaglantilari,
    queryFn: sqlBaglantilariGetir,
  })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('ayarlar.sql.baslik')}</h2>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{t('ayarlar.sql.aciklama')}</p>

      {baglantilar.isError ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(baglantilar.error))}
            tekrarDene={() => void baglantilar.refetch()}
          />
        </div>
      ) : null}

      {baglantilar.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {baglantilar.isSuccess ? (
        <>
          <AktifOrtamBolumu
            aktifOrtam={baglantilar.data.aktif_ortam}
            testVar={baglantilar.data.test !== null}
            canliVar={baglantilar.data.canli !== null}
          />

          <div className="mt-5 grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
            <BaglantiFormu
              key={`test-${JSON.stringify(baglantilar.data.test)}`}
              ortam="test"
              tanim={baglantilar.data.test}
            />
            <BaglantiFormu
              key={`canli-${JSON.stringify(baglantilar.data.canli)}`}
              ortam="canli"
              tanim={baglantilar.data.canli}
            />
          </div>
        </>
      ) : null}
    </>
  )
}
