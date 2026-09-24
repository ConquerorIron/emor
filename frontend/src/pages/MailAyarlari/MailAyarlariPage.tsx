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
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SelectField } from '@/components/SelectField'
import {
  mailAyariGetir,
  mailAyariGuncelle,
  testMailiGonder,
  type MailAyarGovdesi,
  type MailAyari,
  type MailSifreleme,
} from '@/features/ayarlar/mailApi'
import { useAuth } from '@/hooks/useAuth'
import { useIzin } from '@/hooks/useIzin'

const SIFRELEMELER: MailSifreleme[] = ['tls', 'ssl', 'yok']

const EPOSTA = z.email('ayarlar.mail.dogrulama.epostaGecersiz')

const ayarSemasi = z.object({
  sunucu: z
    .string()
    .min(1, 'ayarlar.mail.dogrulama.sunucuZorunlu')
    .max(255)
    .regex(/^[A-Za-z0-9.-]+$/, 'ayarlar.mail.dogrulama.sunucuGecersiz'),
  port: z
    .string()
    .refine(
      (deger) => /^\d+$/.test(deger) && Number(deger) >= 1 && Number(deger) <= 65535,
      'ayarlar.mail.dogrulama.portGecersiz',
    ),
  sifreleme: z.enum(SIFRELEMELER),
  kullanici_adi: z.string().max(255),
  sifre: z.string().max(255),
  gonderen_adres: EPOSTA,
  gonderen_ad: z.string().min(1, 'ayarlar.mail.dogrulama.gonderenAdZorunlu').max(120),
  yonlendirme_adresi: z.union([z.literal(''), EPOSTA]),
})

type AyarGirdisi = z.infer<typeof ayarSemasi>

function tanimdanGirdi(ayar: MailAyari | null): AyarGirdisi {
  return {
    sunucu: ayar?.sunucu ?? '',
    port: ayar ? String(ayar.port) : '587',
    sifreleme: ayar?.sifreleme ?? 'tls',
    kullanici_adi: ayar?.kullanici_adi ?? '',
    sifre: '',
    gonderen_adres: ayar?.gonderen_adres ?? '',
    gonderen_ad: ayar?.gonderen_ad ?? '',
    yonlendirme_adresi: ayar?.yonlendirme_adresi ?? '',
  }
}

function girdidenGovde(girdi: AyarGirdisi): MailAyarGovdesi {
  return {
    sunucu: girdi.sunucu,
    port: Number(girdi.port),
    sifreleme: girdi.sifreleme,
    kullanici_adi: girdi.kullanici_adi === '' ? null : girdi.kullanici_adi,
    gonderen_adres: girdi.gonderen_adres,
    gonderen_ad: girdi.gonderen_ad,
    yonlendirme_adresi: girdi.yonlendirme_adresi === '' ? null : girdi.yonlendirme_adresi,
    // Boş bırakılırsa gönderilmez — hedef değişmediyse backend kayıtlı şifreyi korur
    ...(girdi.sifre !== '' ? { sifre: girdi.sifre } : {}),
  }
}

