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
import { KolonSecici } from '@/components/KolonSecici'
import { Modal } from '@/components/Modal'
import { Pagination } from '@/components/Pagination'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { LISTE_AZAMI_GUN, tutarGoster, varsayilanBaslangic } from '@/features/efatura/bicim'
import { durumIsaretSinifi, durumSatirSinifi } from '@/features/efatura/durumRenkleri'
import type { EFatura, ErpOkunduFiltresi, ExcelKolonu } from '@/features/efatura/efaturaApi'
import { EvetHayirRozeti, Metin, OzetKartlari } from '@/features/efatura/ListeParcalari'
import { PdfGoruntuleyici } from '@/features/efatura/PdfGoruntuleyici'
import {
  type ProtelFaturaFiltresi,
  protelDurumuGetir,
  protelExcelIndir,
  protelFaturalariGetir,
  protelPdfGetir,
  protelSenkronBaslat,
} from '@/features/efatura/protelApi'
import { SenkronDurumuPaneli } from '@/features/efatura/SenkronDurumuPaneli'
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { useKolonGorunurlugu } from '@/hooks/useKolonGorunurlugu'
import { bugunIso, gunFarki, tarihGoster, zamanGoster } from '@/utils/tarih'

const ERP_FILTRELERI: readonly ErpOkunduFiltresi[] = ['evet', 'hayir', 'bilinmiyor']

/** Protel `erpStatus` değerleri (Swagger enum'u); bilinmeyen değer olduğu gibi gösterilir */
const ERP_DURUMLARI = ['New', 'Processing', 'Completed', 'Cancelled', 'Error', 'Booked'] as const

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

type AcikPdf = EFatura | null

/**
 * Protel Gelen Faturalar (PRT-12) — otelin e-Fatura posta kutusu. Yalnız
 * okunur: Protel'de hiçbir alan değiştirilmez. eMOR karşılaştırması yok
 * (faturalar otelindir — kullanıcı kararı 2026-11-18).
 */
