import { skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu, type ToplamSatiri } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { tutarGoster } from '@/features/kesinti/bicim'
import { bugunIso, tarihGoster } from '@/utils/tarih'

import {
  KOLONLAR,
  TUTARLAR,
  taseronHakedisleriExcel,
  taseronHakedisleriGetir,
  taseronHakedisSecenekleriGetir,
  type HakedisSatiri,
  type RaporParametreleri,
} from './taseronHakedisleriApi'

/** Açılışta tüm geçmiş, bugüne kadar */
const ILK_TARIH = '2000-01-01'

/** Tarihler dışında ortalanan kısa kolonlar */
const ORTALI = new Set<string>(['hakedis_no', 'para_kodu', 'onay_saati'])

/** Uzun metinler (en uzun ~130 karakter): sabit geniş kolon, sığmayan kısım "…" */
const UZUN_METINLER = new Set<string>(['firma_adi', 'sozlesme_konusu'])

type TarihHatalari = Partial<Record<'ilk_tarih' | 'son_tarih', string>>

/**
 * Raporlar → Taşeron Hakedişleri (kullanıcı isteği 2026-11-03): ERP rapor
 * proc'unun hakedişleri, hakediş başına bir satır, kolonlar kullanıcının
 * verdiği sırayla; para koduna göre toplamlarıyla. Proc ağır (tüm tarihlerde
 * ~9 sn): yalnız "Raporu Çalıştır"la çalışır, sonuç kendiliğinden tazelenmez.
 */
