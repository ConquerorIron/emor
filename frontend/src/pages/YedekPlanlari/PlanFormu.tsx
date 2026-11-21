import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Controller, useFieldArray, useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { Switch } from '@/components/Switch'
import { TarihInput } from '@/components/TarihInput'
import {
  alanHataMetni,
  dogrulamaHatalari,
  hataGovdesi,
  istanbulZamani,
} from '@/features/yedekleme/bicim'
import type { Hedef } from '@/features/yedekleme/hedefApi'

import {
  planKaydet,
  planOnizle,
  SIKLIK_TURLERI,
  type Plan,
  type PlanGovdesi,
  type PlanOnizlemesi,
  type Siklik,
  type SiklikTuru,
} from './planApi'

const SAAT = /^([01]\d|2[0-3]):[0-5]\d$/
const TAM_SAYI = /^\d+$/
const GUNLER = [1, 2, 3, 4, 5, 6, 7] as const

/** Backend sınırları (YedekPlaniController) */
const SINIR = {
  saatlikAralik: 24,
  gunlukAralik: 366,
  sonAdet: 1000,
  gun: 3650,
} as const

/** Ayın 29–31'i bazı aylarda yok: o aylarda ayın son günü çalışır */
const AY_SONU_ESIGI = 29

interface PlanGirdisi {
  ad: string
  aktif: boolean
  siklik_turu: SiklikTuru
  saat: string
  aralik: string
  saat_baslangic: string
  saat_bitis: string
  gunler: number[]
  baslangic_tarihi: string
  ayin_gunu: string
  hedefler: { hedef_id: string; sakla_son_adet: string; sakla_gun: string }[]
}

function aralikta(deger: string, en: number, cok: number): boolean {
  return TAM_SAYI.test(deger) && Number(deger) >= en && Number(deger) <= cok
}

const planSemasi = z
  .object({
    ad: z
      .string()
      .trim()
      .min(1, 'yedekleme.plan.dogrulama.adZorunlu')
      .max(100, 'yedekleme.plan.dogrulama.adUzun'),
    aktif: z.boolean(),
    siklik_turu: z.enum(SIKLIK_TURLERI),
    saat: z.string().regex(SAAT, 'yedekleme.plan.dogrulama.saat'),
    aralik: z.string(),
    saat_baslangic: z.string(),
    saat_bitis: z.string(),
    gunler: z.array(z.number()),
    baslangic_tarihi: z.string(),
    ayin_gunu: z.string(),
    hedefler: z.array(
      z.object({ hedef_id: z.string(), sakla_son_adet: z.string(), sakla_gun: z.string() }),
    ),
  })
  .superRefine((girdi, ctx) => {
    const hata = (path: (string | number)[], message: string) =>
      ctx.addIssue({ code: 'custom', path, message })

    if (girdi.siklik_turu === 'saatlik') {
      if (!aralikta(girdi.aralik, 1, SINIR.saatlikAralik)) {
        hata(['aralik'], 'yedekleme.plan.dogrulama.saatlikAralik')
      }
      for (const alan of ['saat_baslangic', 'saat_bitis'] as const) {
        if (girdi[alan] !== '' && !SAAT.test(girdi[alan])) {
          hata([alan], 'yedekleme.plan.dogrulama.saat')
        }
      }
    }
    if (girdi.siklik_turu === 'n_gunluk' && !aralikta(girdi.aralik, 1, SINIR.gunlukAralik)) {
      hata(['aralik'], 'yedekleme.plan.dogrulama.gunlukAralik')
    }
    if (girdi.siklik_turu === 'haftalik' && girdi.gunler.length === 0) {
      hata(['gunler'], 'yedekleme.plan.dogrulama.gunSec')
    }
    if (girdi.siklik_turu === 'aylik' && !aralikta(girdi.ayin_gunu, 1, 31)) {
      hata(['ayin_gunu'], 'yedekleme.plan.dogrulama.ayinGunu')
    }

    // Hedefsiz plan kaydedilmez; her hedefin saklama kuralı boş olamaz
    if (girdi.hedefler.length === 0) {
      hata(['hedefler'], 'yedekleme.plan.dogrulama.hedefZorunlu')
    }
    const gorulen = new Set<string>()
    girdi.hedefler.forEach((hedef, sira) => {
      if (hedef.hedef_id === '') {
        hata(['hedefler', sira, 'hedef_id'], 'yedekleme.plan.dogrulama.hedefSec')
      } else if (gorulen.has(hedef.hedef_id)) {
        hata(['hedefler', sira, 'hedef_id'], 'yedekleme.plan.dogrulama.hedefTekrar')
      }
      gorulen.add(hedef.hedef_id)

      const adet = hedef.sakla_son_adet.trim()
      const gun = hedef.sakla_gun.trim()
      if (adet === '' && gun === '') {
        hata(['hedefler', sira, 'sakla_son_adet'], 'yedekleme.plan.dogrulama.saklamaZorunlu')
      }
      if (adet !== '' && !aralikta(adet, 1, SINIR.sonAdet)) {
        hata(['hedefler', sira, 'sakla_son_adet'], 'yedekleme.plan.dogrulama.sonAdet')
      }
      if (gun !== '' && !aralikta(gun, 1, SINIR.gun)) {
        hata(['hedefler', sira, 'sakla_gun'], 'yedekleme.plan.dogrulama.gun')
      }
    })
  })

