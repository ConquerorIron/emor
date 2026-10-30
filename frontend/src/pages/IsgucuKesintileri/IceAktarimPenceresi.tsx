import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { Pagination } from '@/components/Pagination'
import { Switch } from '@/components/Switch'
import {
  isgucuAktarimKolonlariniGetir,
  isgucuIceAktar,
  isgucuOnizle,
  type IceAktarimGirdisi,
  type IceAktarimOnizlemesi,
  type KesintiToplami,
  type OnizlemeSatiri,
} from '@/features/kesinti/isgucuApi'
import { YapistirmaTablosu } from '@/features/kesinti/YapistirmaTablosu'
import { bosSatirlar, sekmeliMetin, type YapistirmaKolonu } from '@/features/kesinti/yapistirma'
import { tarihGoster } from '@/utils/tarih'

import { tutarGoster } from './bicim'

const SAYFA_BOYUTU = 50

/** Önizleme tablosunda gösterilen alanlar (tümü Yüklemeden sonra listede görünür) */
const ONIZLEME_ALANLARI = [
  'kesinti_turu',
  'taseron',
  'proje',
  'tarih',
  'adi_soyadi',
  'mesai_turu',
  'yansitma_turu',
  'miktar_saat',
  'miktar_yevmiye',
  'birim_fiyat',
  'para_birimi',
  'toplam_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
] as const

const SAYI_ALANLARI: Record<string, number> = {
  miktar_saat: 2,
  miktar_yevmiye: 2,
  birim_fiyat: 4,
  toplam_tutar: 2,
  yansitilan_tutar: 2,
  kesilen_tutar: 2,
  kalan_tutar: 2,
}

export function ToplamOzeti({ toplamlar }: { toplamlar: KesintiToplami[] }) {
  const { t } = useTranslation()

  return (
    <ul className="space-y-1 text-sm" aria-label={t('kesinti.isgucu.toplamlar')}>
      {toplamlar.map((toplam) => (
        <li key={toplam.para_birimi} className="flex flex-wrap gap-x-4 gap-y-1">
          <strong>{toplam.para_birimi}</strong>
          <span>{t('kesinti.isgucu.satirSayisi', { sayi: toplam.satir_sayisi })}</span>
          {(
            [
              'toplam_tutar',
              'iptal_edilen_tutar',
              'yansitilan_tutar',
              'kesilen_tutar',
              'kalan_tutar',
            ] as const
          ).map((alan) => (
            <span key={alan}>
              <span className="text-slate-500 dark:text-slate-400">
                {t(`kesinti.isgucu.alan.${alan}`)}:
              </span>{' '}
              <span className="tabular-nums">{tutarGoster(toplam[alan])}</span>
            </span>
          ))}
        </li>
      ))}
    </ul>
  )
}

