import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { YapistirmaTablosu } from '@/components/YapistirmaTablosu'
import { bosSatirlar, sekmeliMetin, type YapistirmaKolonu } from '@/utils/yapistirma'

import { faturaListeleriniTazele } from './faturaOnbellegi'
import {
  type BelgeTuru,
  iceAktar,
  iceAktarimKolonlari,
  iceAktarimKontrol,
  type IceAktarimGirdisi,
  type IceAktarimOnizlemesi,
  type StatikKaynak,
} from './kaynakApi'

const HATA_KUTUSU =
  'rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300'

/** Kontrol sonucu: dosya hatası, eksik kolonlar, gruplu hatalar ya da sayılar */
function OnizlemeSonucu({ sonuc }: { sonuc: IceAktarimOnizlemesi }) {
  const { t } = useTranslation()
  const alanAdi = (alan: string) => t(`efatura.iceAktarim.alan.${alan}`, { defaultValue: alan })

  if (sonuc.hata !== null) {
    return (
      <p role="alert" className={HATA_KUTUSU}>
        {t(`efatura.iceAktarim.dosyaHatasi.${sonuc.hata}`)}
      </p>
    )
  }

  if (sonuc.eksik_kolonlar.length > 0) {
    return (
      <p role="alert" className={HATA_KUTUSU}>
        {t('efatura.iceAktarim.eksikKolonlar', {
          kolonlar: sonuc.eksik_kolonlar.map(alanAdi).join(', '),
        })}
      </p>
    )
  }

  if (sonuc.hatalar.length > 0) {
    return (
      <div className="space-y-2">
        <p role="alert" className={HATA_KUTUSU}>
          {t('efatura.iceAktarim.hataliDosya')}
        </p>
        <div className="max-h-[45vh] overflow-y-auto">
          <DataTable
            kolonlar={[
              {
                anahtar: 'alan',
                baslik: t('efatura.iceAktarim.alanBaslik'),
                render: (h) => alanAdi(h.alan),
              },
              {
                anahtar: 'deger',
                baslik: t('efatura.iceAktarim.degerBaslik'),
                render: (h) => <strong>{h.deger === '' ? '—' : h.deger}</strong>,
              },
              {
                anahtar: 'kod',
                baslik: t('efatura.iceAktarim.nedenBaslik'),
                render: (h) => t(`efatura.iceAktarim.hataKodu.${h.kod}`, { defaultValue: h.kod }),
              },
              {
                anahtar: 'adet',
                baslik: t('efatura.iceAktarim.adetBaslik'),
                hizala: 'sag',
                render: (h) => h.adet,
              },
              {
                anahtar: 'satirlar',
                baslik: t('efatura.iceAktarim.satirlarBaslik'),
                render: (h) => h.satirlar.join(', ') + (h.adet > h.satirlar.length ? ' …' : ''),
              },
            ]}
            satirlar={sonuc.hatalar}
            satirAnahtari={(h) => `${h.alan}|${h.kod}|${h.deger}`}
          />
        </div>
      </div>
    )
  }

  const { ozet } = sonuc

  return (
    <div className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
      <p className="font-semibold">
        {sonuc.belge_turu === null
          ? t('efatura.iceAktarim.hatasiz', { sayi: ozet.okunan })
          : t('efatura.iceAktarim.hatasizTurlu', {
              sayi: ozet.okunan,
              tur: t(`efatura.belgeTuru.${sonuc.belge_turu}`),
            })}
      </p>
      <p className="mt-1">
        {t('efatura.iceAktarim.ozet', {
          yeni: ozet.yeni,
          guncellenecek: ozet.guncellenecek,
          degismeyen: ozet.degismeyen,
          red: ozet.red,
        })}
      </p>
    </div>
  )
}

/** Hatasız sonuç: kaydedilebilir */
function kaydedilebilir(sonuc: IceAktarimOnizlemesi | null): boolean {
  return (
    sonuc !== null &&
    sonuc.hata === null &&
    sonuc.eksik_kolonlar.length === 0 &&
    sonuc.hatalar.length === 0 &&
    sonuc.ozet.okunan > 0
  )
}

/** Sunucunun 422 + önizleme yanıtı (dosya kontrolden sonra değiştiyse) */
function hataliOnizleme(error: unknown): IceAktarimOnizlemesi | null {
  if (isAxiosError(error) && error.response?.status === 422) {
    const govde = error.response.data as { kod?: string; data?: IceAktarimOnizlemesi } | undefined

    return govde?.kod === 'ICE_AKTARIM_HATALI' && govde.data ? govde.data : null
  }

  return null
}