export function ProtelFaturalariPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const pdfIzni = useIzin('protel_faturalari.pdf')
  const excelIzni = useIzin('protel_faturalari.disari_aktar')
  const senkronIzni = useIzin('protel_faturalari.senkron')

  const [filtre, setFiltre] = useState<ProtelFaturaFiltresi>(() => ({
    baslangic: varsayilanBaslangic(bugunIso()),
    bitis: bugunIso(),
    ara: '',
    durum: '',
    erp_okundu: '',
    para_birimi: '',
    tip: '',
  }))
  const [sayfa, setSayfa] = useState(1)
  const [pdf, setPdf] = useState<AcikPdf>(null)
  const [excelSuruyor, setExcelSuruyor] = useState(false)
  // Açılışta en son alınan üstte (İzibiz gelen faturalarıyla aynı)
  const { siralama, siralamaDegistir } = useKaliciSiralama('protel-gelen', {
    anahtar: 'olusturma_zamani',
    yon: 'desc',
  })
  const kolonGorunurlugu = useKolonGorunurlugu('protel-gelen')

  const ara = useDebounce(filtre.ara)
  const sorguFiltresi: ProtelFaturaFiltresi = { ...filtre, ara }
  const tarihHatasi = aralikHatasi(filtre.baslangic, filtre.bitis)

  const filtreDegistir = <K extends keyof ProtelFaturaFiltresi>(
    alan: K,
    deger: ProtelFaturaFiltresi[K],
  ) => {
    setFiltre((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }

  const durum = useQuery({
    queryKey: queryKeys.protel.durum,
    queryFn: protelDurumuGetir,
    refetchInterval: (sorgu) => {
      const veri = sorgu.state.data
      const suruyor = veri?.manuel_istek != null || (veri?.yonler?.gelen?.calisiyor ?? false)

      return suruyor ? 5_000 : 60_000
    },
  })

  const liste = useQuery({
    queryKey: queryKeys.protel.liste({ ...sorguFiltresi, siralama, sayfa }),
    queryFn: () => protelFaturalariGetir(sorguFiltresi, siralama, sayfa),
    enabled: tarihHatasi === null,
    placeholderData: keepPreviousData,
  })

  const sonSayfa = liste.isPlaceholderData ? undefined : liste.data?.meta.last_page
  useEffect(() => {
    if (sonSayfa !== undefined && sayfa > Math.max(sonSayfa, 1)) {
      setSayfa(Math.max(sonSayfa, 1))
    }
  }, [sonSayfa, sayfa])

  // Yeni senkron bitince / ortam değişince liste tazelenir (İzibiz ekranıyla aynı)
  const gelen = durum.data?.yonler?.gelen
  const tazelikIzi = durum.data
    ? `${durum.data.ortam ?? ''}|${gelen?.veri_zamani ?? ''}|${String(
        durum.data.manuel_istek !== null || (gelen?.calisiyor ?? false),
      )}`
    : null
  const oncekiTazelikIzi = useRef<string | null>(null)
  useEffect(() => {
    if (tazelikIzi === null) {
      return
    }
    if (oncekiTazelikIzi.current !== null && oncekiTazelikIzi.current !== tazelikIzi) {
      void queryClient.invalidateQueries({ queryKey: queryKeys.protel.listeler })
    }
    oncekiTazelikIzi.current = tazelikIzi
  }, [tazelikIzi, queryClient])

  // Yenilenirken eski sonuç soluk kalır; Excel bu arada kapalı (Dikkat_Edilecekler F1)
  const eskiVeri = liste.isPlaceholderData
  const eskiVeriSinifi = `transition-opacity ${eskiVeri ? 'opacity-50' : ''}`

  const excelAl = async (kolonlar: ExcelKolonu[], ortam: string) => {
    setExcelSuruyor(true)
    try {
      await protelExcelIndir(sorguFiltresi, siralama, sayfa, kolonlar, ortam)
    } catch (error) {
      toast.error(t(apiErrorKey(error)))
    } finally {
      setExcelSuruyor(false)
    }
  }

  const secilebilirKolonlar: DataTableKolonu<EFatura>[] = [
    {
      anahtar: 'belge_no',
      baslik: t('efatura.kolon.faturaNo'),
      siralamaAnahtari: 'belge_no',
      render: (f) => <span className="font-semibold whitespace-nowrap">{f.belge_no}</span>,
    },
    {
      anahtar: 'belge_tarihi',
      baslik: t('efatura.kolon.tarih'),
      siralamaAnahtari: 'belge_tarihi',
      render: (f) => <span className="whitespace-nowrap">{tarihGoster(f.belge_tarihi)}</span>,
    },
    {
      anahtar: 'karsi_vkn',
      baslik: t('efatura.kolon.vkn'),
      siralamaAnahtari: 'karsi_vkn',
      render: (f) => <Metin deger={f.gonderici_vkn} />,
    },
    {
      anahtar: 'karsi_unvan',
      baslik: t('efatura.kolon.unvan'),
      siralamaAnahtari: 'karsi_unvan',
      render: (f) => <Metin deger={f.gonderici_unvan} genis />,
    },
    {
      anahtar: 'fatura_tipi',
      baslik: t('efatura.kolon.tip'),
      siralamaAnahtari: 'fatura_tipi',
      render: (f) => <Metin deger={f.fatura_tipi} />,
    },
    {
      anahtar: 'tutar',
      baslik: t('efatura.kolon.tutar'),
      siralamaAnahtari: 'tutar',
      hizala: 'sag',
      render: (f) => <span className="whitespace-nowrap tabular-nums">{tutarGoster(f.tutar)}</span>,
    },
    {
      anahtar: 'para_birimi',
      baslik: t('efatura.kolon.paraBirimi'),
      siralamaAnahtari: 'para_birimi',
      render: (f) => f.para_birimi,
    },
    {
      anahtar: 'vergi_tutari',
      baslik: t('efatura.kolon.vergiTutari'),
      siralamaAnahtari: 'vergi_tutari',
      hizala: 'sag',
      render: (f) => (
        <span className="whitespace-nowrap tabular-nums">{tutarGoster(f.vergi_tutari)}</span>
      ),
    },
    {
      anahtar: 'olusturma_zamani',
      baslik: t('efatura.kolon.alinmaZamani'),
      siralamaAnahtari: 'olusturma_zamani',
      render: (f) => (
        <span className="whitespace-nowrap">
          {zamanGoster(f.olusturma_zamani, { saniye: true })}
        </span>
      ),
    },
    {
      anahtar: 'durum',
      baslik: t('efatura.kolon.durum'),
      siralamaAnahtari: 'durum',
      render: (f) => <Metin deger={f.durum_aciklamasi ?? f.durum} />,
    },
    {
      anahtar: 'zarf_durumu',
      baslik: t('efatura.kolon.faturaZarfDurumu'),
      siralamaAnahtari: 'zarf_durumu',
      render: (f) => (
        <Metin
          deger={
            f.gib_durum_kodu === null
              ? f.gib_durum_aciklamasi
              : `${f.gib_durum_kodu} ${f.gib_durum_aciklamasi ?? ''}`.trim()
          }
          genis
        />
      ),
    },
    // Otelin ERP'si (yalnız gösterilir; bu uygulama değiştirmez)
    {
      anahtar: 'erp_okundu',
      baslik: t('protel.kolon.erpAldi'),
      siralamaAnahtari: 'erp_okundu',
      hizala: 'orta',
      render: (f) => <EvetHayirRozeti deger={f.erp_okundu} />,
    },
    {
      anahtar: 'erp_durumu',
      baslik: t('protel.kolon.erpDurumu'),
      siralamaAnahtari: 'erp_durumu',
      render: (f) => (
        <Metin
          deger={
            f.erp_durumu === null
              ? null
              : (ERP_DURUMLARI as readonly string[]).includes(f.erp_durumu)
                ? t(`protel.erpDurumu.${f.erp_durumu}`)
                : f.erp_durumu
          }
        />
      ),
    },
    {
      anahtar: 'yazdirildi',
      baslik: t('protel.kolon.yazdirildi'),
      siralamaAnahtari: 'yazdirildi',
      hizala: 'orta',
      render: (f) => <EvetHayirRozeti deger={f.yazdirildi} />,
    },
    {
      anahtar: 'irsaliye_no',
      baslik: t('efatura.kolon.irsaliyeNo'),
      siralamaAnahtari: 'irsaliye_no',
      render: (f) => <Metin deger={f.irsaliye_no} />,
    },
    {
      anahtar: 'yanit_aciklamasi',
      baslik: t('efatura.kolon.yanitAciklamasi'),
      siralamaAnahtari: 'yanit_aciklamasi',
      render: (f) => <Metin deger={f.yanit_aciklamasi} genis />,
    },
    {
      anahtar: 'senaryo',
      baslik: t('efatura.kolon.senaryo'),
      siralamaAnahtari: 'senaryo',
      render: (f) => <Metin deger={f.senaryo} />,
    },
    {
      anahtar: 'ettn',
      baslik: t('efatura.kolon.ettn'),
      render: (f) => <span className="font-mono text-xs whitespace-nowrap">{f.ettn}</span>,
    },
  ]

  const kolonlar: DataTableKolonu<EFatura>[] = [
    ...(pdfIzni
      ? [
          {
            anahtar: 'islemler',
            baslik: '',
            render: (f: EFatura) => (
              <Button variant="secondary" onClick={() => setPdf(f)}>
                {t('efatura.pdf')}
              </Button>
            ),
          },
        ]
      : []),
    ...secilebilirKolonlar.filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar)),
  ]

  const excelKolonlari: ExcelKolonu[] = secilebilirKolonlar
    .filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar))
    .map((kolon) => ({ anahtar: kolon.anahtar, baslik: kolon.baslik }))

  const secenekler = liste.data?.secenekler
  const durumSecenekleri: SecenekOgesi[] = (secenekler?.durumlar ?? []).map((d) => ({
    value: d.deger,
    label: d.aciklama ? `${d.aciklama} (${d.deger})` : d.deger,
  }))
  const paraSecenekleri: SecenekOgesi[] = (secenekler?.para_birimleri ?? []).map((p) => ({
    value: p,
    label: p,
  }))
  const tipSecenekleri: SecenekOgesi[] = (secenekler?.tipler ?? []).map((d) => ({
    value: d,
    label: d,
  }))
  const erpSecenekleri: SecenekOgesi[] = ERP_FILTRELERI.map((d) => ({
    value: d,
    label: t(`efatura.${d}`),
  }))
  const secili = (ogeler: SecenekOgesi[], deger: string) =>
    deger === ''
      ? null
      : (ogeler.find((oge) => oge.value === deger) ?? { value: deger, label: deger })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold">{t('protel.baslik')}</h2>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{t('protel.aciklama')}</p>
        </div>
        {excelIzni ? (
          <Button
            variant="yesil"
            onClick={() => {
              if (liste.data) {
                void excelAl(excelKolonlari, liste.data.kapsam.ortam)
              }
            }}
            title={t('efatura.excelAciklama')}
            yukleniyor={excelSuruyor}
            disabled={
              tarihHatasi !== null ||
              // Yenilenirken (filtre ya da ortam değişti) görünen ≠ inecek olan (F1, PRT-R3).
              // Bağlantı yokken istek beklemeye alınır (paused): isFetching false
              // olur, eski veri durur — üç durum birlikte (PRT-R9)
              liste.isFetching ||
              liste.isPaused ||
              liste.isPlaceholderData ||
              (liste.data?.meta.total ?? 0) === 0 ||
              excelKolonlari.length === 0
            }
          >
            {t('efatura.excel')}
          </Button>
        ) : null}
      </div>

      <div className="mt-4">
        {durum.data ? (
          <SenkronDurumuPaneli
            yon="gelen"
            durum={durum.data}
            senkronIzni={senkronIzni}
            senkronBaslat={protelSenkronBaslat}
            durumAnahtari={queryKeys.protel.durum}
            kapaliMesaji={t('protel.modulKapali')}
          />
        ) : null}
        {durum.isError ? (
          <ErrorState mesaj={t('efatura.durumAlinamadi')} tekrarDene={() => void durum.refetch()} />
        ) : null}
      </div>

      <div className="mt-4 flex flex-wrap items-start gap-3">
        <div className="w-full sm:w-36">
          <TarihInput
            id="protel-baslangic"
            label={t('efatura.baslangic')}
            value={filtre.baslangic}
            onChange={(deger) => filtreDegistir('baslangic', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-36">
          <TarihInput
            id="protel-bitis"
            label={t('efatura.bitis')}
            value={filtre.bitis}
            onChange={(deger) => filtreDegistir('bitis', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:min-w-64 sm:flex-1">
          <Input
            id="protel-ara"
            type="search"
            label={t('efatura.ara')}
            maxLength={100}
            value={filtre.ara}
            onChange={(olay) => filtreDegistir('ara', olay.target.value)}
          />
        </div>
        <div className="w-full sm:w-60">
          <SelectField
            id="protel-durum"
            label={t('efatura.alan.durum')}
            options={durumSecenekleri}
            secenekBicimi={(secenek) => (
              <span className="inline-flex items-center gap-2">
                <span
                  aria-hidden="true"
                  className={`size-3 shrink-0 rounded-full border ${durumIsaretSinifi(secenek.value)}`}
                />
                {secenek.label}
              </span>
            )}
            value={secili(durumSecenekleri, filtre.durum)}
            onChange={(secim) => filtreDegistir('durum', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
        <div className="w-full sm:w-36">
          <SelectField
            id="protel-tip"
            label={t('efatura.kolon.tip')}
            options={tipSecenekleri}
            value={secili(tipSecenekleri, filtre.tip)}
            onChange={(secim) => filtreDegistir('tip', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
        <div className="w-full sm:w-36">
          <SelectField
            id="protel-erp"
            label={t('protel.kolon.erpAldi')}
            options={erpSecenekleri}
            value={secili(erpSecenekleri, filtre.erp_okundu)}
            onChange={(secim) =>
              filtreDegistir('erp_okundu', (secim?.value ?? '') as ErpOkunduFiltresi)
            }
            placeholder={t('efatura.hepsi')}
            isClearable
            isSearchable={false}
          />
        </div>
        <div className="w-full sm:w-28">
          <SelectField
            id="protel-para"
            label={t('efatura.alan.paraBirimi')}
            options={paraSecenekleri}
            value={secili(paraSecenekleri, filtre.para_birimi)}
            onChange={(secim) => filtreDegistir('para_birimi', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
      </div>

      {tarihHatasi !== null ? (
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
          {liste.data ? (
            <div className={`mt-4 ${eskiVeriSinifi}`}>
              <OzetKartlari ozet={liste.data.ozet} toplam={liste.data.meta.total} />
            </div>
          ) : null}
          {eskiVeri ? (
            <p role="status" className="mt-2 text-xs text-slate-500 dark:text-slate-400">
              {t('efatura.guncelleniyor')}
            </p>
          ) : null}
          {liste.data ? (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
              <KolonSecici
                kolonlar={secilebilirKolonlar.map(({ anahtar, baslik }) => ({ anahtar, baslik }))}
                gorunurMu={kolonGorunurlugu.gorunurMu}
                degistir={kolonGorunurlugu.degistir}
                hepsiniGoster={kolonGorunurlugu.hepsiniGoster}
              />
              <Pagination
                sayfa={liste.data.meta.current_page}
                toplamSayfa={liste.data.meta.last_page}
                sayfaDegistir={setSayfa}
                disabled={liste.isFetching}
                boyutSeciciId="protel-sayfa-boyutu-ust"
              />
            </div>
          ) : null}
          <div className={`mt-4 ${eskiVeriSinifi}`} aria-busy={liste.isFetching}>
            <DataTable
              kolonlar={kolonlar}
              satirlar={liste.data?.data ?? []}
              satirAnahtari={(f) => f.id}
              yukleniyor={liste.isPending}
              ustKaydirma
              satirSinifi={(f) => durumSatirSinifi(f.durum)}
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
                boyutSeciciId="protel-sayfa-boyutu-alt"
              />
            </div>
          ) : null}
        </>
      )}

      <Modal
        acik={pdf !== null}
        kapat={() => setPdf(null)}
        baslik={pdf === null ? '' : t('efatura.pdfBaslik', { no: pdf.belge_no })}
        boyut="devasa"
      >
        {pdf !== null ? <PdfGoruntuleyici fatura={pdf} pdfGetir={protelPdfGetir} /> : null}
      </Modal>
    </>
  )
}
