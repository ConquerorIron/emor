import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Input } from '@/components/Input'
import {
  type EntegratorBaglanti,
  type EntegratorOrtam,
  type ProtelBaglantiGovdesi,
  type ProtelBaglantilari,
  type ProtelSinamaSonucu,
  protelAktifOrtamDegistir,
  protelBaglantiGuncelle,
  protelBaglantiSina,
} from '@/features/ayarlar/entegratorApi'

import { adresGovdesi, API_ADRESI, ARALIK } from './adresKurallari'

function protelSemasi(sifreZorunlu: boolean) {
  const adres = z
    .string()
    .max(255)
    .refine(
      (deger) => deger.trim() === '' || API_ADRESI.test(deger.trim()),
      'ayarlar.entegrator.dogrulama.apiAdresiGecersiz',
    )

  return z.object({
    auth_url: adres,
    api_url: adres,
    kullanici_adi: z.email('ayarlar.protel.dogrulama.ePosta').max(128),
    sifre: z
      .string()
      .max(255)
      .refine(
        (deger) => !sifreZorunlu || deger !== '',
        'ayarlar.entegrator.dogrulama.sifreZorunlu',
      ),
    vkn: z.string().regex(/^\d{10,11}$/, 'ayarlar.entegrator.dogrulama.vknGecersiz'),
    senkron_araligi_dakika: z
      .number('ayarlar.protel.dogrulama.aralikGecersiz')
      .int('ayarlar.protel.dogrulama.aralikGecersiz')
      .min(ARALIK.enAz, 'ayarlar.protel.dogrulama.aralikGecersiz')
      .max(ARALIK.enCok, 'ayarlar.protel.dogrulama.aralikGecersiz'),
  })
}

type ProtelGirdisi = z.infer<ReturnType<typeof protelSemasi>>

function govde(girdi: ProtelGirdisi): ProtelBaglantiGovdesi {
  return {
    auth_url: adresGovdesi(girdi.auth_url),
    api_url: adresGovdesi(girdi.api_url),
    kullanici_adi: girdi.kullanici_adi,
    vkn: girdi.vkn,
    senkron_araligi_dakika: girdi.senkron_araligi_dakika,
    // Boş bırakılırsa gönderilmez — kullanıcı aynıysa sunucu kayıtlı şifreyi korur
    ...(girdi.sifre !== '' ? { sifre: girdi.sifre } : {}),
  }
}

function formAnahtari(tanim: EntegratorBaglanti | null): string {
  return tanim
    ? JSON.stringify([
        tanim.id,
        tanim.auth_url,
        tanim.auth_url_ozel,
        tanim.api_url,
        tanim.api_url_ozel,
        tanim.kullanici_adi,
        tanim.vkn,
        tanim.senkron_araligi_dakika,
        tanim.sifre_dolu,
      ])
    : 'yeni'
}

/**
 * Formun adres alanları kullanılan adresle DOLU gelir; yeni tanımda ortamın
 * varsayılanı yazılıdır — kullanıcı yalnız şifreyi girer (kullanıcı isteği
 * 2026-11-20). Alan boşaltılırsa yine varsayılan kullanılır.
 */
function formAdresleri(
  tanim: EntegratorBaglanti | null,
  varsayilanGiris: string,
  varsayilanFatura: string,
): Pick<ProtelGirdisi, 'auth_url' | 'api_url'> {
  return {
    auth_url: tanim?.auth_url ?? varsayilanGiris,
    api_url: tanim?.api_url ?? varsayilanFatura,
  }
}

