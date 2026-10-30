import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { KolonSecici } from '@/components/KolonSecici'
import { Modal } from '@/components/Modal'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { Pagination } from '@/components/Pagination'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SelectField } from '@/components/SelectField'
import {
  isgucuKesintileriniGetir,
  isgucuKesintisiSil,
  kesintiSecenekleriniGetir,
  type IsgucuFiltresi,
  type IsgucuKesintisi,
  type KesintiTanimi,
} from '@/features/kesinti/isgucuApi'
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { useKolonGorunurlugu } from '@/hooks/useKolonGorunurlugu'
import { tarihGoster } from '@/utils/tarih'

import { tutarGoster } from './bicim'
import { IceAktarimPenceresi, ToplamOzeti } from './IceAktarimPenceresi'
import { IsgucuKesintisiFormu } from './IsgucuKesintisiFormu'
import { YuklemelerPenceresi } from './YuklemelerPenceresi'

const BOS_FILTRE: IsgucuFiltresi = { ara: '', firma_id: '', proje_id: '', baslangic: '', bitis: '' }

/** Metin kolonları (anahtar = alan = sıralama anahtarı) */
const METIN_KOLONLARI = [
  'mahal',
  'kaynak_firma',
  'kaynak_fis_no',
  'adi_soyadi',
  'tanim',
  'gorev',
  'departman',
  'aktivite',
] as const

/** Sayı kolonları ve ondalık basamakları */
const SAYI_KOLONLARI = {
  miktar_saat: 2,
  miktar_yevmiye: 2,
  birim_fiyat: 4,
  toplam_tutar: 2,
  iptal_edilen_tutar: 2,
  yansitilan_tutar: 2,
  kesilen_tutar: 2,
  kalan_tutar: 2,
} as const

function tanimSecenekleri(liste: KesintiTanimi[] | undefined) {
  return (liste ?? []).map((tanim) => ({
    value: String(tanim.id),
    label: `${tanim.kod} — ${tanim.ad}`,
  }))
}