/** Sekme / seçim düğmesi (Kesinti içe aktarım penceresiyle aynı görünüm) */
function SecimDugmesi({
  secili,
  onClick,
  children,
  rol,
}: {
  secili: boolean
  onClick: () => void
  children: ReactNode
  rol: 'tab' | 'radio'
}) {
  return (
    <button
      type="button"
      role={rol}
      aria-selected={rol === 'tab' ? secili : undefined}
      aria-checked={rol === 'radio' ? secili : undefined}
      onClick={onClick}
      className={`cursor-pointer rounded-lg px-3 py-1.5 text-sm font-semibold ${
        secili
          ? 'bg-violet-600 text-white'
          : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
      }`}
    >
      {children}
    </button>
  )
}

/**
 * Excel dosyası ya da Excel'den kopyala-yapıştır (Kesinti'deki gibi) →
 * "Kontrol Et" → hatasızsa "Kaydet" (tek parti). Yapıştırma tablosunun
 * kolonları sunucunun kolon tanımından gelir; başlıkla kopyalanırsa kolonlar
 * adlarından eşlenir. Kontrolün satır numaraları tablonunkilerle aynıdır:
 * hatalı satırın nedeni satır numarasının üzerinde görünür.
 */
function AktarimIcerigi({ onEk, kapat }: { onEk: StatikKaynak; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [kaynak, setKaynak] = useState<'dosya' | 'metin'>('dosya')
  const [dosya, setDosya] = useState<File | null>(null)
  // Yapıştırma tablosunun hücreleri; kolonlar gelene kadar boş tablo
  const [hucreler, setHucreler] = useState<string[][] | null>(null)
  // İki türlü kaynakta (e-Arşiv / e-SMM) yapıştırılan listenin türü
  const [belgeTuru, setBelgeTuru] = useState<BelgeTuru | null>(null)
  // Girdi değişince önceki kontrol geçersizdir: Kaydet yeniden kontrol ister
  const [onizleme, setOnizleme] = useState<IceAktarimOnizlemesi | null>(null)
  // Bekleme durumu bir sonraki çizimde gelir: art arda iki tıklama tek istek (F5)
  const suruyor = useRef(false)
  const alanAdi = (alan: string) => t(`efatura.iceAktarim.alan.${alan}`, { defaultValue: alan })

  const kolonSorgusu = useQuery({
    queryKey: queryKeys.kaynak.iceAktarimKolonlari(onEk),
    queryFn: () => iceAktarimKolonlari(onEk),
    enabled: kaynak === 'metin',
    staleTime: Infinity,
  })
  const turler = kolonSorgusu.data ?? []
  const secilenTur = turler.find((tur) => tur.belge_turu === belgeTuru) ?? turler.at(0)
  const kolonlar: YapistirmaKolonu[] = (secilenTur?.kolonlar ?? []).map((kolon) => ({
    ...kolon,
    etiket: kolon.basliklar[0] ?? kolon.alan,
  }))
  const tablo = hucreler ?? bosSatirlar(kolonlar.length)
  const metin = kolonlar.length > 0 ? sekmeliMetin(tablo) : ''

  const girdi: IceAktarimGirdisi | null =
    kaynak === 'dosya'
      ? dosya
        ? { dosya }
        : null
      : metin.trim() !== '' && secilenTur !== undefined
        ? { metin, belge_turu: secilenTur.belge_turu }
        : null

  // Kontrol yalnız örnek satır numaralarını döner (gruptan en çok 10): o satırlar işaretlenir
  const hataliSatirlar = new Map<number, string>()
  for (const hata of onizleme?.hatalar ?? []) {
    const neden = t(`efatura.iceAktarim.hataKodu.${hata.kod}`, { defaultValue: hata.kod })
    const metni =
      t('efatura.iceAktarim.satirHatasi', { alan: alanAdi(hata.alan), neden }) +
      (hata.deger === '' ? '' : ` (${hata.deger})`)
    for (const satir of hata.satirlar) {
      const onceki = hataliSatirlar.get(satir)
      hataliSatirlar.set(satir, onceki === undefined ? metni : `${onceki}\n${metni}`)
    }
  }

  const kontrol = useMutation({
    mutationFn: (secilen: IceAktarimGirdisi) => iceAktarimKontrol(onEk, secilen),
    onSuccess: setOnizleme,
    onError: (error) => toast.error(t(apiErrorKey(error))),
  })

  const kaydet = useMutation({
    mutationFn: (secilen: IceAktarimGirdisi) => iceAktar(onEk, secilen),
    onSuccess: (parti) => {
      faturaListeleriniTazele(queryClient)
      void queryClient.invalidateQueries({ queryKey: queryKeys.kaynak.yuklemeler(onEk) })
      toast.success(
        t('efatura.iceAktarim.kaydedildi', {
          yeni: parti.yeni,
          guncellenen: parti.guncellenen,
          degismeyen: parti.degismeyen,
        }),
      )
      kapat()
    },
    onError: (error) => {
      const sonuc = hataliOnizleme(error)
      if (sonuc !== null) {
        setOnizleme(sonuc)
      } else {
        toast.error(t(apiErrorKey(error)))
      }
    },
    onSettled: () => {
      suruyor.current = false
    },
  })

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600 dark:text-slate-400">
        {t(`efatura.iceAktarim.aciklama.${onEk}`)}
      </p>
      <div role="tablist" className="flex gap-2">
        {(['dosya', 'metin'] as const).map((deger) => (
          <SecimDugmesi
            key={deger}
            rol="tab"
            secili={kaynak === deger}
            onClick={() => {
              setKaynak(deger)
              setOnizleme(null)
            }}
          >
            {t(`efatura.iceAktarim.kaynak.${deger}`)}
          </SecimDugmesi>
        ))}
      </div>

      {kaynak === 'dosya' ? (
        <div>
          <label
            htmlFor={`${onEk}-ice-aktar-dosya`}
            className="text-sm font-semibold text-slate-700 dark:text-slate-300"
          >
            {t('efatura.iceAktarim.dosya')}
          </label>
          <input
            id={`${onEk}-ice-aktar-dosya`}
            type="file"
            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            disabled={kaydet.isPending}
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
        <div className="space-y-3">
          {turler.length > 1 ? (
            <div className="flex flex-wrap items-center gap-2">
              <span
                id={`${onEk}-belge-turu`}
                className="text-sm font-semibold text-slate-700 dark:text-slate-300"
              >
                {t('efatura.iceAktarim.belgeTuruSec')}
              </span>
              <div role="radiogroup" aria-labelledby={`${onEk}-belge-turu`} className="flex gap-2">
                {turler.map((tur) => (
                  <SecimDugmesi
                    key={tur.belge_turu ?? ''}
                    rol="radio"
                    secili={tur === secilenTur}
                    onClick={() => {
                      // Kolonlar türe göre değişir: tablo ve kontrol sıfırlanır
                      setBelgeTuru(tur.belge_turu)
                      setHucreler(null)
                      setOnizleme(null)
                    }}
                  >
                    {t(`efatura.belgeTuru.${tur.belge_turu ?? 'fatura'}`)}
                  </SecimDugmesi>
                ))}
              </div>
            </div>
          ) : null}
          <YapistirmaTablosu
            kolonlar={kolonlar}
            satirlar={tablo}
            degistir={(yeni) => {
              setHucreler(yeni)
              setOnizleme(null)
            }}
            hataliSatirlar={hataliSatirlar}
          />
        </div>
      )}

      {onizleme !== null ? <OnizlemeSonucu sonuc={onizleme} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={kapat} disabled={kaydet.isPending}>
          {t('ortak.iptal')}
        </Button>
        <Button
          variant="secondary"
          disabled={girdi === null || kaydet.isPending}
          yukleniyor={kontrol.isPending}
          onClick={() => {
            if (girdi !== null) {
              kontrol.mutate(girdi)
            }
          }}
        >
          {t('efatura.iceAktarim.kontrolEt')}
        </Button>
        {/* Kaydet yalnız hatasız "Kontrol Et"ten sonra açılır */}
        <Button
          disabled={girdi === null || !kaydedilebilir(onizleme) || kontrol.isPending}
          yukleniyor={kaydet.isPending}
          onClick={() => {
            if (girdi === null || suruyor.current) {
              return
            }
            suruyor.current = true
            kaydet.mutate(girdi)
          }}
        >
          {t('ortak.kaydet')}
        </Button>
      </div>
    </div>
  )
}

/** Excel listesini içe aktarma düğmesi ve penceresi (EK-06) */
export function IceAktarimDugmesi({ onEk }: { onEk: StatikKaynak }) {
  const { t } = useTranslation()
  const [acik, setAcik] = useState(false)

  return (
    <>
      <Button variant="mor" onClick={() => setAcik(true)}>
        {t('efatura.iceAktarim.dugme')}
      </Button>
      <Modal
        acik={acik}
        kapat={() => setAcik(false)}
        baslik={t('efatura.iceAktarim.baslik')}
        boyut="devasa"
      >
        {acik ? <AktarimIcerigi onEk={onEk} kapat={() => setAcik(false)} /> : null}
      </Modal>
    </>
  )
}
