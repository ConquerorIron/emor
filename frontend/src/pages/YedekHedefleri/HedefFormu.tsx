import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { Switch } from '@/components/Switch'
import { alanHataMetni, boyutGoster, dogrulamaHatalari } from '@/features/yedekleme/bicim'
import {
  googleBaglantiAdresi,
  hedefKaydet,
  hedefSina,
  type Hedef,
  type HedefAlanTanimi,
  type HedefDegeri,
  type HedefSinamaSonucu,
  type HedefTuruTanimi,
} from '@/features/yedekleme/hedefApi'

/** Çok satırlı alanların sınırı (backend: SFTP özel anahtarı en çok 16384 karakter) */
const UZUN_METIN_SINIRI = 16384

type AlanDegeri = string | boolean

interface HedefGirdisi {
  ad: string
  aktif: boolean
  alanlar: Record<string, AlanDegeri>
}

/** Sır kayıtlıysa boş bırakılabilir (değişmez); değilse zorunluluk tanımdan gelir */
function zorunluMu(alan: HedefAlanTanimi, hedef: Hedef | null): boolean {
  return alan.zorunlu && !(alan.gizli && hedef?.gizli_kayitli[alan.ad] === true)
}

/** Zod şeması çalışma zamanında tanımdan üretilir: yeni tür ekranda kod istemez */
function semaUret(
  tanim: HedefTuruTanimi,
  hedef: Hedef | null,
): z.ZodType<HedefGirdisi, HedefGirdisi> {
  const alanlar: Record<string, z.ZodType<AlanDegeri>> = {}
  for (const alan of tanim.alanlar) {
    if (alan.tur === 'evet_hayir') {
      alanlar[alan.ad] = z.boolean()
      continue
    }
    const zorunlu = zorunluMu(alan, hedef)
    alanlar[alan.ad] = z.string().superRefine((deger, ctx) => {
      if (zorunlu && deger.trim() === '') {
        ctx.addIssue({ code: 'custom', message: 'yedekleme.hedef.dogrulama.zorunlu' })
      }
      if (alan.tur === 'sayi' && deger.trim() !== '' && !/^\d+$/.test(deger.trim())) {
        ctx.addIssue({ code: 'custom', message: 'yedekleme.hedef.dogrulama.sayi' })
      }
    })
  }

  // Alan adları yalnız çalışma zamanında belli: şemanın türü form türüne bağlanır
  return z.object({
    ad: z
      .string()
      .trim()
      .min(1, 'yedekleme.hedef.dogrulama.adZorunlu')
      .max(100, 'yedekleme.hedef.dogrulama.adUzun'),
    aktif: z.boolean(),
    alanlar: z.object(alanlar),
  }) as unknown as z.ZodType<HedefGirdisi, HedefGirdisi>
}

/** Sırlar hiçbir zaman önceden doldurulmaz; diğerleri kayıttan ya da varsayılandan */
function baslangicDegerleri(
  tanim: HedefTuruTanimi,
  hedef: Hedef | null,
  varsayilanAd: string,
): HedefGirdisi {
  const alanlar: Record<string, AlanDegeri> = {}
  for (const alan of tanim.alanlar) {
    const kayitli = hedef === null ? alan.varsayilan : (hedef.baglanti[alan.ad] ?? alan.varsayilan)
    if (alan.tur === 'evet_hayir') {
      alanlar[alan.ad] = kayitli === true
    } else {
      alanlar[alan.ad] = alan.gizli || kayitli === null ? '' : String(kayitli)
    }
  }

  return { ad: hedef?.ad ?? varsayilanAd, aktif: hedef?.aktif ?? true, alanlar }
}

/** Boş sır gönderilmez (kayıtlı korunur); boş metin null, sayı sayı olarak gider */
function alanlariGovdeye(
  tanim: HedefTuruTanimi,
  alanlar: Record<string, AlanDegeri>,
): Record<string, HedefDegeri> {
  const govde: Record<string, HedefDegeri> = {}
  for (const alan of tanim.alanlar) {
    const deger = alanlar[alan.ad]
    if (alan.tur === 'evet_hayir') {
      govde[alan.ad] = deger === true
      continue
    }
    const metin = typeof deger === 'string' ? deger : ''
    if (alan.gizli) {
      if (metin !== '') {
        govde[alan.ad] = metin
      }
      continue
    }
    const temiz = metin.trim()
    govde[alan.ad] = temiz === '' ? null : alan.tur === 'sayi' ? Number(temiz) : temiz
  }

  return govde
}

