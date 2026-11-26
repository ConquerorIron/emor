import { useState, type ReactNode } from 'react'
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
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { useKolonGorunurlugu } from '@/hooks/useKolonGorunurlugu'
import { bugunIso, tarihGoster, zamanGoster } from '@/utils/tarih'

import { LISTE_AZAMI_GUN, listeAraligiHatasi, tutarGoster, varsayilanBaslangic } from './bicim'
import { durumIsaretSinifi, durumSatirSinifi } from './durumRenkleri'
import type { EFatura, ExcelKolonu, FaturaSinifi } from './efaturaApi'
import { EmorRozeti } from './EmorRozeti'
import { EmorVknRozeti } from './EmorVknRozeti'
import { GecmisDugmesi } from './GecmisPenceresi'
import { HizliFiltre } from './HizliFiltre'
import { IslendiAnahtari } from './IslendiAnahtari'
import {
  kaynakExcelIndir,
  kaynakFaturalariGetir,
  type KaynakFiltresi,
  type KaynakOneki,
  kaynakPdfGetir,
} from './kaynakApi'
import { Metin, OzetKartlari, PdfDugmesi, SiraNo } from './ListeParcalari'
import { PdfGoruntuleyici } from './PdfGoruntuleyici'
import { RedDugmesi } from './RedIslemi'
import { SapDurumu } from './SapDurumu'
import { useFaturaListesi } from './useFaturaListesi'

export type FiltreDegistir = <K extends keyof KaynakFiltresi>(
  alan: K,
  deger: KaynakFiltresi[K],
) => void

/**
 * Tek kaynaklı gelen fatura ekranı: Protel (PRT-12) ve statik kaynaklar
 * (Logo, e-Arşiv / e-SMM — EK-07, EK-08). Liste, hızlı filtreler, RED'e çekme,
 * "SAP (Manuel İşle)", işlem geçmişi ve Excel ortaktır; PDF yalnız Protel'de
 * (statik kaynağın belgesi yok); kaynağa özgü kısımlar
 * (senkron paneli, içe aktarım, özel kolon ve filtreler) sayfadan verilir.
 * Üç sayfa kopyalanmaz (Dikkat_Edilecekler K10).
 */
