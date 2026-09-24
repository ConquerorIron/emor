import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Controller, useForm, type UseFormRegister } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Switch } from '@/components/Switch'
import {
  type AlarmBildirimi,
  type AlarmKurali,
  type AlarmTuru,
  alarmKuraliKaydet,
  alarmKurallariniGetir,
} from '@/features/ayarlar/alarmApi'
import { zamanGoster } from '@/utils/tarih'

const SAAT = /^([01]\d|2[0-3]):[0-5]\d$/

/** Virgül, noktalı virgül ya da satır sonuyla ayrılmış adresler. */
function alicilariAyir(metin: string): string[] {
  return metin
    .split(/[,;\n]/)
    .map((adres) => adres.trim())
    .filter((adres) => adres !== '')
}

const PARAMETRELER: Record<
  AlarmTuru,
  readonly ('ardisik_hata' | 'gecikme_saat' | 'gun' | 'saat')[]
> = {
  senkron_arizasi: ['ardisik_hata', 'gecikme_saat'],
  gunluk_ozet: ['saat'],
  erp_okumadi: ['gun', 'saat'],
}

const ARALIKLAR = {
  ardisik_hata: [1, 20],
  gecikme_saat: [1, 72],
  gun: [1, 30],
} as const

function kuralSemasi(tur: AlarmTuru) {
  return z
    .object({
      aktif: z.boolean(),
      alicilar: z.string(),
      ardisik_hata: z.number(),
      gecikme_saat: z.number(),
      gun: z.number(),
      saat: z.string(),
    })
    .superRefine((deger, ctx) => {
      const alicilar = alicilariAyir(deger.alicilar)
      if (deger.aktif && alicilar.length === 0) {
        ctx.addIssue({
          code: 'custom',
          path: ['alicilar'],
          message: 'ayarlar.alarm.dogrulama.aliciZorunlu',
        })
      }
      if (alicilar.some((adres) => !z.email().safeParse(adres).success)) {
        ctx.addIssue({
          code: 'custom',
          path: ['alicilar'],
          message: 'ayarlar.alarm.dogrulama.aliciGecersiz',
        })
      }
      for (const alan of PARAMETRELER[tur]) {
        if (alan === 'saat') {
          if (!SAAT.test(deger.saat)) {
            ctx.addIssue({
              code: 'custom',
              path: ['saat'],
              message: 'ayarlar.alarm.dogrulama.saatBicimi',
            })
          }
          continue
        }
        const [en, cok] = ARALIKLAR[alan]
        const sayi = deger[alan]
        if (!Number.isInteger(sayi) || sayi < en || sayi > cok) {
          ctx.addIssue({ code: 'custom', path: [alan], message: 'ayarlar.alarm.dogrulama.aralik' })
        }
      }
    })
}

type KuralGirdisi = z.infer<ReturnType<typeof kuralSemasi>>

function formDegerleri(kural: AlarmKurali): KuralGirdisi {
  const p = kural.parametreler

  return {
    aktif: kural.aktif,
    alicilar: kural.alicilar.join(', '),
    ardisik_hata: Number(p['ardisik_hata'] ?? 3),
    gecikme_saat: Number(p['gecikme_saat'] ?? 2),
    gun: Number(p['gun'] ?? 2),
    saat: String(p['saat'] ?? '08:00'),
  }
}

function SayiParametresi({
  tur,
  alan,
  register,
  hata,
}: {
  tur: AlarmTuru
  alan: 'ardisik_hata' | 'gecikme_saat' | 'gun'
  register: UseFormRegister<KuralGirdisi>
  hata?: string
}) {
  const { t } = useTranslation()
  const [en, cok] = ARALIKLAR[alan]

  return (
    <Input
      id={`${tur}-${alan}`}
      type="number"
      min={en}
      max={cok}
      label={t(`ayarlar.alarm.parametre.${alan}`)}
      hata={hata ? t(hata, { en, cok }) : undefined}
      {...register(alan, { valueAsNumber: true })}
    />
  )
}