function formDegerleri(plan: Plan | null): PlanGirdisi {
  const siklik = plan?.siklik ?? {}

  return {
    ad: plan?.ad ?? '',
    aktif: plan?.aktif ?? true,
    siklik_turu: plan?.siklik_turu ?? 'gunluk',
    saat: plan?.saat ?? '02:00',
    aralik: siklik.aralik === undefined ? '' : String(siklik.aralik),
    saat_baslangic: siklik.saat_baslangic ?? '',
    saat_bitis: siklik.saat_bitis ?? '',
    gunler: siklik.gunler ?? [],
    baslangic_tarihi: siklik.baslangic_tarihi ?? '',
    ayin_gunu: siklik.ayin_gunu === undefined ? '' : String(siklik.ayin_gunu),
    // Saklama kuralı bilinçli girilir: yeni hedef satırı boş gelir
    hedefler: plan?.hedefler.map((hedef) => ({
      hedef_id: String(hedef.hedef_id),
      sakla_son_adet: hedef.sakla_son_adet === null ? '' : String(hedef.sakla_son_adet),
      sakla_gun: hedef.sakla_gun === null ? '' : String(hedef.sakla_gun),
    })) ?? [{ hedef_id: '', sakla_son_adet: '', sakla_gun: '' }],
  }
}

/** Gövdede yalnız sıklık türünün anlamlı alanları gider */
function siklikGovdesi(girdi: PlanGirdisi): Siklik {
  switch (girdi.siklik_turu) {
    case 'saatlik':
      return {
        aralik: Number(girdi.aralik),
        ...(girdi.saat_baslangic !== '' ? { saat_baslangic: girdi.saat_baslangic } : {}),
        ...(girdi.saat_bitis !== '' ? { saat_bitis: girdi.saat_bitis } : {}),
        ...(girdi.gunler.length > 0 ? { gunler: girdi.gunler.toSorted((a, b) => a - b) } : {}),
      }
    case 'gunluk':
      return {}
    case 'n_gunluk':
      return {
        aralik: Number(girdi.aralik),
        ...(girdi.baslangic_tarihi !== '' ? { baslangic_tarihi: girdi.baslangic_tarihi } : {}),
      }
    case 'haftalik':
      return { gunler: girdi.gunler.toSorted((a, b) => a - b) }
    case 'aylik':
      return { ayin_gunu: Number(girdi.ayin_gunu) }
  }
}

function govdeUret(girdi: PlanGirdisi): PlanGovdesi {
  const sayi = (deger: string) => (deger.trim() === '' ? null : Number(deger.trim()))

  return {
    ad: girdi.ad.trim(),
    aktif: girdi.aktif,
    siklik_turu: girdi.siklik_turu,
    saat: girdi.saat,
    siklik: siklikGovdesi(girdi),
    hedefler: girdi.hedefler.map((hedef) => ({
      hedef_id: Number(hedef.hedef_id),
      sakla_son_adet: sayi(hedef.sakla_son_adet),
      sakla_gun: sayi(hedef.sakla_gun),
    })),
  }
}

