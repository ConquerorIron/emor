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
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import {
  alanHataMetni,
  dogrulamaHatalari,
  istanbulZamani,
  parmakIziGoster,
} from '@/features/yedekleme/bicim'
import { useIzin } from '@/hooks/useIzin'

import {
  sifrelemeAnahtariniKaldir,
  sifrelemeAnahtariYukle,
  yedekAyarlariniGetir,
  yedekAyarlariniKaydet,
  type YedekAyarGovdesi,
  type YedekAyarlari,
} from './ayarApi'

/** Backend sınırlarıyla aynı (YedekAyariController) */
const SINIRLAR = {
  yayin_oncesi_son_adet: [0, 1000],
  yayin_oncesi_gun: [0, 3650],
  elle_gun: [0, 3650],
  geri_yukleme_oncesi_gun: [0, 3650],
  // En az sayı 1'in altına inemez: son başarılı yedek her zaman kalır
  enaz_adet: [1, 100],
  enaz_bos_alan_yuzde: [1, 90],
} as const

type SayiAlanAdi = keyof typeof SINIRLAR

const SAYI_ALANLARI = Object.keys(SINIRLAR) as SayiAlanAdi[]
const SINYAL_ALANLARI = [
  'sinyal_yerel_adresi',
  'sinyal_uzak_adresi',
  'sinyal_sinama_adresi',
] as const
const EN_COK_ALICI = 20
/** Ascii zırhlı açık anahtar için backend sınırı */
const ANAHTAR_SINIRI = 65536

function sayiSemasi(alan: SayiAlanAdi) {
  const [en, cok] = SINIRLAR[alan]
  const mesaj = 'yedekleme.ayarlar.dogrulama.aralik'

  return z.number(mesaj).int(mesaj).min(en, mesaj).max(cok, mesaj)
}

/** Virgül, noktalı virgül ya da satır sonuyla ayrılmış adresler */
function alicilariAyir(metin: string): string[] {
  return metin
    .split(/[,;\n]/)
    .map((adres) => adres.trim())
    .filter((adres) => adres !== '')
}

function httpsMi(adres: string): boolean {
  if (!/^https:\/\/\S+$/i.test(adres)) {
    return false
  }
  try {
    return new URL(adres).protocol === 'https:'
  } catch {
    return false
  }
}

const sinyalSemasi = z
  .string()
  .trim()
  .max(500, 'yedekleme.ayarlar.dogrulama.sinyal')
  .refine((adres) => adres === '' || httpsMi(adres), 'yedekleme.ayarlar.dogrulama.sinyal')

const ayarSemasi = z.object({
  yayin_oncesi_son_adet: sayiSemasi('yayin_oncesi_son_adet'),
  yayin_oncesi_gun: sayiSemasi('yayin_oncesi_gun'),
  elle_gun: sayiSemasi('elle_gun'),
  geri_yukleme_oncesi_gun: sayiSemasi('geri_yukleme_oncesi_gun'),
  enaz_adet: sayiSemasi('enaz_adet'),
  enaz_bos_alan_yuzde: sayiSemasi('enaz_bos_alan_yuzde'),
  bildirim_alicilari: z.string().superRefine((metin, ctx) => {
    const alicilar = alicilariAyir(metin)
    if (alicilar.length > EN_COK_ALICI) {
      ctx.addIssue({ code: 'custom', message: 'yedekleme.ayarlar.dogrulama.aliciCok' })
    }
    if (alicilar.some((adres) => !z.email().safeParse(adres).success)) {
      ctx.addIssue({ code: 'custom', message: 'yedekleme.ayarlar.dogrulama.aliciGecersiz' })
    }
    const kucuk = alicilar.map((adres) => adres.toLocaleLowerCase('en'))
    if (new Set(kucuk).size !== kucuk.length) {
      ctx.addIssue({ code: 'custom', message: 'yedekleme.ayarlar.dogrulama.aliciTekrar' })
    }
  }),
  sinyal_yerel_adresi: sinyalSemasi,
  sinyal_uzak_adresi: sinyalSemasi,
  sinyal_sinama_adresi: sinyalSemasi,
})

type AyarGirdisi = z.infer<typeof ayarSemasi>

function formDegerleri(ayar: YedekAyarlari): AyarGirdisi {
  return {
    yayin_oncesi_son_adet: ayar.yayin_oncesi_son_adet,
    yayin_oncesi_gun: ayar.yayin_oncesi_gun,
    elle_gun: ayar.elle_gun,
    geri_yukleme_oncesi_gun: ayar.geri_yukleme_oncesi_gun,
    enaz_adet: ayar.enaz_adet,
    enaz_bos_alan_yuzde: ayar.enaz_bos_alan_yuzde,
    bildirim_alicilari: ayar.bildirim_alicilari.join(', '),
    sinyal_yerel_adresi: ayar.sinyal_yerel_adresi ?? '',
    sinyal_uzak_adresi: ayar.sinyal_uzak_adresi ?? '',
    sinyal_sinama_adresi: ayar.sinyal_sinama_adresi ?? '',
  }
}