export function IsgucuKesintileriPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('isgucu_kesintileri.guncelle')
  const [filtre, setFiltre] = useState<IsgucuFiltresi>(BOS_FILTRE)
  const [sayfa, setSayfa] = useState(1)
  // null: kapalı; 'yeni': yeni satır; IsgucuKesintisi: düzenleme
  const [formdaki, setFormdaki] = useState<IsgucuKesintisi | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<IsgucuKesintisi | null>(null)
  const [aktarimAcik, setAktarimAcik] = useState(false)
  const [yuklemelerAcik, setYuklemelerAcik] = useState(false)

  const { siralama, siralamaDegistir: kaliciSiralamaDegistir } = useKaliciSiralama(
    'isgucuKesintileri',
    { anahtar: 'tarih', yon: 'desc' },
  )
  const kolonGorunurlugu = useKolonGorunurlugu('isgucuKesintileri')

  // Arama yazarken her tuşta istek atılmaz; diğer filtreler anında uygulanır
  const ara = useDebounce(filtre.ara)
  const sorguFiltresi = { ...filtre, ara }

  const secenekler = useQuery({
    queryKey: queryKeys.kesinti.secenekler,
    queryFn: kesintiSecenekleriniGetir,
  })
  const liste = useQuery({
    queryKey: queryKeys.kesinti.isgucuListesi({ ...sorguFiltresi, siralama, sayfa }),
    queryFn: () => isgucuKesintileriniGetir(sorguFiltresi, siralama, sayfa),
    placeholderData: keepPreviousData,
  })

  const sil = useMutation({
    mutationFn: (kesinti: IsgucuKesintisi) => isgucuKesintisiSil(kesinti.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuListeleri })
      toast.success(t('kesinti.isgucu.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  const filtreDegistir = <K extends keyof IsgucuFiltresi>(alan: K, deger: IsgucuFiltresi[K]) => {
    setFiltre((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }
  const siralamaDegistir = (anahtar: string) => {
    kaliciSiralamaDegistir(anahtar)
    setSayfa(1)
  }

  const firmaSecenekleri = tanimSecenekleri(secenekler.data?.firmalar)
  const projeSecenekleri = tanimSecenekleri(secenekler.data?.projeler)

  const metin = (deger: string | null) =>
    deger === null || deger === '' ? <span className="text-slate-400">—</span> : deger

  const tumKolonlar: DataTableKolonu<IsgucuKesintisi>[] = [
    {
      anahtar: 'tarih',
      baslik: t('kesinti.isgucu.alan.tarih'),
      siralamaAnahtari: 'tarih',
      render: (k) => <span className="whitespace-nowrap">{tarihGoster(k.tarih)}</span>,
    },
    {
      anahtar: 'kesinti_turu',
      baslik: t('kesinti.isgucu.alan.kesinti_turu'),
      siralamaAnahtari: 'kesinti_turu',
      render: (k) => k.kesinti_turu.kod,
    },
    {
      anahtar: 'taseron',
      baslik: t('kesinti.isgucu.alan.taseron'),
      siralamaAnahtari: 'taseron',
      render: (k) => <strong title={k.firma.ad}>{k.firma.kod}</strong>,
    },
    {
      anahtar: 'proje',
      baslik: t('kesinti.isgucu.alan.proje'),
      siralamaAnahtari: 'proje',
      render: (k) => k.proje.kod,
    },
    ...METIN_KOLONLARI.map((alan): DataTableKolonu<IsgucuKesintisi> => ({
      anahtar: alan,
      baslik: t(`kesinti.isgucu.alan.${alan}`),
      siralamaAnahtari: alan,
      render: (k) => metin(k[alan]),
    })),
    ...(['vasif', 'mesai_turu', 'yansitma_turu'] as const).map(
      (alan): DataTableKolonu<IsgucuKesintisi> => ({
        anahtar: alan,
        baslik: t(`kesinti.isgucu.alan.${alan}`),
        siralamaAnahtari: alan,
        render: (k) => (
          <span className="whitespace-nowrap">
            {t(`kesinti.isgucu.secenek.${alan}.${k[alan]}`)}
          </span>
        ),
      }),
    ),
    {
      anahtar: 'para_birimi',
      baslik: t('kesinti.isgucu.alan.para_birimi'),
      siralamaAnahtari: 'para_birimi',
      render: (k) => k.para_birimi.kod,
    },
    ...(Object.entries(SAYI_KOLONLARI) as [keyof typeof SAYI_KOLONLARI, number][]).map(
      ([alan, basamak]): DataTableKolonu<IsgucuKesintisi> => ({
        anahtar: alan,
        baslik: t(`kesinti.isgucu.alan.${alan}`),
        siralamaAnahtari: alan,
        hizala: 'sag',
        render: (k) => <span className="tabular-nums">{tutarGoster(k[alan], basamak)}</span>,
      }),
    ),
  ]
  const islemKolonu: DataTableKolonu<IsgucuKesintisi> = {
    anahtar: 'islemler',
    baslik: '',
    hizala: 'sag',
    render: (k) => (
      <div className="flex justify-end gap-2">
        <Button variant="turuncu" onClick={() => setFormdaki(k)}>
          {t('ortak.duzenle')}
        </Button>
        <Button variant="danger" onClick={() => setSilinecek(k)}>
          {t('ortak.sil')}
        </Button>
      </div>
    ),
  }
  const gorunenKolonlar = tumKolonlar.filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar))
  // Yalnız görüntüleme izninde düzenle/sil kolonu çizilmez
  const kolonlar = guncelleyebilir ? [...gorunenKolonlar, islemKolonu] : gorunenKolonlar

  const sayfalama = (konum: 'ust' | 'alt') =>
    liste.data ? (
      <Pagination
        sayfa={liste.data.meta.current_page}
        toplamSayfa={liste.data.meta.last_page}
        sayfaDegistir={setSayfa}
        disabled={liste.isFetching}
        boyutSeciciId={`isgucu-sayfa-boyutu-${konum}`}
      />
    ) : null

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.isgucu.baslik')}</h2>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => setYuklemelerAcik(true)}>
            {t('kesinti.isgucu.yuklemeler.baslik')}
          </Button>
          {guncelleyebilir ? (
            <>
              <Button variant="secondary" onClick={() => setAktarimAcik(true)}>
                {t('kesinti.isgucu.iceAktarim.ac')}
              </Button>
              <Button
                variant="mor"
                onClick={() => setFormdaki('yeni')}
                disabled={!secenekler.isSuccess}
              >
                {t('kesinti.isgucu.yeni')}
              </Button>
            </>
          ) : null}
        </div>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.isgucu.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Input
          id="isgucu-ara"
          type="search"
          label={t('kesinti.isgucu.ara')}
          maxLength={100}
          value={filtre.ara}
          onChange={(olay) => filtreDegistir('ara', olay.target.value)}
        />
        <SelectField
          id="isgucu-filtre-taseron"
          label={t('kesinti.isgucu.alan.taseron')}
          options={firmaSecenekleri}
          value={firmaSecenekleri.find((s) => s.value === filtre.firma_id) ?? null}
          onChange={(secenek) => filtreDegistir('firma_id', secenek?.value ?? '')}
          isClearable
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="isgucu-filtre-proje"
          label={t('kesinti.isgucu.alan.proje')}
          options={projeSecenekleri}
          value={projeSecenekleri.find((s) => s.value === filtre.proje_id) ?? null}
          onChange={(secenek) => filtreDegistir('proje_id', secenek?.value ?? '')}
          isClearable
          placeholder={t('ortak.hepsi')}
        />
        <OpsiyonelTarihInput
          id="isgucu-baslangic"
          label={t('kesinti.isgucu.baslangic')}
          value={filtre.baslangic}
          onChange={(iso) => filtreDegistir('baslangic', iso)}
        />
        <OpsiyonelTarihInput
          id="isgucu-bitis"
          label={t('kesinti.isgucu.bitis')}
          value={filtre.bitis}
          onChange={(iso) => filtreDegistir('bitis', iso)}
        />
      </div>

      {liste.error ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        </div>
      ) : (
        <>
          {liste.data && liste.data.ozet.length > 0 ? (
            <div
              className={`mt-4 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700 ${liste.isPlaceholderData ? 'opacity-50' : ''}`}
            >
              <ToplamOzeti toplamlar={liste.data.ozet} />
            </div>
          ) : null}
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <KolonSecici
              kolonlar={tumKolonlar.map(({ anahtar, baslik }) => ({ anahtar, baslik }))}
              gorunurMu={kolonGorunurlugu.gorunurMu}
              degistir={kolonGorunurlugu.degistir}
              hepsiniGoster={kolonGorunurlugu.hepsiniGoster}
            />
            {sayfalama('ust')}
          </div>
          <div className={`mt-3 ${liste.isPlaceholderData ? 'opacity-50' : ''}`}>
            <DataTable
              kolonlar={kolonlar}
              satirlar={liste.data?.data ?? []}
              satirAnahtari={(k) => k.id}
              yukleniyor={liste.isPending}
              siralama={siralama}
              siralamaDegistir={siralamaDegistir}
              ustKaydirma
            />
          </div>
          <div className="mt-3">{sayfalama('alt')}</div>
        </>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('kesinti.isgucu.yeni') : t('kesinti.isgucu.duzenle')}
        boyut="devasa"
      >
        {formdaki !== null && secenekler.isSuccess ? (
          <IsgucuKesintisiFormu
            kesinti={formdaki === 'yeni' ? null : formdaki}
            secenekler={secenekler.data}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <IceAktarimPenceresi acik={aktarimAcik} kapat={() => setAktarimAcik(false)} />
      <YuklemelerPenceresi
        acik={yuklemelerAcik}
        kapat={() => setYuklemelerAcik(false)}
        geriAlabilir={guncelleyebilir}
      />

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.isgucu.silOnayBaslik')}
        mesaj={t('kesinti.isgucu.silOnayMesaj', {
          ad: silinecek?.adi_soyadi ?? '',
          tarih: silinecek ? tarihGoster(silinecek.tarih) : '',
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
