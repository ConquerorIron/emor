import { Controller, type FieldValues, type Path, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import type { ReactNode } from 'react'

import { Input } from '@/components/Input'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { TarihInput } from '@/components/TarihInput'
import { zorunluEtiket, type DuzenAlani, type KatalogAlani } from '@/features/ekranTasarim/types'

import { AlimYeriSecimi } from './AlimYeriSecimi'
import { DepoSecimi } from './DepoSecimi'
import { EvetHayirAlani } from './EvetHayirAlani'
import { FirmamizAdresiSecimi } from './FirmamizAdresiSecimi'
import { ILGI_CINSLERI, VARSAYILAN_ILGI_CINSI, type IlgiCinsi } from './ilgiCinsleri'
import { IlgiliSecimi } from './IlgiliSecimi'
import { OncelikSecimi } from './OncelikSecimi'
import { PersonelSecimi } from './PersonelSecimi'
import { SayiAlani } from './SayiAlani'
import { TeslimatBicimiSecimi } from './TeslimatBicimiSecimi'
import { TeslimatSekliSecimi } from './TeslimatSekliSecimi'
import { sureyiTariheCevir, tarihiSureyeCevir, type TeslimatSuresiBirimi } from './teslimatSuresi'
import { TeslimatSuresiSecimi } from './TeslimatSuresiSecimi'

/**
 * Tasarımdan gelen kuralların ürettiği ORTAK props. Motor hazırlar; giriş
 * tanımları bunu olduğu gibi (`{...ortak}`) bileşene geçirir.
 *
 * Etiket (zorunluluk yıldızı dahil), çevrilmiş hata mesajı ve salt okunur
 * kilidi TEK yerde hesaplanır — yeni giriş tipinde tekrar bağlanmaz.
 */
export interface OrtakGirisProps {
  id: string
  label: string
  hata?: string
  disabled: boolean
}

/** Ekrandan bağımsız form erişimi (hangi ekran olursa olsun aynı sözleşme). */
type GenelForm = UseFormReturn<FieldValues>

export interface GirisBaglami {
  ortak: OrtakGirisProps
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: GenelForm
  /** Ana alanın (veri_anahtarlari[0]) anlık değeri */
  deger: string
  /** Ana alanı günceller */
  degistir: (deger: string) => void
  /** Alanın yazdığı diğer anahtarları güncellemek için (veri_anahtarlari[1], …) */
  yanDegistir: (indeks: number, deger: string) => void
  /** Katalogda tanımlı bağlı alanın değerini okur (ör. ilgi cinsi) */
  bagliOku: () => string
  /** Katalogda tanımlı bağlı alanı yazar (ör. cins değişince ilgiliyi sıfırla) */
  bagliYaz: (deger: string) => void
}

/** Bir giriş tipinin YALNIZ kendine özgü kısmı. */
interface GirisTanimi {
  /**
   * Etiketi alanın kendisi üretiyorsa (ör. ilgi cinsine göre "Proje"/"İş Paketi").
   * Zorunluluk yıldızını yine MOTOR ekler — burada ham metin döndürülür.
   */
  etiket?: (baglam: { bagliOku: () => string; t: (anahtar: string) => string }) => string
  ciz: (baglam: GirisBaglami) => ReactNode
}

/**
 * Giriş tipi → bileşen. ERP'nin domain tipleriyle örtüşür:
 * ACIKLAMAxxx → sinirliMetin · TARIH → tarih · MIKTAR/ORAN → sayi/oran ·
 * BOOL → evetHayir · KAYIT_ID → kayıt seçiciler · MADDE → tablo maddesi ·
 * TUR → sabit listeler. Yeni ekranlar (Sipariş, Fatura, Teklif) aynı tipleri
 * kullanacağı için buraya yeni bileşen eklemeden çalışırlar.
 */
const GIRIS_TANIMLARI: Record<string, GirisTanimi> = {
  metin: {
    ciz: ({ ortak, duzen, deger, degistir }) =>
      duzen.gorunum === 'textarea' ? (
        <div>
          <label
            htmlFor={ortak.id}
            className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
          >
            {ortak.label}
          </label>
          <textarea
            id={ortak.id}
            rows={duzen.satir ?? 2}
            disabled={ortak.disabled}
            value={deger}
            onChange={(olay) => degistir(olay.target.value)}
            className="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900 dark:focus:ring-blue-900"
          />
        </div>
      ) : (
        <Input
          {...ortak}
          autoComplete="off"
          value={deger}
          onChange={(olay) => degistir(olay.target.value)}
        />
      ),
  },

  // ERP ACIKLAMA200 / 512 / 1024 / 3072 — sınır katalogdan gelir
  sinirliMetin: {
    ciz: ({ ortak, katalog, duzen, deger, degistir }) => (
      <SinirliMetinInput
        {...ortak}
        value={deger}
        onChange={degistir}
        limit={katalog.metin_limiti || 200}
        rows={duzen.satir ?? 2}
      />
    ),
  },

  // ERP MIKTAR
  sayi: {
    ciz: ({ ortak, deger, degistir }) => <SayiAlani {...ortak} value={deger} onChange={degistir} />,
  },

  // ERP ORAN (yüzde)
  oran: {
    ciz: ({ ortak, deger, degistir }) => (
      <SayiAlani {...ortak} value={deger} onChange={degistir} yuzde ondalik={2} />
    ),
  },

  // ERP BOOL
  evetHayir: {
    ciz: ({ ortak, deger, degistir }) => (
      <EvetHayirAlani {...ortak} value={deger} onChange={degistir} />
    ),
  },

  // ERP TARIH
  tarih: {
    ciz: ({ ortak, deger, degistir }) => (
      <TarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  /** Anahtarla açılan tarih (ERP Opsiyon tarihi deseni) */
  opsiyonelTarih: {
    ciz: ({ ortak, deger, degistir }) => (
      <OpsiyonelTarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  // ERP KAYIT_ID — kayıt seçiciler
  personel: {
    ciz: ({ ortak, deger, degistir, yanDegistir }) => (
      <PersonelSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          // Görünen ad ikinci veri anahtarına yazılır
          yanDegistir(1, secim?.unvan ?? '')
        }}
      />
    ),
  },

  depo: {
    ciz: ({ ortak, deger, degistir }) => (
      <DepoSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  teslimatAdresi: {
    ciz: ({ ortak, deger, degistir, yanDegistir }) => (
      <FirmamizAdresiSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          // Adres metni ikinci veri anahtarına yazılır (@TESLIMAT_ADRESI)
          yanDegistir(1, secim?.adres ?? '')
        }}
        tekSeceneginiSec
      />
    ),
  },

  // ERP MADDE — tablo maddesi türevleri
  oncelik: {
    ciz: ({ ortak, deger, degistir }) => (
      <OncelikSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  teslimatSekli: {
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatSekliSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => degistir(secim?.kayitId ?? '')}
      />
    ),
  },

  // ERP TUR — sabit listeler
  teslimatBicimi: {
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatBicimiSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
  },

  alimYeri: {
    ciz: ({ ortak, deger, degistir }) => (
      <AlimYeriSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
  },

  ilgiCinsi: {
    ciz: ({ ortak, deger, degistir, bagliYaz }) => (
      <IlgiCinsiSecimi
        ortak={ortak}
        deger={deger}
        degisti={(yeni) => {
          degistir(yeni)
          // Cins değişince bağlı alan (katalogda tanımlı) sıfırlanır
          bagliYaz('')
        }}
      />
    ),
  },

  /** Etiketi ve arama kaynağı bağlı alandaki cinse göre değişir */
  ilgili: {
    etiket: ({ bagliOku, t }) =>
      t(`satinalma.ilgiliEtiket.${(bagliOku() || VARSAYILAN_ILGI_CINSI) as IlgiCinsi}`),
    ciz: ({ ortak, deger, degistir, bagliOku }) => {
      const cins = (bagliOku() || VARSAYILAN_ILGI_CINSI) as IlgiCinsi

      return <IlgiliSecimi {...ortak} cins={cins} deger={deger} degisti={degistir} />
    },
  },

  // ERP SURE — miktar + birim; tarih yüzü aynı veriyi türetir
  teslimatSuresi: {
    ciz: ({ ortak, deger, degistir, yanDegistir, form, katalog }) => (
      <TeslimatSuresiSecimi
        {...ortak}
        sure={deger}
        birim={(form.watch(katalog.veri_anahtarlari[1]) ?? '3') as TeslimatSuresiBirimi}
        degisti={(sure, birim) => {
          degistir(sure)
          yanDegistir(1, birim)
        }}
      />
    ),
  },

  teslimatSuresiTarih: {
    ciz: ({ ortak, deger, degistir, yanDegistir, form, katalog, bagliOku }) => {
      const temel = bagliOku()
      const birim = (form.watch(katalog.veri_anahtarlari[1]) ?? '3') as TeslimatSuresiBirimi

      return (
        <TarihInput
          {...ortak}
          value={sureyiTariheCevir(temel, deger, birim)}
          onChange={(iso) => {
            const cevrim = tarihiSureyeCevir(temel, iso, birim)
            degistir(cevrim.sure)
            yanDegistir(1, cevrim.birim)
          }}
        />
      )
    },
  },
}

/** İlgi cinsi sabit listesi — seçenekler çeviriden üretildiği için ayrı bileşen. */
function IlgiCinsiSecimi({
  ortak,
  deger,
  degisti,
}: {
  ortak: OrtakGirisProps
  deger: string
  degisti: (deger: string) => void
}) {
  const { t } = useTranslation()
  const secenekler: SecenekOgesi[] = ILGI_CINSLERI.map((cins) => ({
    value: cins,
    label: t(`satinalma.ilgiCinsi.${cins}`),
  }))

  return (
    <SelectField
      {...ortak}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => degisti(secim?.value ?? VARSAYILAN_ILGI_CINSI)}
      isClearable={false}
    />
  )
}

export function girisTipiTanimliMi(girisTipi: string): boolean {
  return girisTipi in GIRIS_TANIMLARI
}

/**
 * Tasarımdaki bir alanı çizen TEK giriş noktası — HER ekran için ortaktır.
 * Alanın hangi form anahtarlarını yazdığı katalogdan (`veri_anahtarlari`),
 * başka bir alana bağımlılığı da katalogdan (`bagli_veri_anahtari`) gelir;
 * bu yüzden ekran adı geçen sabit kod yoktur.
 */
export function AlanGirisi<T extends FieldValues>({
  katalog,
  duzen,
  form,
}: {
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: UseFormReturn<T>
}) {
  const { t } = useTranslation()
  const tanim = GIRIS_TANIMLARI[katalog.giris_tipi]
  const genelForm = form as unknown as GenelForm

  const bagliOku = () =>
    katalog.bagli_veri_anahtari ? (genelForm.watch(katalog.bagli_veri_anahtari) ?? '') : ''

  if (!tanim) {
    return null
  }

  const anahtar = katalog.veri_anahtarlari[0]
  const hamEtiket = tanim.etiket?.({ bagliOku, t }) ?? t(katalog.etiket_anahtari)

  return (
    <Controller
      name={anahtar as Path<T>}
      control={form.control}
      render={({ field, fieldState }) =>
        tanim.ciz({
          ortak: {
            id: `alan-${katalog.anahtar}`,
            label: zorunluEtiket(hamEtiket, duzen),
            // Doğrulama mesajları i18n anahtarı taşır
            hata: fieldState.error?.message ? t(fieldState.error.message) : undefined,
            disabled: duzen.salt_okunur === true,
          },
          katalog,
          duzen,
          form: genelForm,
          deger: typeof field.value === 'string' ? field.value : '',
          degistir: field.onChange,
          yanDegistir: (indeks, deger) => {
            const yan = katalog.veri_anahtarlari[indeks]
            if (yan) {
              genelForm.setValue(yan, deger)
            }
          },
          bagliOku,
          bagliYaz: (deger) => {
            if (katalog.bagli_veri_anahtari) {
              genelForm.setValue(katalog.bagli_veri_anahtari, deger)
            }
          },
        }) as React.ReactElement
      }
    />
  )
}
