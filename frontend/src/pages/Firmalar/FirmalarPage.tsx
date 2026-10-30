import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { Pagination } from '@/components/Pagination'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { Switch } from '@/components/Switch'
import { Degisim, ErpAktarimPenceresi } from '@/features/kesinti/ErpAktarimPenceresi'
import {
  erpFirmalariniAktar,
  erpFirmalariniGetir,
  firmaKaydet,
  firmalariExceleAktar,
  firmalariGetir,
  firmaSil,
  type ErpFirmaSatiri,
  type Firma,
} from '@/features/kesinti/firmaApi'
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { buyukHarfKaydi, trBuyukHarf } from '@/utils/metin'

/** Canlıda ~1.700 taşeron var: liste istemcide süzülür ve sayfalanır */
const SAYFA_BOYUTU = 50

/** Backend sınırlarıyla aynı (FirmaKaydetRequest) */
const firmaSemasi = z.object({
  kod: z
    .string()
    .trim()
    .min(1, 'kesinti.firmalar.dogrulama.kodZorunlu')
    .max(50, 'kesinti.firmalar.dogrulama.kodCokUzun'),
  ad: z
    .string()
    .trim()
    .min(1, 'kesinti.firmalar.dogrulama.adZorunlu')
    .max(200, 'kesinti.firmalar.dogrulama.adCokUzun'),
  vergi_kimlik_no: z.string().trim().max(20, 'kesinti.firmalar.dogrulama.vknCokUzun'),
  vergi_dairesi: z.string().trim().max(100, 'kesinti.firmalar.dogrulama.vergiDairesiCokUzun'),
  eposta: z.string().trim().max(255, 'kesinti.firmalar.dogrulama.epostaCokUzun'),
  gsm_no: z.string().trim().max(30, 'kesinti.firmalar.dogrulama.gsmCokUzun'),
  firmamiz: z.boolean(),
})

type FirmaGirdisi = z.infer<typeof firmaSemasi>

/** Boş isteğe bağlı alan null gider */
function bosIseNull(deger: string): string | null {
  return deger === '' ? null : deger
}

