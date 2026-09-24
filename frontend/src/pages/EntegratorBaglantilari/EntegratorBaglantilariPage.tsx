import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
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
import {
  entegratorAktifOrtamDegistir,
  entegratorBaglantiGuncelle,
  entegratorBaglantiSina,
  entegratorBaglantilariGetir,
  type EntegratorBaglanti,
  type EntegratorBaglantiGovdesi,
  type EntegratorBaglantilar,
  type EntegratorOrtam,
  type EntegratorSinamaSonucu,
} from '@/features/ayarlar/entegratorApi'
import { useIzin } from '@/hooks/useIzin'

const URN_ONEKI = 'urn:mail:'

// Yalnız https://alan-adı[:port] — izinli alan adı listesi backend'de denetlenir
const API_ADRESI = /^https:\/\/[A-Za-z0-9.-]+(:\d+)?\/?$/

// İzibiz kuralları (backend EntegratorBaglanti ile aynı): zamanlayıcı en az 15 dk,
// tek çağrıda en çok 100 fatura
const ARALIK = { enAz: 15, enCok: 1440 }
const SAYFA = { enAz: 10, enCok: 100 }

function baglantiSchemaOlustur(sifreZorunlu: boolean) {
  const urn = z
    .string()
    .max(255)
    .refine(
      (deger) => deger === '' || deger.startsWith(URN_ONEKI),
      'ayarlar.entegrator.dogrulama.urnGecersiz',
    )

  return z.object({
    api_url: z
      .string()
      .max(255)
      .refine(
        (deger) => deger.trim() === '' || API_ADRESI.test(deger.trim()),
        'ayarlar.entegrator.dogrulama.apiAdresiGecersiz',
      ),
    kullanici_adi: z.string().min(1, 'ayarlar.entegrator.dogrulama.kullaniciZorunlu').max(128),
    sifre: z
      .string()
      .max(255)
      .refine(
        (deger) => !sifreZorunlu || deger !== '',
        'ayarlar.entegrator.dogrulama.sifreZorunlu',
      ),
    vkn: z.string().regex(/^\d{10,11}$/, 'ayarlar.entegrator.dogrulama.vknGecersiz'),
    posta_kutusu: urn,
    gonderici_birim: urn,
    senkron_araligi_dakika: z
      .number('ayarlar.entegrator.dogrulama.aralikGecersiz')
      .int('ayarlar.entegrator.dogrulama.aralikGecersiz')
      .min(ARALIK.enAz, 'ayarlar.entegrator.dogrulama.aralikGecersiz')
      .max(ARALIK.enCok, 'ayarlar.entegrator.dogrulama.aralikGecersiz'),
    sayfa_boyutu: z
      .number('ayarlar.entegrator.dogrulama.sayfaGecersiz')
      .int('ayarlar.entegrator.dogrulama.sayfaGecersiz')
      .min(SAYFA.enAz, 'ayarlar.entegrator.dogrulama.sayfaGecersiz')
      .max(SAYFA.enCok, 'ayarlar.entegrator.dogrulama.sayfaGecersiz'),
  })
}

type BaglantiGirdisi = z.infer<ReturnType<typeof baglantiSchemaOlustur>>

/** Boş adres = ortamın varsayılanı (null gönderilir). */
function adresGovdesi(adres: string): string | null {
  return adres.trim() === '' ? null : adres.trim()
}

function girdidenGovde(girdi: BaglantiGirdisi): EntegratorBaglantiGovdesi {
  return {
    api_url: adresGovdesi(girdi.api_url),
    kullanici_adi: girdi.kullanici_adi,
    vkn: girdi.vkn,
    posta_kutusu: girdi.posta_kutusu === '' ? null : girdi.posta_kutusu,
    gonderici_birim: girdi.gonderici_birim === '' ? null : girdi.gonderici_birim,
    senkron_araligi_dakika: girdi.senkron_araligi_dakika,
    sayfa_boyutu: girdi.sayfa_boyutu,
    // Boş bırakılırsa gönderilmez — kullanıcı adı aynıysa backend kayıtlı şifreyi korur
    ...(girdi.sifre !== '' ? { sifre: girdi.sifre } : {}),
  }
}

