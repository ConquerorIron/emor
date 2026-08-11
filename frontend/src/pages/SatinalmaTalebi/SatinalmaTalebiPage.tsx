import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useRef } from 'react'
import { useFieldArray, useForm, type FieldErrors, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ErrorState } from '@/components/ErrorState'
import { ekranTasariminiGetir } from '@/features/ekranTasarim/api'
import { SATINALMA_TALEP_EKRANI } from '@/features/ekranTasarim/ekranlar'
import {
  bolumGenisligiSinifi,
  genislikSinifi,
  type DuzenBolumu,
  type EkranTasarimi,
  type KatalogAlani,
} from '@/features/ekranTasarim/types'
import { AlanGirisi, girisTipiTanimliMi, ILGI_CINSI_PROJEMIZ, kurGetir } from '@/formAlanlari'
import { FIYAT_GRUPLARI } from './fiyatGruplari'
import { SatirHucresi } from './SatirHucresi'
import { talepKaydet } from './talepApi'
import { SATIR_ALANLARI, satirVarsayilanAnahtari, sutunGenisligi } from './talepAlanlari'
import { BOS_SATIR, BOS_TALEP, talepSemasiUret, type TalepGirdisi } from './talepSchema'

/**
 * Izgaranın iki ucundaki sabit sütunlar. 30'dan fazla kolon var; satır no ve
 * sil düğmesi yatay kaydırmada kaybolursa ızgara kullanılamaz hale geliyor.
 * Arka plan sayfanınkiyle aynı olmalı ki altındaki hücreler sızmasın.
 */
const SABIT_SOL =
  'sticky left-0 z-20 border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800'
const SABIT_SAG =
  'sticky right-0 z-20 border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800'
const SABIT_SOL_GOVDE =
  'sticky left-0 z-10 border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-950'
const SABIT_SAG_GOVDE =
  'sticky right-0 z-10 border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-950'

/** Kolon genişliği: birime göre piksel ya da ekranın yüzdesi */
function kolonGenisligi(genislik: number, yuzdeMi: boolean): React.CSSProperties | undefined {
  if (genislik <= 0) {
    return undefined
  }

  return yuzdeMi ? { width: `${genislik}%` } : { width: genislik, minWidth: genislik }
}

/** Proje değişince temizlenmesi gereken satır anahtarları */
const PROJEYE_BAGLI_SATIR_ANAHTARLARI = [
  'aktivite_id',
  'aktivite_kodu',
  'aktivite_aciklamasi',
  'masraf_merkezi_id',
  'masraf_merkezi_kodu',
  'masraf_merkezi_adi',
] as const

/**
 * Tasarımdaki bir bölümü çizer: alanları kayıt defterindeki bileşenlere
 * yönlendirir, genişlikleri 12'lik ızgaraya oturtur.
 */
function TasarimBolumu({
  bolum,
  baslik,
  katalogHaritasi,
  form,
}: {
  bolum: DuzenBolumu
  /** Boş dizge = başlık gösterme (tasarımcı bilerek sildi) */
  baslik: string
  katalogHaritasi: Map<string, KatalogAlani>
  form: UseFormReturn<TalepGirdisi>
}) {
  return (
    <div className="rounded-xl border border-slate-200 p-5 dark:border-slate-700">
      {baslik !== '' ? (
        <h3 className="mb-4 text-lg font-bold text-slate-800 dark:text-slate-100">{baslik}</h3>
      ) : null}
      <div className="grid grid-cols-1 items-start gap-4 sm:grid-cols-12">
        {bolum.alanlar.map((duzenAlani) => {
          const katalog = katalogHaritasi.get(duzenAlani.alan)

          // Gizli alan çizilmez; değeri form varsayılanı olarak zaten yüklendi
          if (duzenAlani.gizli) {
            return null
          }

          // Katalogda olmayan ya da bileşeni tanımsız alan sessizce atlanır
          // (kod geri alındığında eski tasarım ekranı kırmayı sürdürmesin)
          if (!katalog || !girisTipiTanimliMi(katalog.giris_tipi)) {
            return null
          }

          return (
            <div key={duzenAlani.alan} className={genislikSinifi(duzenAlani.genislik)}>
              {/* Etiket/yıldız/hata/salt-okunur kuralları AlanGirisi içinde uygulanır */}
              <AlanGirisi katalog={katalog} duzen={duzenAlani} form={form} />
            </div>
          )
        })}
      </div>
    </div>
  )
}

