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
import {
  isgucuKesintisiKaydet,
  MESAI_TURLERI,
  VASIFLAR,
  YANSITMA_TURLERI,
  type IsgucuKesintisi,
  type KesintiSecenekleri,
  type KesintiTanimi,
  type MesaiTuru,
  type Vasif,
  type YansitmaTuru,
} from '@/features/kesinti/isgucuApi'

const ZORUNLU = 'kesinti.isgucu.dogrulama.zorunlu'
const metin = (sinir: 50 | 200) =>
  z.string().trim().max(sinir, `kesinti.isgucu.dogrulama.cokUzun${sinir}`)

/** Backend sınırlarıyla aynı (IsgucuKesintisiKaydetRequest) */
const kesintiSemasi = z.object({
  kesinti_turu_id: z.string().min(1, ZORUNLU),
  firma_id: z.string().min(1, ZORUNLU),
  proje_id: z.string().min(1, ZORUNLU),
  para_birimi_id: z.string().min(1, ZORUNLU),
  tarih: z.string().min(1, ZORUNLU),
  adi_soyadi: metin(200).min(1, ZORUNLU),
  vasif: z.string().min(1, ZORUNLU),
  mesai_turu: z.string().min(1, ZORUNLU),
  yansitma_turu: z.string().min(1, ZORUNLU),
  mahal: metin(200),
  kaynak_firma: metin(200),
  kaynak_fis_no: metin(50),
  tanim: metin(200),
  gorev: metin(200),
  departman: metin(200),
  aktivite: metin(200),
  // SayiAlani noktalı biçimde, her zaman geçerli sayı yazar
  miktar_saat: z.string(),
  miktar_yevmiye: z.string(),
  birim_fiyat: z.string(),
  toplam_tutar: z.string(),
  iptal_edilen_tutar: z.string(),
  kesilen_tutar: z.string(),
})

type KesintiGirdisi = z.infer<typeof kesintiSemasi>

/** Kuruş üzerinden fark: float yuvarlaması olmasın ("2562.15" − "100.00") */
function fark(a: string, b: string): string {
  return ((Math.round(Number(a || 0) * 100) - Math.round(Number(b || 0) * 100)) / 100).toFixed(2)
}

function tanimSecenekleri(liste: KesintiTanimi[]): SecenekOgesi[] {
  return liste.map((tanim) => ({ value: String(tanim.id), label: `${tanim.kod} — ${tanim.ad}` }))
}

function bosIseNull(deger: string): string | null {
  return deger === '' ? null : deger
}