export function TaseronHakedisleriPage() {
  const { t } = useTranslation()
  const [parametreler, setParametreler] = useState<RaporParametreleri>(() => ({
    ilk_tarih: ILK_TARIH,
    son_tarih: bugunIso(),
    proje_kodu: '',
    firma_adi: '',
  }))
  // i18n anahtarları: dil değişince mesaj da değişir
  const [hatalar, setHatalar] = useState<TarihHatalari>({})
  // Son çalıştırma: tablo ve Excel bu parametrelerle; her basış yeni sorgu (no)
  const [calistirma, setCalistirma] = useState<{
    parametreler: RaporParametreleri
    no: number
  } | null>(null)

  const secenekler = useQuery({
    queryKey: queryKeys.raporlar.taseronHakedisSecenekleri,
    queryFn: taseronHakedisSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  const rapor = useQuery({
    queryKey: queryKeys.raporlar.taseronHakedisleri(
      { ...calistirma?.parametreler },
      calistirma?.no ?? 0,
    ),
    queryFn: calistirma ? () => taseronHakedisleriGetir(calistirma.parametreler) : skipToken,
    staleTime: Infinity,
    // Proc hatası (ör. yetki) yeniden denenirse bekleme iki katına çıkar
    retry: false,
  })

  const excel = useMutation({
    mutationFn: taseronHakedisleriExcel,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const degistir = <K extends keyof RaporParametreleri>(alan: K, deger: RaporParametreleri[K]) =>
    setParametreler((onceki) => ({ ...onceki, [alan]: deger }))

  function calistir(olay: FormEvent<HTMLFormElement>) {
    olay.preventDefault()
    const { ilk_tarih, son_tarih } = parametreler
    const yeniHatalar: TarihHatalari = {}

    if (!ilk_tarih) {
      yeniHatalar.ilk_tarih = 'raporlar.dogrulama.tarihZorunlu'
    }
    if (!son_tarih) {
      yeniHatalar.son_tarih = 'raporlar.dogrulama.tarihZorunlu'
    } else if (ilk_tarih && son_tarih < ilk_tarih) {
      yeniHatalar.son_tarih = 'raporlar.dogrulama.sonTarihOnce'
    }

    setHatalar(yeniHatalar)
    if (Object.keys(yeniHatalar).length === 0) {
      setCalistirma((onceki) => ({ parametreler, no: (onceki?.no ?? 0) + 1 }))
    }
  }

  const projeSecenekleri: SecenekOgesi[] = (secenekler.data?.projeler ?? []).map((proje) => ({
    value: proje.kod,
    label: proje.ad && proje.ad !== proje.kod ? `${proje.kod} - ${proje.ad}` : proje.kod,
  }))
  const firmaSecenekleri: SecenekOgesi[] = (secenekler.data?.firmalar ?? []).map((ad) => ({
    value: ad,
    label: ad,
  }))
  const secenekHatasi = secenekler.error ? t(apiErrorKey(secenekler.error)) : undefined

  const kolonBasligi = (alan: string) => t(`raporlar.taseronHakedisleri.kolon.${alan}`)
  const kolonlar: DataTableKolonu<HakedisSatiri>[] = [
    {
      anahtar: 'sira_no',
      baslik: kolonBasligi('sira_no'),
      hizala: 'orta',
      render: (_satir, sira) => (
        <span className="text-slate-500 tabular-nums dark:text-slate-400">{sira + 1}</span>
      ),
    },
    ...KOLONLAR.map(({ alan, tur }): DataTableKolonu<HakedisSatiri> => ({
      anahtar: alan,
      baslik: kolonBasligi(alan),
      hizala: tur === 'tutar' ? 'sag' : tur === 'tarih' || ORTALI.has(alan) ? 'orta' : 'sol',
      render: (satir) => {
        const deger = satir[alan]

        if (tur === 'tutar') {
          return <span className="whitespace-nowrap tabular-nums">{tutarGoster(deger ?? 0)}</span>
        }
        if (tur === 'tarih') {
          return (
            <span className="whitespace-nowrap tabular-nums">
              {tarihGoster(String(deger ?? ''))}
            </span>
          )
        }

        // Hepsi tek satır; uzun adlar kesilir, tamamı üzerine gelince görünür
        return UZUN_METINLER.has(alan) ? (
          <span className="block w-112 truncate" title={String(deger)}>
            {deger}
          </span>
        ) : (
          <span className="whitespace-nowrap">{deger}</span>
        )
      },
    })),
  ]

  // Para koduna göre genel toplam, tablonun üstünde ve altında
  const toplamSatirlari: ToplamSatiri[] = (rapor.data?.toplamlar ?? []).map((toplam) => ({
    anahtar: toplam.para_kodu,
    etiket: t('raporlar.toplam'),
    hucreler: {
      para_kodu: toplam.para_kodu,
      ...Object.fromEntries(
        TUTARLAR.map((alan) => [
          alan,
          <span key={alan} className="whitespace-nowrap tabular-nums">
            {tutarGoster(toplam[alan])}
          </span>,
        ]),
      ),
    },
  }))

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('raporlar.taseronHakedisleri.baslik')}</h2>
        <Button
          variant="yesil"
          yukleniyor={excel.isPending}
          disabled={!rapor.isSuccess || rapor.isFetching}
          onClick={() => {
            if (calistirma) {
              excel.mutate(calistirma.parametreler)
            }
          }}
        >
          {t('raporlar.excelAktar')}
        </Button>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('raporlar.taseronHakedisleri.aciklamaMetni')}
      </p>

      <form
        noValidate
        onSubmit={calistir}
        aria-label={t('raporlar.parametreler')}
        className="mt-4 grid items-start gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-[10rem_10rem_minmax(0,1fr)_minmax(0,1fr)_auto] dark:border-slate-700"
      >
        <TarihInput
          id="hakedis-ilk-tarih"
          label={t('raporlar.ilkTarih')}
          value={parametreler.ilk_tarih}
          onChange={(iso) => degistir('ilk_tarih', iso)}
          hata={hatalar.ilk_tarih ? t(hatalar.ilk_tarih) : undefined}
        />
        <TarihInput
          id="hakedis-son-tarih"
          label={t('raporlar.sonTarih')}
          value={parametreler.son_tarih}
          onChange={(iso) => degistir('son_tarih', iso)}
          hata={hatalar.son_tarih ? t(hatalar.son_tarih) : undefined}
        />
        <SelectField
          id="hakedis-proje"
          label={t('raporlar.projeKodu')}
          options={projeSecenekleri}
          value={projeSecenekleri.find((s) => s.value === parametreler.proje_kodu) ?? null}
          onChange={(secenek) => degistir('proje_kodu', secenek?.value ?? '')}
          isClearable
          yukleniyor={secenekler.isPending}
          hata={secenekHatasi}
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="hakedis-firma"
          label={t('raporlar.firmaAdi')}
          options={firmaSecenekleri}
          value={firmaSecenekleri.find((s) => s.value === parametreler.firma_adi) ?? null}
          onChange={(secenek) => degistir('firma_adi', secenek?.value ?? '')}
          isClearable
          yukleniyor={secenekler.isPending}
          hata={secenekHatasi}
          placeholder={t('ortak.hepsi')}
        />
        {/* Etiket yüksekliği kadar aşağıda: kutularla aynı hizada */}
        <div className="sm:col-span-2 lg:col-span-1 lg:pt-6">
          <Button type="submit" yukleniyor={rapor.isFetching} className="w-full lg:w-auto">
            {t('raporlar.calistir')}
          </Button>
        </div>
      </form>

      {/* TarihInput yalnız çerçeveyi kırmızı yapar; mesaj e-Fatura'daki gibi formun altında */}
      {Object.keys(hatalar).length > 0 ? (
        <p
          role="alert"
          className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {[...new Set(Object.values(hatalar))].map((anahtar) => t(anahtar)).join(' ')}
        </p>
      ) : null}

      {calistirma === null ? (
        <div className="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          {t('raporlar.onceCalistirin')}
        </div>
      ) : rapor.error ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(rapor.error))} tekrarDene={() => void rapor.refetch()} />
        </div>
      ) : (
        <div className="mt-4">
          <DataTable
            kolonlar={kolonlar}
            satirlar={rapor.data?.data ?? []}
            satirAnahtari={(satir) => satir.hakedis_id}
            yukleniyor={rapor.isFetching}
            ustKaydirma
            toplamSatirlari={toplamSatirlari}
          />
        </div>
      )}
    </>
  )
}
