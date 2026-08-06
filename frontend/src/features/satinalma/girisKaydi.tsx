import { Controller, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import type { ReactNode } from 'react'

import { Input } from '@/components/Input'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { TarihInput } from '@/components/TarihInput'
import { FirmamizAdresiSecimi } from '@/features/adres/FirmamizAdresiSecimi'
import { DepoSecimi } from '@/features/depo/DepoSecimi'
import { zorunluEtiket, type DuzenAlani, type KatalogAlani } from '@/features/ekranTasarim/types'
import { PersonelSecimi } from '@/features/personel/PersonelSecimi'
import { AlimYeriSecimi } from '@/features/satinalma/AlimYeriSecimi'
import { IlgiliSecimi } from '@/features/satinalma/IlgiliSecimi'
import {
  ARAMALI_ILGI_CINSLERI,
  ILGI_CINSLERI,
  VARSAYILAN_ILGI_CINSI,
  type IlgiCinsi,
} from '@/features/satinalma/ilgiCinsleri'
import type { TalepGirdisi } from '@/features/satinalma/schemas/talepSchema'
import { OncelikSecimi } from '@/features/tabloMaddesi/OncelikSecimi'
import { TeslimatSekliSecimi } from '@/features/tabloMaddesi/TeslimatSekliSecimi'
import { TeslimatBicimiSecimi } from '@/features/teslimat/TeslimatBicimiSecimi'
import {
  sureyiTariheCevir,
  tarihiSureyeCevir,
  type TeslimatSuresiBirimi,
} from '@/features/teslimat/teslimatSuresi'
import { TeslimatSuresiSecimi } from '@/features/teslimat/TeslimatSuresiSecimi'

type FormAnahtari = keyof TalepGirdisi

/**
 * Tasarımdan gelen kuralların ürettiği ORTAK props. Motor hazırlar; giriş
 * tanımları bunu olduğu gibi (`{...ortak}`) bileşene geçirir.
 *
 * Bu tip tasarımın çekirdeğidir: etiket (zorunluluk yıldızı dahil), çevrilmiş
 * hata mesajı ve salt okunur kilidi TEK yerde hesaplanır. Yeni bir giriş tipi
 * eklerken bunları tekrar bağlamak gerekmez — dolayısıyla unutulamaz.
 */
export interface OrtakGirisProps {
  id: string
  label: string
  hata?: string
  disabled: boolean
}

/** Giriş tanımının çizim sırasında eline geçen her şey. */
export interface GirisBaglami {
  ortak: OrtakGirisProps
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: UseFormReturn<TalepGirdisi>
  /** Controller'a bağlı ana alanın anlık değeri */
  deger: string
  /** Ana alanı günceller (yan alanlar için form.setValue kullanılır) */
  degistir: (deger: string) => void
}

/**
 * Bir giriş tipinin YALNIZ kendine özgü kısmı. Ortak kurallar motorda.
 */
interface GirisTanimi {
  /** Controller'ın bağlanacağı form anahtarı; varsayılan katalogun ilk veri anahtarı */
  anahtar?: (katalog: KatalogAlani) => FormAnahtari
  /**
   * Etiketi alanın kendisi üretiyorsa (ör. ilgi cinsine göre "Proje"/"İş Paketi").
   * Zorunluluk yıldızını yine MOTOR ekler — burada ham metin döndürülür.
   */
  etiket?: (form: UseFormReturn<TalepGirdisi>, t: (anahtar: string) => string) => string
  ciz: (baglam: GirisBaglami) => ReactNode
}

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

  personel: {
    anahtar: () => 'personel_id',
    ciz: ({ ortak, deger, degistir, form }) => (
      <PersonelSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          form.setValue('personel_adi', secim?.unvan ?? '')
        }}
      />
    ),
  },

  tarih: {
    anahtar: () => 'tarih',
    ciz: ({ ortak, deger, degistir }) => (
      <TarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  opsiyonelTarih: {
    anahtar: () => 'termin',
    ciz: ({ ortak, deger, degistir }) => (
      <OpsiyonelTarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  oncelik: {
    anahtar: () => 'oncelik_id',
    ciz: ({ ortak, deger, degistir }) => (
      <OncelikSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  ilgiCinsi: {
    anahtar: () => 'ilgi_cinsi',
    ciz: ({ ortak, deger, degistir, form }) => (
      <IlgiCinsiSecimi
        ortak={ortak}
        deger={deger}
        degisti={(yeni) => {
          degistir(yeni)
          // Cins değişince önceki ilgili seçimi anlamsızlaşır
          form.setValue('ilgili_id', '')
        }}
      />
    ),
  },

  ilgili: {
    anahtar: () => 'ilgili_id',
    // Etiket seçili cinse göre değişir; yıldızı motor ekler
    etiket: (form, t) =>
      t(
        `satinalma.ilgiliEtiket.${(form.watch('ilgi_cinsi') || VARSAYILAN_ILGI_CINSI) as IlgiCinsi}`,
      ),
    ciz: ({ ortak, deger, degistir, form }) => {
      const cins = (form.watch('ilgi_cinsi') || VARSAYILAN_ILGI_CINSI) as IlgiCinsi

      return ARAMALI_ILGI_CINSLERI.includes(cins) ? (
        <IlgiliSecimi {...ortak} cins={cins} deger={deger} degisti={degistir} />
      ) : (
        <Input
          {...ortak}
          autoComplete="off"
          value={deger}
          onChange={(olay) => degistir(olay.target.value)}
        />
      )
    },
  },

  depo: {
    anahtar: () => 'depomuz_id',
    ciz: ({ ortak, deger, degistir }) => (
      <DepoSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  teslimatAdresi: {
    anahtar: () => 'teslimat_adresi_id',
    ciz: ({ ortak, deger, degistir, form }) => (
      <FirmamizAdresiSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          form.setValue('teslimat_adresi', secim?.adres ?? '')
        }}
        tekSeceneginiSec
      />
    ),
  },

  teslimatBicimi: {
    anahtar: () => 'teslimat_bicimi',
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatBicimiSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
  },

  teslimatSekli: {
    anahtar: () => 'teslimat_sekli_id',
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatSekliSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => degistir(secim?.kayitId ?? '')}
      />
    ),
  },

  // Süre alanının tarih yüzü: aynı veriyi (süre+birim) tarihten türetir
  teslimatSuresiTarih: {
    anahtar: () => 'teslimat_suresi',
    ciz: ({ ortak, form }) => {
      const temel = form.watch('tarih')
      const sure = form.watch('teslimat_suresi')
      const birim = form.watch('teslimat_suresi_birimi') as TeslimatSuresiBirimi

      return (
        <TarihInput
          {...ortak}
          value={sureyiTariheCevir(temel, sure, birim)}
          onChange={(iso) => {
            const cevrim = tarihiSureyeCevir(temel, iso, birim)
            form.setValue('teslimat_suresi', cevrim.sure)
            form.setValue('teslimat_suresi_birimi', cevrim.birim)
          }}
        />
      )
    },
  },

  teslimatSuresi: {
    anahtar: () => 'teslimat_suresi',
    ciz: ({ ortak, form }) => (
      <TeslimatSuresiSecimi
        {...ortak}
        sure={form.watch('teslimat_suresi')}
        birim={form.watch('teslimat_suresi_birimi') as TeslimatSuresiBirimi}
        degisti={(sure, birim) => {
          form.setValue('teslimat_suresi', sure)
          form.setValue('teslimat_suresi_birimi', birim)
        }}
      />
    ),
  },

  alimYeri: {
    anahtar: () => 'alim_yeri',
    ciz: ({ ortak, deger, degistir }) => (
      <AlimYeriSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
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
 * Tasarımdaki bir alanı çizen TEK giriş noktası. Tasarım kurallarını burada
 * uygular ve giriş tipine özgü kısmı kayıt defterinden çağırır:
 *
 *   • etiket + zorunluluk yıldızı
 *   • doğrulama mesajının çevirisi
 *   • salt okunur kilidi
 *
 * Yeni bir alan tipi eklemek = GIRIS_TANIMLARI'na bir kayıt. Ortak kuralları
 * tekrar bağlamak gerekmediği için atlanamaz.
 */
export function AlanGirisi({
  katalog,
  duzen,
  form,
}: {
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: UseFormReturn<TalepGirdisi>
}) {
  const { t } = useTranslation()
  const tanim = GIRIS_TANIMLARI[katalog.giris_tipi]

  if (!tanim) {
    return null
  }

  const anahtar = tanim.anahtar?.(katalog) ?? (katalog.veri_anahtarlari[0] as FormAnahtari)
  const hamEtiket = tanim.etiket?.(form, t) ?? t(katalog.etiket_anahtari)

  return (
    <Controller
      name={anahtar}
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
          form,
          deger: typeof field.value === 'string' ? field.value : '',
          degistir: field.onChange,
        }) as React.ReactElement
      }
    />
  )
}