function SinamaSonucuPaneli({ sonuc }: { sonuc: HedefSinamaSonucu }) {
  const { t } = useTranslation()

  return (
    <div
      role="status"
      className={`rounded-lg px-3 py-2 text-sm ${
        sonuc.basarili
          ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
          : 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
      }`}
    >
      <p className="font-semibold">
        {t(sonuc.basarili ? 'yedekleme.hedef.sinama.basarili' : 'yedekleme.hedef.sinama.basarisiz')}
      </p>
      <ol className="mt-2 space-y-1">
        {sonuc.adimlar.map((adim) => (
          <li key={adim.adim} className="flex gap-2">
            <span aria-hidden="true" className="w-4 shrink-0 text-center font-bold">
              {adim.basarili === true ? '✓' : adim.basarili === false ? '✗' : '–'}
            </span>
            <span>
              <span className="font-semibold">{t(`yedekleme.hedef.sinama.adim.${adim.adim}`)}</span>
              {': '}
              {adim.basarili === true
                ? t('yedekleme.hedef.sinama.gecti')
                : adim.basarili === null
                  ? t('yedekleme.hedef.sinama.atlandi')
                  : adim.kod
                    ? t(`hata.${adim.kod}`, { defaultValue: adim.mesaj ?? adim.kod })
                    : (adim.mesaj ?? t('hata.BILINMEYEN'))}
            </span>
          </li>
        ))}
      </ol>
      {sonuc.kalan_alan !== null ? (
        <p className="mt-2">
          {t('yedekleme.hedef.sinama.kalanAlan', { alan: boyutGoster(sonuc.kalan_alan) })}
        </p>
      ) : null}
      {sonuc.silinme_korumasi !== null ? (
        <p className="mt-1">{t(`yedekleme.hedef.sinama.koruma.${sonuc.silinme_korumasi}`)}</p>
      ) : null}
      {sonuc.sunucu_parmak_izi ? (
        <p className="mt-1 font-mono text-xs break-all">
          {t('yedekleme.hedef.sftp.parmakIzi')}: {sonuc.sunucu_parmak_izi}
        </p>
      ) : null}
      <p className="mt-1 text-xs">
        {t(
          sonuc.kaydedildi
            ? 'yedekleme.hedef.sinama.kayitliSinandi'
            : 'yedekleme.hedef.sinama.kaydedilmemisSinandi',
        )}
      </p>
    </div>
  )
}

/**
 * Hedef formu: alanlar bağlayıcının tanımından çizilir (BY-016 — yeni tür
 * ekranda kod istemez). Sırlar önceden doldurulmaz; boş bırakılırsa kayıtlı
 * değer korunur. "Bağlantıyı Sına" formdaki (kaydedilmemiş) değerlerle çalışır.
 */