function KuralKarti({ kural }: { kural: AlarmKurali }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting, isDirty },
    reset,
  } = useForm<KuralGirdisi>({
    resolver: standardSchemaResolver(kuralSemasi(kural.tur)),
    defaultValues: formDegerleri(kural),
  })

  const kaydet = useMutation({
    mutationFn: (girdi: KuralGirdisi) =>
      alarmKuraliKaydet(kural.tur, {
        aktif: girdi.aktif,
        alicilar: alicilariAyir(girdi.alicilar),
        parametreler: Object.fromEntries(
          PARAMETRELER[kural.tur].map((alan) => [alan, girdi[alan]]),
        ),
      }),
    onSuccess: async (kaydedilen) => {
      // Form sunucunun kaydettiği hâle döner (alıcılar tekilleştirilmiş olabilir);
      // kart yeniden kurulmaz — periyodik yenileme yazılmakta olanı silmesin
      reset(formDegerleri(kaydedilen))
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.alarmKurallari })
      toast.success(t('ayarlar.alarm.kaydedildi', { ad: t(`ayarlar.alarm.kural.${kural.tur}.ad`) }))
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      noValidate
      aria-label={t(`ayarlar.alarm.kural.${kural.tur}.ad`)}
      className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"
    >
      <div>
        <h3 className="text-lg font-bold">{t(`ayarlar.alarm.kural.${kural.tur}.ad`)}</h3>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {t(`ayarlar.alarm.kural.${kural.tur}.aciklama`)}
        </p>
      </div>

      <Controller
        control={control}
        name="aktif"
        render={({ field }) => (
          <Switch
            id={`${kural.tur}-aktif`}
            label={t('ayarlar.alarm.aktif')}
            checked={field.value}
            onChange={field.onChange}
          />
        )}
      />

      <Input
        id={`${kural.tur}-alicilar`}
        label={t('ayarlar.alarm.alicilar')}
        placeholder="muhasebe@firma.com.tr, finans@firma.com.tr"
        hata={errors.alicilar?.message ? t(errors.alicilar.message) : undefined}
        {...register('alicilar')}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        {PARAMETRELER[kural.tur].map((alan) =>
          alan === 'saat' ? (
            <Input
              key={alan}
              id={`${kural.tur}-saat`}
              label={t('ayarlar.alarm.parametre.saat')}
              placeholder="08:00"
              inputMode="numeric"
              hata={errors.saat?.message ? t(errors.saat.message) : undefined}
              {...register('saat')}
            />
          ) : (
            <SayiParametresi
              key={alan}
              tur={kural.tur}
              alan={alan}
              register={register}
              hata={errors[alan]?.message}
            />
          ),
        )}
      </div>

      {errors.root?.message ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}

      <div className="flex justify-end">
        <Button type="submit" yukleniyor={isSubmitting} disabled={!isDirty}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

const DURUM_SINIFLARI: Record<AlarmBildirimi['durum'], string> = {
  bekliyor: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
  gonderildi: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  basarisiz: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  atlandi: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
}

export function AlarmKurallariPage() {
  const { t } = useTranslation()

  const sorgu = useQuery({
    queryKey: queryKeys.ayarlar.alarmKurallari,
    queryFn: alarmKurallariniGetir,
    // Gönderim durumu kuyrukta değişir; ekran açıkken tazelenir
    refetchInterval: 30_000,
  })

  const kolonlar: DataTableKolonu<AlarmBildirimi>[] = [
    {
      anahtar: 'olusturuldu',
      baslik: t('ayarlar.alarm.bildirim.zaman'),
      render: (b) => <span className="whitespace-nowrap">{zamanGoster(b.olusturuldu)}</span>,
    },
    {
      anahtar: 'kural',
      baslik: t('ayarlar.alarm.bildirim.kural'),
      render: (b) => (
        <div>
          <p>{t(`ayarlar.alarm.kural.${b.kural_turu}.ad`)}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {t(`ayarlar.alarm.bildirim.tur.${b.tur}`)} · {t(`efatura.ortam.${b.ortam}`)}
          </p>
        </div>
      ),
    },
    {
      anahtar: 'durum',
      baslik: t('ayarlar.alarm.bildirim.durum'),
      render: (b) => (
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-semibold ${DURUM_SINIFLARI[b.durum]}`}
        >
          {t(`ayarlar.alarm.bildirim.durumlar.${b.durum}`)}
        </span>
      ),
    },
    {
      anahtar: 'hata',
      baslik: t('ayarlar.alarm.bildirim.aciklama'),
      render: (b) =>
        b.hata_kodu === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <div className="max-w-md">
            <p>
              {t(`ayarlar.alarm.bildirim.kodlar.${b.hata_kodu}`, { defaultValue: b.hata_kodu })}
            </p>
            {b.hata_mesaji ? (
              <p
                className="truncate text-xs text-slate-500 dark:text-slate-400"
                title={b.hata_mesaji}
              >
                {b.hata_mesaji}
              </p>
            ) : null}
          </div>
        ),
    },
    {
      anahtar: 'gonderildi',
      baslik: t('ayarlar.alarm.bildirim.gonderildi'),
      render: (b) => (
        <span className="whitespace-nowrap">
          {zamanGoster(b.gonderildi)}
          {b.deneme > 1 ? ` (${t('ayarlar.alarm.bildirim.deneme', { sayi: b.deneme })})` : ''}
        </span>
      ),
    },
  ]

  return (
    <>
      <h2 className="text-2xl font-bold">{t('ayarlar.alarm.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.alarm.aciklama')}
      </p>

      {sorgu.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(sorgu.error))} tekrarDene={() => void sorgu.refetch()} />
        </div>
      ) : null}

      {sorgu.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {sorgu.data ? (
        <>
          <div className="mt-4 grid gap-4 xl:grid-cols-3">
            {sorgu.data.data.map((kural) => (
              <KuralKarti key={kural.tur} kural={kural} />
            ))}
          </div>

          <h3 className="mt-8 text-lg font-bold">{t('ayarlar.alarm.bildirim.baslik')}</h3>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
            {t('ayarlar.alarm.bildirim.aciklamaMetni')}
          </p>
          <div className="mt-3">
            <DataTable
              kolonlar={kolonlar}
              satirlar={sorgu.data.bildirimler}
              satirAnahtari={(b) => b.id}
            />
          </div>
        </>
      ) : null}
    </>
  )
}
