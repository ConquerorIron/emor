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
import { Switch } from '@/components/Switch'
import { TarihInput } from '@/components/TarihInput'
import {
  type EFatura,
  type EFaturaFiltresi,
  type EmorDurumu,
  type EmorFiltresi,
  type ErpOkunduFiltresi,
  type FaturaYonu,
  type ParaBirimiOzeti,
  excelIndir,
  type ExcelKolonu,
  faturalariGetir,
  senkronDurumuGetir,
} from '@/features/efatura/efaturaApi'
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { useKolonGorunurlugu } from '@/hooks/useKolonGorunurlugu'
import { bugunIso, gunFarki, tarihGoster, zamanGoster } from '@/utils/tarih'

import { FaturaDetayi } from './FaturaDetayi'
import { GizlemeDugmesi } from './GizlemeDugmesi'
import { ILK_TARAMA_TARIHI, LISTE_AZAMI_GUN, tutarGoster } from './bicim'
import { durumIsaretSinifi, durumSatirSinifi } from './durumRenkleri'
import { istisnaKoduFarkli } from './istisna'
import { PdfGoruntuleyici } from './PdfGoruntuleyici'
import { SenkronDurumuPaneli } from './SenkronDurumuPaneli'

const BOS_FILTRE: Omit<EFaturaFiltresi, 'bitis'> = {
  baslangic: ILK_TARAMA_TARIHI,
  ara: '',
  durum: '',
  erp_okundu: '',
  emor: '',
  para_birimi: '',
  tip: '',
  istisna_kodu: '',
  istisnali: '',
  gizlenenler: '',
}

/** Gizlenen fatura "Gizlenenleri de göster" açıkken soluk görünür */
const GIZLI_SATIR = 'opacity-55'

const ERP_FILTRELERI: readonly ErpOkunduFiltresi[] = ['evet', 'hayir', 'bilinmiyor']

const EMOR_FILTRELERI: readonly EmorFiltresi[] = [
  'islendi',
  'elle_islendi',
  'islenmemis',
  'havuzda',
  'yok',
  'bilinmiyor',
]

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