function GunSecici({
  secili,
  degistir,
  hata,
  kilitli,
}: {
  secili: number[]
  degistir: (gunler: number[]) => void
  hata?: string
  kilitli: boolean
}) {
  const { t } = useTranslation()

  return (
    <fieldset className="sm:col-span-2">
      <legend className="text-sm font-semibold text-slate-700 dark:text-slate-300">
        {t('yedekleme.plan.gunler')}
      </legend>
      <div className="mt-1 flex flex-wrap gap-2">
        {GUNLER.map((gun) => {
          const acik = secili.includes(gun)

          return (
            <button
              key={gun}
              type="button"
              aria-pressed={acik}
              disabled={kilitli}
              onClick={() =>
                degistir(acik ? secili.filter((aday) => aday !== gun) : [...secili, gun])
              }
              className={`cursor-pointer rounded-lg border px-3 py-1.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                acik
                  ? 'border-blue-600 bg-blue-600 text-white dark:border-blue-500 dark:bg-blue-500'
                  : 'border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800'
              }`}
            >
              {t(`yedekleme.plan.gunKisa.${gun}`)}
            </button>
          )
        })}
      </div>
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </fieldset>
  )
}

function OnizlemePaneli({
  onizleme,
  hedefAdi,
}: {
  onizleme: PlanOnizlemesi
  hedefAdi: (id: number | null) => string
}) {
  const { t } = useTranslation()

  return (
    <section
      aria-label={t('yedekleme.plan.onizleme.baslik')}
      className="space-y-3 rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700"
    >
      <div>
        <h4 className="font-semibold">{t('yedekleme.plan.onizleme.siradakiler')}</h4>
        {onizleme.siradakiler.length === 0 ? (
          <p className="text-slate-500 dark:text-slate-400">{t('yedekleme.plan.onizleme.yok')}</p>
        ) : (
          <ol className="mt-1 list-decimal pl-5 tabular-nums">
            {onizleme.siradakiler.map((an) => (
              <li key={an}>{istanbulZamani(an)}</li>
            ))}
          </ol>
        )}
      </div>

      {onizleme.ay_sonu_uyarisi ? (
        <p
          role="alert"
          className="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('yedekleme.plan.aySonuUyarisi')}
        </p>
      ) : null}

      {onizleme.cakisan_planlar.length > 0 ? (
        <div
          role="alert"
          className="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          <p className="font-semibold">{t('yedekleme.plan.onizleme.cakisanlar')}</p>
          <ul className="mt-1 list-disc pl-5">
            {onizleme.cakisan_planlar.map((plan) => (
              <li key={plan.id}>
                {plan.ad} — {istanbulZamani(plan.an)}
              </li>
            ))}
          </ul>
          <p className="mt-1 text-xs">{t('yedekleme.plan.onizleme.cakismaAciklama')}</p>
        </div>
      ) : null}

      <div>
        <h4 className="font-semibold">{t('yedekleme.plan.onizleme.silinecekler')}</h4>
        {onizleme.silinecekler.length === 0 ? (
          <p className="text-slate-500 dark:text-slate-400">
            {t('yedekleme.plan.onizleme.silinecekYok')}
          </p>
        ) : (
          <ul className="mt-1 space-y-1">
            {onizleme.silinecekler.map((karar) => (
              <li
                key={`${karar.tur}-${karar.yedek_id}-${karar.hedef_id ?? 'klasor'}`}
                className="rounded-lg bg-red-50 px-3 py-1.5 text-red-800 dark:bg-red-950 dark:text-red-200"
              >
                <span className="font-mono text-xs break-all">{karar.dosya_adi ?? '—'}</span>{' '}
                <span className="tabular-nums">({istanbulZamani(karar.alinma_zamani)})</span>
                <span className="block text-xs">
                  {hedefAdi(karar.hedef_id)} ·{' '}
                  {t(`yedekleme.plan.neden.${karar.neden}`, { defaultValue: karar.neden })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

/**
 * Plan formu (BY-017): sıklık, saat (İstanbul), hedefler ve hedef başına
 * saklama kuralı. "Önizle" kaydetmeden sıradaki çalışmaları, uyarıları ve bu
 * kuralla silinecekleri gösterir; form değişince önizleme düşer (eski kurala
 * ait sonuç ekranda kalmasın).
 */
export function PlanFormu({
  plan,
  hedefler,
  kapat,
}: {
  plan: Plan | null
  hedefler: Hedef[]
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [onizleme, setOnizleme] = useState<PlanOnizlemesi | null>(null)
  const [kopyaliHedefler, setKopyaliHedefler] = useState<
    { hedef_id: number; ad: string; kopya_sayisi: number }[]
  >([])
  const onizlemeSurumu = useRef(0)

  const {
    register,
    control,
    handleSubmit,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<PlanGirdisi>({
    resolver: standardSchemaResolver(planSemasi),
    defaultValues: formDegerleri(plan),
  })
  const { fields, append, remove } = useFieldArray({ control, name: 'hedefler' })

  // Her değişiklik önceki önizlemeyi geçersiz kılar
  useEffect(() => {
    const abonelik = watch(() => {
      onizlemeSurumu.current += 1
      setOnizleme(null)
      setKopyaliHedefler([])
    })

    return () => abonelik.unsubscribe()
  }, [watch])

  const siklikTuru = watch('siklik_turu')
  const ayinGunu = watch('ayin_gunu')

  const hedefSecenekleri: SecenekOgesi[] = hedefler.map((hedef) => ({
    value: String(hedef.id),
    label: `${hedef.ad} (${t(`yedekleme.hedef.tur.${hedef.tur}`, { defaultValue: hedef.tur })})${
      hedef.aktif ? '' : ` — ${t('yedekleme.hedef.pasif')}`
    }`,
  }))
  const hedefAdi = (id: number | null) =>
    id === null
      ? t('yedekleme.plan.onizleme.yedekKlasoru')
      : (hedefler.find((hedef) => hedef.id === id)?.ad ?? `#${id}`)
  const siklikSecenekleri: SecenekOgesi[] = SIKLIK_TURLERI.map((tur) => ({
    value: tur,
    label: t(`yedekleme.plan.siklikTuru.${tur}`),
  }))

  const sunucuHatalariniDagit = (error: unknown) => {
    const govde = hataGovdesi<{
      hedefler?: { hedef_id: number; ad: string; kopya_sayisi: number }[]
    }>(error)
    if (govde?.kod === 'PLAN_HEDEFI_KOPYALI' && govde.hedefler) {
      setKopyaliHedefler(govde.hedefler)
      return
    }

    let dagitildi = false
    for (const [anahtar, mesajlar] of Object.entries(dogrulamaHatalari(error))) {
      const alan = anahtar.startsWith('siklik.') ? anahtar.slice('siklik.'.length) : anahtar
      if (
        ['ad', 'saat', 'aralik', 'saat_baslangic', 'saat_bitis', 'gunler', 'ayin_gunu'].includes(
          alan,
        ) ||
        /^hedefler\.\d+\.(hedef_id|sakla_son_adet|sakla_gun)$/.test(alan)
      ) {
        setError(alan as 'ad', { message: mesajlar[0] })
        dagitildi = true
      }
    }
    if (!dagitildi) {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    }
  }

  const kaydet = useMutation({
    mutationFn: (girdi: PlanGirdisi) => planKaydet(plan?.id ?? null, govdeUret(girdi)),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.planlar })
      toast.success(t('yedekleme.plan.kaydedildi'))
      kapat()
    },
    onError: sunucuHatalariniDagit,
  })

  const onizle = useMutation({
    mutationFn: ({ girdi }: { girdi: PlanGirdisi; surum: number }) =>
      planOnizle(plan?.id ?? null, govdeUret(girdi)),
    onSuccess: (sonuc, { surum }) => {
      if (surum === onizlemeSurumu.current) {
        setOnizleme(sonuc)
      }
    },
    onError: sunucuHatalariniDagit,
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })
  const onizlemeIste = handleSubmit((girdi) =>
    onizle.mutate({ girdi, surum: onizlemeSurumu.current }),
  )

  const hata = (mesaj: string | undefined) => alanHataMetni(t, mesaj)
  const hedeflerHatasi = errors.hedefler?.root?.message ?? errors.hedefler?.message

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <Input
          id="plan-ad"
          label={`${t('yedekleme.plan.ad')} *`}
          maxLength={100}
          hata={hata(errors.ad?.message)}
          readOnly={isSubmitting}
          {...register('ad')}
        />
        <Controller
          control={control}
          name="aktif"
          render={({ field }) => (
            <Switch
              id="plan-aktif"
              label={t('yedekleme.plan.aktif')}
              checked={field.value}
              disabled={isSubmitting}
              onChange={field.onChange}
            />
          )}
        />
        <Controller
          control={control}
          name="siklik_turu"
          render={({ field }) => (
            <SelectField
              id="plan-siklik"
              label={t('yedekleme.plan.siklik')}
              options={siklikSecenekleri}
              value={siklikSecenekleri.find((secenek) => secenek.value === field.value) ?? null}
              onChange={(secenek) => {
                if (secenek) {
                  field.onChange(secenek.value)
                }
              }}
              isSearchable={false}
              disabled={isSubmitting}
            />
          )}
        />
        <Input
          id="plan-saat"
          label={`${t(siklikTuru === 'saatlik' ? 'yedekleme.plan.ilkSaat' : 'yedekleme.plan.saat')} *`}
          placeholder="02:00"
          inputMode="numeric"
          maxLength={5}
          hata={hata(errors.saat?.message)}
          readOnly={isSubmitting}
          {...register('saat')}
        />

        {siklikTuru === 'saatlik' || siklikTuru === 'n_gunluk' ? (
          <Input
            id="plan-aralik"
            label={`${t(
              siklikTuru === 'saatlik' ? 'yedekleme.plan.saatAraligi' : 'yedekleme.plan.gunAraligi',
            )} *`}
            inputMode="numeric"
            hata={hata(errors.aralik?.message)}
            readOnly={isSubmitting}
            {...register('aralik')}
          />
        ) : null}

        {siklikTuru === 'saatlik' ? (
          <>
            <Input
              id="plan-saat-baslangic"
              label={t('yedekleme.plan.saatBaslangic')}
              placeholder="08:00"
              inputMode="numeric"
              maxLength={5}
              hata={hata(errors.saat_baslangic?.message)}
              readOnly={isSubmitting}
              {...register('saat_baslangic')}
            />
            <Input
              id="plan-saat-bitis"
              label={t('yedekleme.plan.saatBitis')}
              placeholder="19:00"
              inputMode="numeric"
              maxLength={5}
              hata={hata(errors.saat_bitis?.message)}
              readOnly={isSubmitting}
              {...register('saat_bitis')}
            />
          </>
        ) : null}

        {siklikTuru === 'n_gunluk' ? (
          <Controller
            control={control}
            name="baslangic_tarihi"
            render={({ field }) => (
              <TarihInput
                id="plan-baslangic-tarihi"
                label={t('yedekleme.plan.baslangicTarihi')}
                value={field.value}
                onChange={field.onChange}
                disabled={isSubmitting}
              />
            )}
          />
        ) : null}

        {siklikTuru === 'saatlik' || siklikTuru === 'haftalik' ? (
          <Controller
            control={control}
            name="gunler"
            render={({ field }) => (
              <GunSecici
                secili={field.value}
                degistir={field.onChange}
                hata={hata(errors.gunler?.message)}
                kilitli={isSubmitting}
              />
            )}
          />
        ) : null}

        {siklikTuru === 'aylik' ? (
          <Input
            id="plan-ayin-gunu"
            label={`${t('yedekleme.plan.ayinGunu')} *`}
            inputMode="numeric"
            maxLength={2}
            hata={hata(errors.ayin_gunu?.message)}
            readOnly={isSubmitting}
            {...register('ayin_gunu')}
          />
        ) : null}
      </div>

      {siklikTuru === 'saatlik' ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.plan.saatlikNotu')}
        </p>
      ) : null}
      {siklikTuru === 'aylik' && Number(ayinGunu) >= AY_SONU_ESIGI ? (
        <p
          role="alert"
          className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('yedekleme.plan.aySonuUyarisi')}
        </p>
      ) : null}
      <p className="text-xs text-slate-500 dark:text-slate-400">{t('yedekleme.plan.saatNotu')}</p>

      <fieldset className="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
        <legend className="pr-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          {t('yedekleme.plan.hedeflerVeSaklama')}
        </legend>
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.plan.saklamaNotu')}
        </p>
        {fields.map((alan, sira) => {
          const kayitli = plan?.hedefler.find(
            (hedef) => String(hedef.hedef_id) === alan.hedef_id && hedef.kopya_sayisi > 0,
          )

          return (
            <div
              key={alan.id}
              className="grid grid-cols-1 items-start gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[2fr_1fr_1fr_auto] dark:border-slate-700"
            >
              <Controller
                control={control}
                name={`hedefler.${sira}.hedef_id`}
                render={({ field }) => (
                  <SelectField
                    id={`plan-hedef-${sira}`}
                    label={t('yedekleme.plan.hedef')}
                    options={hedefSecenekleri}
                    value={
                      hedefSecenekleri.find((secenek) => secenek.value === field.value) ?? null
                    }
                    onChange={(secenek) => field.onChange(secenek?.value ?? '')}
                    hata={hata(errors.hedefler?.[sira]?.hedef_id?.message)}
                    disabled={isSubmitting}
                  />
                )}
              />
              <Input
                id={`plan-hedef-${sira}-son-adet`}
                label={t('yedekleme.plan.sonAdet')}
                inputMode="numeric"
                placeholder="14"
                hata={hata(errors.hedefler?.[sira]?.sakla_son_adet?.message)}
                readOnly={isSubmitting}
                {...register(`hedefler.${sira}.sakla_son_adet`)}
              />
              <Input
                id={`plan-hedef-${sira}-gun`}
                label={t('yedekleme.plan.gunSayisi')}
                inputMode="numeric"
                placeholder="30"
                hata={hata(errors.hedefler?.[sira]?.sakla_gun?.message)}
                readOnly={isSubmitting}
                {...register(`hedefler.${sira}.sakla_gun`)}
              />
              <div className="sm:pt-7">
                <Button
                  variant="secondary"
                  disabled={isSubmitting}
                  aria-label={t('yedekleme.plan.hedefiKaldir', { sira: sira + 1 })}
                  onClick={() => remove(sira)}
                >
                  {t('yedekleme.plan.kaldir')}
                </Button>
              </div>
              {kayitli ? (
                <p className="text-xs text-slate-500 sm:col-span-4 dark:text-slate-400">
                  {t('yedekleme.plan.hedefKopyaNotu', { adet: kayitli.kopya_sayisi })}
                </p>
              ) : null}
            </div>
          )
        })}
        {hedeflerHatasi ? (
          <p className="text-sm text-red-600 dark:text-red-400">{hata(hedeflerHatasi)}</p>
        ) : null}
        <Button
          variant="secondary"
          disabled={isSubmitting}
          onClick={() => append({ hedef_id: '', sakla_son_adet: '', sakla_gun: '' })}
        >
          {t('yedekleme.plan.hedefEkle')}
        </Button>
      </fieldset>

      {kopyaliHedefler.length > 0 ? (
        <div
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          <p className="font-semibold">{t('yedekleme.plan.hedefKopyaliBaslik')}</p>
          <ul className="mt-1 list-disc pl-5">
            {kopyaliHedefler.map((hedef) => (
              <li key={hedef.hedef_id}>
                {t('yedekleme.plan.hedefKopyaliSatir', {
                  ad: hedef.ad,
                  adet: hedef.kopya_sayisi,
                })}
              </li>
            ))}
          </ul>
          <p className="mt-1">{t('yedekleme.plan.hedefKopyaliAciklama')}</p>
        </div>
      ) : null}

      {errors.root?.message ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}

      {onizleme ? <OnizlemePaneli onizleme={onizleme} hedefAdi={hedefAdi} /> : null}

      <div className="flex flex-wrap justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button
          variant="secondary"
          yukleniyor={onizle.isPending}
          disabled={isSubmitting}
          onClick={() => void onizlemeIste()}
        >
          {t('yedekleme.plan.onizle')}
        </Button>
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}
