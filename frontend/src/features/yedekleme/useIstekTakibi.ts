import { useQueries, useQueryClient } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { useAuth } from '@/hooks/useAuth'

import { istekDurumuGetir, type IstekDurumu, type IstekKabulu } from './yedekApi'

/** Çalıştırıcıya bırakılan işin türü — toast metni bundan seçilir */
export type IstekTuru =
  | 'yedek_al'
  | 'tara'
  | 'dogrula'
  | 'aciklama'
  | 'sabitle'
  | 'sabit_coz'
  | 'sil'
  | 'plan_calistir'
  | 'kopya_geri_yukle'
  | 'kopya_sil'

const ISTEK_TURLERI: readonly string[] = [
  'yedek_al',
  'tara',
  'dogrula',
  'aciklama',
  'sabitle',
  'sabit_coz',
  'sil',
  'plan_calistir',
  'kopya_geri_yukle',
  'kopya_sil',
]

type Ceviri = (anahtar: string, secenekler?: Record<string, unknown>) => string

/** Başarı bildirimi; sonuç ayrıntı taşıyorsa (doğrulama durumu, tarama sayıları, kopya adı) onu da söyler */
function basariMetni(tur: IstekTuru, sonuc: IstekDurumu, t: Ceviri): string {
  if (tur === 'dogrula' && sonuc.dogrulama_durumu) {
    return t('yedekleme.istek.ayrinti.dogrula', {
      durum: t(`yedekleme.dogrulama.${sonuc.dogrulama_durumu}`, {
        defaultValue: sonuc.dogrulama_durumu,
      }),
    })
  }
  if (tur === 'sil' && sonuc.yedek_durumu && sonuc.yedek_durumu !== 'silindi') {
    return t('yedekleme.istek.ayrinti.sil', {
      durum: t(`yedekleme.yedekDurumu.${sonuc.yedek_durumu}`, { defaultValue: sonuc.yedek_durumu }),
    })
  }
  if (tur === 'tara' && sonuc.tarama) {
    return t('yedekleme.istek.ayrinti.tara', {
      eklenen: sonuc.tarama['eklenen'] ?? 0,
      guvenilmeyen: sonuc.tarama['guvenilmeyen'] ?? 0,
    })
  }
  if (tur === 'kopya_geri_yukle' && sonuc.hedef_veritabani) {
    return t('yedekleme.istek.ayrinti.kopya_geri_yukle', { veritabani: sonuc.hedef_veritabani })
  }

  return t(`yedekleme.istek.tamam.${tur}`)
}

export interface TakipEdilenIstek {
  kimlik: string
  tur: IstekTuru
}

/** Yoklama aralığı (ms) */
const YOKLAMA_ARALIGI = 2000

/** Ağ hatası ya da 5xx'ten sonra yoklama aralığı (ms): geçici arıza beklenir, sunucu yorulmaz */
const HATA_SONRASI_ARALIK = 10000

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

type TakipSayfasi = 'yedekler' | 'planlar' | 'geri_yuklemeler'

/**
 * Depo kullanıcı başınadır (YF-09): aynı sekmede giren sonraki kullanıcı
 * öncekinin işlerini devralmaz, yetkisi olmayan isteği yoklamaz.
 */
const depoAnahtari = (kapsam: string, sayfa: TakipSayfasi) =>
  `erp.yedekleme.istekler.v2.${kapsam}.${sayfa}`

/**
 * Sayfadan ayrılıp dönülünce yoklama kaldığı yerden sürer (README §4.10).
 * Depo erişilemezse (gizli pencere, kota) takip yalnız bu çizimde yaşar.
 */
function oku(kapsam: string, sayfa: TakipSayfasi): TakipEdilenIstek[] {
  try {
    const ham = sessionStorage.getItem(depoAnahtari(kapsam, sayfa))
    const deger: unknown = ham === null ? [] : JSON.parse(ham)
    if (!Array.isArray(deger)) {
      return []
    }

    return deger.filter(
      (oge): oge is TakipEdilenIstek =>
        typeof oge === 'object' &&
        oge !== null &&
        typeof (oge as TakipEdilenIstek).kimlik === 'string' &&
        UUID.test((oge as TakipEdilenIstek).kimlik) &&
        ISTEK_TURLERI.includes((oge as TakipEdilenIstek).tur),
    )
  } catch {
    return []
  }
}

function yaz(kapsam: string, sayfa: TakipSayfasi, kayitlar: TakipEdilenIstek[]): void {
  try {
    if (kayitlar.length === 0) {
      sessionStorage.removeItem(depoAnahtari(kapsam, sayfa))
    } else {
      sessionStorage.setItem(depoAnahtari(kapsam, sayfa), JSON.stringify(kayitlar))
    }
  } catch {
    // depo kapalıysa takip yalnız bu çizimde yaşar
  }
}