function ProtelFormu({
  ortam,
  tanim,
  varsayilanGirisAdresi,
  varsayilanFaturaAdresi,
}: {
  ortam: EntegratorOrtam
  tanim: EntegratorBaglanti | null
  varsayilanGirisAdresi: string
  varsayilanFaturaAdresi: string
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [sinamaSonucu, setSinamaSonucu] = useState<ProtelSinamaSonucu | null>(null)
  const [sinamaHatasi, setSinamaHatasi] = useState<string | null>(null)
  const sinamaSurumu = useRef(0)

  const {
    register,
    handleSubmit,
    getValues,
    resetField,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ProtelGirdisi>({
    resolver: standardSchemaResolver(protelSemasi(!tanim?.sifre_dolu)),
    defaultValues: {
      ...formAdresleri(tanim, varsayilanGirisAdresi, varsayilanFaturaAdresi),
      kullanici_adi: tanim?.kullanici_adi ?? '',
      sifre: '',
      vkn: tanim?.vkn ?? '',
      senkron_araligi_dakika: tanim?.senkron_araligi_dakika ?? ARALIK.enAz,
    },
  })

  // Kayıt sonrası liste yenilenir; form tanımla anahtarlı olduğu için şifre alanı boşalır.
  // Kayıt sürerken alanlar salt okunur: yanıt (sıfırlama / yeniden kurulum) arada
  // yazılan değeri silmesin (PRT-R11)
  const kaydet = useMutation({
    mutationFn: (girdi: ProtelGirdisi) => protelBaglantiGuncelle(ortam, govde(girdi)),
    onSuccess: async () => {
      // Form yalnız şifre değiştiyse yeniden kurulmaz: girilen şifre alanda kalmasın (PRT-R6)
      resetField('sifre', { defaultValue: '' })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.entegratorBaglantilari })
      toast.success(t('ayarlar.protel.kaydedildi', { ortam: t(`ayarlar.sql.ortam.${ortam}`) }))
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const sina = useMutation({
    mutationFn: (_surum: number) => protelBaglantiSina(ortam, govde(getValues())),
    onSuccess: (sonuc, surum) => {
      if (surum !== sinamaSurumu.current) return
      setSinamaHatasi(null)
      setSinamaSonucu(sonuc)
    },
    onError: (error: unknown, surum) => {
      if (surum !== sinamaSurumu.current) return
      setSinamaSonucu(null)
      setSinamaHatasi(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
  })

  // Form değişince önceki sınama sonucu artık bu değerlere ait değildir
  const sinamayiTemizle = () => {
    sinamaSurumu.current += 1
    setSinamaSonucu(null)
    setSinamaHatasi(null)
  }

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  const alanHatasi = (anahtar?: string) => (anahtar ? t(anahtar) : undefined)

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      onChange={sinamayiTemizle}
      className="rounded-xl border border-slate-200 p-5 dark:border-slate-700"
      noValidate
      aria-label={t('ayarlar.protel.formBaslik', { ortam: t(`ayarlar.sql.ortam.${ortam}`) })}
    >
      <div className="mb-4 flex items-center justify-between gap-3">
        <h4 className="text-lg font-bold text-slate-800 dark:text-slate-100">
          {t(`ayarlar.sql.ortam.${ortam}`)}
        </h4>
        {tanim?.aktif ? (
          <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700 uppercase dark:bg-emerald-950 dark:text-emerald-300">
            {t('ayarlar.sql.aktifRozet')}
          </span>
        ) : null}
      </div>

      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Input
            id={`protel-${ortam}-giris-adresi`}
            label={t('ayarlar.protel.girisAdresi')}
            placeholder={varsayilanGirisAdresi}
            inputMode="url"
            autoComplete="off"
            hata={alanHatasi(errors.auth_url?.message)}
            readOnly={isSubmitting}
            {...register('auth_url')}
          />
          <p className="mt-1 text-xs break-all text-slate-500 dark:text-slate-400">
            {t('ayarlar.protel.adresNotu', { varsayilan: varsayilanGirisAdresi })}
          </p>
        </div>
        <div className="sm:col-span-2">
          <Input
            id={`protel-${ortam}-fatura-adresi`}
            label={t('ayarlar.protel.faturaAdresi')}
            placeholder={varsayilanFaturaAdresi}
            inputMode="url"
            autoComplete="off"
            hata={alanHatasi(errors.api_url?.message)}
            readOnly={isSubmitting}
            {...register('api_url')}
          />
          <p className="mt-1 text-xs break-all text-slate-500 dark:text-slate-400">
            {t('ayarlar.protel.adresNotu', { varsayilan: varsayilanFaturaAdresi })}
          </p>
        </div>
        <div className="sm:col-span-2">
          <Input
            id={`protel-${ortam}-kullanici`}
            label={t('ayarlar.protel.kullanici')}
            type="email"
            autoComplete="off"
            hata={alanHatasi(errors.kullanici_adi?.message)}
            readOnly={isSubmitting}
            {...register('kullanici_adi')}
          />
        </div>
        <Input
          id={`protel-${ortam}-sifre`}
          type="password"
          label={t('ayarlar.entegrator.sifre')}
          autoComplete="new-password"
          placeholder={tanim?.sifre_dolu ? '••••••••' : undefined}
          hata={alanHatasi(errors.sifre?.message)}
          readOnly={isSubmitting}
          {...register('sifre')}
        />
        <Input
          id={`protel-${ortam}-vkn`}
          label={t('ayarlar.entegrator.vkn')}
          inputMode="numeric"
          maxLength={11}
          autoComplete="off"
          hata={alanHatasi(errors.vkn?.message)}
          readOnly={isSubmitting}
          {...register('vkn')}
        />
      </div>

      <fieldset className="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
        <legend className="pr-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          {t('ayarlar.entegrator.senkronAyarlari')}
        </legend>
        <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
          <Input
            id={`protel-${ortam}-aralik`}
            type="number"
            label={t('ayarlar.entegrator.senkronAraligi')}
            min={ARALIK.enAz}
            max={ARALIK.enCok}
            step={1}
            hata={alanHatasi(errors.senkron_araligi_dakika?.message)}
            readOnly={isSubmitting}
            {...register('senkron_araligi_dakika', { valueAsNumber: true })}
          />
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.protel.senkronNotu')}
        </p>
      </fieldset>

      {tanim?.sifre_dolu ? (
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.entegrator.sifreNotu')}
        </p>
      ) : null}

      {errors.root?.message ? (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
          {errors.root.message}
        </p>
      ) : null}

      {sinamaSonucu ? (
        <div
          role="status"
          className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
        >
          <p className="font-semibold">
            {t('ayarlar.protel.sinamaBasarili', { firma: sinamaSonucu.firma_unvani })}
          </p>
          <p className="mt-1">
            {t('ayarlar.entegrator.tokenGecerlilik', {
              zaman: new Date(sinamaSonucu.gecerlilik_bitis).toLocaleString(),
            })}
          </p>
        </div>
      ) : null}

      {sinamaHatasi ? (
        <p
          role="alert"
          className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {sinamaHatasi}
        </p>
      ) : null}

      <div className="mt-4 flex justify-end gap-3">
        <Button
          type="button"
          variant="secondary"
          yukleniyor={sina.isPending}
          onClick={() => sina.mutate(sinamaSurumu.current)}
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

function ProtelAktifOrtam({ veri }: { veri: ProtelBaglantilari }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [canliOnayi, setCanliOnayi] = useState(false)

  const degistir = useMutation({
    mutationFn: (ortam: EntegratorOrtam) => protelAktifOrtamDegistir(ortam),
    onSuccess: async (tanim) => {
      // Eski ortamın listeleri önbellekte kalıp yeni ortamınmış gibi görünmesin
      queryClient.removeQueries({ queryKey: ['protel'] })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.entegratorBaglantilari })
      toast.success(
        t('ayarlar.protel.aktifYapildi', { ortam: t(`ayarlar.sql.ortam.${tanim.ortam}`) }),
      )
    },
    onError: (error: unknown) => {
      toast.error(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
    onSettled: () => setCanliOnayi(false),
  })

  const ortamlar: { ortam: EntegratorOrtam; tanimli: boolean }[] = [
    { ortam: 'test', tanimli: veri.test !== null },
    { ortam: 'canli', tanimli: veri.canli !== null },
  ]

  return (
    <div
      className="mt-4 flex flex-col gap-3 sm:flex-row"
      role="group"
      aria-label={t('ayarlar.protel.aktifOrtam')}
    >
      {ortamlar.map(({ ortam, tanimli }) => (
        <button
          key={ortam}
          type="button"
          aria-pressed={veri.aktif_ortam === ortam}
          disabled={!tanimli || degistir.isPending}
          onClick={() => {
            if (ortam === veri.aktif_ortam) return
            if (ortam === 'canli') {
              setCanliOnayi(true)
              return
            }
            degistir.mutate(ortam)
          }}
          className={`flex-1 cursor-pointer rounded-xl border-2 px-5 py-3 text-left transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
            veri.aktif_ortam === ortam
              ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:border-emerald-500 dark:bg-emerald-950 dark:text-emerald-300'
              : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
          }`}
        >
          <span className="block font-bold">{t(`ayarlar.sql.ortam.${ortam}`)}</span>
          <span className="mt-0.5 block text-xs">
            {tanimli ? t('ayarlar.protel.ortamSec') : t('ayarlar.sql.onceTanimla')}
          </span>
        </button>
      ))}

      <ConfirmDialog
        acik={canliOnayi}
        kapat={() => setCanliOnayi(false)}
        baslik={t('ayarlar.protel.canliOnayBaslik')}
        mesaj={t('ayarlar.protel.canliOnayMesaj')}
        onayEtiketi={t('ayarlar.sql.canliOnayEtiket')}
        yukleniyor={degistir.isPending}
        onayla={() => degistir.mutate('canli')}
      />
    </div>
  )
}

/**
 * Protel — otelin e-Fatura posta kutusu (PRT-03, PRT-05). Yalnız okunur.
 * Adresler ve senkron aralığı İzibiz formundaki gibi ekrandan girilir (boş
 * adres = varsayılan). Aktif ortam İzibiz'den bağımsızdır.
 */
export function ProtelBolumu({ veri }: { veri: ProtelBaglantilari }) {
  const { t } = useTranslation()

  return (
    <section className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-700">
      <h3 className="text-xl font-bold">{t('ayarlar.protel.baslik')}</h3>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.protel.aciklama')}
      </p>

      {!veri.modul_aktif ? (
        <p className="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          {t('ayarlar.protel.modulKapali')}
        </p>
      ) : null}

      <ProtelAktifOrtam veri={veri} />

      <div className="mt-5 grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
        <ProtelFormu
          key={`protel-test-${formAnahtari(veri.test)}`}
          ortam="test"
          tanim={veri.test}
          varsayilanGirisAdresi={veri.varsayilan_auth_url.test}
          varsayilanFaturaAdresi={veri.varsayilan_api_url.test}
        />
        <ProtelFormu
          key={`protel-canli-${formAnahtari(veri.canli)}`}
          ortam="canli"
          tanim={veri.canli}
          varsayilanGirisAdresi={veri.varsayilan_auth_url.canli}
          varsayilanFaturaAdresi={veri.varsayilan_api_url.canli}
        />
      </div>
    </section>
  )
}