/** Önizleme sonucu: dosya hatası, eksik kolonlar, hata özeti, toplamlar, satırlar */
function OnizlemeSonucu({ sonuc }: { sonuc: IceAktarimOnizlemesi }) {
  const { t } = useTranslation()
  const [sayfa, setSayfa] = useState(1)
  const [yalnizHatali, setYalnizHatali] = useState(false)

  if (sonuc.hata !== null) {
    return (
      <p
        role="alert"
        className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
      >
        {t(`kesinti.isgucu.iceAktarim.dosyaHatasi.${sonuc.hata}`)}
      </p>
    )
  }

  if (sonuc.eksik_kolonlar.length > 0) {
    return (
      <p
        role="alert"
        className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
      >
        {t('kesinti.isgucu.iceAktarim.eksikKolonlar', {
          kolonlar: sonuc.eksik_kolonlar.map((alan) => t(`kesinti.isgucu.alan.${alan}`)).join(', '),
        })}
      </p>
    )
  }

  const satirlar = yalnizHatali ? sonuc.satirlar.filter((satir) => satir.hatali) : sonuc.satirlar
  const toplamSayfa = Math.max(1, Math.ceil(satirlar.length / SAYFA_BOYUTU))
  const gecerliSayfa = Math.min(sayfa, toplamSayfa)

  const kolonlar: DataTableKolonu<OnizlemeSatiri>[] = [
    {
      anahtar: 'satir',
      baslik: t('kesinti.isgucu.iceAktarim.excelSatiri'),
      hizala: 'sag',
      render: (satir) => satir.satir,
    },
    ...ONIZLEME_ALANLARI.map((alan): DataTableKolonu<OnizlemeSatiri> => ({
      anahtar: alan,
      baslik: t(`kesinti.isgucu.alan.${alan}`),
      hizala: alan in SAYI_ALANLARI ? 'sag' : 'sol',
      render: (satir) => {
        const deger = String(satir[alan] ?? '')

        if (alan === 'tarih') {
          return tarihGoster(deger)
        }

        return alan in SAYI_ALANLARI ? (
          <span className="tabular-nums">{tutarGoster(deger, SAYI_ALANLARI[alan])}</span>
        ) : (
          deger
        )
      },
    })),
  ]

  return (
    <div className="space-y-4">
      {sonuc.hatalar.length > 0 ? (
        <div className="space-y-2">
          <p
            role="alert"
            className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
          >
            {t('kesinti.isgucu.iceAktarim.hataliDosya', {
              sayi: sonuc.satirlar.filter((satir) => satir.hatali).length,
            })}
          </p>
          <DataTable
            kolonlar={[
              {
                anahtar: 'alan',
                baslik: t('kesinti.isgucu.iceAktarim.alanBaslik'),
                render: (hata) => t(`kesinti.isgucu.alan.${hata.alan}`),
              },
              {
                anahtar: 'deger',
                baslik: t('kesinti.isgucu.iceAktarim.degerBaslik'),
                render: (hata) => <strong>{hata.deger === '' ? '—' : hata.deger}</strong>,
              },
              {
                anahtar: 'kod',
                baslik: t('kesinti.isgucu.iceAktarim.nedenBaslik'),
                render: (hata) => t(`kesinti.isgucu.iceAktarim.hataKodu.${hata.kod}`),
              },
              {
                anahtar: 'adet',
                baslik: t('kesinti.isgucu.iceAktarim.satirSayisiBaslik'),
                hizala: 'sag',
                render: (hata) => hata.adet,
              },
              {
                anahtar: 'satirlar',
                baslik: t('kesinti.isgucu.iceAktarim.ornekSatirlarBaslik'),
                render: (hata) =>
                  hata.satirlar.join(', ') + (hata.adet > hata.satirlar.length ? ' …' : ''),
              },
            ]}
            satirlar={sonuc.hatalar}
            satirAnahtari={(hata) => `${hata.alan}|${hata.kod}|${hata.deger}`}
          />
        </div>
      ) : (
        <div className="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
          <p className="mb-1 text-sm font-semibold">
            {t('kesinti.isgucu.iceAktarim.hatasiz', { sayi: sonuc.satirlar.length })}
          </p>
          <ToplamOzeti toplamlar={sonuc.toplamlar} />
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-3">
        {sonuc.hatalar.length > 0 ? (
          <Switch
            id="isgucu-yalniz-hatali"
            label={t('kesinti.isgucu.iceAktarim.yalnizHatali')}
            checked={yalnizHatali}
            onChange={(deger) => {
              setYalnizHatali(deger)
              setSayfa(1)
            }}
          />
        ) : (
          <span />
        )}
        {toplamSayfa > 1 ? (
          <Pagination
            sayfa={gecerliSayfa}
            toplamSayfa={toplamSayfa}
            sayfaDegistir={setSayfa}
            boyutSecici={false}
          />
        ) : null}
      </div>
      <div className="max-h-[45vh] overflow-y-auto">
        <DataTable
          kolonlar={kolonlar}
          satirlar={satirlar.slice((gecerliSayfa - 1) * SAYFA_BOYUTU, gecerliSayfa * SAYFA_BOYUTU)}
          satirAnahtari={(satir) => satir.satir}
          satirSinifi={(satir) => (satir.hatali ? 'bg-red-50 dark:bg-red-950/40' : undefined)}
        />
      </div>
    </div>
  )
}

/** Excel dosyası ya da kopyala-yapıştır → önizleme → (hatasızsa) tek parti olarak kayıt */
function AktarimIcerigi({ kapat }: { kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [kaynak, setKaynak] = useState<'dosya' | 'metin'>('dosya')
  const [dosya, setDosya] = useState<File | null>(null)
  // Yapıştırma tablosunun hücreleri; kolonlar gelene kadar boş tablo
  const [hucreler, setHucreler] = useState<string[][] | null>(null)
  // Girdi değişince önceki önizleme geçersizdir
  const [onizleme, setOnizleme] = useState<IceAktarimOnizlemesi | null>(null)

  // Tablo başlıkları ve kabul edilen başlık adları backend'in kolon tanımından
  const kolonSorgusu = useQuery({
    queryKey: queryKeys.kesinti.isgucuKolonlari,
    queryFn: isgucuAktarimKolonlariniGetir,
    staleTime: Infinity,
  })
  const kolonlar: YapistirmaKolonu[] = (kolonSorgusu.data ?? []).map((kolon) => ({
    ...kolon,
    etiket: t(`kesinti.isgucu.alan.${kolon.alan}`),
  }))
  const tablo = hucreler ?? bosSatirlar(kolonlar.length)
  const metin = kolonlar.length > 0 ? sekmeliMetin(tablo) : ''

  const girdi: IceAktarimGirdisi | null =
    kaynak === 'dosya' ? (dosya ? { dosya } : null) : metin.trim() !== '' ? { metin } : null
  // Önizleme satır numaraları tablonunkilerle aynı (başlıksız, standart sırayla gönderilir)
  const hataliSatirlar = new Set(
    (onizleme?.satirlar ?? []).filter((satir) => satir.hatali).map((satir) => satir.satir),
  )

  const onizle = useMutation({
    mutationFn: (secilen: IceAktarimGirdisi) => isgucuOnizle(secilen),
    onSuccess: setOnizleme,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const kaydet = useMutation({
    mutationFn: (secilen: IceAktarimGirdisi) => isgucuIceAktar(secilen),
    onSuccess: async (yukleme) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuListeleri }),
        queryClient.invalidateQueries({ queryKey: queryKeys.kesinti.isgucuYuklemeleri }),
      ])
      toast.success(t('kesinti.isgucu.iceAktarim.kaydedildi', { sayi: yukleme.satir_sayisi }))
      kapat()
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const kaydedilebilir =
    onizleme !== null &&
    onizleme.hata === null &&
    onizleme.eksik_kolonlar.length === 0 &&
    onizleme.hatalar.length === 0 &&
    onizleme.satirlar.length > 0

  const sekme = (deger: 'dosya' | 'metin') => (
    <button
      type="button"
      role="tab"
      aria-selected={kaynak === deger}
      onClick={() => {
        setKaynak(deger)
        setOnizleme(null)
      }}
      className={`cursor-pointer rounded-lg px-3 py-1.5 text-sm font-semibold ${
        kaynak === deger
          ? 'bg-violet-600 text-white'
          : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
      }`}
    >
      {t(`kesinti.isgucu.iceAktarim.kaynak.${deger}`)}
    </button>
  )

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.isgucu.iceAktarim.aciklama')}
      </p>
      <div role="tablist" className="flex gap-2">
        {sekme('dosya')}
        {sekme('metin')}
      </div>

      {kaynak === 'dosya' ? (
        <div>
          <label htmlFor="isgucu-dosya" className="text-sm font-semibold">
            {t('kesinti.isgucu.iceAktarim.dosyaSec')}
          </label>
          <input
            id="isgucu-dosya"
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            onChange={(olay) => {
              setDosya(olay.target.files?.[0] ?? null)
              setOnizleme(null)
            }}
            className="mt-1 block w-full text-sm file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold dark:file:bg-slate-800"
          />
        </div>
      ) : kolonSorgusu.error ? (
        <ErrorState
          mesaj={t(apiErrorKey(kolonSorgusu.error))}
          tekrarDene={() => void kolonSorgusu.refetch()}
        />
      ) : kolonlar.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-500">{t('ortak.yukleniyor')}</p>
      ) : (
        <YapistirmaTablosu
          kolonlar={kolonlar}
          satirlar={tablo}
          degistir={(yeni) => {
            setHucreler(yeni)
            setOnizleme(null)
          }}
          hataliSatirlar={hataliSatirlar}
        />
      )}

      <div className="flex justify-end">
        <Button
          type="button"
          variant="secondary"
          disabled={girdi === null}
          yukleniyor={onizle.isPending}
          onClick={() => {
            if (girdi !== null) {
              onizle.mutate(girdi)
            }
          }}
        >
          {t('kesinti.isgucu.iceAktarim.onizle')}
        </Button>
      </div>

      {onizleme !== null ? <OnizlemeSonucu sonuc={onizleme} /> : null}

      <div className="flex justify-end gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button
          type="button"
          disabled={!kaydedilebilir}
          yukleniyor={kaydet.isPending}
          onClick={() => {
            if (girdi !== null) {
              kaydet.mutate(girdi)
            }
          }}
        >
          {t('kesinti.isgucu.iceAktarim.kaydet', { sayi: onizleme?.satirlar.length ?? 0 })}
        </Button>
      </div>
    </div>
  )
}

export function IceAktarimPenceresi({ acik, kapat }: { acik: boolean; kapat: () => void }) {
  const { t } = useTranslation()

  return (
    <Modal acik={acik} kapat={kapat} baslik={t('kesinti.isgucu.iceAktarim.baslik')} boyut="devasa">
      {acik ? <AktarimIcerigi kapat={kapat} /> : null}
    </Modal>
  )
}
