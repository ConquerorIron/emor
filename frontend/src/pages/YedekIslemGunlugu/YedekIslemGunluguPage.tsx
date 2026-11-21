import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Pagination } from '@/components/Pagination'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { kullanicilariGetir } from '@/features/ayarlar/yetkiApi'
import { istanbulZamani, sureGoster } from '@/features/yedekleme/bicim'
import { Rozet } from '@/features/yedekleme/Rozet'
import { useIzin } from '@/hooks/useIzin'
import { bugunIso, gunEkle } from '@/utils/tarih'

import { islemKayitlariniGetir, type IslemKaydi, type IslemKaydiFiltresi } from './islemGunluguApi'

/** Açılışta son 30 gün — sabit tarih yok, aralık bugüne göre kayar (F2) */
const VARSAYILAN_GUN = 30

/**
 * Bilinen işlem adları (süzgeç seçenekleri). Liste kapalı değildir:
 * çalıştırıcı yenilerini ekler; bilinmeyen ad çevirisiz, olduğu gibi gösterilir.
 */
const BILINEN_ISLEMLER = [
  'istek',
  'yedek_al',
  'kopya',
  'kopya_sil',
  'saklama_bag',
  'saklama_sil',
  'klasor_tara',
  'sinama_geri_yukleme',
  'geri_yukle_kopya',
  'eski_yedekleri_al',
  'kurtarma_paketi',
  'sifre_coz',
  'hedef_ekle',
  'hedef_guncelle',
  'hedef_sil',
  'hedef_sina',
  'hedef_sunucu_anahtari_sifirla',
  'hedef_google_baglan',
  'hedef_google_kes',
  'plan_ekle',
  'plan_guncelle',
  'plan_sil',
  'ayar_guncelle',
  'sifreleme_anahtari_yukle',
  'sifreleme_anahtari_kaldir',
] as const

type Ceviri = (anahtar: string, secenekler?: Record<string, unknown>) => string

/** İşlemin etiketi; `istek` kaydında istenen işin türü `ayrinti.tur`'dadır */
function islemEtiketi(kayit: Pick<IslemKaydi, 'islem' | 'ayrinti'>, t: Ceviri): string {
  const etiket = t(`yedekleme.gunluk.islem.${kayit.islem}`, { defaultValue: kayit.islem })
  const tur = kayit.ayrinti['tur']
  if (kayit.islem !== 'istek' || typeof tur !== 'string') {
    return etiket
  }

  return `${etiket}: ${t(`yedekleme.istek.tur.${tur}`, { defaultValue: tur })}`
}

function ayrintiMetni(deger: unknown): string {
  if (deger === null || deger === undefined) {
    return '—'
  }

  return typeof deger === 'object' ? JSON.stringify(deger) : String(deger)
}

function varsayilanFiltre(): IslemKaydiFiltresi {
  const bugun = bugunIso()

  return {
    baslangic: gunEkle(bugun, -(VARSAYILAN_GUN - 1)),
    bitis: bugun,
    kullanici_id: '',
    islem: '',
    sonuc: '',
  }
}

