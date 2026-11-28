import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { LISTE_AZAMI_GUN, listeAraligiHatasi, tutarGoster } from '@/features/efatura/bicim'
import {
  type FaturaSinifi,
  GELEN_SAGLAYICILARI,
  type Saglayici,
} from '@/features/efatura/efaturaApi'
import {
  type FirmaSuzgeci,
  panoFirmalariExcelIndir,
  panoFirmalariGetir,
  panoGetir,
  type PanoSayilari,
} from '@/features/efatura/panoApi'
import { useIzin } from '@/hooks/useIzin'
import { bugunIso } from '@/utils/tarih'

/**
 * Kayıt durumu dilimleri: doğrulanmış kategorik paletin ilk üç dilimi
 * (dataviz skill references/palette.md; açık ve koyu tema ayrı adımlar).
 * Sıra sabittir — renk dilimin kimliğini izler, büyüklüğünü değil.
 */
const DILIMLER = [
  { anahtar: 'islenen', sinif: 'islendi', renk: 'bg-[#2a78d6] dark:bg-[#3987e5]' },
  { anahtar: 'red', sinif: 'red', renk: 'bg-[#eb6834] dark:bg-[#d95926]' },
  { anahtar: 'islenecek', sinif: 'islenecek', renk: 'bg-[#1baf7a] dark:bg-[#199e70]' },
] as const

type Dilim = (typeof DILIMLER)[number]['anahtar']

const SAYI = new Intl.NumberFormat('tr-TR')

function yilBasi(bugun: string): string {
  return `${bugun.slice(0, 4)}-01-01`
}

/** Aralıktaki aylar ('2026-01' …) */
function aralikAylari(baslangic: string, bitis: string): string[] {
  const aylar: string[] = []
  let yil = Number(baslangic.slice(0, 4))
  let ay = Number(baslangic.slice(5, 7))
  const sonYil = Number(bitis.slice(0, 4))
  const sonAy = Number(bitis.slice(5, 7))
  while (yil < sonYil || (yil === sonYil && ay <= sonAy)) {
    aylar.push(`${yil}-${String(ay).padStart(2, '0')}`)
    ay += 1
    if (ay > 12) {
      ay = 1
      yil += 1
    }
  }

  return aylar
}

/** Ayın aralıkla kesişen ilk ve son günü (panodaki sayı ile listenin toplamı aynı kalsın) */
function ayAraligi(
  ay: string,
  baslangic: string,
  bitis: string,
): { baslangic: string; bitis: string } {
  const [yil, sira] = ay.split('-').map(Number)
  const sonGun = new Date(yil, sira, 0).getDate()
  const ilk = `${ay}-01`
  const son = `${ay}-${String(sonGun).padStart(2, '0')}`

  return { baslangic: ilk < baslangic ? baslangic : ilk, bitis: son > bitis ? bitis : son }
}

/** Sayı → aynı süzgeçle açılan Tüm Faturalar (izin yoksa yalnız sayı) */
function SayiBaglantisi({
  deger,
  parametreler,
  baglanabilir,
  vurgulu = false,
}: {
  deger: number
  parametreler: Record<string, string>
  baglanabilir: boolean
  vurgulu?: boolean
}) {
  const metin = (
    <span className={`tabular-nums ${vurgulu && deger > 0 ? 'font-bold' : ''}`}>
      {SAYI.format(deger)}
    </span>
  )

  if (!baglanabilir || deger === 0) {
    return metin
  }

  return (
    <Link
      to={`/efatura/tum?${new URLSearchParams(parametreler).toString()}`}
      className="rounded text-blue-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-blue-300"
    >
      {metin}
    </Link>
  )
}

/** Yazısız ince bölümlü çubuk: rakamlar yanındaki tabloda (tek gösterge tablonun üstünde) */
function DurumCubugu({ sayilar, etiket }: { sayilar: PanoSayilari; etiket: string }) {
  const toplam = sayilar.islenen + sayilar.red + sayilar.islenecek

  return (
    <div
      role="img"
      aria-label={etiket}
      className="flex h-2 w-32 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
    >
      {toplam === 0
        ? null
        : DILIMLER.map((dilim) =>
            sayilar[dilim.anahtar] === 0 ? null : (
              <span
                key={dilim.anahtar}
                className={`h-full ${dilim.renk} border-r-2 border-white last:border-r-0 dark:border-slate-900`}
                style={{ width: `${(sayilar[dilim.anahtar] / toplam) * 100}%` }}
              />
            ),
          )}
    </div>
  )
}