export function HedefFormu({
  tanim,
  hedef,
  kapat,
}: {
  tanim: HedefTuruTanimi
  hedef: Hedef | null
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [sinamaSonucu, setSinamaSonucu] = useState<HedefSinamaSonucu | null>(null)
  const [sinamaHatasi, setSinamaHatasi] = useState<string | null>(null)
  const sinamaSurumu = useRef(0)

  const {
    register,
    control,
    handleSubmit,
    getValues,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<HedefGirdisi>({
    resolver: standardSchemaResolver(semaUret(tanim, hedef)),
    // Hesabı tarayıcıdan bağlanan türde (Google Drive) ad kendiliğinden gelir: ilk adım Google girişi
    defaultValues: baslangicDegerleri(
      tanim,
      hedef,
      tanim.oauth ? t(`yedekleme.hedef.tur.${tanim.tur}`, { defaultValue: tanim.tur }) : '',
    ),
  })

  const sunucuHatalariniDagit = (error: unknown) => {
    const adlar = new Set(tanim.alanlar.map((alan) => alan.ad))
    let dagitildi = false
    for (const [anahtar, mesajlar] of Object.entries(dogrulamaHatalari(error))) {
      // Bağlayıcı kuralları alan adıyla, sır kuralı `alanlar.<ad>` ile döner
      const ad = anahtar.startsWith('alanlar.') ? anahtar.slice('alanlar.'.length) : anahtar
      if (adlar.has(ad)) {
        setError(`alanlar.${ad}`, { message: mesajlar[0] })
        dagitildi = true
      } else if (ad === 'ad') {
        setError('ad', { message: mesajlar[0] })
        dagitildi = true
      }
    }
    if (!dagitildi) {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    }
  }

  const googleyaGidilecek = tanim.oauth && tanim.oauth_hazir && hedef === null

  const kaydet = useMutation({
    mutationFn: (girdi: HedefGirdisi) =>
      hedefKaydet(hedef?.id ?? null, {
        ad: girdi.ad.trim(),
        aktif: girdi.aktif,
        ...(hedef === null ? { tur: tanim.tur } : {}),
        alanlar: alanlariGovdeye(tanim, girdi.alanlar),
      }),
    onSuccess: async (kaydedilen) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.hedefler })
      toast.success(t('yedekleme.hedef.kaydedildi'))
      // Yeni Google Drive hedefi: kayıttan sonra doğrudan Google girişine gidilir;
      // dönüşte Hedefler ekranı klasör seçme penceresini açar
      if (googleyaGidilecek) {
        try {
          window.location.assign(await googleBaglantiAdresi(kaydedilen.id))
          return
        } catch (error: unknown) {
          toast.error(t(apiErrorKey(error)))
        }
      }
      kapat()
    },
    onError: sunucuHatalariniDagit,
  })

  const sina = useMutation({
    mutationFn: (_surum: number) =>
      hedefSina({
        ...(hedef === null ? {} : { id: hedef.id }),
        tur: tanim.tur,
        alanlar: alanlariGovdeye(tanim, getValues('alanlar')),
      }),
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

  // Sunucunun bildirdiği konum kilidi (BY-016-R1): kopyalar eski konumda durur
  const kilitliAlanlar = new Set(hedef?.konum_kilitli_alanlar ?? [])

  const etiket = (alan: HedefAlanTanimi) =>
    `${t(alan.etiket, { defaultValue: alan.ad })}${zorunluMu(alan, hedef) ? ' *' : ''}`

  const alanCiz = (alan: HedefAlanTanimi) => {
    const id = `hedef-alan-${alan.ad}`
    const hata = alanHataMetni(t, errors.alanlar?.[alan.ad]?.message)
    // Kayıtlı sır gösterilmez; yer tutucu yalnız kayıtlı olduğunu söyler
    const sirKayitli = alan.gizli && hedef?.gizli_kayitli[alan.ad] === true
    const yerTutucu = sirKayitli ? t('yedekleme.hedef.sirKayitli') : undefined
    // Kopyası duran hedefin konum alanı değiştirilemez (BY-016-R1/R3; ör. port, S3 yol stili)
    const konumKilitli = kilitliAlanlar.has(alan.ad)

    if (alan.tur === 'evet_hayir') {
      return (
        <Controller
          key={alan.ad}
          control={control}
          name={`alanlar.${alan.ad}`}
          render={({ field }) => (
            // Controller'lı anahtarda disabled yalnız arayüzü kilitler; değer gövdede kalır
            <Switch
              id={id}
              alanHizali
              label={etiket(alan)}
              checked={field.value === true}
              disabled={isSubmitting || konumKilitli}
              onChange={(deger) => {
                field.onChange(deger)
                sinamayiTemizle()
              }}
            />
          )}
        />
      )
    }

    if (alan.tur === 'uzun_metin') {
      return (
        <div key={alan.ad} className="sm:col-span-2">
          <Controller
            control={control}
            name={`alanlar.${alan.ad}`}
            render={({ field }) => (
              <SinirliMetinInput
                id={id}
                label={etiket(alan)}
                value={typeof field.value === 'string' ? field.value : ''}
                onChange={field.onChange}
                limit={UZUN_METIN_SINIRI}
                rows={4}
                hata={hata}
                disabled={isSubmitting}
              />
            )}
          />
          {sirKayitli ? (
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{yerTutucu}</p>
          ) : null}
        </div>
      )
    }

    // Kopyası duran hedefin konum alanı salt okunur (disabled değil: değer gövdede kalmalı)
    return (
      <Input
        key={alan.ad}
        id={id}
        label={etiket(alan)}
        type={alan.tur === 'parola' ? 'password' : 'text'}
        inputMode={alan.tur === 'sayi' ? 'numeric' : undefined}
        autoComplete={alan.tur === 'parola' ? 'new-password' : 'off'}
        placeholder={yerTutucu}
        hata={hata}
        readOnly={isSubmitting || konumKilitli}
        title={konumKilitli ? t('yedekleme.hedef.konumKilitli') : undefined}
        className={
          konumKilitli
            ? 'read-only:bg-slate-100 read-only:text-slate-500 dark:read-only:bg-slate-900 dark:read-only:text-slate-400'
            : ''
        }
        {...register(`alanlar.${alan.ad}`)}
      />
    )
  }

  const parmakIzi = hedef?.baglanti['sunucu_parmak_izi']

  return (
    <form
      onSubmit={(e) => void onSubmit(e)}
      onChange={sinamayiTemizle}
      noValidate
      className="space-y-4"
    >
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.hedef.turEtiketi')}:{' '}
        <strong className="text-slate-800 dark:text-slate-100">
          {t(`yedekleme.hedef.tur.${tanim.tur}`, { defaultValue: tanim.tur })}
        </strong>
      </p>

      {tanim.uyari ? (
        <p
          role="alert"
          className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
        >
          {t(tanim.uyari, { defaultValue: tanim.uyari })}
        </p>
      ) : null}

      {kilitliAlanlar.size > 0 ? (
        <p
          role="note"
          className="rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
        >
          {t('yedekleme.hedef.konumKilitli')}
        </p>
      ) : null}

      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
        <Input
          id="hedef-ad"
          label={`${t('yedekleme.hedef.ad')} *`}
          maxLength={100}
          hata={alanHataMetni(t, errors.ad?.message)}
          readOnly={isSubmitting}
          {...register('ad')}
        />
        <Controller
          control={control}
          name="aktif"
          render={({ field }) => (
            <Switch
              id="hedef-aktif"
              alanHizali
              label={t('yedekleme.hedef.aktif')}
              checked={field.value}
              disabled={isSubmitting}
              onChange={field.onChange}
            />
          )}
        />
        {tanim.alanlar.map(alanCiz)}
      </div>

      {tanim.alanlar.some((alan) => alan.gizli) ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">{t('yedekleme.hedef.sirNotu')}</p>
      ) : null}
      {tanim.sunucu_disi ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.hedef.sunucuDisiNotu')}
        </p>
      ) : null}
      {tanim.oauth && tanim.oauth_hazir ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.hedef.google.formNotu')}
        </p>
      ) : null}
      {tanim.oauth && !tanim.oauth_hazir ? (
        <p
          role="alert"
          className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('yedekleme.hedef.google.yapilandirilmadi')}
        </p>
      ) : null}
      {tanim.tur === 'sftp' ? (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {typeof parmakIzi === 'string' && parmakIzi !== '' ? (
            <>
              {t('yedekleme.hedef.sftp.parmakIzi')}:{' '}
              <span className="font-mono break-all">{parmakIzi}</span>
            </>
          ) : (
            t('yedekleme.hedef.sftp.ilkSina')
          )}
        </p>
      ) : null}

      {errors.root?.message ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}

      {sinamaSonucu ? <SinamaSonucuPaneli sonuc={sinamaSonucu} /> : null}
      {sinamaHatasi ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {sinamaHatasi}
        </p>
      ) : null}

      <div className="flex flex-wrap justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        {/* Hesabı tarayıcıdan bağlanan tür (Google Drive) kaydedilmeden sınanamaz: hesap ve klasör sonra seçilir */}
        {tanim.oauth && hedef === null ? null : (
          <Button
            variant="secondary"
            yukleniyor={sina.isPending}
            disabled={isSubmitting}
            onClick={() => sina.mutate(sinamaSurumu.current)}
          >
            {t('yedekleme.hedef.sina')}
          </Button>
        )}
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting
            ? t('ortak.kaydediliyor')
            : googleyaGidilecek
              ? t('yedekleme.hedef.google.kaydetVeBaglan')
              : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}
