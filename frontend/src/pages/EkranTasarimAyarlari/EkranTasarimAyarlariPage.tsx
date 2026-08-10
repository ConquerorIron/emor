import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { OnayRoluSecimi } from './OnayRoluSecimi'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { EkranListesi } from './EkranListesi'
import { SatirTasarimi } from './SatirTasarimi'
import { ErrorState } from '@/components/ErrorState'
import {
  ekranTaslaginiGetir,
  ekranTaslaginiKaydet,
  ekranTasariminiYayinla,
} from '@/features/ekranTasarim/api'
import { AlanAyarPaneli } from './AlanAyarPaneli'
import {
  alanGuncelle,
  alanKaldir,
  alanKonumu,
  alanYerlestir,
  bolumBasligiDegistir,
  bolumGenisligiDegistir,
  kullanilmayanAlanlar,
} from '@/features/ekranTasarim/duzenIslemleri'
import { SURUKLE_TURU, TasarimTuvali } from './TasarimTuvali'
import type { EkranDuzeni, KatalogAlani } from '@/features/ekranTasarim/types'
import { TASARLANABILIR_EKRANLAR } from '@/features/ekranTasarim/ekranlar'

export function EkranTasarimAyarlariPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  // null = ekran listesi; bir ekran seçilince editör açılır
  const [ekran, setEkran] = useState<string | null>(null)
  const [duzen, setDuzen] = useState<EkranDuzeni | null>(null)
  const [seciliAlan, setSeciliAlan] = useState<string | null>(null)
  const [sekme, setSekme] = useState<'baslik' | 'satirlar'>('baslik')
  const [yayinOnayi, setYayinOnayi] = useState(false)

  const taslak = useQuery({
    queryKey: queryKeys.ekranTaslagi(ekran ?? ''),
    queryFn: () => ekranTaslaginiGetir(ekran ?? ''),
    enabled: ekran !== null,
  })

  const ekranAdi = TASARLANABILIR_EKRANLAR.find((e) => e.anahtar === ekran)?.etiketAnahtari

  // Sunucudan gelen taslak yerel düzenleme durumuna alınır
  useEffect(() => {
    if (taslak.data) {
      setDuzen(taslak.data.duzen)
    }
  }, [taslak.data])

  const katalogHaritasi = useMemo(
    () => new Map((taslak.data?.katalog ?? []).map((alan) => [alan.anahtar, alan])),
    [taslak.data],
  )

  const kullanilmayan: KatalogAlani[] = useMemo(
    () => (taslak.data && duzen ? kullanilmayanAlanlar(taslak.data.katalog, duzen) : []),
    [taslak.data, duzen],
  )

  const kaydet = useMutation({
    mutationFn: (yeni: EkranDuzeni) => ekranTaslaginiKaydet(ekran ?? '', yeni),
    onSuccess: (kaydedilen) => {
      // Sunucu düzeni temizleyip döner (kilitli kurallar uygulanmış hali)
      setDuzen(kaydedilen)
      toast.success(t('tasarim.taslakKaydedildi'))
    },
    onError: (hata: unknown) => toast.error(dogrulamaMesaji(hata) ?? t(apiErrorKey(hata))),
  })

  const yayinla = useMutation({
    mutationFn: () => ekranTasariminiYayinla(ekran ?? ''),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ekranTasarimi(ekran ?? '') })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ekranTaslagi(ekran ?? '') })
      toast.success(t('tasarim.yayinlandi'))
    },
    onError: (hata: unknown) => toast.error(dogrulamaMesaji(hata) ?? t(apiErrorKey(hata))),
    onSettled: () => setYayinOnayi(false),
  })

  const seciliKatalog = seciliAlan ? katalogHaritasi.get(seciliAlan) : undefined
  const seciliDuzenAlani =
    duzen && seciliAlan
      ? (() => {
          const konum = alanKonumu(duzen, seciliAlan)

          return konum ? duzen.bolumler[konum.bolum].alanlar[konum.indeks] : undefined
        })()
      : undefined

  // Hangi ekranın tasarlandığı seçilmeden editör açılmaz
  if (ekran === null) {
    return (
      <>
        <h2 className="text-2xl font-bold">{t('tasarim.baslik')}</h2>
        <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {t('tasarim.ekranSecAciklama')}
        </p>
        <EkranListesi sec={setEkran} />
      </>
    )
  }

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <button
            type="button"
            onClick={() => {
              setEkran(null)
              setDuzen(null)
              setSeciliAlan(null)
            }}
            className="cursor-pointer text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"
          >
            ‹ {t('tasarim.baslik')}
          </button>
          {/* Hangi ekranı düzenlediğimiz her an görünür olmalı */}
          <h2 className="text-2xl font-bold">{ekranAdi ? t(ekranAdi) : ekran}</h2>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {taslak.data?.yayinda_surum ? (
            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
              {t('tasarim.yayindaSurum', { surum: taslak.data.yayinda_surum })}
            </span>
          ) : (
            <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
              {t('tasarim.henuzYayinlanmadi')}
            </span>
          )}
          <Button
            type="button"
            variant="secondary"
            disabled={!duzen}
            yukleniyor={kaydet.isPending}
            onClick={() => duzen && kaydet.mutate(duzen)}
          >
            {t('tasarim.taslagiKaydet')}
          </Button>
          <Button type="button" disabled={!duzen} onClick={() => setYayinOnayi(true)}>
            {t('tasarim.yayinla')}
          </Button>
        </div>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{t('tasarim.aciklama')}</p>

      {taslak.isError ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(taslak.error))}
            tekrarDene={() => void taslak.refetch()}
          />
        </div>
      ) : null}

      {taslak.isPending ? (
        <p className="mt-4 text-sm text-slate-500 dark:text-slate-400">{t('ortak.yukleniyor')}</p>
      ) : null}

      {/* Başlık ve satır ızgarası ayrı tasarlanır */}
      {duzen && taslak.data ? (
        <div className="mt-4 flex gap-2 border-b border-slate-200 dark:border-slate-700">
          {(['baslik', 'satirlar'] as const).map((ad) => (
            <button
              key={ad}
              type="button"
              onClick={() => setSekme(ad)}
              className={`cursor-pointer px-4 py-2 text-sm font-semibold transition-colors ${
                sekme === ad
                  ? 'border-b-2 border-blue-600 text-blue-700 dark:text-blue-400'
                  : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
              }`}
            >
              {t(`tasarim.sekme.${ad}`)}
            </button>
          ))}
        </div>
      ) : null}

      {duzen && taslak.data && sekme === 'satirlar' ? (
        <SatirTasarimi
          katalog={taslak.data.satir_katalogu ?? []}
          satirlar={duzen.satirlar ?? []}
          degisti={(yeni) => setDuzen({ ...duzen, satirlar: yeni })}
        />
      ) : null}

      {duzen && taslak.data && sekme === 'baslik' ? (
        <div className="mt-4 flex flex-col gap-4 xl:flex-row">
          {/* Palet: tasarımda yeri olmayan alanlar */}
          <aside
            className="w-full shrink-0 rounded-xl border border-slate-200 bg-white p-4 xl:w-64 dark:border-slate-700 dark:bg-slate-900"
            onDragOver={(olay) => olay.preventDefault()}
            onDrop={(olay) => {
              olay.preventDefault()
              const ham =
                olay.dataTransfer.getData(SURUKLE_TURU) || olay.dataTransfer.getData('text/plain')
              if (!ham) {
                return
              }
              const veri = JSON.parse(ham) as { alan: string; kaynak: string }
              const katalog = katalogHaritasi.get(veri.alan)
              if (veri.kaynak === 'tuval' && katalog && !katalog.kaldirilamaz) {
                setDuzen(alanKaldir(duzen, veri.alan))
                setSeciliAlan(null)
              }
            }}
          >
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
              {t('tasarim.kullanilmayanAlanlar')}
            </h3>
            <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
              {t('tasarim.paletAciklama')}
            </p>
            <div className="mt-3 space-y-2">
              {kullanilmayan.length === 0 ? (
                <p className="text-xs text-slate-400 dark:text-slate-500">
                  {t('tasarim.tumAlanlarKullanimda')}
                </p>
              ) : null}
              {kullanilmayan.map((alan) => (
                <div
                  key={alan.anahtar}
                  draggable
                  onDragStart={(olay) => {
                    const veri = { alan: alan.anahtar, kaynak: 'palet' }
                    olay.dataTransfer.setData(SURUKLE_TURU, JSON.stringify(veri))
                    olay.dataTransfer.setData('text/plain', JSON.stringify(veri))
                    olay.dataTransfer.effectAllowed = 'copy'
                  }}
                  className="cursor-grab rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-600 transition-colors hover:border-blue-400 hover:text-blue-700 active:cursor-grabbing dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300"
                >
                  {t(alan.etiket_anahtari)}
                </div>
              ))}
            </div>

            <OnayRoluSecimi
              deger={duzen.onay_rol_id ?? null}
              degisti={(rolId) => setDuzen({ ...duzen, onay_rol_id: rolId })}
            />
          </aside>

          <div className="min-w-0 flex-1">
            <TasarimTuvali
              duzen={duzen}
              bolumTanimlari={taslak.data.bolumler}
              katalogHaritasi={katalogHaritasi}
              seciliAlan={seciliAlan}
              alanSecildi={setSeciliAlan}
              birakildi={(alanAnahtari, bolumAnahtari, hedefIndeks) => {
                const katalog = katalogHaritasi.get(alanAnahtari)
                setDuzen(
                  alanYerlestir(
                    duzen,
                    alanAnahtari,
                    bolumAnahtari,
                    hedefIndeks,
                    katalog?.varsayilan_genislik ?? 6,
                  ),
                )
                setSeciliAlan(alanAnahtari)
              }}
              bolumGenisligiDegisti={(bolumAnahtari, genislik) =>
                setDuzen(bolumGenisligiDegistir(duzen, bolumAnahtari, genislik))
              }
              bolumBasligiDegisti={(bolumAnahtari, baslik) =>
                setDuzen(bolumBasligiDegistir(duzen, bolumAnahtari, baslik))
              }
            />
          </div>

          <aside className="w-full shrink-0 xl:w-72">
            {seciliKatalog && seciliDuzenAlani ? (
              <AlanAyarPaneli
                katalog={seciliKatalog}
                duzen={seciliDuzenAlani}
                degisti={(degisiklik) =>
                  setDuzen(alanGuncelle(duzen, seciliKatalog.anahtar, degisiklik))
                }
                kaldir={() => {
                  setDuzen(alanKaldir(duzen, seciliKatalog.anahtar))
                  setSeciliAlan(null)
                }}
              />
            ) : (
              <div className="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-400 dark:border-slate-700 dark:text-slate-500">
                {t('tasarim.alanSecin')}
              </div>
            )}
          </aside>
        </div>
      ) : null}

      <ConfirmDialog
        acik={yayinOnayi}
        kapat={() => setYayinOnayi(false)}
        baslik={t('tasarim.yayinOnayBaslik')}
        mesaj={t('tasarim.yayinOnayMesaj')}
        onayEtiketi={t('tasarim.yayinla')}
        yukleniyor={yayinla.isPending}
        onayla={() => {
          // Yayın kaydedilmiş taslağı alır; ekrandaki değişiklikler önce kaydedilir
          if (duzen) {
            kaydet.mutateAsync(duzen).then(
              () => yayinla.mutate(),
              () => setYayinOnayi(false),
            )
          }
        }}
      />
    </>
  )
}
