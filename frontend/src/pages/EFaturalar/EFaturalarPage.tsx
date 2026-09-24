import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { Pagination } from '@/components/Pagination'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import {
  type EFatura,
  type EFaturaFiltresi,
  type ErpOkunduFiltresi,
  type FaturaYonu,
  type ParaBirimiOzeti,
  excelIndir,
  faturalariGetir,
  senkronDurumuGetir,
} from '@/features/efatura/efaturaApi'
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { bugunIso, gunFarki, tarihGoster } from '@/utils/tarih'

import { FaturaDetayi } from './FaturaDetayi'
import { ILK_TARAMA_TARIHI, LISTE_AZAMI_GUN, tutarGoster } from './bicim'
import { PdfGoruntuleyici } from './PdfGoruntuleyici'
import { SenkronDurumuPaneli } from './SenkronDurumuPaneli'

const BOS_FILTRE: Omit<EFaturaFiltresi, 'bitis'> = {
  baslangic: ILK_TARAMA_TARIHI,
  ara: '',
  durum: '',
  erp_okundu: '',
  para_birimi: '',
}

const ERP_FILTRELERI: readonly ErpOkunduFiltresi[] = ['evet', 'hayir', 'bilinmiyor']

/** Alarm mailindeki bağlantı (?erp_okundu=hayir) listeyi o filtreyle açar. */
function adrestekiErpFiltresi(): ErpOkunduFiltresi {
  const deger = new URLSearchParams(window.location.search).get('erp_okundu') ?? ''

  return (ERP_FILTRELERI as readonly string[]).includes(deger) ? (deger as ErpOkunduFiltresi) : ''
}

function aralikHatasi(baslangic: string, bitis: string): string | null {
  if (baslangic === '' || bitis === '') {
    return 'efatura.dogrulama.tarihZorunlu'
  }
  const fark = gunFarki(baslangic, bitis)
  if (fark === null || fark < 0) {
    return 'efatura.dogrulama.sira'
  }

  return fark + 1 > LISTE_AZAMI_GUN ? 'efatura.dogrulama.cokGenis' : null
}

function OzetKartlari({ ozet, toplam }: { ozet: ParaBirimiOzeti[]; toplam: number }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-wrap gap-3" aria-label={t('efatura.ozetBaslik')}>
      <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
        <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
          {t('efatura.toplamFatura')}
        </p>
        <p className="text-lg font-bold">{toplam.toLocaleString('tr-TR')}</p>
      </div>
      {ozet.map((grup) => (
        <div
          key={grup.para_birimi}
          className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"
        >
          <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
            {t('efatura.ozetGrup', { para: grup.para_birimi, adet: grup.adet })}
          </p>
          <p className="text-lg font-bold tabular-nums">
            {tutarGoster(grup.tutar)} {grup.para_birimi}
          </p>
          <p className="text-xs text-slate-500 tabular-nums dark:text-slate-400">
            {t('efatura.ozetVergi', { tutar: tutarGoster(grup.vergi_tutari) })}
          </p>
        </div>
      ))}
    </div>
  )
}