export function SatinalmaTalebiPage() {
  const { t } = useTranslation()

  const tasarim = useQuery({
    queryKey: queryKeys.ekranTasarimi(SATINALMA_TALEP_EKRANI),
    queryFn: () => ekranTasariminiGetir(SATINALMA_TALEP_EKRANI),
    staleTime: 5 * 60_000,
  })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('satinalma.baslik')}</h2>
      </div>

      {tasarim.isError ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(tasarim.error))}
            tekrarDene={() => void tasarim.refetch()}
          />
        </div>
      ) : null}

      {tasarim.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {tasarim.isSuccess ? <TalepFormu tasarim={tasarim.data} /> : null}
    </>
  )
}

/**
 * Form, tasarım geldikten SONRA kurulur: zorunlu alanlar tasarımdan geldiği
 * için doğrulama şeması da o anda üretilir.
 */
function TalepFormu({ tasarim }: { tasarim: EkranTasarimi }) {
  const { t } = useTranslation()

  const katalogHaritasi = useMemo(
    () => new Map(tasarim.katalog.map((alan) => [alan.anahtar, alan])),
    [tasarim.katalog],
  )
  const bolumBasliklari = useMemo(
    () => new Map(tasarim.bolumler.map((bolum) => [bolum.anahtar, bolum.baslik_anahtari])),
    [tasarim.bolumler],
  )

  // Tasarımda "zorunlu" işaretli alanların yazdığı form anahtarları
  const zorunluAnahtarlar = useMemo(() => {
    const anahtarlar: string[] = []
    for (const bolum of tasarim.duzen.bolumler) {
      for (const alan of bolum.alanlar) {
        // Gizli alan kullanıcı tarafından doldurulamaz — zorunlu sayılmaz
        if (!alan.zorunlu || alan.gizli) {
          continue
        }
        const katalog = katalogHaritasi.get(alan.alan)
        // Çok anahtarlı alanlarda ilki kimliktir (ör. personel_id)
        if (katalog?.veri_anahtarlari[0]) {
          anahtarlar.push(katalog.veri_anahtarlari[0])
        }
      }
    }

    return anahtarlar
  }, [tasarim.duzen, katalogHaritasi])

  /**
   * Yeni satırın açılış değerleri. Tasarımda varsayılan verilen kolonlar
   * (gizlenmiş olsalar bile) satıra işlenir — kullanıcı görmese de ERP'ye
   * gitmesi gereken değerler böyle taşınır.
   */
  const bosSatir = useMemo(() => {
    const satir = { ...BOS_SATIR }

    for (const kolon of tasarim.duzen.satirlar ?? []) {
      const tanim = SATIR_ALANLARI.find((alan) => alan.etiketAnahtari === kolon.alan)
      const anahtar = tanim ? satirVarsayilanAnahtari(tanim.hucre) : null

      if (anahtar && anahtar !== 'ozellikler' && (kolon.varsayilan ?? '') !== '') {
        satir[anahtar] = kolon.varsayilan as never
      }
    }

    return satir
  }, [tasarim.duzen.satirlar])

  const varsayilanlar = useMemo(() => {
    const deger: Record<string, string> = {}
    for (const bolum of tasarim.duzen.bolumler) {
      for (const alan of bolum.alanlar) {
        const katalog = katalogHaritasi.get(alan.alan)
        if (alan.varsayilan && katalog?.veri_anahtarlari[0]) {
          deger[katalog.veri_anahtarlari[0]] = alan.varsayilan
        }
      }
    }

    return { ...BOS_TALEP, satirlar: [bosSatir], ...deger }
  }, [tasarim.duzen, katalogHaritasi, bosSatir])

  const form = useForm<TalepGirdisi>({
    resolver: standardSchemaResolver(
      talepSemasiUret(
        zorunluAnahtarlar,
        // Zorunluluk ERP'nin tanımından gelir, tasarımdan değil
        (tasarim.satir_katalogu ?? [])
          .filter((alan) => alan.zorunlu === true && alan.ozellik_id !== undefined)
          .map((alan) => alan.ozellik_id as number),
      ),
    ),
    defaultValues: varsayilanlar,
  })

  const {
    control,
    handleSubmit,
    formState: { isSubmitting },
  } = form
  const satirlar = useFieldArray({ control, name: 'satirlar' })
  const istemci = useQueryClient()

  /**
   * Izgara kolonları tasarımdan gelir: sıra, görünürlük ve piksel genişlik.
   * Tasarımda satır düzeni yoksa katalog sırası kullanılır — ekran bugünkü
   * haliyle açılır, motor devreye girmeden önceki davranış korunur.
   */
  const yuzdeMi = tasarim.duzen.satir_genislik_birimi === 'yuzde'

  const kolonlar = useMemo(() => {
    const tanimlar = new Map(SATIR_ALANLARI.map((alan) => [alan.etiketAnahtari, alan]))
    const duzen = tasarim.duzen.satirlar ?? []

    if (duzen.length === 0) {
      return SATIR_ALANLARI.map((alan) => ({ alan, genislik: 0 }))
    }

    // ERP özel alanları sabit listede yok: kolon tanımı katalogdan üretilir
    const ozellikler = new Map(
      (tasarim.satir_katalogu ?? [])
        .filter((alan) => alan.ozellik_id !== undefined)
        .map((alan) => [alan.anahtar, alan]),
    )

    return duzen.flatMap((kolon) => {
      if (kolon.gizli === true) {
        return []
      }

      const ozellik = ozellikler.get(kolon.alan)
      if (ozellik?.ozellik_id !== undefined) {
        return [
          {
            alan: {
              etiketAnahtari: kolon.alan,
              etiket: ozellik.etiket,
              hucre: { tip: 'ozellik' as const, ozellikId: ozellik.ozellik_id },
            },
            genislik: kolon.genislik,
          },
        ]
      }

      // Çizimi henüz tanımlanmamış kolon sessizce atlanır
      const alan = tanimlar.get(kolon.alan)

      return alan ? [{ alan, genislik: kolon.genislik }] : []
    })
  }, [tasarim.duzen.satirlar, tasarim.satir_katalogu])

  // Satır listeleri (aktivite, masraf merkezi) başlıktaki projeye bağlıdır;
  // proje yalnız "İlgi konusu = Projemiz" iken anlamlıdır
  const projeliMi = form.watch('ilgi_cinsi') === ILGI_CINSI_PROJEMIZ
  const projemizId = projeliMi ? form.watch('ilgili_id') : ''
  const projeKodu = projeliMi ? form.watch('ilgili_kodu') : ''

  // Proje değişince eski projenin aktivite/masraf merkezi seçimleri geçersizdir
  const oncekiProje = useRef(projemizId)
  useEffect(() => {
    if (oncekiProje.current === projemizId) {
      return
    }
    oncekiProje.current = projemizId
    form.getValues('satirlar').forEach((_, indeks) => {
      for (const anahtar of PROJEYE_BAGLI_SATIR_ANAHTARLARI) {
        form.setValue(`satirlar.${indeks}.${anahtar}`, '')
      }
    })
  }, [projemizId, form])

  // Kur belge tarihine bağlıdır: tarih değişince satırlardaki kurlar (ve
  // onlardan hesaplanan tutarlar) yenilenir — kullanıcı bildirimi 2026-08-07
  const tarih = form.watch('tarih')
  const oncekiTarih = useRef(tarih)
  useEffect(() => {
    if (oncekiTarih.current === tarih) {
      return
    }
    oncekiTarih.current = tarih

    void (async () => {
      const mevcut = form.getValues('satirlar')
      for (const [indeks, satir] of mevcut.entries()) {
        for (const grup of Object.values(FIYAT_GRUPLARI)) {
          const paraId = satir[grup.para]
          if (paraId === '') {
            continue
          }
          // Aynı para+tarih için tek istek atılır (önbellek anahtarı ortak)
          const kur = await istemci.fetchQuery({
            queryKey: queryKeys.secenekler.kur(paraId, tarih),
            queryFn: () => kurGetir(paraId, tarih),
            staleTime: 60 * 60_000,
          })
          form.setValue(`satirlar.${indeks}.${grup.kur}`, kur)
        }
      }
    })()
  }, [tarih, form, istemci])

  // Kayıt doğrudan ERP'ye gider (SOHOM_SIPARIS_KAYDET); talep numarasını ERP üretir
  const kayit = useMutation({
    mutationFn: talepKaydet,
    onSuccess: (sonuc) => {
      toast.success(t('satinalma.kaydedildi', { no: sonuc.talep_no }))
      form.reset(varsayilanlar)
    },
    onError: (hata) => toast.error(t(apiErrorKey(hata))),
  })

  const onSubmit = handleSubmit(
    (girdi) => {
      kayit.mutate(girdi)
    },
    (hatalar: FieldErrors<TalepGirdisi>) => {
      const satirHatasi = hatalar.satirlar?.root?.message ?? hatalar.satirlar?.message
      if (satirHatasi) {
        toast.error(t(satirHatasi))
      }
    },
  )

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate>
      <div className="mt-4 grid grid-cols-1 items-start gap-5 xl:grid-cols-12">
        {tasarim.duzen.bolumler.map((bolum) => (
          <div key={bolum.anahtar} className={bolumGenisligiSinifi(bolum.genislik)}>
            <TasarimBolumu
              bolum={bolum}
              // Tasarımda başlık yazılmışsa o; yoksa katalogun i18n başlığı
              baslik={bolum.baslik ?? t(bolumBasliklari.get(bolum.anahtar) ?? bolum.anahtar)}
              katalogHaritasi={katalogHaritasi}
              form={form}
            />
          </div>
        ))}
      </div>

      <div className="mt-5 rounded-xl border border-slate-200 p-5 dark:border-slate-700">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-lg font-bold text-slate-800 dark:text-slate-100">
            {t('satinalma.satirlar')}
          </h3>
          <Button type="button" variant="mor" onClick={() => satirlar.append({ ...bosSatir })}>
            {t('satinalma.satirEkle')}
          </Button>
        </div>

        {/* Sütunlar doğal genişliklerini alsın diye tablo içeriğe göre büyür
            (w-full olsaydı 38 sütun ekrana sıkıştırılır, hücreler ezilirdi) */}
        <div className="overflow-x-auto">
          {/* Genişlikler tasarımdan geldiğinde table-fixed şart: aksi halde
              tarayıcı kolonları içeriğe/başlık metnine göre esnetiyor ve
              verilen px değeri tutmuyor (kullanıcı bildirimi 2026-08-10) */}
          <table
            className={`border-separate border-spacing-0 ${
              yuzdeMi
                ? // Yüzde ekranın payıdır: tablo ekranı doldurur, kaydırma olmaz
                  'w-full table-fixed'
                : kolonlar.some((k) => k.genislik > 0)
                  ? 'w-max table-fixed'
                  : 'w-max min-w-full'
            }`}
          >
            <thead>
              <tr>
                {/* Izgara çok geniş: satır no ve sil düğmesi yatay kaydırmada
                    kaybolmasın diye iki uçta sabit kalır */}
                <th
                  className={`w-10 rounded-tl-lg border-y border-l px-2 py-2 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase ${SABIT_SOL} dark:text-slate-400`}
                >
                  #
                </th>
                {kolonlar.map(({ alan, genislik }) => (
                  <th
                    key={alan.etiketAnahtari}
                    // Tasarımda genişlik verilmişse piksel, yoksa katalog sınıfı
                    style={kolonGenisligi(genislik, yuzdeMi)}
                    // Başlık metni nowrap olursa kolonu kendi uzunluğu kadar
                    // zorluyor; verilen genişlikte kırpılmalı
                    className={`overflow-hidden border-y border-slate-200 bg-slate-50 px-2 py-2 text-left text-xs font-semibold tracking-wide text-ellipsis text-slate-500 uppercase dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400 ${genislik > 0 ? 'whitespace-nowrap' : `whitespace-nowrap ${sutunGenisligi(alan.hucre)}`}`}
                  >
                    {alan.zorunlu ? '* ' : ''}
                    {t(`satinalma.alan.${alan.etiketAnahtari}`)}
                  </th>
                ))}
                <th className={`w-12 rounded-tr-lg border-y border-r px-2 py-2 ${SABIT_SAG}`} />
              </tr>
            </thead>
            <tbody>
              {satirlar.fields.map((satir, indeks) => (
                <tr key={satir.id}>
                  <td
                    className={`border-b border-l px-2 py-1.5 text-sm text-slate-500 ${SABIT_SOL_GOVDE} dark:text-slate-400`}
                  >
                    {indeks + 1}
                  </td>
                  {kolonlar.map(({ alan, genislik }) => (
                    <td
                      key={alan.etiketAnahtari}
                      style={kolonGenisligi(genislik, yuzdeMi)}
                      className={`border-b border-slate-200 px-1 py-1.5 align-top dark:border-slate-700 ${genislik > 0 ? '' : sutunGenisligi(alan.hucre)}`}
                    >
                      <SatirHucresi
                        alan={alan}
                        indeks={indeks}
                        form={form}
                        projemizId={projemizId}
                        projeKodu={projeKodu}
                        tarih={tarih}
                      />
                    </td>
                  ))}
                  <td className={`border-r border-b px-1 py-1.5 text-center ${SABIT_SAG_GOVDE}`}>
                    <button
                      type="button"
                      onClick={() => satirlar.remove(indeks)}
                      disabled={satirlar.fields.length === 1}
                      title={t('satinalma.satirSil')}
                      aria-label={`${t('satinalma.satirSil')} ${indeks + 1}`}
                      className="cursor-pointer rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-red-950 dark:hover:text-red-400"
                    >
                      <svg
                        aria-hidden="true"
                        className="size-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.8}
                      >
                        <path
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"
                        />
                      </svg>
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="mt-5 flex justify-end">
        <Button type="submit" yukleniyor={isSubmitting || kayit.isPending}>
          {t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}