function AyarFormu({ ayar }: { ayar: MailAyari | null }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('mail_ayarlari.guncelle')

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<AyarGirdisi>({
    resolver: standardSchemaResolver(ayarSemasi),
    defaultValues: tanimdanGirdi(ayar),
  })

  const kaydet = useMutation({
    mutationFn: (girdi: AyarGirdisi) => mailAyariGuncelle(girdidenGovde(girdi)),
    onSuccess: async (kaydedilen) => {
      // Şifre alanı kayıttan sonra boşalır; kayıtlı olduğu rozetle gösterilir
      reset(tanimdanGirdi(kaydedilen))
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.mail })
      toast.success(t('ayarlar.mail.kaydedildi'))
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  const alanHatasi = (anahtar?: string) => (anahtar ? t(anahtar) : undefined)
  const secenekler = SIFRELEMELER.map((deger) => ({
    value: deger,
    label: t(`ayarlar.mail.sifreleme.${deger}`),
  }))

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      className="mt-5 rounded-xl border border-slate-200 p-5 dark:border-slate-700"
      noValidate
    >
      <h3 className="mb-4 text-lg font-bold text-slate-800 dark:text-slate-100">
        {t('ayarlar.mail.sunucuBaslik')}
      </h3>

      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <Input
          id="mail-sunucu"
          label={t('ayarlar.mail.sunucu')}
          placeholder="smtp.office365.com"
          autoComplete="off"
          hata={alanHatasi(errors.sunucu?.message)}
          {...register('sunucu')}
        />
        <Input
          id="mail-port"
          label={t('ayarlar.mail.port')}
          inputMode="numeric"
          maxLength={5}
          hata={alanHatasi(errors.port?.message)}
          {...register('port')}
        />
        <Controller
          control={control}
          name="sifreleme"
          render={({ field }) => (
            <SelectField
              id="mail-sifreleme"
              label={t('ayarlar.mail.sifrelemeEtiket')}
              options={secenekler}
              isSearchable={false}
              // react-select fieldset'in pasifliğini tanımaz (menü portalda açılır)
              disabled={!guncelleyebilir}
              value={secenekler.find((secenek) => secenek.value === field.value) ?? null}
              onChange={(secilen) => field.onChange(secilen?.value ?? 'tls')}
            />
          )}
        />
        <Input
          id="mail-kullanici"
          label={t('ayarlar.mail.kullaniciAdi')}
          autoComplete="off"
          hata={alanHatasi(errors.kullanici_adi?.message)}
          {...register('kullanici_adi')}
        />
        <Input
          id="mail-sifre"
          type="password"
          label={t('ayarlar.mail.sifre')}
          autoComplete="new-password"
          placeholder={ayar?.sifre_dolu ? '••••••••' : undefined}
          hata={alanHatasi(errors.sifre?.message)}
          {...register('sifre')}
        />
      </div>

      <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
        {ayar?.sifre_dolu ? t('ayarlar.mail.sifreNotu') : t('ayarlar.mail.sifreGirilmedi')}
      </p>

      <h3 className="mt-6 mb-4 text-lg font-bold text-slate-800 dark:text-slate-100">
        {t('ayarlar.mail.gonderenBaslik')}
      </h3>

      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <Input
          id="mail-gonderen-adres"
          type="email"
          label={t('ayarlar.mail.gonderenAdres')}
          autoComplete="off"
          hata={alanHatasi(errors.gonderen_adres?.message)}
          {...register('gonderen_adres')}
        />
        <Input
          id="mail-gonderen-ad"
          label={t('ayarlar.mail.gonderenAd')}
          autoComplete="off"
          hata={alanHatasi(errors.gonderen_ad?.message)}
          {...register('gonderen_ad')}
        />
        <div className="sm:col-span-2">
          <Input
            id="mail-yonlendirme"
            type="email"
            label={t('ayarlar.mail.yonlendirmeAdresi')}
            autoComplete="off"
            hata={alanHatasi(errors.yonlendirme_adresi?.message)}
            {...register('yonlendirme_adresi')}
          />
          <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {t('ayarlar.mail.yonlendirmeAciklama')}
          </p>
        </div>
      </div>

      {errors.root?.message ? (
        <p
          role="alert"
          className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}

      <div className="mt-4 flex justify-end">
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

function TestMailiBolumu({ tanimli }: { tanimli: boolean }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [alici, setAlici] = useState(user?.email ?? '')
  const [sonuc, setSonuc] = useState<{ basarili: boolean; mesaj: string } | null>(null)

  const gonder = useMutation({
    mutationFn: () => testMailiGonder(alici),
    onSuccess: () =>
      setSonuc({ basarili: true, mesaj: t('ayarlar.mail.testGonderildi', { alici }) }),
    onError: (error: unknown) =>
      setSonuc({ basarili: false, mesaj: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) }),
  })

  return (
    <div className="mt-5 rounded-xl border border-slate-200 p-5 dark:border-slate-700">
      <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100">
        {t('ayarlar.mail.testBaslik')}
      </h3>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {t('ayarlar.mail.testAciklama')}
      </p>

      <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <div className="flex-1">
          <Input
            id="mail-test-alici"
            type="email"
            label={t('ayarlar.mail.testAlici')}
            value={alici}
            onChange={(e) => {
              setAlici(e.target.value)
              setSonuc(null)
            }}
          />
        </div>
        <Button
          type="button"
          variant="secondary"
          disabled={!tanimli || alici === ''}
          yukleniyor={gonder.isPending}
          onClick={() => gonder.mutate()}
        >
          {t('ayarlar.mail.testGonder')}
        </Button>
      </div>

      {sonuc ? (
        <p
          role={sonuc.basarili ? 'status' : 'alert'}
          className={`mt-3 rounded-lg px-3 py-2 text-sm break-words ${
            sonuc.basarili
              ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
              : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
          }`}
        >
          {sonuc.mesaj}
        </p>
      ) : null}
    </div>
  )
}

export function MailAyarlariPage() {
  const { t } = useTranslation()
  const guncelleyebilir = useIzin('mail_ayarlari.guncelle')

  const ayar = useQuery({
    queryKey: queryKeys.ayarlar.mail,
    queryFn: mailAyariGetir,
  })

  return (
    <>
      <h2 className="text-2xl font-bold">{t('ayarlar.mail.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.mail.aciklama')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      {ayar.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(ayar.error))} tekrarDene={() => void ayar.refetch()} />
        </div>
      ) : null}

      {ayar.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {ayar.isSuccess ? (
        // Yalnız görüntüleme izninde kayıt ve test maili pasiftir
        <fieldset disabled={!guncelleyebilir} className="max-w-3xl min-w-0">
          {ayar.data?.yonlendirme_adresi ? (
            <p
              role="note"
              className="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
            >
              {t('ayarlar.mail.yonlendirmeAktif', { adres: ayar.data.yonlendirme_adresi })}
            </p>
          ) : null}
          <AyarFormu key={ayar.data?.updated_at ?? 'yeni'} ayar={ayar.data} />
          <TestMailiBolumu tanimli={ayar.data !== null} />
        </fieldset>
      ) : null}
    </>
  )
}