function FirmaFormu({
  firma,
  mevcutFirmamiz,
  kapat,
}: {
  firma: Firma | null
  /** Şu an "firmamız" işaretli firma (varsa) */
  mevcutFirmamiz: Firma | null
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
  } = useForm<FirmaGirdisi>({
    resolver: standardSchemaResolver(firmaSemasi),
    defaultValues: {
      kod: firma?.kod ?? '',
      ad: firma?.ad ?? '',
      vergi_kimlik_no: firma?.vergi_kimlik_no ?? '',
      vergi_dairesi: firma?.vergi_dairesi ?? '',
      eposta: firma?.eposta ?? '',
      gsm_no: firma?.gsm_no ?? '',
      firmamiz: firma?.firmamiz ?? false,
    },
  })
  const firmamizIsaretli = useWatch({ control, name: 'firmamiz' })
  // İşaret başka bir firmadan bu firmaya geçecekse kaydetmeden önce söylenir
  const isaretGececek =
    firmamizIsaretli && mevcutFirmamiz !== null && mevcutFirmamiz.id !== firma?.id

  const kaydet = useMutation({
    mutationFn: (girdi: FirmaGirdisi) =>
      firmaKaydet(firma?.id ?? null, {
        kod: girdi.kod,
        ad: girdi.ad,
        vergi_kimlik_no: bosIseNull(girdi.vergi_kimlik_no),
        vergi_dairesi: bosIseNull(girdi.vergi_dairesi),
        eposta: bosIseNull(girdi.eposta),
        gsm_no: bosIseNull(girdi.gsm_no),
        firmamiz: girdi.firmamiz,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.firmalar })
      toast.success(t('kesinti.firmalar.kaydedildi'))
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

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      {firma?.erp_guid ? (
        <p className="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-950 dark:text-sky-300">
          {t('kesinti.firmalar.erpFirmasiUyarisi')}
        </p>
      ) : null}
      <div className="grid gap-4 sm:grid-cols-3">
        <Input
          id="firma-kod"
          label={t('kesinti.firmalar.kod')}
          maxLength={50}
          hata={hata(errors.kod?.message)}
          // Kod büyük/küçük harf ayırmaz: yazarken Türkçe kurallı büyük harfe döner
          {...register('kod', buyukHarfKaydi)}
        />
        <div className="sm:col-span-2">
          <Input
            id="firma-ad"
            label={t('kesinti.firmalar.ad')}
            maxLength={200}
            hata={hata(errors.ad?.message)}
            {...register('ad')}
          />
        </div>
        <Input
          id="firma-vkn"
          label={t('kesinti.firmalar.vkn')}
          maxLength={20}
          inputMode="numeric"
          hata={hata(errors.vergi_kimlik_no?.message)}
          {...register('vergi_kimlik_no')}
        />
        <div className="sm:col-span-2">
          <Input
            id="firma-vergi-dairesi"
            label={t('kesinti.firmalar.vergiDairesi')}
            maxLength={100}
            hata={hata(errors.vergi_dairesi?.message)}
            {...register('vergi_dairesi')}
          />
        </div>
        <div className="sm:col-span-2">
          <Input
            id="firma-eposta"
            type="email"
            label={t('kesinti.firmalar.eposta')}
            maxLength={255}
            hata={hata(errors.eposta?.message)}
            {...register('eposta')}
          />
        </div>
        <Input
          id="firma-gsm"
          type="tel"
          label={t('kesinti.firmalar.gsm')}
          maxLength={30}
          hata={hata(errors.gsm_no?.message)}
          {...register('gsm_no')}
        />
      </div>

      <div className="space-y-2">
        <Controller
          control={control}
          name="firmamiz"
          render={({ field }) => (
            <Switch
              id="firma-firmamiz"
              label={t('kesinti.firmalar.firmamiz')}
              checked={field.value}
              onChange={field.onChange}
            />
          )}
        />
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('kesinti.firmalar.firmamizAciklama')}
        </p>
        {isaretGececek ? (
          <p
            role="status"
            className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-300"
          >
            {t('kesinti.firmalar.firmamizDegisecek', {
              kod: mevcutFirmamiz.kod,
              ad: mevcutFirmamiz.ad,
            })}
          </p>
        ) : null}
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

export function FirmalarPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('firmalar.guncelle')
  // null: kapalı; 'yeni': yeni firma; Firma: düzenleme
  const [formdaki, setFormdaki] = useState<Firma | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<Firma | null>(null)
  const [erpAcik, setErpAcik] = useState(false)
  const [ara, setAra] = useState('')
  const [sayfa, setSayfa] = useState(1)

  const firmalar = useQuery({ queryKey: queryKeys.kesinti.firmalar, queryFn: firmalariGetir })

  const sil = useMutation({
    mutationFn: (firma: Firma) => firmaSil(firma.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.firmalar })
      toast.success(t('kesinti.firmalar.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  // Kolon başlığına tıklayınca sıralanır (tercih hatırlanır); yeni sıra 1. sayfadan başlar
  const { siralama, siralamaDegistir: kaliciSiralamaDegistir } = useKaliciSiralama('firmalar')
  const siralamaDegistir = (anahtar: string) => {
    kaliciSiralamaDegistir(anahtar)
    setSayfa(1)
  }

  const aranan = trBuyukHarf(ara.trim())
  const tumFirmalar = firmalar.data ?? []
  // Excel'deki firma adı koda göre eşleşir: birden fazla firmada geçen kod uyarılır
  const kodSayilari = new Map<string, number>()
  for (const firma of tumFirmalar) {
    const kod = trBuyukHarf(firma.kod)
    kodSayilari.set(kod, (kodSayilari.get(kod) ?? 0) + 1)
  }
  const tekrarlananKodlar = new Set(
    [...kodSayilari].filter(([, sayi]) => sayi > 1).map(([kod]) => kod),
  )
  const suzulenler =
    aranan === ''
      ? tumFirmalar
      : tumFirmalar.filter((firma) =>
          trBuyukHarf(`${firma.kod} ${firma.ad} ${firma.vergi_kimlik_no ?? ''}`).includes(aranan),
        )
  // Sıralama sayfalamadan önce, süzülmüş listenin tamamına uygulanır
  const siraliFirmalar = siralaUygula(suzulenler, siralama, {
    vkn: (firma) => firma.vergi_kimlik_no,
    gsm: (firma) => firma.gsm_no,
    kaynak: (firma) => (firma.erp_guid === null ? 0 : 1),
  })
  // Ekrandaki arama ve sıralamayla tüm firmalar (yalnız bu sayfa değil), tüm kolonlar
  const excel = useMutation({
    mutationFn: () =>
      firmalariExceleAktar(
        siraliFirmalar.map((firma) => firma.id),
        `${t('kesinti.firmalar.baslik')}.xlsx`,
      ),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const toplamSayfa = Math.max(1, Math.ceil(suzulenler.length / SAYFA_BOYUTU))
  // Silme sonrası liste kısalınca boş sayfada kalınmaz
  const gecerliSayfa = Math.min(sayfa, toplamSayfa)
  const sayfadakiler = siraliFirmalar.slice(
    (gecerliSayfa - 1) * SAYFA_BOYUTU,
    gecerliSayfa * SAYFA_BOYUTU,
  )

  const bosIseTire = (deger: string | null) =>
    deger === null ? <span className="text-slate-400">—</span> : deger

  const tumKolonlar: DataTableKolonu<Firma>[] = [
    {
      anahtar: 'kod',
      siralamaAnahtari: 'kod',
      baslik: t('kesinti.firmalar.kod'),
      render: (firma) => (
        <span className="inline-flex items-center gap-2">
          <strong>{firma.kod}</strong>
          {firma.firmamiz ? (
            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
              {t('kesinti.firmalar.firmamiz')}
            </span>
          ) : null}
          {tekrarlananKodlar.has(trBuyukHarf(firma.kod)) ? (
            <span
              className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-amber-800 dark:bg-amber-950 dark:text-amber-300"
              title={t('kesinti.firmalar.kodTekrarAciklama')}
            >
              {t('kesinti.firmalar.kodTekrar')}
            </span>
          ) : null}
        </span>
      ),
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.firmalar.ad'),
      siralamaAnahtari: 'ad',
      render: (firma) => firma.ad,
    },
    {
      anahtar: 'vkn',
      siralamaAnahtari: 'vkn',
      baslik: t('kesinti.firmalar.vkn'),
      render: (firma) => bosIseTire(firma.vergi_kimlik_no),
    },
    {
      anahtar: 'vergi_dairesi',
      siralamaAnahtari: 'vergi_dairesi',
      baslik: t('kesinti.firmalar.vergiDairesi'),
      render: (firma) => bosIseTire(firma.vergi_dairesi),
    },
    {
      anahtar: 'eposta',
      siralamaAnahtari: 'eposta',
      baslik: t('kesinti.firmalar.eposta'),
      render: (firma) => bosIseTire(firma.eposta),
    },
    {
      anahtar: 'gsm',
      siralamaAnahtari: 'gsm',
      baslik: t('kesinti.firmalar.gsm'),
      render: (firma) => bosIseTire(firma.gsm_no),
    },
    {
      anahtar: 'kaynak',
      siralamaAnahtari: 'kaynak',
      baslik: t('kesinti.firmalar.kaynak'),
      render: (firma) =>
        firma.erp_guid === null ? (
          <span className="text-slate-500 dark:text-slate-400">
            {t('kesinti.firmalar.kaynakElle')}
          </span>
        ) : (
          <span className="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
            {t('kesinti.firmalar.kaynakErp')}
          </span>
        ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (firma) => (
        <div className="flex justify-end gap-2">
          <Button variant="turuncu" onClick={() => setFormdaki(firma)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(firma)}>
            {t('ortak.sil')}
          </Button>
        </div>
      ),
    },
  ]
  // Yalnız görüntüleme izninde düzenle/sil kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  const erpKolonlari: DataTableKolonu<ErpFirmaSatiri>[] = [
    {
      anahtar: 'kod',
      baslik: t('kesinti.firmalar.kod'),
      render: (satir) => (
        // Kod uygulamaya aittir: aktarım ezmez, eşleşen firmanın kodu kalır
        <strong>{satir.mevcut?.kod ?? satir.kod}</strong>
      ),
    },
    {
      anahtar: 'ad',
      baslik: t('kesinti.firmalar.ad'),
      render: (satir) => <Degisim eski={satir.mevcut?.ad} yeni={satir.ad} />,
    },
    ...(
      [
        ['vergi_kimlik_no', 'kesinti.firmalar.vkn'],
        ['vergi_dairesi', 'kesinti.firmalar.vergiDairesi'],
        ['eposta', 'kesinti.firmalar.eposta'],
        ['gsm_no', 'kesinti.firmalar.gsm'],
      ] as const
    ).map(([alan, etiket]): DataTableKolonu<ErpFirmaSatiri> => ({
      anahtar: alan,
      baslik: t(etiket),
      render: (satir) => <Degisim eski={satir.mevcut?.[alan]} yeni={satir[alan]} />,
    })),
  ]

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.firmalar.baslik')}</h2>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            disabled={!firmalar.isSuccess}
            yukleniyor={excel.isPending}
            onClick={() => excel.mutate()}
          >
            {t('kesinti.firmalar.excelAktar')}
          </Button>
          {guncelleyebilir ? (
            <>
              <Button variant="secondary" onClick={() => setErpAcik(true)}>
                {t('kesinti.firmalar.erpdenGetir')}
              </Button>
              <Button variant="mor" onClick={() => setFormdaki('yeni')}>
                {t('kesinti.firmalar.yeni')}
              </Button>
            </>
          ) : null}
        </div>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.firmalar.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      {firmalar.error ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(firmalar.error))}
            tekrarDene={() => void firmalar.refetch()}
          />
        </div>
      ) : (
        <>
          <div className="mt-4 flex flex-wrap items-end justify-between gap-3">
            <div className="w-full sm:w-80">
              <Input
                id="firma-ara"
                type="search"
                label={t('kesinti.firmalar.ara')}
                value={ara}
                onChange={(olay) => {
                  setAra(olay.target.value)
                  setSayfa(1)
                }}
              />
            </div>
            <p className="text-sm text-slate-600 dark:text-slate-400">
              {t('kesinti.firmalar.kayitSayisi', { sayi: suzulenler.length })}
            </p>
          </div>
          <div className="mt-3">
            <DataTable
              kolonlar={kolonlar}
              satirlar={sayfadakiler}
              satirAnahtari={(firma) => firma.id}
              yukleniyor={firmalar.isPending}
              siralama={siralama}
              siralamaDegistir={siralamaDegistir}
            />
          </div>
          {toplamSayfa > 1 ? (
            <div className="mt-3">
              <Pagination
                sayfa={gecerliSayfa}
                toplamSayfa={toplamSayfa}
                sayfaDegistir={setSayfa}
                boyutSecici={false}
              />
            </div>
          ) : null}
        </>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('kesinti.firmalar.yeni') : t('kesinti.firmalar.duzenle')}
        boyut="genis"
      >
        {formdaki !== null ? (
          <FirmaFormu
            firma={formdaki === 'yeni' ? null : formdaki}
            mevcutFirmamiz={tumFirmalar.find((kayit) => kayit.firmamiz) ?? null}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ErpAktarimPenceresi
        acik={erpAcik}
        kapat={() => setErpAcik(false)}
        baslik={t('kesinti.firmalar.erpBaslik')}
        tanim={{
          sorguAnahtari: queryKeys.kesinti.erpFirmalari,
          getir: erpFirmalariniGetir,
          aktar: erpFirmalariniAktar,
          listeAnahtari: queryKeys.kesinti.firmalar,
          kolonlar: erpKolonlari,
          aramaMetni: (satir) => `${satir.kod} ${satir.ad} ${satir.vergi_kimlik_no ?? ''}`,
        }}
      />

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.firmalar.silOnayBaslik')}
        mesaj={t('kesinti.firmalar.silOnayMesaj', {
          kod: silinecek?.kod ?? '',
          ad: silinecek?.ad ?? '',
        })}
        onayEtiketi={t('ortak.sil')}
        yukleniyor={sil.isPending}
        onayla={() => {
          if (silinecek !== null) {
            sil.mutate(silinecek)
          }
        }}
      />
    </>
  )
}