function govdeUret(girdi: AyarGirdisi): YedekAyarGovdesi {
  const adres = (deger: string) => (deger.trim() === '' ? null : deger.trim())

  return {
    yayin_oncesi_son_adet: girdi.yayin_oncesi_son_adet,
    yayin_oncesi_gun: girdi.yayin_oncesi_gun,
    elle_gun: girdi.elle_gun,
    geri_yukleme_oncesi_gun: girdi.geri_yukleme_oncesi_gun,
    enaz_adet: girdi.enaz_adet,
    enaz_bos_alan_yuzde: girdi.enaz_bos_alan_yuzde,
    bildirim_alicilari: alicilariAyir(girdi.bildirim_alicilari),
    sinyal_yerel_adresi: adres(girdi.sinyal_yerel_adresi),
    sinyal_uzak_adresi: adres(girdi.sinyal_uzak_adresi),
    sinyal_sinama_adresi: adres(girdi.sinyal_sinama_adresi),
  }
}

function AyarFormu({ ayar }: { ayar: YedekAyarlari }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<AyarGirdisi>({
    resolver: standardSchemaResolver(ayarSemasi),
    defaultValues: formDegerleri(ayar),
  })

  // Yanıtta form sunucunun kaydettiği hâle döner; kayıt sürerken alanlar salt
  // okunur — arada yazılan değer reset'te kaybolmasın (F3)
  const kaydet = useMutation({
    mutationFn: (girdi: AyarGirdisi) => yedekAyarlariniKaydet(govdeUret(girdi)),
    onSuccess: (kaydedilen) => {
      reset(formDegerleri(kaydedilen))
      queryClient.setQueryData(queryKeys.yedekleme.ayarlar, kaydedilen)
      toast.success(t('yedekleme.ayarlar.kaydedildi'))
    },
    onError: (error: unknown) => {
      let dagitildi = false
      for (const [anahtar, mesajlar] of Object.entries(dogrulamaHatalari(error))) {
        const alan = anahtar.startsWith('bildirim_alicilari') ? 'bildirim_alicilari' : anahtar
        if (
          alan in SINIRLAR ||
          alan === 'bildirim_alicilari' ||
          SINYAL_ALANLARI.some((s) => s === alan)
        ) {
          setError(alan as keyof AyarGirdisi, { message: mesajlar[0] })
          dagitildi = true
        }
      }
      if (!dagitildi) {
        setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
      }
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  const sayiHatasi = (alan: SayiAlanAdi) => {
    const mesaj = errors[alan]?.message
    const [en, cok] = SINIRLAR[alan]

    return mesaj?.startsWith('yedekleme.') ? t(mesaj, { en, cok }) : mesaj
  }

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      noValidate
      aria-label={t('yedekleme.ayarlar.baslik')}
      className="space-y-5 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"
    >
      <fieldset>
        <legend className="text-lg font-bold">{t('yedekleme.ayarlar.saklamaBaslik')}</legend>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {t('yedekleme.ayarlar.saklamaAciklama')}
        </p>
        <div className="mt-3 grid grid-cols-1 items-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {SAYI_ALANLARI.map((alan) => (
            <Input
              key={alan}
              id={`ayar-${alan}`}
              type="number"
              min={SINIRLAR[alan][0]}
              max={SINIRLAR[alan][1]}
              step={1}
              label={t(`yedekleme.ayarlar.alan.${alan}`)}
              hata={sayiHatasi(alan)}
              readOnly={isSubmitting}
              {...register(alan, { valueAsNumber: true })}
            />
          ))}
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.ayarlar.sifirNotu')}
        </p>
      </fieldset>

      <fieldset className="border-t border-slate-200 pt-4 dark:border-slate-700">
        <legend className="pr-2 text-lg font-bold">{t('yedekleme.ayarlar.bildirimBaslik')}</legend>
        <div className="mt-3 grid grid-cols-1 items-start gap-4">
          <Input
            id="ayar-bildirim-alicilari"
            label={t('yedekleme.ayarlar.alan.bildirim_alicilari')}
            placeholder="bt@firma.com.tr, yonetici@firma.com.tr"
            hata={alanHataMetni(t, errors.bildirim_alicilari?.message)}
            readOnly={isSubmitting}
            {...register('bildirim_alicilari')}
          />
          {SINYAL_ALANLARI.map((alan) => (
            <Input
              key={alan}
              id={`ayar-${alan}`}
              inputMode="url"
              autoComplete="off"
              placeholder="https://"
              label={t(`yedekleme.ayarlar.alan.${alan}`)}
              hata={alanHataMetni(t, errors[alan]?.message)}
              readOnly={isSubmitting}
              {...register(alan)}
            />
          ))}
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.ayarlar.sinyalNotu')}
        </p>
      </fieldset>

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

/**
 * Sunucu dışı kopyaların açık anahtarı (BY-019). Özel anahtar sunucuya
 * yüklenmez (backend reddeder). Anahtar yoksa sunucu dışına kopya GİTMEZ —
 * sessizce şifresiz gönderilmez.
 */
function SifrelemeBolumu({ ayar }: { ayar: YedekAyarlari }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [anahtar, setAnahtar] = useState('')
  const [hata, setHata] = useState<string | null>(null)
  const [kaldirmaOnayi, setKaldirmaOnayi] = useState(false)

  const guncelle = (yeni: YedekAyarlari) =>
    queryClient.setQueryData(queryKeys.yedekleme.ayarlar, yeni)

  const yukle = useMutation({
    mutationFn: () => sifrelemeAnahtariYukle(anahtar.trim()),
    onSuccess: (yeni) => {
      guncelle(yeni)
      setAnahtar('')
      setHata(null)
      toast.success(t('yedekleme.ayarlar.anahtar.yuklendi'))
    },
    onError: (error: unknown) => setHata(t(apiErrorKey(error))),
  })

  const kaldir = useMutation({
    mutationFn: sifrelemeAnahtariniKaldir,
    onSuccess: (yeni) => {
      guncelle(yeni)
      toast.success(t('yedekleme.ayarlar.anahtar.kaldirildi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setKaldirmaOnayi(false),
  })

  return (
    <section
      aria-label={t('yedekleme.ayarlar.anahtar.baslik')}
      className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"
    >
      <div>
        <h3 className="text-lg font-bold">{t('yedekleme.ayarlar.anahtar.baslik')}</h3>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {t('yedekleme.ayarlar.anahtar.aciklama')}
        </p>
      </div>

      {ayar.sifreleme === null ? (
        <p
          role="alert"
          className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('yedekleme.ayarlar.anahtar.yok')}
        </p>
      ) : (
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-[auto_1fr]">
          <dt className="font-semibold text-slate-600 dark:text-slate-300">
            {t('yedekleme.ayarlar.anahtar.parmakIzi')}
          </dt>
          <dd className="font-mono break-all">{parmakIziGoster(ayar.sifreleme.parmak_izi)}</dd>
          <dt className="font-semibold text-slate-600 dark:text-slate-300">
            {t('yedekleme.ayarlar.anahtar.kullanici')}
          </dt>
          <dd className="break-all">{ayar.sifreleme.kullanici ?? '—'}</dd>
          <dt className="font-semibold text-slate-600 dark:text-slate-300">
            {t('yedekleme.ayarlar.anahtar.bitis')}
          </dt>
          <dd>
            {ayar.sifreleme.bitis === null
              ? t('yedekleme.ayarlar.anahtar.suresiz')
              : istanbulZamani(ayar.sifreleme.bitis)}
          </dd>
        </dl>
      )}

      <SinirliMetinInput
        id="ayar-acik-anahtar"
        label={t(
          ayar.sifreleme === null
            ? 'yedekleme.ayarlar.anahtar.yeni'
            : 'yedekleme.ayarlar.anahtar.degistir',
        )}
        value={anahtar}
        onChange={(deger) => {
          setAnahtar(deger)
          setHata(null)
        }}
        limit={ANAHTAR_SINIRI}
        rows={6}
        hata={hata ?? undefined}
        disabled={yukle.isPending}
      />
      <p className="text-xs text-slate-500 dark:text-slate-400">
        {t('yedekleme.ayarlar.anahtar.yalnizAcik')}
      </p>

      <div className="flex flex-wrap justify-end gap-3">
        {ayar.sifreleme === null ? null : (
          <Button variant="danger" onClick={() => setKaldirmaOnayi(true)}>
            {t('yedekleme.ayarlar.anahtar.kaldir')}
          </Button>
        )}
        <Button
          yukleniyor={yukle.isPending}
          disabled={anahtar.trim() === ''}
          onClick={() => yukle.mutate()}
        >
          {t('yedekleme.ayarlar.anahtar.yukle')}
        </Button>
      </div>

      <ConfirmDialog
        acik={kaldirmaOnayi}
        kapat={() => setKaldirmaOnayi(false)}
        baslik={t('yedekleme.ayarlar.anahtar.kaldirOnayBaslik')}
        mesaj={t('yedekleme.ayarlar.anahtar.kaldirOnayMesaj')}
        onayEtiketi={t('yedekleme.ayarlar.anahtar.kaldir')}
        yukleniyor={kaldir.isPending}
        onayla={() => kaldir.mutate()}
      />
    </section>
  )
}

export function YedeklemeAyarlariPage() {
  const { t } = useTranslation()
  const guncelleyebilir = useIzin('yedek_planlari.guncelle')

  const ayarlar = useQuery({ queryKey: queryKeys.yedekleme.ayarlar, queryFn: yedekAyarlariniGetir })

  return (
    <>
      <h2 className="text-2xl font-bold">{t('yedekleme.ayarlar.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.ayarlar.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      {ayarlar.isError ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(ayarlar.error))}
            tekrarDene={() => void ayarlar.refetch()}
          />
        </div>
      ) : null}

      {ayarlar.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {ayarlar.data ? (
        // Yalnız görüntüleme izninde alanlar ve düğmeler pasif
        <fieldset disabled={!guncelleyebilir} className="mt-4 grid min-w-0 gap-5">
          <AyarFormu ayar={ayarlar.data} />
          <SifrelemeBolumu ayar={ayarlar.data} />
        </fieldset>
      ) : null}
    </>
  )
}