function ErpRozeti({ deger }: { deger: boolean | null }) {
  const { t } = useTranslation()

  if (deger === null) {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
        deger
          ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
          : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
      }`}
    >
      {deger ? t('efatura.evet') : t('efatura.hayir')}
    </span>
  )
}

type AcikPencere = { tur: 'detay' | 'pdf'; fatura: EFatura } | null

export function EFaturalarPage({ yon }: { yon: FaturaYonu }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const pdfIzni = useIzin('efatura.pdf')
  const excelIzni = useIzin('efatura.disari_aktar')

  const [filtre, setFiltre] = useState<EFaturaFiltresi>(() => ({
    ...BOS_FILTRE,
    bitis: bugunIso(),
    erp_okundu: adrestekiErpFiltresi(),
  }))
  const [sayfa, setSayfa] = useState(1)
  const [pencere, setPencere] = useState<AcikPencere>(null)
  const [excelSuruyor, setExcelSuruyor] = useState(false)
  const { siralama, siralamaDegistir } = useKaliciSiralama(`efatura-${yon}`)

  // Arama yazarken her tuşta istek atılmaz; diğer filtreler anında uygulanır
  const ara = useDebounce(filtre.ara)
  const sorguFiltresi: EFaturaFiltresi = { ...filtre, ara }
  const tarihHatasi = aralikHatasi(filtre.baslangic, filtre.bitis)

  const filtreDegistir = <K extends keyof EFaturaFiltresi>(alan: K, deger: EFaturaFiltresi[K]) => {
    setFiltre((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }

  const durum = useQuery({
    queryKey: queryKeys.efatura.durum,
    queryFn: senkronDurumuGetir,
    // Senkron sürerken ya da sıradayken sık, aksi hâlde dakikada bir tazelenir
    refetchInterval: (sorgu) => {
      const veri = sorgu.state.data
      const suruyor =
        veri?.manuel_istek != null ||
        Object.values(veri?.yonler ?? {}).some((yonDurumu) => yonDurumu.calisiyor)

      return suruyor ? 5_000 : 60_000
    },
  })

  const liste = useQuery({
    queryKey: queryKeys.efatura.liste(yon, { ...sorguFiltresi, siralama, sayfa }),
    queryFn: () => faturalariGetir(yon, sorguFiltresi, siralama, sayfa),
    enabled: tarihHatasi === null,
    placeholderData: keepPreviousData,
  })

  // Sunucudaki veri değiştiğinde liste tazelenir: aktif ortam değişti (başka
  // sekme/yönetici), yeni bir senkron bitti (veri zamanı ilerledi) ya da
  // elle/zamanlanmış senkron sürüyor↔bitti. Yoklama arasına düşen kısa
  // senkron da veri zamanından yakalanır.
  const yonDurumu = durum.data?.yonler?.[yon]
  const tazelikIzi = durum.data
    ? `${durum.data.ortam ?? ''}|${yonDurumu?.veri_zamani ?? ''}|${String(
        durum.data.manuel_istek !== null || (yonDurumu?.calisiyor ?? false),
      )}`
    : null
  const oncekiTazelikIzi = useRef<string | null>(null)
  useEffect(() => {
    if (tazelikIzi === null) {
      return
    }
    if (oncekiTazelikIzi.current !== null && oncekiTazelikIzi.current !== tazelikIzi) {
      void queryClient.invalidateQueries({ queryKey: queryKeys.efatura.yonListeleri(yon) })
    }
    oncekiTazelikIzi.current = tazelikIzi
  }, [tazelikIzi, queryClient, yon])

  // Filtre değişince önceki sonuç yeni filtreninmiş gibi gösterilmez
  const eskiVeri = liste.isPlaceholderData

  const excelAl = async () => {
    setExcelSuruyor(true)
    try {
      await excelIndir(yon, sorguFiltresi, siralama)
    } catch (error) {
      toast.error(t(apiErrorKey(error)))
    } finally {
      setExcelSuruyor(false)
    }
  }

  const karsiUnvan = (f: EFatura) => (yon === 'gelen' ? f.gonderici_unvan : f.alici_unvan)
  const karsiVkn = (f: EFatura) => (yon === 'gelen' ? f.gonderici_vkn : f.alici_vkn)

  const kolonlar: DataTableKolonu<EFatura>[] = [
    {
      anahtar: 'belge_tarihi',
      baslik: t('efatura.alan.belgeTarihi'),
      siralamaAnahtari: 'belge_tarihi',
      render: (f) => <span className="whitespace-nowrap">{tarihGoster(f.belge_tarihi)}</span>,
    },
    {
      anahtar: 'belge_no',
      baslik: t('efatura.alan.belgeNo'),
      siralamaAnahtari: 'belge_no',
      render: (f) => (
        <div>
          <p className="font-semibold whitespace-nowrap">{f.belge_no}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            {[f.fatura_tipi, f.senaryo].filter(Boolean).join(' / ')}
          </p>
        </div>
      ),
    },
    {
      anahtar: 'karsi_unvan',
      baslik: t(yon === 'gelen' ? 'efatura.alan.gonderici' : 'efatura.alan.alici'),
      siralamaAnahtari: 'karsi_unvan',
      render: (f) => (
        <div className="max-w-md">
          <p className="truncate" title={karsiUnvan(f) ?? undefined}>
            {karsiUnvan(f) ?? '—'}
          </p>
          <p className="text-xs text-slate-500 dark:text-slate-400">{karsiVkn(f)}</p>
        </div>
      ),
    },
    {
      anahtar: 'tutar',
      baslik: t('efatura.alan.tutar'),
      siralamaAnahtari: 'tutar',
      hizala: 'sag',
      render: (f) => (
        <span className="whitespace-nowrap tabular-nums">
          {tutarGoster(f.tutar)} {f.para_birimi}
        </span>
      ),
    },
    {
      anahtar: 'durum',
      baslik: t('efatura.alan.durum'),
      render: (f) => f.durum_aciklamasi ?? f.durum ?? '—',
    },
    {
      anahtar: 'erp_okundu',
      baslik: t('efatura.alan.erpOkundu'),
      hizala: 'orta',
      render: (f) => <ErpRozeti deger={f.erp_okundu} />,
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (f) => (
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setPencere({ tur: 'detay', fatura: f })}>
            {t('efatura.detay')}
          </Button>
          {pdfIzni ? (
            <Button variant="secondary" onClick={() => setPencere({ tur: 'pdf', fatura: f })}>
              {t('efatura.pdf')}
            </Button>
          ) : null}
        </div>
      ),
    },
  ]

  const secenekler = liste.data?.secenekler
  const durumSecenekleri: SecenekOgesi[] = (secenekler?.durumlar ?? []).map((d) => ({
    value: d,
    label: d,
  }))
  const paraSecenekleri: SecenekOgesi[] = (secenekler?.para_birimleri ?? []).map((p) => ({
    value: p,
    label: p,
  }))
  const erpSecenekleri: SecenekOgesi[] = ERP_FILTRELERI.map((d) => ({
    value: d,
    label: t(`efatura.${d}`),
  }))
  // Seçili değer yeni aralığın seçeneklerinde yoksa da görünür kalır
  // (aksi hâlde kutu "Hepsi" derken filtre isteğe gitmeye devam eder)
  const secili = (ogeler: SecenekOgesi[], deger: string) =>
    deger === ''
      ? null
      : (ogeler.find((oge) => oge.value === deger) ?? { value: deger, label: deger })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t(`efatura.baslik.${yon}`)}</h2>
        {excelIzni ? (
          <Button
            variant="yesil"
            onClick={() => void excelAl()}
            yukleniyor={excelSuruyor}
            disabled={tarihHatasi !== null || eskiVeri || (liste.data?.meta.total ?? 0) === 0}
          >
            {t('efatura.excel')}
          </Button>
        ) : null}
      </div>

      <div className="mt-4">
        {durum.data ? <SenkronDurumuPaneli yon={yon} durum={durum.data} /> : null}
        {durum.isError ? (
          // Güncellik bilinmiyorsa liste güncel sanılmasın
          <ErrorState mesaj={t('efatura.durumAlinamadi')} tekrarDene={() => void durum.refetch()} />
        ) : null}
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <TarihInput
          id="efatura-baslangic"
          label={t('efatura.baslangic')}
          value={filtre.baslangic}
          onChange={(deger) => filtreDegistir('baslangic', deger)}
          hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
        />
        <TarihInput
          id="efatura-bitis"
          label={t('efatura.bitis')}
          value={filtre.bitis}
          onChange={(deger) => filtreDegistir('bitis', deger)}
          hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
        />
        <Input
          id="efatura-ara"
          type="search"
          label={t('efatura.ara')}
          maxLength={100}
          value={filtre.ara}
          onChange={(olay) => filtreDegistir('ara', olay.target.value)}
        />
        <SelectField
          id="efatura-durum"
          label={t('efatura.alan.durum')}
          options={durumSecenekleri}
          value={secili(durumSecenekleri, filtre.durum)}
          onChange={(secim) => filtreDegistir('durum', secim?.value ?? '')}
          placeholder={t('efatura.hepsi')}
          isClearable
        />
        <SelectField
          id="efatura-erp"
          label={t('efatura.alan.erpOkundu')}
          options={erpSecenekleri}
          value={secili(erpSecenekleri, filtre.erp_okundu)}
          onChange={(secim) =>
            filtreDegistir('erp_okundu', (secim?.value ?? '') as ErpOkunduFiltresi)
          }
          placeholder={t('efatura.hepsi')}
          isClearable
          isSearchable={false}
        />
        <SelectField
          id="efatura-para"
          label={t('efatura.alan.paraBirimi')}
          options={paraSecenekleri}
          value={secili(paraSecenekleri, filtre.para_birimi)}
          onChange={(secim) => filtreDegistir('para_birimi', secim?.value ?? '')}
          placeholder={t('efatura.hepsi')}
          isClearable
        />
      </div>

      {tarihHatasi !== null ? (
        // Önceki aralığın listesi yeni filtreninmiş gibi gösterilmez
        <p
          role="alert"
          className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {t(tarihHatasi, { gun: LISTE_AZAMI_GUN })}
        </p>
      ) : liste.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        </div>
      ) : (
        <>
          {liste.data && !eskiVeri ? (
            <div className="mt-4">
              <OzetKartlari ozet={liste.data.ozet} toplam={liste.data.meta.total} />
            </div>
          ) : null}
          <div className="mt-4" aria-busy={liste.isFetching}>
            <DataTable
              kolonlar={kolonlar}
              satirlar={liste.data?.data ?? []}
              satirAnahtari={(f) => f.id}
              yukleniyor={liste.isPending || eskiVeri}
              siralama={siralama}
              siralamaDegistir={(anahtar) => {
                siralamaDegistir(anahtar)
                setSayfa(1)
              }}
            />
          </div>
          {liste.data ? (
            <div className="mt-4">
              <Pagination
                sayfa={liste.data.meta.current_page}
                toplamSayfa={liste.data.meta.last_page}
                sayfaDegistir={setSayfa}
                disabled={liste.isFetching}
              />
            </div>
          ) : null}
        </>
      )}

      <Modal
        acik={pencere !== null}
        kapat={() => setPencere(null)}
        baslik={
          pencere === null
            ? ''
            : t(pencere.tur === 'pdf' ? 'efatura.pdfBaslik' : 'efatura.detayBaslik', {
                no: pencere.fatura.belge_no,
              })
        }
        boyut={pencere?.tur === 'pdf' ? 'devasa' : 'genis'}
      >
        {pencere?.tur === 'detay' ? <FaturaDetayi fatura={pencere.fatura} /> : null}
        {pencere?.tur === 'pdf' ? <PdfGoruntuleyici fatura={pencere.fatura} /> : null}
      </Modal>
    </>
  )
}

export function GelenFaturalarPage() {
  return <EFaturalarPage yon="gelen" />
}

export function GidenFaturalarPage() {
  return <EFaturalarPage yon="giden" />
}