function formAnahtari(tanim: EntegratorBaglanti | null): string {
  if (!tanim) return 'yeni'

  // Aktiflik ve güncelleme zamanı formun girdileri değildir; ortam seçimi
  // kaydedilmemiş düzenlemeleri silmemelidir.
  return JSON.stringify([
    tanim.id,
    tanim.api_url,
    tanim.api_url_ozel,
    tanim.kullanici_adi,
    tanim.vkn,
    tanim.posta_kutusu,
    tanim.gonderici_birim,
    tanim.senkron_araligi_dakika,
    tanim.sayfa_boyutu,
    tanim.sifre_dolu,
  ])
}

/** Formun adres alanı: yalnız ekrandan tanımlanmış adres dolu gelir; varsayılan ipucudur. */
function formAdresi(tanim: EntegratorBaglanti | null): string {
  return tanim?.api_url_ozel ? tanim.api_url : ''
}

function BaglantiFormu({
  ortam,
  tanim,
  varsayilanAdres,
}: {
  ortam: EntegratorOrtam
  tanim: EntegratorBaglanti | null
  varsayilanAdres: string
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [sinamaSonucu, setSinamaSonucu] = useState<EntegratorSinamaSonucu | null>(null)
  const [sinamaHatasi, setSinamaHatasi] = useState<string | null>(null)
  const sinamaSurumu = useRef(0)

  const {
    register,
    handleSubmit,
    getValues,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<BaglantiGirdisi>({
    resolver: standardSchemaResolver(baglantiSchemaOlustur(!tanim?.sifre_dolu)),
    defaultValues: {
      api_url: formAdresi(tanim),
      kullanici_adi: tanim?.kullanici_adi ?? '',
      sifre: '',
      vkn: tanim?.vkn ?? '',
      posta_kutusu: tanim?.posta_kutusu ?? '',
      gonderici_birim: tanim?.gonderici_birim ?? '',
      senkron_araligi_dakika: tanim?.senkron_araligi_dakika ?? ARALIK.enAz,
      sayfa_boyutu: tanim?.sayfa_boyutu ?? SAYFA.enCok,
    },
  })

  // Kayıt sonrası liste yenilenir; sayfa formu tanımla anahtarladığı için form
  // yeniden kurulur ve şifre alanı boşalır
  const kaydet = useMutation({
    mutationFn: (girdi: BaglantiGirdisi) => entegratorBaglantiGuncelle(ortam, girdidenGovde(girdi)),
    onSuccess: async (kaydedilen) => {
      reset({
        api_url: formAdresi(kaydedilen),
        kullanici_adi: kaydedilen.kullanici_adi,
        sifre: '',
        vkn: kaydedilen.vkn,
        posta_kutusu: kaydedilen.posta_kutusu ?? '',
        gonderici_birim: kaydedilen.gonderici_birim ?? '',
        senkron_araligi_dakika: kaydedilen.senkron_araligi_dakika,
        sayfa_boyutu: kaydedilen.sayfa_boyutu,
      })
      sinamayiTemizle()
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.entegratorBaglantilari })
      toast.success(t('ayarlar.entegrator.kaydedildi', { ortam: t(`ayarlar.sql.ortam.${ortam}`) }))
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  // Sınama formdaki adres/kullanıcı/şifreyle yapılır — kaydetmeden de denenebilir
  const sina = useMutation({
    mutationFn: (_surum: number) => {
      const { api_url, kullanici_adi, sifre } = getValues()

      return entegratorBaglantiSina(ortam, {
        api_url: adresGovdesi(api_url),
        kullanici_adi,
        ...(sifre !== '' ? { sifre } : {}),
      })
    },
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
        <div className="sm:col-span-2">
          <Input
            id={`entegrator-${ortam}-api-adresi`}
            label={t('ayarlar.entegrator.apiAdresi')}
            placeholder={varsayilanAdres}
            inputMode="url"
            autoComplete="off"
            hata={alanHatasi(errors.api_url?.message)}
            {...register('api_url')}
          />
          <p className="mt-1 text-xs break-all text-slate-500 dark:text-slate-400">
            {t('ayarlar.entegrator.apiAdresiNotu', { varsayilan: varsayilanAdres })}
          </p>
        </div>
        <Input
          id={`entegrator-${ortam}-kullanici`}
          label={t('ayarlar.entegrator.kullaniciAdi')}
          autoComplete="off"
          hata={alanHatasi(errors.kullanici_adi?.message)}
          {...register('kullanici_adi')}
        />
        <Input
          id={`entegrator-${ortam}-sifre`}
          type="password"
          label={t('ayarlar.entegrator.sifre')}
          autoComplete="new-password"
          placeholder={tanim?.sifre_dolu ? '••••••••' : undefined}
          hata={alanHatasi(errors.sifre?.message)}
          {...register('sifre')}
        />
        <Input
          id={`entegrator-${ortam}-vkn`}
          label={t('ayarlar.entegrator.vkn')}
          inputMode="numeric"
          maxLength={11}
          autoComplete="off"
          hata={alanHatasi(errors.vkn?.message)}
          {...register('vkn')}
        />
        <Input
          id={`entegrator-${ortam}-posta-kutusu`}
          label={t('ayarlar.entegrator.postaKutusu')}
          placeholder={`${URN_ONEKI}…`}
          autoComplete="off"
          hata={alanHatasi(errors.posta_kutusu?.message)}
          {...register('posta_kutusu')}
        />
        <Input
          id={`entegrator-${ortam}-gonderici-birim`}
          label={t('ayarlar.entegrator.gondericiBirim')}
          placeholder={`${URN_ONEKI}…`}
          autoComplete="off"
          hata={alanHatasi(errors.gonderici_birim?.message)}
          {...register('gonderici_birim')}
        />
      </div>

      <fieldset className="mt-5 border-t border-slate-200 pt-4 dark:border-slate-700">
        <legend className="pr-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          {t('ayarlar.entegrator.senkronAyarlari')}
        </legend>
        <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-2">
          <Input
            id={`entegrator-${ortam}-aralik`}
            type="number"
            label={t('ayarlar.entegrator.senkronAraligi')}
            min={ARALIK.enAz}
            max={ARALIK.enCok}
            step={1}
            hata={alanHatasi(errors.senkron_araligi_dakika?.message)}
            {...register('senkron_araligi_dakika', { valueAsNumber: true })}
          />
          <Input
            id={`entegrator-${ortam}-sayfa`}
            type="number"
            label={t('ayarlar.entegrator.sayfaBoyutu')}
            min={SAYFA.enAz}
            max={SAYFA.enCok}
            step={1}
            hata={alanHatasi(errors.sayfa_boyutu?.message)}
            {...register('sayfa_boyutu', { valueAsNumber: true })}
          />
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.entegrator.senkronAyarlariNotu')}
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
          <p className="font-semibold">{t('ayarlar.entegrator.sinamaBasarili')}</p>
          <p className="mt-1">
            {t('ayarlar.entegrator.tokenGecerlilik', {
              zaman: new Date(sinamaSonucu.gecerlilik_bitis).toLocaleString(),
            })}
          </p>
          <p className="mt-1 text-xs">{t('ayarlar.entegrator.sinamaKapsami')}</p>
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

/** SQL ve entegratör ortamları bağımsız seçilir (EFAT-15, S5); uyuşmazlık yalnız uyarıdır. */
function OrtamUyumu({ veri }: { veri: EntegratorBaglantilar }) {
  const { t } = useTranslation()
  const ortamAdi = (ortam: EntegratorOrtam | null) =>
    ortam ? t(`ayarlar.sql.ortam.${ortam}`) : t('ayarlar.entegrator.secilmedi')

  return (
    <div
      role={veri.ortam_uyumsuz ? 'alert' : undefined}
      className={`mt-4 rounded-xl border px-5 py-3 text-sm ${
        veri.ortam_uyumsuz
          ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200'
          : 'border-slate-200 text-slate-600 dark:border-slate-700 dark:text-slate-300'
      }`}
    >
      <p>
        {t('ayarlar.entegrator.sqlAktifOrtam')}: <strong>{ortamAdi(veri.sql_aktif_ortam)}</strong>
        {' — '}
        {t('ayarlar.entegrator.entegratorAktifOrtam')}:{' '}
        <strong>{ortamAdi(veri.aktif_ortam)}</strong>
      </p>
      {veri.ortam_uyumsuz ? (
        <p className="mt-1 font-semibold">{t('ayarlar.entegrator.ortamUyumsuz')}</p>
      ) : null}
    </div>
  )
}

function AktifOrtamBolumu({
  aktifOrtam,
  testVar,
  canliVar,
}: {
  aktifOrtam: EntegratorOrtam | null
  testVar: boolean
  canliVar: boolean
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // Canlıya geçiş onay ister: alarmlar ve raporlar gerçek faturaları okur
  const [onayBekleyen, setOnayBekleyen] = useState<EntegratorOrtam | null>(null)

  const degistir = useMutation({
    mutationFn: (ortam: EntegratorOrtam) => entegratorAktifOrtamDegistir(ortam),
    onSuccess: async (tanim) => {
      // Eski ortamın fatura listeleri önbellekte kalıp yeni ortamınmış gibi görünmesin
      queryClient.removeQueries({ queryKey: queryKeys.efatura.hepsi })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.entegratorBaglantilari })
      toast.success(
        t('ayarlar.entegrator.aktifYapildi', { ortam: t(`ayarlar.sql.ortam.${tanim.ortam}`) }),
      )
    },
    onError: (error: unknown) => {
      toast.error(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
    onSettled: () => setOnayBekleyen(null),
  })

  const sec = (ortam: EntegratorOrtam) => {
    if (ortam === aktifOrtam) {
      return
    }
    if (ortam === 'canli') {
      setOnayBekleyen(ortam)
      return
    }
    degistir.mutate(ortam)
  }

  const secimSinifi = (ortam: EntegratorOrtam, tanimli: boolean): string => {
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
        {t('ayarlar.entegrator.aktifOrtamBaslik')}
      </p>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
        {t('ayarlar.entegrator.aktifOrtamAciklama')}
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
            {testVar ? t('ayarlar.entegrator.testSecimAciklama') : t('ayarlar.sql.onceTanimla')}
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
            {canliVar ? t('ayarlar.entegrator.canliSecimAciklama') : t('ayarlar.sql.onceTanimla')}
          </span>
        </button>
      </div>

      <ConfirmDialog
        acik={onayBekleyen !== null}
        kapat={() => setOnayBekleyen(null)}
        baslik={t('ayarlar.entegrator.canliOnayBaslik')}
        mesaj={t('ayarlar.entegrator.canliOnayMesaj')}
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

export function EntegratorBaglantilariPage() {
  const { t } = useTranslation()
  const guncelleyebilir = useIzin('entegrator_baglantilari.guncelle')

  const baglantilar = useQuery({
    queryKey: queryKeys.ayarlar.entegratorBaglantilari,
    queryFn: entegratorBaglantilariGetir,
  })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('ayarlar.entegrator.baslik')}</h2>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.entegrator.aciklama')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

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
        // Yalnız görüntüleme izninde ortam seçimi, sınama ve kayıt pasiftir
        <fieldset disabled={!guncelleyebilir} className="min-w-0">
          <OrtamUyumu veri={baglantilar.data} />

          <AktifOrtamBolumu
            aktifOrtam={baglantilar.data.aktif_ortam}
            testVar={baglantilar.data.test !== null}
            canliVar={baglantilar.data.canli !== null}
          />

          <div className="mt-5 grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
            <BaglantiFormu
              key={`test-${formAnahtari(baglantilar.data.test)}`}
              ortam="test"
              tanim={baglantilar.data.test}
              varsayilanAdres={baglantilar.data.varsayilan_api_url.test}
            />
            <BaglantiFormu
              key={`canli-${formAnahtari(baglantilar.data.canli)}`}
              ortam="canli"
              tanim={baglantilar.data.canli}
              varsayilanAdres={baglantilar.data.varsayilan_api_url.canli}
            />
          </div>
        </fieldset>
      ) : null}
    </>
  )
}