/** Tablo hücresi metni: boşsa "—", uzunsa kısaltılır, tamamı üzerine gelince görünür. */
function Metin({ deger, genis = false }: { deger: string | null; genis?: boolean }) {
  if (deger === null || deger === '') {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span className={`block truncate ${genis ? 'max-w-xs' : 'max-w-[12rem]'}`} title={deger}>
      {deger}
    </span>
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

const EMOR_ROZETLERI: Record<Exclude<EmorDurumu, 'yok'>, string> = {
  islendi: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  elle_islendi: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  havuzda: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
}

/**
 * eMOR: İşlendi (muhasebeleşmiş) yeşil, elle işlendi (ETTN'siz, no + VKN ile)
 * mavi, Havuzda (ERP almış, muhasebeleşmemiş) sarı; ERP'de yoksa boş; henüz kontrol edilmediyse (null) soluk tire.
 */
function EmorRozeti({ deger }: { deger: EmorDurumu | null }) {
  const { t } = useTranslation()

  if (deger === null) {
    return (
      <span className="text-slate-400" title={t('efatura.emor.bilinmiyor')}>
        —
      </span>
    )
  }

  return deger === 'yok' ? null : (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${EMOR_ROZETLERI[deger]}`}
      title={t(`efatura.emor.aciklama.${deger}`)}
    >
      {t(`efatura.emor.${deger}`)}
    </span>
  )
}

/** İstisna kodu hücresi; iki kaynak farklıysa kırmızı ve açıklamalı. */
function IstisnaKodu({ deger, farkli }: { deger: string | null; farkli: boolean }) {
  const { t } = useTranslation()

  if (deger === null) {
    return <span className="text-slate-400">—</span>
  }

  return farkli ? (
    <span
      className="rounded bg-red-100 px-1.5 py-0.5 font-semibold whitespace-nowrap text-red-800 dark:bg-red-950 dark:text-red-300"
      title={t('efatura.istisnaFarkli')}
    >
      {deger}
    </span>
  ) : (
    <span className="whitespace-nowrap">{deger}</span>
  )
}

/** Hızlı filtre düğmesi: basılıyken dolu mavi, tekrar basınca kapanır. */
function HizliFiltre({
  aktif,
  onClick,
  children,
}: {
  aktif: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      aria-pressed={aktif}
      onClick={onClick}
      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
        aktif
          ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700 dark:border-blue-500 dark:bg-blue-600'
          : 'border-slate-300 text-slate-700 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-blue-950 dark:hover:text-blue-300'
      }`}
    >
      {children}
    </button>
  )
}

type AcikPencere = { tur: 'detay' | 'pdf'; fatura: EFatura } | null

export function EFaturalarPage({ yon }: { yon: FaturaYonu }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const pdfIzni = useIzin('efatura.pdf')
  const excelIzni = useIzin('efatura.disari_aktar')
  const gizlemeIzni = useIzin('efatura.gizle')

  const [filtre, setFiltre] = useState<EFaturaFiltresi>(() => ({
    ...BOS_FILTRE,
    bitis: bugunIso(),
    erp_okundu: adrestekiErpFiltresi(),
  }))
  const [sayfa, setSayfa] = useState(1)
  const [pencere, setPencere] = useState<AcikPencere>(null)
  const [excelSuruyor, setExcelSuruyor] = useState(false)
  // Gelen faturalar açılışta en son alınan üstte (kullanıcı isteği 2026-09-24)
  const { siralama, siralamaDegistir } = useKaliciSiralama(
    `efatura-${yon}`,
    yon === 'gelen' ? { anahtar: 'olusturma_zamani', yon: 'desc' } : null,
  )
  const kolonGorunurlugu = useKolonGorunurlugu(`efatura-${yon}`)

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

  // Filtre/sıralama değişince yeni sonuç gelene kadar önceki sonuç yerinde
  // kalır (tablo silinip yeniden çizilmez) ama soluk ve "güncelleniyor"
  // işaretlidir — yeni filtreninmiş gibi sunulmaz, Excel bu arada kapalıdır
  const eskiVeri = liste.isPlaceholderData
  const eskiVeriSinifi = `transition-opacity ${eskiVeri ? 'opacity-50' : ''}`

  const excelAl = async (gorunenKolonlar: ExcelKolonu[]) => {
    setExcelSuruyor(true)
    try {
      await excelIndir(yon, sorguFiltresi, siralama, sayfa, gorunenKolonlar)
    } catch (error) {
      toast.error(t(apiErrorKey(error)))
    } finally {
      setExcelSuruyor(false)
    }
  }

  // Karşı taraf: gelen faturada gönderici, giden faturada alıcı
  const gelen = yon === 'gelen'

  // Kullanıcı isteği (2026-09-24) sırasıyla; hepsi göster/gizle seçilebilir
  const secilebilirKolonlar: DataTableKolonu<EFatura>[] = [
    {
      anahtar: 'erp_okundu',
      baslik: t('efatura.alan.erpOkundu'),
      siralamaAnahtari: 'erp_okundu',
      hizala: 'orta',
      render: (f) => <ErpRozeti deger={f.erp_okundu} />,
    },
    // ERP'deki aşama (gelen: havuz TOHOM_E_FATURA → muhasebe TOHOM_FATURA; giden: gönderilen listesi)
    {
      anahtar: 'emor',
      baslik: t('efatura.kolon.emor'),
      siralamaAnahtari: 'emor',
      hizala: 'orta',
      render: (f) => <EmorRozeti deger={f.emor_durumu} />,
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
      render: (f) => <Metin deger={gelen ? f.gonderici_vkn : f.alici_vkn} />,
    },
    {
      anahtar: 'karsi_unvan',
      baslik: t('efatura.kolon.unvan'),
      siralamaAnahtari: 'karsi_unvan',
      render: (f) => <Metin deger={gelen ? f.gonderici_unvan : f.alici_unvan} genis />,
    },
    {
      anahtar: 'karsi_ad_soyad',
      baslik: t('efatura.kolon.adSoyad'),
      siralamaAnahtari: 'karsi_ad_soyad',
      render: (f) => <Metin deger={gelen ? f.gonderici_ad_soyad : f.alici_ad_soyad} />,
    },
    {
      anahtar: 'fatura_tipi',
      baslik: t('efatura.kolon.tip'),
      siralamaAnahtari: 'fatura_tipi',
      render: (f) => <Metin deger={f.fatura_tipi} />,
    },
    // Vergi istisna kodu iki kaynaktan (yalnız gelen): entegratördeki UBL esas,
    // ERP'deki (TOHOM_E_FATURA) yanında; farklıysa ikisi de kırmızı
    ...(gelen
      ? [
          {
            anahtar: 'izibiz_istisna_kodu',
            baslik: t('efatura.kolon.istisnaKoduEntegrator'),
            render: (f: EFatura) => (
              <IstisnaKodu deger={f.izibiz_istisna_kodu} farkli={istisnaKoduFarkli(f)} />
            ),
          },
          {
            anahtar: 'vergi_istisna_kodu',
            baslik: t('efatura.kolon.istisnaKoduErp'),
            render: (f: EFatura) => (
              <IstisnaKodu deger={f.vergi_istisna_kodu} farkli={istisnaKoduFarkli(f)} />
            ),
          },
        ]
      : []),
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
      anahtar: 'irsaliye_no',
      baslik: t('efatura.kolon.irsaliyeNo'),
      siralamaAnahtari: 'irsaliye_no',
      render: (f) => <Metin deger={f.irsaliye_no} />,
    },
    {
      anahtar: 'siparis_no',
      baslik: t('efatura.kolon.siparisNo'),
      siralamaAnahtari: 'siparis_no',
      render: (f) => <Metin deger={f.siparis_no} />,
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
    {
      anahtar: 'yanit_aciklamasi',
      baslik: t('efatura.kolon.yanitAciklamasi'),
      siralamaAnahtari: 'yanit_aciklamasi',
      render: (f) => <Metin deger={f.yanit_aciklamasi} genis />,
    },
    {
      anahtar: 'gtb_ref_no',
      baslik: t('efatura.kolon.gtbRefNo'),
      render: (f) => <Metin deger={f.gtb_ref_no} />,
    },
    {
      anahtar: 'gcb_tescil_no',
      baslik: t('efatura.kolon.gcbTescilNo'),
      render: (f) => <Metin deger={f.gcb_tescil_no} />,
    },
    {
      anahtar: 'gcb_tarihi',
      baslik: t('efatura.kolon.gcbTarihi'),
      render: (f) => (
        <span className="whitespace-nowrap">{f.gcb_tarihi ? tarihGoster(f.gcb_tarihi) : '—'}</span>
      ),
    },
    {
      anahtar: 'gonderici_etiketi',
      baslik: t('efatura.kolon.gondericiBilgisi'),
      render: (f) => <Metin deger={f.gonderici_etiketi} />,
    },
    {
      anahtar: 'alici_etiketi',
      baslik: t('efatura.kolon.aliciBilgisi'),
      render: (f) => <Metin deger={f.alici_etiketi} />,
    },
    {
      anahtar: 'portal_notu',
      baslik: t('efatura.kolon.portalNotu'),
      render: (f) => <Metin deger={f.portal_notu} genis />,
    },
    // Eski Excel'deki alanlar (kullanıcı isteği 2026-09-24): ekranda da seçilebilir,
    // Excel tablonun aynısı olduğu için oraya da buradan gider
    {
      anahtar: 'ettn',
      baslik: t('efatura.kolon.ettn'),
      render: (f) => <span className="font-mono text-xs whitespace-nowrap">{f.ettn}</span>,
    },
    {
      anahtar: 'senaryo',
      baslik: t('efatura.kolon.senaryo'),
      siralamaAnahtari: 'senaryo',
      render: (f) => <Metin deger={f.senaryo} />,
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
  ]

  const kolonlar: DataTableKolonu<EFatura>[] = [
    {
      // İşlemler en solda ve her zaman görünür (seçicide yok) — kullanıcı isteği 2026-09-24
      anahtar: 'islemler',
      baslik: '',
      render: (f) => (
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => setPencere({ tur: 'detay', fatura: f })}>
            {t('efatura.detay')}
          </Button>
          {pdfIzni ? (
            <Button variant="secondary" onClick={() => setPencere({ tur: 'pdf', fatura: f })}>
              {t('efatura.pdf')}
            </Button>
          ) : null}
          {f.gizli ? (
            <span
              className="self-center rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
              title={t('efatura.gizleme.rozetAciklama', { kisi: f.gizleyen ?? '—' })}
            >
              {t('efatura.gizleme.rozet')}
            </span>
          ) : null}
        </div>
      ),
    },
    ...secilebilirKolonlar.filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar)),
  ]

  // Excel tablonun aynısı: görünen kolonlar, ekrandaki başlık ve sırayla
  const excelKolonlari: ExcelKolonu[] = secilebilirKolonlar
    .filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar))
    .map((kolon) => ({ anahtar: kolon.anahtar, baslik: kolon.baslik }))

  const secenekler = liste.data?.secenekler
  // Türkçe açıklama, parantez içinde İzibiz kodu: "Kabul Edildi (ACCEPTED)"
  const durumSecenekleri: SecenekOgesi[] = (secenekler?.durumlar ?? []).map((d) => ({
    value: d.deger,
    label: d.aciklama ? `${d.aciklama} (${d.deger})` : d.deger,
  }))
  const paraSecenekleri: SecenekOgesi[] = (secenekler?.para_birimleri ?? []).map((p) => ({
    value: p,
    label: p,
  }))
  // Giden faturada havuz aşaması yok
  const emorSecenekleri: SecenekOgesi[] = EMOR_FILTRELERI.filter(
    (d) => gelen || d !== 'havuzda',
  ).map((d) => ({ value: d, label: t(`efatura.emor.filtre.${d}`) }))
  const tipSecenekleri: SecenekOgesi[] = (secenekler?.tipler ?? []).map((d) => ({
    value: d,
    label: d,
  }))
  const istisnaSecenekleri: SecenekOgesi[] = (secenekler?.istisna_kodlari ?? []).map((d) => ({
    value: d,
    label: d,
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
            onClick={() => void excelAl(excelKolonlari)}
            title={t('efatura.excelAciklama')}
            yukleniyor={excelSuruyor}
            disabled={tarihHatasi !== null || eskiVeri || (liste.data?.meta.total ?? 0) === 0}
          >
            {t('efatura.excel')}
          </Button>
        ) : null}
      </div>

      {/* Hızlı filtreler (kullanıcı isteği 2026-09-24): tekrar basınca kapanır */}
      <div
        className="mt-3 flex flex-wrap items-center gap-2"
        role="group"
        aria-label={t('efatura.hizliFiltre.baslik')}
      >
        <span className="text-sm font-semibold text-slate-500 dark:text-slate-400">
          {t('efatura.hizliFiltre.baslik')}
        </span>
        <HizliFiltre
          aktif={filtre.emor === 'islenmemis'}
          onClick={() => filtreDegistir('emor', filtre.emor === 'islenmemis' ? '' : 'islenmemis')}
        >
          {t('efatura.hizliFiltre.erpIslenmeyenler')}
        </HizliFiltre>
        {gelen ? (
          // Entegratörde olup ERP havuzunda (TOHOM_E_FATURA) olmayanlar = eMOR "ERP'de yok";
          // eMOR alanını paylaştığı için "ERP İşlenmeyenler" ile birlikte basılı olamaz
          <HizliFiltre
            aktif={filtre.emor === 'yok'}
            onClick={() => filtreDegistir('emor', filtre.emor === 'yok' ? '' : 'yok')}
          >
            {t('efatura.hizliFiltre.havuzdaOlmayanlar')}
          </HizliFiltre>
        ) : null}
        {gelen ? (
          <HizliFiltre
            aktif={filtre.istisnali === 'evet'}
            onClick={() => filtreDegistir('istisnali', filtre.istisnali === 'evet' ? '' : 'evet')}
          >
            {t('efatura.hizliFiltre.istisnalilar')}
          </HizliFiltre>
        ) : null}
        {gelen ? (
          // Gizlenen (bize ait olmayan) faturalar açılışta listede yok (kullanıcı isteği 2026-09-24)
          <div className="ml-auto">
            <Switch
              id={`efatura-gizlenenler-${yon}`}
              label={t('efatura.gizleme.goster', { adet: secenekler?.gizlenen_adet ?? 0 })}
              checked={filtre.gizlenenler === 'dahil'}
              onChange={(acik) => filtreDegistir('gizlenenler', acik ? 'dahil' : '')}
            />
          </div>
        ) : null}
      </div>

      <div className="mt-4">
        {durum.data ? <SenkronDurumuPaneli yon={yon} durum={durum.data} /> : null}
        {durum.isError ? (
          // Güncellik bilinmiyorsa liste güncel sanılmasın
          <ErrorState mesaj={t('efatura.durumAlinamadi')} tekrarDene={() => void durum.refetch()} />
        ) : null}
      </div>

      {/* Kısa değerli alanlar dar (tarih, ERP okudu, para birimi); sığmayan alta geçer */}
      <div className="mt-4 flex flex-wrap items-start gap-3">
        <div className="w-full sm:w-36">
          <TarihInput
            id="efatura-baslangic"
            label={t('efatura.baslangic')}
            value={filtre.baslangic}
            onChange={(deger) => filtreDegistir('baslangic', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-36">
          <TarihInput
            id="efatura-bitis"
            label={t('efatura.bitis')}
            value={filtre.bitis}
            onChange={(deger) => filtreDegistir('bitis', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:min-w-64 sm:flex-1">
          <Input
            id="efatura-ara"
            type="search"
            label={t('efatura.ara')}
            maxLength={100}
            value={filtre.ara}
            onChange={(olay) => filtreDegistir('ara', olay.target.value)}
          />
        </div>
        <div className="w-full sm:w-60">
          <SelectField
            id="efatura-durum"
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
            id="efatura-tip"
            label={t('efatura.kolon.tip')}
            options={tipSecenekleri}
            value={secili(tipSecenekleri, filtre.tip)}
            onChange={(secim) => filtreDegistir('tip', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
        {gelen ? (
          <div className="w-full sm:w-40">
            <SelectField
              id="efatura-istisna"
              label={t('efatura.kolon.vergiIstisnaKodu')}
              options={istisnaSecenekleri}
              value={secili(istisnaSecenekleri, filtre.istisna_kodu)}
              onChange={(secim) => filtreDegistir('istisna_kodu', secim?.value ?? '')}
              placeholder={t('efatura.hepsi')}
              isClearable
            />
          </div>
        ) : null}
        <div className="w-full sm:w-36">
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
        </div>
        <div className="w-full sm:w-52">
          <SelectField
            id="efatura-emor"
            label={t('efatura.kolon.emor')}
            options={emorSecenekleri}
            value={secili(emorSecenekleri, filtre.emor)}
            onChange={(secim) => filtreDegistir('emor', (secim?.value ?? '') as EmorFiltresi)}
            placeholder={t('efatura.hepsi')}
            isClearable
            isSearchable={false}
          />
        </div>
        <div className="w-full sm:w-28">
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
            // Uzun listede alta inmeden sayfa değiştirilebilsin (altta da aynısı var)
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
                boyutSeciciId="sayfa-boyutu-ust"
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
              satirSinifi={(f) => (f.gizli ? GIZLI_SATIR : durumSatirSinifi(f.durum))}
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
                boyutSeciciId="sayfa-boyutu-alt"
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
        {pencere?.tur === 'detay' && gelen && gizlemeIzni ? (
          <GizlemeDugmesi
            fatura={pencere.fatura}
            degisti={(fatura) => setPencere({ tur: 'detay', fatura })}
          />
        ) : null}
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