export function GelenFaturaEkrani({
  onEk,
  izinOneki,
  baslik,
  aciklama,
  baslikEylemleri,
  durumAlani,
  ekFiltreler,
  ekKolonlar = [],
}: {
  onEk: KaynakOneki
  izinOneki: 'protel_faturalari' | 'logo_faturalari' | 'earsiv_faturalari'
  baslik: string
  aciklama: string
  /** Başlığın sağında, Excel'den önce (statik kaynak: İçe Aktar, Yüklemeler) */
  baslikEylemleri?: ReactNode
  /** Hızlı filtrelerin altında (Protel: senkron paneli) */
  durumAlani?: ReactNode
  /** Kaynağa özgü filtre alanları (Protel: otelin ERP'si; e-Arşiv: belge türü) */
  ekFiltreler?: (filtre: KaynakFiltresi, degistir: FiltreDegistir) => ReactNode
  /** Kaynağa özgü seçilebilir kolonlar, ortak kolonların sonunda */
  ekKolonlar?: DataTableKolonu<EFatura>[]
}) {
  const { t } = useTranslation()
  const pdfIzni = useIzin(`${izinOneki}.pdf`)
  const excelIzni = useIzin(`${izinOneki}.disari_aktar`)
  const isleIzni = useIzin(`${izinOneki}.isle`)
  const redIzni = useIzin(`${izinOneki}.red`)

  const [filtre, setFiltre] = useState<KaynakFiltresi>(() => ({
    baslangic: varsayilanBaslangic(bugunIso()),
    bitis: bugunIso(),
    ara: '',
    durum: '',
    para_birimi: '',
    tip: '',
    emor_vkn: '',
    sinif: '',
    otel_erp_okundu: '',
    belge_turu: '',
  }))
  const [sayfa, setSayfa] = useState(1)
  const [pdf, setPdf] = useState<EFatura | null>(null)
  const [excelSuruyor, setExcelSuruyor] = useState(false)
  // Açılışta en son alınan üstte (İzibiz gelen faturalarıyla aynı)
  const { siralama, siralamaDegistir } = useKaliciSiralama(`${onEk}-gelen`, {
    anahtar: 'olusturma_zamani',
    yon: 'desc',
  })
  const kolonGorunurlugu = useKolonGorunurlugu(`${onEk}-gelen`)

  const ara = useDebounce(filtre.ara)
  const sorguFiltresi: KaynakFiltresi = { ...filtre, ara }
  const tarihHatasi = listeAraligiHatasi(filtre.baslangic, filtre.bitis)

  const filtreDegistir: FiltreDegistir = (alan, deger) => {
    setFiltre((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }
  const sinifDegistir = (sinif: FaturaSinifi) =>
    filtreDegistir('sinif', filtre.sinif === sinif ? '' : sinif)

  const { liste, eskiVeri, yenileniyor } = useFaturaListesi({
    queryKey: queryKeys.kaynak.liste(onEk, { ...sorguFiltresi, siralama, sayfa }),
    queryFn: () => kaynakFaturalariGetir(onEk, sorguFiltresi, siralama, sayfa),
    enabled: tarihHatasi === null,
    sayfa,
    setSayfa,
  })
  const eskiVeriSinifi = `transition-opacity ${eskiVeri ? 'opacity-50' : ''}`

  const excelAl = async (kolonlar: ExcelKolonu[], ortam: string) => {
    setExcelSuruyor(true)
    try {
      await kaynakExcelIndir(onEk, sorguFiltresi, siralama, sayfa, kolonlar, ortam)
    } catch (error) {
      toast.error(t(apiErrorKey(error)))
    } finally {
      setExcelSuruyor(false)
    }
  }

  const secilebilirKolonlar: DataTableKolonu<EFatura>[] = [
    // SAP (yazı): "SAP (Manuel İşle)" anahtarının sonucu, eMOR gibi (2026-11-26)
    {
      anahtar: 'sap',
      baslik: t('efatura.kolon.sap'),
      siralamaAnahtari: 'sap',
      hizala: 'orta',
      render: (f) => <SapDurumu fatura={f} />,
    },
    // eMOR'a (ERP) işlendi mi (kullanıcı isteği l, 2026-11-25)
    {
      anahtar: 'emor',
      baslik: t('efatura.kolon.emor'),
      siralamaAnahtari: 'emor',
      hizala: 'orta',
      render: (f) => <EmorRozeti deger={f.emor_durumu} />,
    },
    // Gönderici eMOR'da kayıtlı bir cari mi: yanlış kutuya kesilen fatura (kullanıcı isteği 2026-11-25)
    {
      anahtar: 'emor_vkn',
      baslik: t('efatura.kolon.emorVkn'),
      siralamaAnahtari: 'emor_vkn',
      hizala: 'orta',
      render: (f) => <EmorVknRozeti kurum={f.emor_kurum} />,
    },
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
      render: (f) =>
        f.vergi_tutari === null ? (
          <span className="text-slate-400">—</span>
        ) : (
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
    ...ekKolonlar,
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
      render: (f) => <span className="font-mono text-xs whitespace-nowrap">{f.ettn ?? '—'}</span>,
    },
  ]

  // Sıra No sayfalar boyunca kesintisiz: sunucunun sayfa başlangıcından
  const ilkSira = liste.data?.meta.from ?? 1
  const kolonlar: DataTableKolonu<EFatura>[] = [
    {
      // Sıra No en solda, her zaman görünür (kullanıcı isteği 2026-11-20)
      anahtar: 'sira_no',
      baslik: t('efatura.kolon.siraNo'),
      hizala: 'orta',
      render: (_f: EFatura, sira: number) => <SiraNo deger={ilkSira + sira} />,
    },
    {
      // İşlemler (ikon) her zaman görünür: PDF, RED, işlem geçmişi
      anahtar: 'islemler',
      baslik: '',
      render: (f: EFatura) => (
        <div className="flex items-center gap-1">
          {/* Logo / e-Arşiv Excel listesinden gelir: belgesi yok, PDF yalnız Protel'de (2026-11-26) */}
          {onEk === 'protel' && pdfIzni ? <PdfDugmesi onClick={() => setPdf(f)} /> : null}
          <RedDugmesi fatura={f} ekran={onEk} izinli={redIzni} />
          <GecmisDugmesi fatura={f} ekran={onEk} />
        </div>
      ),
    },
    {
      // "SAP (Manuel İşle)" anahtarı (Protel: 2026-11-23; başlık 2026-11-26), her
      // zaman görünür; izni olmayan durumu görür, değiştiremez; RED faturada "Red"
      anahtar: 'sap_isle',
      baslik: t('efatura.kolon.sapManuel'),
      hizala: 'orta',
      render: (f: EFatura) => <IslendiAnahtari fatura={f} izinli={isleIzni} ekran={onEk} />,
    },
    ...secilebilirKolonlar.filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar)),
  ]

  // Excel tablonun aynısı: görünen kolonlar (SAP yazı kolonu dahil), ekrandaki başlık ve sırayla
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
  const secili = (ogeler: SecenekOgesi[], deger: string) =>
    deger === ''
      ? null
      : (ogeler.find((oge) => oge.value === deger) ?? { value: deger, label: deger })

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-2xl font-bold">{baslik}</h2>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{aciklama}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {baslikEylemleri}
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
              disabled={tarihHatasi !== null || yenileniyor || (liste.data?.meta.total ?? 0) === 0}
            >
              {t('efatura.excel')}
            </Button>
          ) : null}
        </div>
      </div>

      {/* Hızlı filtreler Tüm Faturalar'daki yerde: başlığın hemen altında (kullanıcı isteği 2026-11-25) */}
      <div
        className="mt-3 flex flex-wrap items-center gap-2"
        role="group"
        aria-label={t('efatura.hizliFiltre.baslik')}
      >
        <span className="text-sm font-semibold text-slate-500 dark:text-slate-400">
          {t('efatura.hizliFiltre.baslik')}
        </span>
        {/* Sınıf alanını paylaşırlar: aynı anda biri basılı olur; RED faturalar işlendi / işlenmeyen süzgecine gelmez */}
        <HizliFiltre
          aktif={filtre.sinif === 'islenecek'}
          onClick={() => sinifDegistir('islenecek')}
        >
          {t('efatura.hizliFiltre.erpIslenmeyenler')}
        </HizliFiltre>
        <HizliFiltre aktif={filtre.sinif === 'islendi'} onClick={() => sinifDegistir('islendi')}>
          {t('efatura.hizliFiltre.erpIslenenler')}
        </HizliFiltre>
        <HizliFiltre aktif={filtre.sinif === 'red'} onClick={() => sinifDegistir('red')}>
          {t('efatura.hizliFiltre.red')}
        </HizliFiltre>
        <HizliFiltre
          aktif={filtre.emor_vkn === 'evet'}
          onClick={() => filtreDegistir('emor_vkn', filtre.emor_vkn === 'evet' ? '' : 'evet')}
        >
          {t('efatura.hizliFiltre.emorVknVar')}
        </HizliFiltre>
      </div>

      {durumAlani === undefined ? null : <div className="mt-4">{durumAlani}</div>}

      <div className="mt-4 flex flex-wrap items-start gap-3">
        <div className="w-full sm:w-36">
          <TarihInput
            id={`${onEk}-baslangic`}
            label={t('efatura.baslangic')}
            value={filtre.baslangic}
            onChange={(deger) => filtreDegistir('baslangic', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-36">
          <TarihInput
            id={`${onEk}-bitis`}
            label={t('efatura.bitis')}
            value={filtre.bitis}
            onChange={(deger) => filtreDegistir('bitis', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:min-w-64 sm:flex-1">
          <Input
            id={`${onEk}-ara`}
            type="search"
            label={t('efatura.ara')}
            maxLength={100}
            value={filtre.ara}
            onChange={(olay) => filtreDegistir('ara', olay.target.value)}
          />
        </div>
        <div className="w-full sm:w-60">
          <SelectField
            id={`${onEk}-durum`}
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
            id={`${onEk}-tip`}
            label={t('efatura.kolon.tip')}
            options={tipSecenekleri}
            value={secili(tipSecenekleri, filtre.tip)}
            onChange={(secim) => filtreDegistir('tip', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
        {ekFiltreler?.(filtre, filtreDegistir)}
        <div className="w-full sm:w-28">
          <SelectField
            id={`${onEk}-para`}
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
                kolonlar={secilebilirKolonlar.map(({ anahtar, baslik: kolonBasligi }) => ({
                  anahtar,
                  baslik: kolonBasligi,
                }))}
                gorunurMu={kolonGorunurlugu.gorunurMu}
                degistir={kolonGorunurlugu.degistir}
                hepsiniGoster={kolonGorunurlugu.hepsiniGoster}
              />
              <Pagination
                sayfa={liste.data.meta.current_page}
                toplamSayfa={liste.data.meta.last_page}
                sayfaDegistir={setSayfa}
                disabled={liste.isFetching}
                boyutSeciciId={`${onEk}-sayfa-boyutu-ust`}
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
              // Bir boy küçük yazı: çok kolonlu liste (kullanıcı isteği 2026-11-20)
              yaziBoyutu="kucuk"
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
                boyutSeciciId={`${onEk}-sayfa-boyutu-alt`}
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
        {pdf !== null ? (
          <PdfGoruntuleyici fatura={pdf} pdfGetir={(faturaId) => kaynakPdfGetir(onEk, faturaId)} />
        ) : null}
      </Modal>
    </>
  )
}