/** Yedekleme denetim kaydı (BY-018): salt okunur — ekrandan silinemez / değiştirilemez */
export function YedekIslemGunluguPage() {
  const { t } = useTranslation()
  const kullanicilariGorebilir = useIzin('kullanicilar.goruntule')
  const [filtre, setFiltre] = useState<IslemKaydiFiltresi>(varsayilanFiltre)
  const [sayfa, setSayfa] = useState(1)

  const araligiHatali =
    filtre.baslangic !== '' && filtre.bitis !== '' && filtre.baslangic > filtre.bitis

  const kayitlar = useQuery({
    queryKey: queryKeys.yedekleme.islemKayitlari({ filtre, sayfa }),
    queryFn: () => islemKayitlariniGetir(filtre, sayfa),
    enabled: !araligiHatali,
    placeholderData: keepPreviousData,
  })
  const yenileniyor = kayitlar.isFetching || kayitlar.isPaused || kayitlar.isPlaceholderData

  // Kişi süzgeci yalnız kullanıcı listesini görebilenlere (liste ERP'den okunur)
  const kullanicilar = useQuery({
    queryKey: queryKeys.ayarlar.kullanicilar,
    queryFn: kullanicilariGetir,
    enabled: kullanicilariGorebilir,
  })

  const sonSayfa = kayitlar.isPlaceholderData ? undefined : kayitlar.data?.meta.last_page
  useEffect(() => {
    if (sonSayfa !== undefined && sayfa > Math.max(sonSayfa, 1)) {
      setSayfa(Math.max(sonSayfa, 1))
    }
  }, [sonSayfa, sayfa])

  const filtreDegistir = (degisiklik: Partial<IslemKaydiFiltresi>) => {
    setFiltre((onceki) => ({ ...onceki, ...degisiklik }))
    setSayfa(1)
  }

  const islemSecenekleri: SecenekOgesi[] = BILINEN_ISLEMLER.map((islem) => ({
    value: islem,
    label: t(`yedekleme.gunluk.islem.${islem}`),
  }))
  const sonucSecenekleri: SecenekOgesi[] = [
    { value: 'basarili', label: t('yedekleme.gunluk.sonuc.basarili') },
    { value: 'basarisiz', label: t('yedekleme.gunluk.sonuc.basarisiz') },
  ]
  const kullaniciSecenekleri: SecenekOgesi[] = (kullanicilar.data?.kullanicilar ?? []).flatMap(
    (kullanici) =>
      kullanici.id === null ? [] : [{ value: String(kullanici.id), label: kullanici.ad }],
  )

  const kolonlar: DataTableKolonu<IslemKaydi>[] = [
    {
      anahtar: 'zaman',
      baslik: t('yedekleme.gunluk.zaman'),
      render: (kayit) => (
        <span className="whitespace-nowrap tabular-nums">
          {istanbulZamani(kayit.zaman, { saniye: true })}
        </span>
      ),
    },
    {
      anahtar: 'kullanici',
      baslik: t('yedekleme.gunluk.kullanici'),
      render: (kayit) =>
        kayit.kullanici ?? (
          <span className="text-slate-500 dark:text-slate-400">{t('yedekleme.gunluk.sistem')}</span>
        ),
    },
    {
      anahtar: 'islem',
      baslik: t('yedekleme.gunluk.islemBaslik'),
      render: (kayit) => <span className="whitespace-nowrap">{islemEtiketi(kayit, t)}</span>,
    },
    {
      anahtar: 'sonuc',
      baslik: t('yedekleme.gunluk.sonucBaslik'),
      render: (kayit) => (
        <Rozet renk={kayit.sonuc === 'basarili' ? 'yesil' : 'kirmizi'}>
          {t(`yedekleme.gunluk.sonuc.${kayit.sonuc}`, { defaultValue: kayit.sonuc })}
        </Rozet>
      ),
    },
    {
      anahtar: 'sure',
      baslik: t('yedekleme.gunluk.sure'),
      hizala: 'sag',
      render: (kayit) => (
        <span className="whitespace-nowrap tabular-nums">{sureGoster(kayit.sure_ms)}</span>
      ),
    },
    {
      anahtar: 'yedek',
      baslik: t('yedekleme.gunluk.yedek'),
      render: (kayit) =>
        kayit.yedek === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <span className="font-mono text-xs break-all">{kayit.yedek.dosya_adi}</span>
        ),
    },
    {
      anahtar: 'ayrinti',
      baslik: t('yedekleme.gunluk.ayrinti'),
      render: (kayit) => {
        const satirlar = Object.entries(kayit.ayrinti)

        return (
          <div className="max-w-md space-y-0.5 text-xs">
            {satirlar.length === 0 ? <span className="text-slate-400">—</span> : null}
            {satirlar.map(([anahtar, deger]) => (
              <p key={anahtar} className="break-all">
                <span className="font-semibold">{anahtar}</span>: {ayrintiMetni(deger)}
              </p>
            ))}
            {kayit.hata_mesaji ? (
              <p className="break-all text-red-600 dark:text-red-400">{kayit.hata_mesaji}</p>
            ) : null}
          </div>
        )
      },
    },
  ]

  return (
    <>
      <h2 className="text-2xl font-bold">{t('yedekleme.gunluk.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.gunluk.aciklamaMetni')}
      </p>

      <div className="mt-4 grid grid-cols-1 items-start gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <TarihInput
          id="gunluk-baslangic"
          label={t('yedekleme.gunluk.baslangic')}
          value={filtre.baslangic}
          onChange={(baslangic) => filtreDegistir({ baslangic })}
          hata={araligiHatali ? t('yedekleme.gunluk.aralikHatali') : undefined}
        />
        <TarihInput
          id="gunluk-bitis"
          label={t('yedekleme.gunluk.bitis')}
          value={filtre.bitis}
          onChange={(bitis) => filtreDegistir({ bitis })}
        />
        {kullanicilariGorebilir ? (
          <SelectField
            id="gunluk-kullanici"
            label={t('yedekleme.gunluk.kullanici')}
            options={kullaniciSecenekleri}
            value={kullaniciSecenekleri.find((s) => s.value === filtre.kullanici_id) ?? null}
            onChange={(s) => filtreDegistir({ kullanici_id: s?.value ?? '' })}
            yukleniyor={kullanicilar.isPending}
            isClearable
            placeholder={t('ortak.hepsi')}
          />
        ) : null}
        <SelectField
          id="gunluk-islem"
          label={t('yedekleme.gunluk.islemBaslik')}
          options={islemSecenekleri}
          value={islemSecenekleri.find((s) => s.value === filtre.islem) ?? null}
          onChange={(s) => filtreDegistir({ islem: s?.value ?? '' })}
          isClearable
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="gunluk-sonuc"
          label={t('yedekleme.gunluk.sonucBaslik')}
          options={sonucSecenekleri}
          value={sonucSecenekleri.find((s) => s.value === filtre.sonuc) ?? null}
          onChange={(s) =>
            filtreDegistir({ sonuc: (s?.value ?? '') as IslemKaydiFiltresi['sonuc'] })
          }
          isClearable
          isSearchable={false}
          placeholder={t('ortak.hepsi')}
        />
      </div>

      {/* TarihInput hatayı yalnız çerçeveyle gösterir; neden burada yazılır */}
      {araligiHatali ? (
        <p role="alert" className="mt-2 text-sm text-red-600 dark:text-red-400">
          {t('yedekleme.gunluk.aralikHatali')}
        </p>
      ) : null}

      <div className={`mt-4 ${kayitlar.isPlaceholderData ? 'opacity-60' : ''}`}>
        {kayitlar.isError ? (
          <ErrorState
            mesaj={t(apiErrorKey(kayitlar.error))}
            tekrarDene={() => void kayitlar.refetch()}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={kayitlar.data?.data ?? []}
            satirAnahtari={(kayit) => kayit.id}
            yukleniyor={kayitlar.isPending && !araligiHatali}
          />
        )}
      </div>

      {kayitlar.data && kayitlar.data.meta.last_page > 1 ? (
        <div className="mt-3">
          <Pagination
            sayfa={sayfa}
            toplamSayfa={kayitlar.data.meta.last_page}
            sayfaDegistir={setSayfa}
            disabled={yenileniyor}
          />
        </div>
      ) : null}
    </>
  )
}
