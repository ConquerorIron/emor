import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { SayiAlani } from '@/components/SayiAlani'
import { SelectField } from '@/components/SelectField'
import type { SecenekOgesi } from '@/components/selectStilleri'
import { TarihInput } from '@/components/TarihInput'

import type { IcmalGovdesi, IcmalSatiri, KesintiSecenekleri } from './icmalApi'
import {
  alanDegeri,
  satirTanimi,
  TANIMLAR,
  type AlanTuru,
  type FormAlani,
  type IcmalTanimi,
  type TanimAlani,
} from './icmalTanimi'

type FormDegerleri = Record<string, string>

/** Kuruş üzerinden fark: float yuvarlaması olmasın ("2562.15" − "100.00") */
function fark(a: string | undefined, b: string | undefined): string {
  return ((Math.round(Number(a || 0) * 100) - Math.round(Number(b || 0) * 100)) / 100).toFixed(2)
}

function formAlanlari<T extends IcmalSatiri>(tanim: IcmalTanimi<T>): string[] {
  return tanim.form.flatMap((bolum) =>
    bolum.alanlar.map((alan) => (typeof alan === 'string' ? alan : alan.alan)),
  )
}

/** Form anahtarı: tanımlar id ile (taşeron → firma_id), diğerleri alan adıyla */
function formAnahtari(alan: string, tur: AlanTuru): string {
  return tur.tur === 'tanim' ? TANIMLAR[alan as TanimAlani].anahtar : alan
}

/** Zorunlular ve metin sınırları; backend aynı kuralları tekrar doğrular */
function semaUret(alanlar: [string, AlanTuru][]) {
  return z.record(z.string(), z.string()).superRefine((veri, ctx) => {
    for (const [alan, tur] of alanlar) {
      const anahtar = formAnahtari(alan, tur)
      const deger = (veri[anahtar] ?? '').trim()
      const zorunlu = tur.tur === 'metin' ? tur.zorunlu === true : tur.tur !== 'sayi'

      if (zorunlu && deger === '') {
        ctx.addIssue({
          code: 'custom',
          path: [anahtar],
          message: 'kesinti.icmal.dogrulama.zorunlu',
        })
      } else if (tur.tur === 'metin' && deger.length > tur.sinir) {
        ctx.addIssue({
          code: 'custom',
          path: [anahtar],
          message: `kesinti.icmal.dogrulama.cokUzun${tur.sinir}`,
        })
      }
    }
  })
}