export function IsgucuKesintisiFormu({
  kesinti,
  secenekler,
  kapat,
}: {
  kesinti: IsgucuKesintisi | null
  secenekler: KesintiSecenekleri
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
  } = useForm<KesintiGirdisi>({
    resolver: standardSchemaResolver(kesintiSemasi),
    defaultValues: {
      kesinti_turu_id: kesinti ? String(kesinti.kesinti_turu.id) : '',
      firma_id: kesinti ? String(kesinti.firma.id) : '',
      proje_id: kesinti ? String(kesinti.proje.id) : '',
      // Yeni kayıtta Excel'lerdeki tek para birimi olan TL önerilir
      para_birimi_id: kesinti
        ? String(kesinti.para_birimi.id)
        : String(secenekler.para_birimleri.find((para) => para.kod === 'TL')?.id ?? ''),
      tarih: kesinti?.tarih ?? '',
      adi_soyadi: kesinti?.adi_soyadi ?? '',
      vasif: kesinti?.vasif ?? '',
      mesai_turu: kesinti?.mesai_turu ?? '',
      yansitma_turu: kesinti?.yansitma_turu ?? '',
      mahal: kesinti?.mahal ?? '',
      kaynak_firma: kesinti?.kaynak_firma ?? '',
      kaynak_fis_no: kesinti?.kaynak_fis_no ?? '',
      tanim: kesinti?.tanim ?? '',
      gorev: kesinti?.gorev ?? '',
      departman: kesinti?.departman ?? '',
      aktivite: kesinti?.aktivite ?? '',
      miktar_saat: kesinti?.miktar_saat ?? '0.00',
      miktar_yevmiye: kesinti?.miktar_yevmiye ?? '0.00',
      birim_fiyat: kesinti?.birim_fiyat ?? '0.0000',
      toplam_tutar: kesinti?.toplam_tutar ?? '0.00',
      iptal_edilen_tutar: kesinti?.iptal_edilen_tutar ?? '0.00',
      kesilen_tutar: kesinti?.kesilen_tutar ?? '0.00',
    },
  })

  // Elle girişte hesaplanır (kullanıcı kararı): yansıtılan = toplam − iptal, kalan = yansıtılan − kesilen
  const [toplam, iptal, kesilen] = useWatch({
    control,
    name: ['toplam_tutar', 'iptal_edilen_tutar', 'kesilen_tutar'],
  })
  const yansitilan = fark(toplam, iptal)
  const kalan = fark(yansitilan, kesilen)

  const kaydet = useMutation({
    mutationFn: (girdi: KesintiGirdisi) =>
      isgucuKesintisiKaydet(kesinti?.id ?? null, {
        ...girdi,
        kesinti_turu_id: Number(girdi.kesinti_turu_id),
        firma_id: Number(girdi.firma_id),
        proje_id: Number(girdi.proje_id),
        para_birimi_id: Number(girdi.para_birimi_id),
        vasif: girdi.vasif as Vasif,
        mesai_turu: girdi.mesai_turu as MesaiTuru,
        yansitma_turu: girdi.yansitma_turu as YansitmaTuru,
        mahal: bosIseNull(girdi.mahal),
        kaynak_firma: bosIseNull(girdi.kaynak_firma),
        kaynak_fis_no: bosIseNull(girdi.kaynak_fis_no),
        tanim: bosIseNull(girdi.tanim),
        gorev: bosIseNull(girdi.gorev),
        departman: bosIseNull(girdi.departman),
        aktivite: bosIseNull(girdi.aktivite),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuListeleri })
      toast.success(t('kesinti.isgucu.kaydedildi'))
      kapat()
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  const hata = (mesaj: string | undefined) => (mesaj ? t(mesaj) : undefined)

  const tanimSecimi = (
    ad: 'kesinti_turu_id' | 'firma_id' | 'proje_id' | 'para_birimi_id',
    etiket: string,
    liste: KesintiTanimi[],
  ) => {
    const secenekListesi = tanimSecenekleri(liste)

    return (
      <Controller
        control={control}
        name={ad}
        render={({ field }) => (
          <SelectField
            id={`isgucu-${ad}`}
            label={etiket}
            options={secenekListesi}
            value={secenekListesi.find((secenek) => secenek.value === field.value) ?? null}
            onChange={(secenek) => field.onChange(secenek?.value ?? '')}
            hata={hata(errors[ad]?.message)}
          />
        )}
      />
    )
  }

  const sabitSecim = (
    ad: 'vasif' | 'mesai_turu' | 'yansitma_turu',
    degerler: readonly string[],
  ) => {
    const secenekListesi = degerler.map((deger) => ({
      value: deger,
      label: t(`kesinti.isgucu.secenek.${ad}.${deger}`),
    }))

    return (
      <Controller
        control={control}
        name={ad}
        render={({ field }) => (
          <SelectField
            id={`isgucu-${ad}`}
            label={t(`kesinti.isgucu.alan.${ad}`)}
            options={secenekListesi}
            value={secenekListesi.find((secenek) => secenek.value === field.value) ?? null}
            onChange={(secenek) => field.onChange(secenek?.value ?? '')}
            isSearchable={false}
            hata={hata(errors[ad]?.message)}
          />
        )}
      />
    )
  }

  const sayiAlani = (
    ad:
      | 'miktar_saat'
      | 'miktar_yevmiye'
      | 'birim_fiyat'
      | 'toplam_tutar'
      | 'iptal_edilen_tutar'
      | 'kesilen_tutar',
    ondalik: number,
  ) => (
    <Controller
      control={control}
      name={ad}
      render={({ field }) => (
        <SayiAlani
          id={`isgucu-${ad}`}
          label={t(`kesinti.isgucu.alan.${ad}`)}
          value={field.value}
          onChange={field.onChange}
          ondalik={ondalik}
        />
      )}
    />
  )

  const metinAlani = (
    ad:
      | 'adi_soyadi'
      | 'mahal'
      | 'kaynak_firma'
      | 'kaynak_fis_no'
      | 'tanim'
      | 'gorev'
      | 'departman'
      | 'aktivite',
    sinir: number,
  ) => (
    <Input
      id={`isgucu-${ad}`}
      label={t(`kesinti.isgucu.alan.${ad}`)}
      maxLength={sinir}
      hata={hata(errors[ad]?.message)}
      {...register(ad)}
    />
  )

  const bolumBasligi = (anahtar: string) => (
    <h3 className="text-sm font-semibold tracking-wide text-slate-500 uppercase sm:col-span-4 dark:text-slate-400">
      {t(anahtar)}
    </h3>
  )

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-4">
        {bolumBasligi('kesinti.isgucu.bolum.kesinti')}
        {tanimSecimi(
          'kesinti_turu_id',
          t('kesinti.isgucu.alan.kesinti_turu'),
          secenekler.kesinti_turleri,
        )}
        {tanimSecimi('firma_id', t('kesinti.isgucu.alan.taseron'), secenekler.firmalar)}
        {tanimSecimi('proje_id', t('kesinti.isgucu.alan.proje'), secenekler.projeler)}
        <Controller
          control={control}
          name="tarih"
          render={({ field }) => (
            <TarihInput
              id="isgucu-tarih"
              label={t('kesinti.isgucu.alan.tarih')}
              value={field.value}
              onChange={field.onChange}
              hata={hata(errors.tarih?.message)}
            />
          )}
        />

        {bolumBasligi('kesinti.isgucu.bolum.personel')}
        <div className="sm:col-span-2">{metinAlani('adi_soyadi', 200)}</div>
        {sabitSecim('vasif', VASIFLAR)}
        {sabitSecim('mesai_turu', MESAI_TURLERI)}
        {sabitSecim('yansitma_turu', YANSITMA_TURLERI)}
        {metinAlani('kaynak_firma', 200)}
        {metinAlani('kaynak_fis_no', 50)}
        {metinAlani('mahal', 200)}

        {bolumBasligi('kesinti.isgucu.bolum.is')}
        {metinAlani('tanim', 200)}
        {metinAlani('gorev', 200)}
        {metinAlani('departman', 200)}
        {metinAlani('aktivite', 200)}

        {bolumBasligi('kesinti.isgucu.bolum.tutarlar')}
        {sayiAlani('miktar_saat', 2)}
        {sayiAlani('miktar_yevmiye', 2)}
        {sayiAlani('birim_fiyat', 4)}
        {tanimSecimi(
          'para_birimi_id',
          t('kesinti.isgucu.alan.para_birimi'),
          secenekler.para_birimleri,
        )}
        {sayiAlani('toplam_tutar', 2)}
        {sayiAlani('iptal_edilen_tutar', 2)}
        <SayiAlani
          id="isgucu-yansitilan_tutar"
          label={t('kesinti.isgucu.alan.yansitilan_tutar')}
          value={yansitilan}
          onChange={() => undefined}
          ondalik={2}
          disabled
        />
        {sayiAlani('kesilen_tutar', 2)}
        <SayiAlani
          id="isgucu-kalan_tutar"
          label={t('kesinti.isgucu.alan.kalan_tutar')}
          value={kalan}
          onChange={() => undefined}
          ondalik={2}
          disabled
        />
        <p className="self-end text-xs text-slate-500 sm:col-span-3 dark:text-slate-400">
          {t('kesinti.isgucu.hesapAciklamasi')}
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
