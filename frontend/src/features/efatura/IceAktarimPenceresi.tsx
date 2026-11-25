import { useMutation, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable } from '@/components/DataTable'
import { Modal } from '@/components/Modal'

import { faturaListeleriniTazele } from './faturaOnbellegi'
import {
  iceAktar,
  iceAktarimKontrol,
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

function AktarimIcerigi({ onEk, kapat }: { onEk: StatikKaynak; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [dosya, setDosya] = useState<File | null>(null)
  const [onizleme, setOnizleme] = useState<IceAktarimOnizlemesi | null>(null)
  // Bekleme durumu bir sonraki çizimde gelir: art arda iki tıklama tek istek (F5)
  const suruyor = useRef(false)

  const kontrol = useMutation({
    mutationFn: (secilen: File) => iceAktarimKontrol(onEk, secilen),
    onSuccess: setOnizleme,
    onError: (error) => toast.error(t(apiErrorKey(error))),
  })

  const kaydet = useMutation({
    mutationFn: (secilen: File) => iceAktar(onEk, secilen),
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
            // Dosya değişince önceki kontrol geçersiz: Kaydet yeniden kontrol ister
            setOnizleme(null)
          }}
          className="mt-1 block w-full text-sm file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-semibold dark:file:bg-slate-800"
        />
      </div>

      {onizleme !== null ? <OnizlemeSonucu sonuc={onizleme} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" onClick={kapat} disabled={kaydet.isPending}>
          {t('ortak.iptal')}
        </Button>
        <Button
          variant="secondary"
          disabled={dosya === null || kaydet.isPending}
          yukleniyor={kontrol.isPending}
          onClick={() => {
            if (dosya !== null) {
              kontrol.mutate(dosya)
            }
          }}
        >
          {t('efatura.iceAktarim.kontrolEt')}
        </Button>
        {/* Kaydet yalnız hatasız "Kontrol Et"ten sonra açılır */}
        <Button
          disabled={dosya === null || !kaydedilebilir(onizleme) || kontrol.isPending}
          yukleniyor={kaydet.isPending}
          onClick={() => {
            if (dosya === null || suruyor.current) {
              return
            }
            suruyor.current = true
            kaydet.mutate(dosya)
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
        boyut="genis"
      >
        {acik ? <AktarimIcerigi onEk={onEk} kapat={() => setAcik(false)} /> : null}
      </Modal>
    </>
  )
}