function Gosterge({ t }: { t: (anahtar: string) => string }) {
  return (
    <ul className="flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-400">
      {DILIMLER.map((dilim) => (
        <li key={dilim.anahtar} className="inline-flex items-center gap-1.5">
          <span aria-hidden="true" className={`size-2.5 rounded-sm ${dilim.renk}`} />
          {t(`efatura.pano.sayi.${dilim.anahtar}`)}
        </li>
      ))}
    </ul>
  )
}

function SayiKutusu({ baslik, deger, alt }: { baslik: string; deger: number; alt?: ReactNode }) {
  return (
    <div className="min-w-36 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
      <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">{baslik}</p>
      <p className="text-2xl font-bold tabular-nums">{SAYI.format(deger)}</p>
      {alt}
    </div>
  )
}

/**
 * İşlenecek faturaların aylara göre adedi: tek seri, tek eksen; değerler
 * çubukların üstünde, üzerine gelince ay ve adet.
 */
function AylikGrafik({
  aylar,
  degerler,
  t,
}: {
  aylar: string[]
  degerler: number[]
  t: (a: string, o?: Record<string, unknown>) => string
}) {
  const enBuyuk = Math.max(1, ...degerler)
  const genislik = 640
  const yukseklik = 180
  const ust = 18
  const alt = 22
  const aralik = genislik / Math.max(1, aylar.length)
  const cubuk = Math.min(36, aralik * 0.6)

  return (
    <svg
      viewBox={`0 0 ${genislik} ${yukseklik}`}
      className="h-auto w-full max-w-3xl"
      role="img"
      aria-label={t('efatura.pano.aylikGrafik')}
    >
      <line
        x1={0}
        x2={genislik}
        y1={yukseklik - alt}
        y2={yukseklik - alt}
        className="stroke-slate-300 dark:stroke-slate-700"
      />
      {aylar.map((ay, i) => {
        const deger = degerler[i]
        const boy = ((yukseklik - ust - alt) * deger) / enBuyuk
        const x = i * aralik + (aralik - cubuk) / 2
        const y = yukseklik - alt - boy

        return (
          <g key={ay}>
            <title>{t('efatura.pano.aylikIpucu', { ay, adet: deger })}</title>
            {/* Geniş vuruş alanı: ince çubuğun üzerine gelmek kolay olsun */}
            <rect x={i * aralik} y={0} width={aralik} height={yukseklik - alt} fill="transparent" />
            {deger > 0 ? (
              <rect
                x={x}
                y={y}
                width={cubuk}
                height={boy}
                rx={4}
                className="fill-[#2a78d6] dark:fill-[#3987e5]"
              />
            ) : null}
            {deger > 0 ? (
              <text
                x={x + cubuk / 2}
                y={y - 4}
                textAnchor="middle"
                className="fill-slate-700 text-[11px] dark:fill-slate-300"
              >
                {SAYI.format(deger)}
              </text>
            ) : null}
            <text
              x={i * aralik + aralik / 2}
              y={yukseklik - 6}
              textAnchor="middle"
              className="fill-slate-500 text-[11px] dark:fill-slate-400"
            >
              {t(`efatura.pano.ay.${ay.slice(5, 7)}`)}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

/**
 * e-Fatura panosu (EK-09; kullanıcı isteği m, 2026-11-25): portal bazında
 * gelen / işlenen / iptal / işlenecek fatura sayıları, işlenecek faturaların
 * aylara göre dağılımı ve gönderen firma dökümü. Her sayı Tüm Faturalar'ı
 * aynı süzgeçle açar (sayı ile listenin toplamı aynıdır).
 */
export function EFaturaPanosuPage() {
  const { t } = useTranslation()
  const listeIzni = useIzin('tum_faturalar.goruntule')
  const [aralik, setAralik] = useState(() => ({
    baslangic: yilBasi(bugunIso()),
    bitis: bugunIso(),
  }))
  const [firmaSuzgeci, setFirmaSuzgeci] = useState<{
    entegrator: '' | Saglayici
    sinif: FaturaSinifi
  }>({
    entegrator: '',
    sinif: 'islenecek',
  })
  const [excelSuruyor, setExcelSuruyor] = useState(false)
  const tarihHatasi = listeAraligiHatasi(aralik.baslangic, aralik.bitis)

  const pano = useQuery({
    queryKey: queryKeys.efaturaPano.ozet(aralik),
    queryFn: () => panoGetir(aralik),
    enabled: tarihHatasi === null,
    placeholderData: keepPreviousData,
  })
  const firmaParametreleri: FirmaSuzgeci = { ...aralik, ...firmaSuzgeci }
  const firmalar = useQuery({
    queryKey: queryKeys.efaturaPano.firmalar({ ...firmaParametreleri }),
    queryFn: () => panoFirmalariGetir(firmaParametreleri),
    enabled: tarihHatasi === null,
    placeholderData: keepPreviousData,
  })

  // Yeni aralığın sonucu beklenirken (yenileniyor ya da bağlantı yok) ekrandaki
  // sayılar eski aralığındır; bağlantılar ise yeni aralıkla açılırdı: bağlantısız (F-8)
  const sayilarBaglanir = listeIzni && !pano.isPlaceholderData
  // Görülen ile inen aynı olsun: yenilenirken, beklemedeyken ve eski veri gösterilirken kapalı (F1)
  const firmalarYenileniyor = firmalar.isFetching || firmalar.isPaused || firmalar.isPlaceholderData

  const baglanti = (ekler: Record<string, string>, araliktan = aralik) => ({
    baslangic: araliktan.baslangic,
    bitis: araliktan.bitis,
    ...ekler,
  })
  const aylar = aralikAylari(aralik.baslangic, aralik.bitis)
  const veri = pano.data
  const portalAdi = (s: Saglayici) => t(`efatura.pano.portal.${s}`)

  const kaynakSecenekleri: SecenekOgesi[] = GELEN_SAGLAYICILARI.map((s) => ({
    value: s,
    label: portalAdi(s),
  }))
  const sinifSecenekleri: SecenekOgesi[] = (['islenecek', 'islendi', 'red'] as const).map((s) => ({
    value: s,
    label: t(`efatura.pano.sinif.${s}`),
  }))

  const excelAl = async () => {
    setExcelSuruyor(true)
    try {
      await panoFirmalariExcelIndir(firmaParametreleri)
    } catch (error) {
      toast.error(t(apiErrorKey(error)))
    } finally {
      setExcelSuruyor(false)
    }
  }

  return (
    <>
      <h2 className="text-2xl font-bold">{t('efatura.pano.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('efatura.pano.aciklama')}
      </p>

      <div className="mt-4 flex flex-wrap items-start gap-3">
        <div className="w-full sm:w-36">
          <TarihInput
            id="pano-baslangic"
            label={t('efatura.baslangic')}
            value={aralik.baslangic}
            onChange={(deger) => setAralik((onceki) => ({ ...onceki, baslangic: deger }))}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-36">
          <TarihInput
            id="pano-bitis"
            label={t('efatura.bitis')}
            value={aralik.bitis}
            onChange={(deger) => setAralik((onceki) => ({ ...onceki, bitis: deger }))}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
      </div>

      {tarihHatasi !== null ? null : pano.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(pano.error))} tekrarDene={() => void pano.refetch()} />
        </div>
      ) : veri === undefined ? (
        <p className="mt-6 text-sm text-slate-500">{t('ortak.yukleniyor')}</p>
      ) : (
        <div
          className={`mt-6 space-y-8 transition-opacity ${pano.isPlaceholderData ? 'opacity-50' : ''}`}
        >
          <div className="flex flex-wrap gap-3">
            <SayiKutusu baslik={t('efatura.pano.sayi.gelen')} deger={veri.toplam.gelen} />
            <SayiKutusu baslik={t('efatura.pano.sayi.islenen')} deger={veri.toplam.islenen} />
            <SayiKutusu baslik={t('efatura.pano.sayi.red')} deger={veri.toplam.red} />
            <SayiKutusu
              baslik={t('efatura.pano.sayi.islenecek')}
              deger={veri.toplam.islenecek}
              alt={
                veri.toplam.red_islenmis > 0 ? (
                  <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                    {t('efatura.pano.redIslenmisUyari', { adet: veri.toplam.red_islenmis })}
                  </p>
                ) : null
              }
            />
          </div>

          <section aria-labelledby="pano-dagilim">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h3 id="pano-dagilim" className="text-lg font-semibold">
                {t('efatura.pano.dagilimBaslik')}
              </h3>
              <Gosterge t={t} />
            </div>
            <div className="mt-3">
              <DataTable
                kolonlar={[
                  {
                    anahtar: 'portal',
                    baslik: t('efatura.pano.portalBaslik'),
                    render: (p) => <span className="font-semibold">{portalAdi(p.saglayici)}</span>,
                  },
                  ...(['gelen', 'islenen', 'red', 'islenecek'] as const).map((sayi) => ({
                    anahtar: sayi,
                    baslik: t(`efatura.pano.sayi.${sayi}`),
                    hizala: 'sag' as const,
                    render: (p: (typeof veri.portallar)[number]) => (
                      <SayiBaglantisi
                        deger={p[sayi]}
                        baglanabilir={sayilarBaglanir}
                        vurgulu={sayi === 'islenecek'}
                        parametreler={baglanti({
                          entegrator: p.saglayici,
                          ...(sayi === 'gelen'
                            ? {}
                            : {
                                sinif:
                                  DILIMLER.find((d) => d.anahtar === (sayi as Dilim))?.sinif ?? '',
                              }),
                        })}
                      />
                    ),
                  })),
                  {
                    anahtar: 'cubuk',
                    baslik: '',
                    render: (p) => (
                      <DurumCubugu
                        sayilar={p}
                        etiket={t('efatura.pano.cubukEtiketi', {
                          portal: portalAdi(p.saglayici),
                          islenen: p.islenen,
                          red: p.red,
                          islenecek: p.islenecek,
                        })}
                      />
                    ),
                  },
                  {
                    anahtar: 'diger',
                    baslik: t('efatura.pano.digerBaslik'),
                    render: (p) => (
                      <span className="text-xs text-slate-500 dark:text-slate-400">
                        {p.gizlenen > 0 ? (
                          <SayiBaglantisi
                            deger={p.gizlenen}
                            baglanabilir={sayilarBaglanir}
                            parametreler={baglanti({
                              entegrator: p.saglayici,
                              gizlenenler: 'yalniz',
                            })}
                          />
                        ) : null}
                        {p.gizlenen > 0 ? ` ${t('efatura.pano.gizlenen')}` : ''}
                        {p.red_islenmis > 0 ? (
                          <span className="ml-2 text-amber-700 dark:text-amber-300">
                            <SayiBaglantisi
                              deger={p.red_islenmis}
                              baglanabilir={sayilarBaglanir}
                              parametreler={baglanti({
                                entegrator: p.saglayici,
                                sinif: 'red_islenmis',
                              })}
                            />{' '}
                            {t('efatura.pano.redIslenmis')}
                          </span>
                        ) : null}
                      </span>
                    ),
                  },
                ]}
                satirlar={veri.portallar}
                satirAnahtari={(p) => p.saglayici}
                toplamSatirlari={[
                  {
                    anahtar: 'toplam',
                    etiket: t('efatura.pano.toplam'),
                    hucreler: {
                      gelen: (
                        <strong className="tabular-nums">{SAYI.format(veri.toplam.gelen)}</strong>
                      ),
                      islenen: (
                        <strong className="tabular-nums">{SAYI.format(veri.toplam.islenen)}</strong>
                      ),
                      red: <strong className="tabular-nums">{SAYI.format(veri.toplam.red)}</strong>,
                      islenecek: (
                        <strong className="tabular-nums">
                          {SAYI.format(veri.toplam.islenecek)}
                        </strong>
                      ),
                    },
                  },
                ]}
              />
            </div>
          </section>

          <section aria-labelledby="pano-aylik">
            <h3 id="pano-aylik" className="text-lg font-semibold">
              {t('efatura.pano.aylikBaslik')}
            </h3>
            <div className="mt-3">
              <AylikGrafik
                aylar={aylar}
                degerler={aylar.map((ay) =>
                  veri.aylik.reduce((toplam, p) => toplam + (p.aylar[ay] ?? 0), 0),
                )}
                t={t}
              />
            </div>
            <div className="mt-3 overflow-x-auto">
              <DataTable
                kolonlar={[
                  {
                    anahtar: 'portal',
                    baslik: t('efatura.pano.portalBaslik'),
                    render: (p) => <span className="font-semibold">{portalAdi(p.saglayici)}</span>,
                  },
                  ...aylar.map((ay) => ({
                    anahtar: ay,
                    baslik: t(`efatura.pano.ay.${ay.slice(5, 7)}`),
                    hizala: 'sag' as const,
                    render: (p: (typeof veri.aylik)[number]) => (
                      <SayiBaglantisi
                        deger={p.aylar[ay] ?? 0}
                        baglanabilir={sayilarBaglanir}
                        parametreler={baglanti(
                          { entegrator: p.saglayici, sinif: 'islenecek' },
                          ayAraligi(ay, aralik.baslangic, aralik.bitis),
                        )}
                      />
                    ),
                  })),
                ]}
                satirlar={veri.aylik}
                satirAnahtari={(p) => p.saglayici}
                yaziBoyutu="kucuk"
              />
            </div>
          </section>

          <section aria-labelledby="pano-firmalar">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <h3 id="pano-firmalar" className="text-lg font-semibold">
                {t('efatura.pano.firmaBaslik')}
              </h3>
              <div className="flex flex-wrap items-end gap-3">
                <div className="w-44">
                  <SelectField
                    id="pano-firma-portal"
                    label={t('efatura.pano.portalBaslik')}
                    options={kaynakSecenekleri}
                    value={
                      kaynakSecenekleri.find((s) => s.value === firmaSuzgeci.entegrator) ?? null
                    }
                    onChange={(secim) =>
                      setFirmaSuzgeci((onceki) => ({
                        ...onceki,
                        entegrator: (secim?.value ?? '') as '' | Saglayici,
                      }))
                    }
                    placeholder={t('efatura.hepsi')}
                    isClearable
                    isSearchable={false}
                  />
                </div>
                <div className="w-40">
                  <SelectField
                    id="pano-firma-sinif"
                    label={t('efatura.pano.sinifBaslik')}
                    options={sinifSecenekleri}
                    value={sinifSecenekleri.find((s) => s.value === firmaSuzgeci.sinif) ?? null}
                    onChange={(secim) =>
                      setFirmaSuzgeci((onceki) => ({
                        ...onceki,
                        sinif: (secim?.value ?? 'islenecek') as FaturaSinifi,
                      }))
                    }
                    isSearchable={false}
                  />
                </div>
                <Button
                  variant="yesil"
                  onClick={() => void excelAl()}
                  yukleniyor={excelSuruyor}
                  disabled={firmalarYenileniyor || (firmalar.data?.length ?? 0) === 0}
                >
                  {t('efatura.excel')}
                </Button>
              </div>
            </div>
            <div className={`mt-3 ${firmalar.isPlaceholderData ? 'opacity-50' : ''}`}>
              {firmalar.isError ? (
                <ErrorState
                  mesaj={t(apiErrorKey(firmalar.error))}
                  tekrarDene={() => void firmalar.refetch()}
                />
              ) : (
                <DataTable
                  kolonlar={[
                    { anahtar: 'vkn', baslik: t('efatura.kolon.vkn'), render: (f) => f.vkn ?? '—' },
                    {
                      anahtar: 'unvan',
                      baslik: t('efatura.kolon.unvan'),
                      render: (f) => f.unvan ?? '—',
                    },
                    {
                      anahtar: 'para',
                      baslik: t('efatura.kolon.paraBirimi'),
                      render: (f) => f.para_birimi,
                    },
                    {
                      anahtar: 'adet',
                      baslik: t('efatura.pano.adet'),
                      hizala: 'sag',
                      render: (f) => SAYI.format(f.adet),
                    },
                    {
                      anahtar: 'tutar',
                      baslik: t('efatura.kolon.tutar'),
                      hizala: 'sag',
                      render: (f) => <span className="tabular-nums">{tutarGoster(f.tutar)}</span>,
                    },
                  ]}
                  satirlar={firmalar.data ?? []}
                  satirAnahtari={(f) => `${f.vkn ?? ''}|${f.para_birimi}`}
                  yukleniyor={firmalar.isPending}
                  yaziBoyutu="kucuk"
                />
              )}
            </div>
          </section>
        </div>
      )}
    </>
  )
}