const bittiMi = (durum: IstekDurumu | undefined) =>
  durum?.durum === 'tamam' || durum?.durum === 'basarisiz'

/**
 * Yanıtı olan 4xx (408 / 429 hariç) kalıcıdır (YF-09): istek dosyası yok
 * (404), yetki yok (403), modül kapalı (409 YEDEKLEME_KAPALI)… Girdi
 * değişmeden düzelmez; yeniden yoklamak yalnız hata biriktirir (D8). Ağ
 * hatası, 408, 429 ve 5xx geçicidir: yoklama seyrekleşerek sürer.
 */
function kaliciHata(hata: unknown): boolean {
  const durum = isAxiosError(hata) ? hata.response?.status : undefined

  return durum !== undefined && durum >= 400 && durum < 500 && durum !== 408 && durum !== 429
}

/**
 * Yedekleme işlerinin (yedek al, doğrula, sil, planı çalıştır…) durumunu
 * yoklar. İş sürerken `calisiyor` true'dur: ekran işlem düğmelerini kapatır.
 * Bitince sonucu bildirir ve yedekleme listelerini (['yedekleme']) tazeler.
 * Durum ucu kalıcı hata verirse (kaliciHata) iş bir kez bildirilir ve takipten
 * düşer.
 *
 * `sayfa`: takip listesinin deposu (ekran ve kullanıcı başına ayrı).
 */
export function useIstekTakibi(sayfa: TakipSayfasi) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const kapsam = `kullanici.${useAuth().user?.id ?? 'yok'}`
  const [kayitlar, setKayitlar] = useState<TakipEdilenIstek[]>(() => oku(kapsam, sayfa))
  // StrictMode'da etki iki kez çalışabilir: aynı istek iki kez bildirilmesin
  const bildirilenler = useRef(new Set<string>())

  const sonuclar = useQueries({
    queries: kayitlar.map((kayit) => ({
      queryKey: queryKeys.yedekleme.istek(kayit.kimlik),
      queryFn: () => istekDurumuGetir(kayit.kimlik),
      staleTime: 0,
      retry: false,
      // Bitmiş istekte ve kalıcı hatada durur; geçici hatada seyrekleşerek sürer
      refetchInterval: (sorgu: { state: { data?: IstekDurumu; error: unknown } }) =>
        bittiMi(sorgu.state.data) || kaliciHata(sorgu.state.error)
          ? false
          : sorgu.state.error
            ? HATA_SONRASI_ARALIK
            : YOKLAMA_ARALIGI,
      refetchIntervalInBackground: true,
    })),
  })

  useEffect(() => {
    yaz(kapsam, sayfa, kayitlar)
  }, [kapsam, sayfa, kayitlar])

  useEffect(() => {
    const bitenler: string[] = []

    kayitlar.forEach((kayit, sira) => {
      const sonuc = sonuclar[sira]
      if (sonuc === undefined || bildirilenler.current.has(kayit.kimlik)) {
        return
      }
      const islem = t(`yedekleme.istek.tur.${kayit.tur}`)

      if (sonuc.data?.durum === 'tamam') {
        toast.success(basariMetni(kayit.tur, sonuc.data, t))
      } else if (sonuc.data?.durum === 'basarisiz') {
        const kod = sonuc.data.kod
        toast.error(
          t('yedekleme.istek.basarisiz', {
            islem,
            neden: kod ? t(`hata.${kod}`, { defaultValue: kod }) : t('hata.BILINMEYEN'),
          }),
        )
      } else if (kaliciHata(sonuc.error)) {
        toast.error(t('yedekleme.istek.basarisiz', { islem, neden: t(apiErrorKey(sonuc.error)) }))
      } else {
        return
      }

      bildirilenler.current.add(kayit.kimlik)
      bitenler.push(kayit.kimlik)
    })

    if (bitenler.length === 0) {
      return
    }

    void queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.hepsi })
    setKayitlar((onceki) => onceki.filter((kayit) => !bitenler.includes(kayit.kimlik)))
  }, [kayitlar, sonuclar, queryClient, t])

  const baslat = (kabul: IstekKabulu, tur: IstekTuru) =>
    setKayitlar((onceki) => [...onceki, { kimlik: kabul.istek_kimligi, tur }])

  return {
    /** Süren işler ve son bilinen durumları (yanıt gelmeden 'kuyrukta') */
    calisanlar: kayitlar.map((kayit, sira) => ({
      ...kayit,
      durum: sonuclar[sira]?.data?.durum === 'calisiyor' ? 'calisiyor' : 'kuyrukta',
    })) as (TakipEdilenIstek & { durum: 'kuyrukta' | 'calisiyor' })[],
    calisiyor: kayitlar.length > 0,
    baslat,
  }
}