/** Elle satır girişi / düzenleme: alanlar ve bölümler icmal tanımından çizilir */
export function IcmalFormu<T extends IcmalSatiri>({
  tanim,
  satir,
  secenekler,
  kapat,
}: {
  tanim: IcmalTanimi<T>
  satir: T | null
  secenekler: KesintiSecenekleri
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // Hesaplanan tutarlar formda girilmez, gönderilmez
  const girilenler = formAlanlari(tanim)
    .map((alan): [string, AlanTuru] => [alan, tanim.alanlar[alan]])
    .filter(([, tur]) => tur.tur !== 'hesap')

  const varsayilan = (alan: string, tur: AlanTuru): string => {
    if (tur.tur === 'tanim') {
      if (satir !== null) {
        return String(satirTanimi(satir, alan as TanimAlani).id)
      }

      // Yeni kayıtta Excel'lerdeki tek para birimi olan TL önerilir
      return alan === 'para_birimi'
        ? String(secenekler.para_birimleri.find((para) => para.kod === 'TL')?.id ?? '')
        : ''
    }

    const deger = satir === null ? null : alanDegeri(satir, alan)

    if (tur.tur === 'sayi') {
      return typeof deger === 'string' ? deger : (0).toFixed(tur.basamak)
    }

    return typeof deger === 'string' ? deger : ''
  }

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<FormDegerleri>({
    resolver: standardSchemaResolver(semaUret(girilenler)),
    defaultValues: Object.fromEntries(
      girilenler.map(([alan, tur]) => [formAnahtari(alan, tur), varsayilan(alan, tur)]),
    ),
  })

  // Elle girişte hesaplanır (kullanıcı kararı): yansıtılan = toplam − iptal, kalan = yansıtılan − kesilen
  const [toplam, iptal, kesilen] = useWatch({
    control,
    name: ['toplam_tutar', 'iptal_edilen_tutar', 'kesilen_tutar'],
  })
  const hesaplananlar: Record<string, string> = { yansitilan_tutar: fark(toplam, iptal) }
  hesaplananlar.kalan_tutar = fark(hesaplananlar.yansitilan_tutar, kesilen)

  const kaydet = useMutation({
    mutationFn: (girdi: FormDegerleri) => {
      const govde: IcmalGovdesi = {}

      for (const [alan, tur] of girilenler) {
        const anahtar = formAnahtari(alan, tur)
        const deger = girdi[anahtar] ?? ''
        govde[anahtar] =
          tur.tur === 'tanim'
            ? Number(deger)
            : tur.tur === 'metin' && deger.trim() === ''
              ? null
              : deger
      }

      return tanim.api.kaydet(satir?.id ?? null, govde)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: queryKeys.kesinti.icmalListeleri(tanim.anahtar),
      })
      toast.success(t('kesinti.icmal.kaydedildi'))
      kapat()
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  const alanCiz = (formAlani: FormAlani) => {
    const alan = typeof formAlani === 'string' ? formAlani : formAlani.alan
    const tur = tanim.alanlar[alan]
    const anahtar = formAnahtari(alan, tur)
    const id = `${tanim.anahtar}-${anahtar}`
    const etiket = t(`kesinti.alan.${alan}`)
    const mesaj = errors[anahtar]?.message
    const hata = mesaj ? t(mesaj) : undefined

    const secim = (liste: SecenekOgesi[], aranabilir: boolean) => (
      <Controller
        control={control}
        name={anahtar}
        render={({ field }) => (
          <SelectField
            id={id}
            label={etiket}
            options={liste}
            value={liste.find((secenek) => secenek.value === field.value) ?? null}
            onChange={(secenek) => field.onChange(secenek?.value ?? '')}
            isSearchable={aranabilir}
            hata={hata}
          />
        )}
      />
    )

    const icerik = (() => {
      switch (tur.tur) {
        case 'tanim':
          return secim(
            secenekler[TANIMLAR[alan as TanimAlani].liste].map((kayit) => ({
              value: String(kayit.id),
              label: `${kayit.kod} — ${kayit.ad}`,
            })),
            true,
          )
        case 'secenek':
          return secim(
            tur.degerler.map((deger) => ({
              value: deger,
              label: t(`kesinti.secenek.${alan}.${deger}`),
            })),
            false,
          )
        case 'tarih':
          return (
            <Controller
              control={control}
              name={anahtar}
              render={({ field }) => (
                <TarihInput
                  id={id}
                  label={etiket}
                  value={field.value}
                  onChange={field.onChange}
                  hata={hata}
                />
              )}
            />
          )
        case 'metin':
          return (
            <Input
              id={id}
              label={etiket}
              maxLength={tur.sinir}
              hata={hata}
              {...register(anahtar)}
            />
          )
        case 'sayi':
          return (
            <Controller
              control={control}
              name={anahtar}
              render={({ field }) => (
                <SayiAlani
                  id={id}
                  label={etiket}
                  value={field.value}
                  onChange={field.onChange}
                  ondalik={tur.basamak}
                />
              )}
            />
          )
        case 'hesap':
          return (
            <SayiAlani
              id={id}
              label={etiket}
              value={hesaplananlar[alan] ?? '0.00'}
              onChange={() => undefined}
              ondalik={2}
              disabled
            />
          )
      }
    })()

    return typeof formAlani === 'string' ? (
      <div key={alan}>{icerik}</div>
    ) : (
      <div key={alan} className="sm:col-span-2">
        {icerik}
      </div>
    )
  }

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-4">
        {tanim.form.map((bolum) => [
          <h3
            key={bolum.baslik}
            className="text-sm font-semibold tracking-wide text-slate-500 uppercase sm:col-span-4 dark:text-slate-400"
          >
            {t(bolum.baslik)}
          </h3>,
          ...bolum.alanlar.map(alanCiz),
        ])}
        <p className="text-xs text-slate-500 sm:col-span-4 dark:text-slate-400">
          {t('kesinti.icmal.hesapAciklamasi')}
        </p>
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
