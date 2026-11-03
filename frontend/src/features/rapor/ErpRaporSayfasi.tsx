import { useMutation, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { ALAN_KUTUSU } from '@/components/alanStilleri'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu, type ToplamSatiri } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Pagination } from '@/components/Pagination'
import { MultiSelectField, SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { tutarGoster } from '@/features/kesinti/bicim'
import { sayfaBoyutuOku } from '@/utils/sayfaBoyutu'
import { bugunIso, tarihGoster } from '@/utils/tarih'

import type { RaporParametreleri, RaporSatiri } from './erpRaporApi'
import type { ErpRaporTanimi, KolonTuru } from './raporTanimi'

/** Açılışta tüm geçmiş, bugüne kadar */
const ILK_TARIH = '2000-01-01'

type TarihHatalari = Partial<Record<'ilk_tarih' | 'son_tarih', string>>

interface AnlikSuzgec {
  projeler: string[]
  firmalar: string[]
}

const BOS_SUZGEC: AnlikSuzgec = { projeler: [], firmalar: [] }

/** Sonuçtaki farklı değerler, Türkçe sırayla (ZONE 7, ZONE 10'dan önce) */
function secenekler(degerler: string[]): SecenekOgesi[] {
  return [...new Set(degerler)]
    .sort((a, b) => a.localeCompare(b, 'tr', { numeric: true }))
    .map((deger) => ({ value: deger, label: deger }))
}

function hizasi(tur: KolonTuru, ortali: boolean): 'sol' | 'orta' | 'sag' {
  if (tur === 'tutar') {
    return 'sag'
  }

  return tur === 'tarih' || ortali ? 'orta' : 'sol'
}

/** Tutar ve tarih biçimli; metin tek satır */
function hucre(tur: KolonTuru, deger: string | number | null | undefined, genislik?: string) {
  if (tur === 'tutar') {
    return <span className="whitespace-nowrap tabular-nums">{tutarGoster(deger ?? 0)}</span>
  }
  if (tur === 'tarih') {
    return (
      <span className="whitespace-nowrap tabular-nums">{tarihGoster(String(deger ?? ''))}</span>
    )
  }

  return genislik ? (
    // Sabit genişlik: sığmayan kısım "…", tamamı üzerine gelince
    <span className={`block truncate ${genislik}`} title={String(deger ?? '')}>
      {deger}
    </span>
  ) : (
    <span className="whitespace-nowrap">{deger}</span>
  )
}

/**
 * Raporlar menüsündeki ERP raporlarının ortak ekranı (ilk: Taşeron
 * Hakedişleri, 2026-11-03). Parametreler proc'a gider ve rapor yalnız
 * "Raporu Çalıştır"la çalışır (mutation — sayfa boyutu değişince tüm
 * sorguların tazelenmesi ağır proc'u yeniden çalıştırmaz). Gelen veride
 * proje / firma süzme, sayfalama ve toplamlar tarayıcıda, anında yapılır;
 * Excel ekranda süzülen satırları alır.
 */
export function ErpRaporSayfasi<T extends RaporSatiri>({ tanim }: { tanim: ErpRaporTanimi<T> }) {
  const { t } = useTranslation()
  const [parametreler, setParametreler] = useState<RaporParametreleri>(() => ({
    ilk_tarih: ILK_TARIH,
    son_tarih: bugunIso(),
    proje_kodu: '',
    firma_adi: '',
  }))
  // i18n anahtarları: dil değişince mesaj da değişir
  const [hatalar, setHatalar] = useState<TarihHatalari>({})
  const [suzgec, setSuzgec] = useState<AnlikSuzgec>(BOS_SUZGEC)
  const [sayfa, setSayfa] = useState(1)
  // Ortak sayfa boyutu tercihi; Pagination değiştirince sayfaDegistir'de yeniden okunur
  const [boyut, setBoyut] = useState(sayfaBoyutuOku)

  const erpSecenekleri = useQuery({
    queryKey: queryKeys.raporlar.secenekler(tanim.anahtar),
    queryFn: tanim.api.secenekler,
    staleTime: 5 * 60_000,
  })

  // Son çalıştırmanın parametreleri rapor.variables'ta: Excel aynılarıyla iner
  const rapor = useMutation({ mutationFn: tanim.api.getir })

  const excel = useMutation({
    mutationFn: tanim.api.excel,
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
      // Önceki sonucun süzgeçleri yeni sonuçta anlamsız olabilir
      setSuzgec(BOS_SUZGEC)
      setSayfa(1)
      rapor.mutate(parametreler)
    }
  }

  const suzgecDegistir = (alan: keyof AnlikSuzgec, degerler: string[]) => {
    setSuzgec((onceki) => ({ ...onceki, [alan]: degerler }))
    setSayfa(1)
  }

  const projeSecenekleri: SecenekOgesi[] = (erpSecenekleri.data?.projeler ?? []).map((proje) => ({
    value: proje.kod,
    label: proje.ad && proje.ad !== proje.kod ? `${proje.kod} - ${proje.ad}` : proje.kod,
  }))
  const firmaSecenekleri: SecenekOgesi[] = (erpSecenekleri.data?.firmalar ?? []).map((ad) => ({
    value: ad,
    label: ad,
  }))
  const secenekHatasi = erpSecenekleri.error ? t(apiErrorKey(erpSecenekleri.error)) : undefined

  const projesi = (satir: T) => String(satir[tanim.projeAlani] ?? '')
  const firmasi = (satir: T) => String(satir[tanim.firmaAlani] ?? '')
  const tumSatirlar = rapor.data ?? []
  const suzgecProjeleri = secenekler(tumSatirlar.map(projesi))
  const suzgecFirmalari = secenekler(tumSatirlar.map(firmasi))
  const satirlar = tumSatirlar.filter(
    (satir) =>
      (suzgec.projeler.length === 0 || suzgec.projeler.includes(projesi(satir))) &&
      (suzgec.firmalar.length === 0 || suzgec.firmalar.includes(firmasi(satir))),
  )

  // Sayfalama tarayıcıda; 0 = "Hepsi"
  const toplamSayfa = boyut === 0 ? 1 : Math.max(1, Math.ceil(satirlar.length / boyut))
  const gecerliSayfa = Math.min(sayfa, toplamSayfa)
  const ilkIndeks = boyut === 0 ? 0 : (gecerliSayfa - 1) * boyut
  const sayfaSatirlari = boyut === 0 ? satirlar : satirlar.slice(ilkIndeks, ilkIndeks + boyut)

  const kolonBasligi = (alan: string) => t(`raporlar.${tanim.anahtar}.kolon.${alan}`)
  const ortali = new Set<string>(tanim.ortali ?? [])
  const kolonlar: DataTableKolonu<T>[] = [
    {
      anahtar: 'sira_no',
      baslik: t('raporlar.siraNo'),
      hizala: 'orta',
      render: (_satir, sira) => (
        <span className="text-slate-500 tabular-nums dark:text-slate-400">
          {ilkIndeks + sira + 1}
        </span>
      ),
    },
    ...tanim.kolonlar.map(({ alan, tur }): DataTableKolonu<T> => ({
      anahtar: alan,
      baslik: kolonBasligi(alan),
      hizala: hizasi(tur, ortali.has(alan)),
      render: (satir) => hucre(tur, satir[alan], tanim.sabitGenislik?.[alan]),
    })),
  ]

  const turler = new Map<string, KolonTuru>(tanim.kolonlar.map(({ alan, tur }) => [alan, tur]))
  const toplamSatirlari: ToplamSatiri[] = tanim.toplamlar(satirlar).map((toplam) => ({
    anahtar: toplam.anahtar,
    etiket: t(toplam.etiket),
    hucreler: Object.fromEntries(
      Object.entries(toplam.degerler).map(([alan, deger]) => [
        alan,
        hucre(turler.get(alan) ?? 'metin', deger),
      ]),
    ),
  }))

  const sayfalama = (konum: 'ust' | 'alt') => (
    <Pagination
      sayfa={gecerliSayfa}
      toplamSayfa={toplamSayfa}
      sayfaDegistir={(yeni) => {
        setSayfa(yeni)
        setBoyut(sayfaBoyutuOku())
      }}
      disabled={rapor.isPending}
      boyutSeciciId={`${tanim.anahtar}-sayfa-boyutu-${konum}`}
    />
  )

  const suzuldu = suzgec.projeler.length > 0 || suzgec.firmalar.length > 0

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t(`raporlar.${tanim.anahtar}.baslik`)}</h2>
        <Button
          variant="yesil"
          yukleniyor={excel.isPending}
          disabled={!rapor.isSuccess || satirlar.length === 0}
          onClick={() => {
            if (rapor.variables) {
              excel.mutate({
                parametreler: rapor.variables,
                anahtarlar: satirlar.map(tanim.satirAnahtari),
              })
            }
          }}
        >
          {t('raporlar.excelAktar')}
        </Button>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t(`raporlar.${tanim.anahtar}.aciklamaMetni`)}
      </p>

      {/* Alanlar alttan hizalı: düğme kutularla aynı satırda ve aynı yükseklikte */}
      <form
        noValidate
        onSubmit={calistir}
        aria-label={t('raporlar.parametreler')}
        className="mt-4 grid items-end gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-[10rem_10rem_minmax(0,1fr)_minmax(0,1fr)_auto] dark:border-slate-700"
      >
        <TarihInput
          id={`${tanim.anahtar}-ilk-tarih`}
          label={t('raporlar.ilkTarih')}
          value={parametreler.ilk_tarih}
          onChange={(iso) => degistir('ilk_tarih', iso)}
          hata={hatalar.ilk_tarih ? t(hatalar.ilk_tarih) : undefined}
        />
        <TarihInput
          id={`${tanim.anahtar}-son-tarih`}
          label={t('raporlar.sonTarih')}
          value={parametreler.son_tarih}
          onChange={(iso) => degistir('son_tarih', iso)}
          hata={hatalar.son_tarih ? t(hatalar.son_tarih) : undefined}
        />
        <SelectField
          id={`${tanim.anahtar}-proje`}
          label={t('raporlar.projeKodu')}
          options={projeSecenekleri}
          value={projeSecenekleri.find((s) => s.value === parametreler.proje_kodu) ?? null}
          onChange={(secenek) => degistir('proje_kodu', secenek?.value ?? '')}
          isClearable
          yukleniyor={erpSecenekleri.isPending}
          hata={secenekHatasi}
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id={`${tanim.anahtar}-firma`}
          label={t('raporlar.firmaAdi')}
          options={firmaSecenekleri}
          value={firmaSecenekleri.find((s) => s.value === parametreler.firma_adi) ?? null}
          onChange={(secenek) => degistir('firma_adi', secenek?.value ?? '')}
          isClearable
          yukleniyor={erpSecenekleri.isPending}
          hata={secenekHatasi}
          placeholder={t('ortak.hepsi')}
        />
        <div className="sm:col-span-2 lg:col-span-1">
          <Button
            type="submit"
            yukleniyor={rapor.isPending}
            className={`w-full lg:w-auto ${ALAN_KUTUSU}`}
          >
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

      {rapor.isIdle ? (
        <div className="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          {t('raporlar.onceCalistirin')}
        </div>
      ) : rapor.isError ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(rapor.error))}
            tekrarDene={() => {
              if (rapor.variables) {
                rapor.mutate(rapor.variables)
              }
            }}
          />
        </div>
      ) : (
        <>
          {/* Gelen veride anında süzme: proc yeniden çalışmaz */}
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            <MultiSelectField
              id={`${tanim.anahtar}-suz-proje`}
              label={t('raporlar.projeyeGoreFiltrele')}
              options={suzgecProjeleri}
              value={suzgecProjeleri.filter((s) => suzgec.projeler.includes(s.value))}
              onChange={(secilenler) =>
                suzgecDegistir(
                  'projeler',
                  secilenler.map((s) => s.value),
                )
              }
              placeholder={t('ortak.hepsi')}
            />
            <MultiSelectField
              id={`${tanim.anahtar}-suz-firma`}
              label={t('raporlar.firmayaGoreFiltrele')}
              options={suzgecFirmalari}
              value={suzgecFirmalari.filter((s) => suzgec.firmalar.includes(s.value))}
              onChange={(secilenler) =>
                suzgecDegistir(
                  'firmalar',
                  secilenler.map((s) => s.value),
                )
              }
              placeholder={t('ortak.hepsi')}
            />
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-slate-600 dark:text-slate-400">
              {rapor.isSuccess
                ? suzuldu
                  ? t('raporlar.suzulmusKayitSayisi', {
                      sayi: satirlar.length,
                      toplam: tumSatirlar.length,
                    })
                  : t('raporlar.kayitSayisi', { sayi: satirlar.length })
                : null}
            </p>
            {sayfalama('ust')}
          </div>
          <div className="mt-3">
            <DataTable
              kolonlar={kolonlar}
              satirlar={sayfaSatirlari}
              satirAnahtari={tanim.satirAnahtari}
              yukleniyor={rapor.isPending}
              ustKaydirma
              toplamSatirlari={toplamSatirlari}
            />
          </div>
          <div className="mt-3">{sayfalama('alt')}</div>
        </>
      )}
    </>
  )
}
