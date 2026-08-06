import { Controller, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import type { ComponentType } from 'react'

import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { Input } from '@/components/Input'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { FirmamizAdresiSecimi } from '@/features/adres/FirmamizAdresiSecimi'
import { DepoSecimi } from '@/features/depo/DepoSecimi'
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
import type { DuzenAlani, KatalogAlani } from '@/features/ekranTasarim/types'

/** Her giriş bileşeninin aldığı standart sözleşme. */
export interface AlanGirisiProps {
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: UseFormReturn<TalepGirdisi>
  /** Katalogdaki i18n anahtarından çözülmüş etiket (zorunluysa * eklenmiş) */
  etiket: string
}

function MetinGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  const { t } = useTranslation()
  const anahtar = katalog.veri_anahtarlari[0] as keyof TalepGirdisi
  const saltOkunur = duzen.salt_okunur === true

  if (duzen.gorunum === 'textarea') {
    return (
      <div>
        <label
          htmlFor={`alan-${katalog.anahtar}`}
          className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
        >
          {etiket}
        </label>
        <textarea
          id={`alan-${katalog.anahtar}`}
          rows={duzen.satir ?? 2}
          disabled={saltOkunur}
          className="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900"
          {...form.register(anahtar)}
        />
      </div>
    )
  }

  return (
    <Input
      id={`alan-${katalog.anahtar}`}
      label={etiket}
      autoComplete="off"
      disabled={saltOkunur}
      placeholder={saltOkunur ? t('satinalma.otomatik') : undefined}
      className={saltOkunur ? 'disabled:bg-slate-50 dark:disabled:bg-slate-900' : undefined}
      {...form.register(anahtar)}
    />
  )
}

function PersonelGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="personel_id"
      control={form.control}
      render={({ field, fieldState }) => (
        <PersonelSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={(secim) => {
            field.onChange(secim?.kayitId ?? '')
            form.setValue('personel_adi', secim?.unvan ?? '')
          }}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function TarihGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="tarih"
      control={form.control}
      render={({ field, fieldState }) => (
        <TarihInput
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          value={field.value}
          onChange={field.onChange}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function OpsiyonelTarihGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="termin"
      control={form.control}
      render={({ field, fieldState }) => (
        <OpsiyonelTarihInput
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          value={field.value}
          onChange={field.onChange}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function OncelikGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="oncelik_id"
      control={form.control}
      render={({ field, fieldState }) => (
        <OncelikSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={(secim) => field.onChange(secim?.kayitId ?? '')}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

/** Karakter sınırlı metin (Açıklama 200, Hakkında 3072…) — sınır katalogdan gelir. */
function SinirliMetinGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  const anahtar = katalog.veri_anahtarlari[0] as keyof TalepGirdisi

  return (
    <Controller
      name={anahtar}
      control={form.control}
      render={({ field, fieldState }) => (
        <SinirliMetinInput
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          value={typeof field.value === 'string' ? field.value : ''}
          onChange={field.onChange}
          limit={katalog.metin_limiti || 200}
          rows={duzen.satir ?? 2}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function IlgiCinsiGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  const { t } = useTranslation()
  const secenekler: SecenekOgesi[] = ILGI_CINSLERI.map((cins) => ({
    value: cins,
    label: t(`satinalma.ilgiCinsi.${cins}`),
  }))

  return (
    <Controller
      name="ilgi_cinsi"
      control={form.control}
      render={({ field }) => (
        <SelectField
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          options={secenekler}
          value={secenekler.find((s) => s.value === field.value) ?? null}
          onChange={(secim) => {
            field.onChange(secim?.value ?? VARSAYILAN_ILGI_CINSI)
            // Cins değişince önceki seçim anlamsızlaşır
            form.setValue('ilgili_id', '')
          }}
          isClearable={false}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

/** Etiketi ve arama kaynağı seçili ilgi cinsine göre değişen bağlı alan. */
function IlgiliGirisi({ katalog, duzen, form }: AlanGirisiProps) {
  const { t } = useTranslation()
  const cins = (form.watch('ilgi_cinsi') || VARSAYILAN_ILGI_CINSI) as IlgiCinsi
  const etiket = t(`satinalma.ilgiliEtiket.${cins}`)

  return (
    <Controller
      name="ilgili_id"
      control={form.control}
      render={({ field, fieldState }) =>
        ARAMALI_ILGI_CINSLERI.includes(cins) ? (
          <IlgiliSecimi
            cins={cins}
            id={`alan-${katalog.anahtar}`}
            label={etiket}
            deger={field.value}
            degisti={field.onChange}
            hata={fieldState.error?.message}
            disabled={duzen.salt_okunur === true}
          />
        ) : (
          <Input
            id={`alan-${katalog.anahtar}`}
            label={etiket}
            autoComplete="off"
            value={field.value}
            onChange={field.onChange}
            hata={fieldState.error?.message}
            disabled={duzen.salt_okunur === true}
          />
        )
      }
    />
  )
}

function DepoGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="depomuz_id"
      control={form.control}
      render={({ field, fieldState }) => (
        <DepoSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={(secim) => field.onChange(secim?.kayitId ?? '')}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function TeslimatAdresiGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="teslimat_adresi_id"
      control={form.control}
      render={({ field, fieldState }) => (
        <FirmamizAdresiSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={(secim) => {
            field.onChange(secim?.kayitId ?? '')
            form.setValue('teslimat_adresi', secim?.adres ?? '')
          }}
          tekSeceneginiSec
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function TeslimatBicimiGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="teslimat_bicimi"
      control={form.control}
      render={({ field }) => (
        <TeslimatBicimiSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={field.onChange}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

function TeslimatSekliGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="teslimat_sekli_id"
      control={form.control}
      render={({ field, fieldState }) => (
        <TeslimatSekliSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={(secim) => field.onChange(secim?.kayitId ?? '')}
          hata={fieldState.error?.message}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

/** Süre alanının tarih yüzü — seçilen tarih süre+birime geri çevrilir. */
function TeslimatSuresiTarihGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  const temel = form.watch('tarih')
  const sure = form.watch('teslimat_suresi')
  const birim = form.watch('teslimat_suresi_birimi') as TeslimatSuresiBirimi

  return (
    <TarihInput
      id={`alan-${katalog.anahtar}`}
      label={etiket}
      value={sureyiTariheCevir(temel, sure, birim)}
      onChange={(iso) => {
        const cevrim = tarihiSureyeCevir(temel, iso, birim)
        form.setValue('teslimat_suresi', cevrim.sure)
        form.setValue('teslimat_suresi_birimi', cevrim.birim)
      }}
      disabled={duzen.salt_okunur === true}
    />
  )
}

function TeslimatSuresiGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  const sure = form.watch('teslimat_suresi')
  const birim = form.watch('teslimat_suresi_birimi') as TeslimatSuresiBirimi

  return (
    <TeslimatSuresiSecimi
      id={`alan-${katalog.anahtar}`}
      label={etiket}
      sure={sure}
      birim={birim}
      degisti={(yeniSure, yeniBirim) => {
        form.setValue('teslimat_suresi', yeniSure)
        form.setValue('teslimat_suresi_birimi', yeniBirim)
      }}
      hata={form.formState.errors.teslimat_suresi?.message}
      disabled={duzen.salt_okunur === true}
    />
  )
}

function AlimYeriGirisi({ katalog, duzen, form, etiket }: AlanGirisiProps) {
  return (
    <Controller
      name="alim_yeri"
      control={form.control}
      render={({ field }) => (
        <AlimYeriSecimi
          id={`alan-${katalog.anahtar}`}
          label={etiket}
          deger={field.value}
          degisti={field.onChange}
          disabled={duzen.salt_okunur === true}
        />
      )}
    />
  )
}

/**
 * Giriş tipi → bileşen kayıt defteri. Katalog (backend) bir alanın giris_tipi'ni
 * söyler, burası onu çizecek bileşeni verir. Yeni giriş tipi eklemek = buraya
 * bir satır + katalogda kullanmak.
 */
export const GIRIS_KAYDI: Record<string, ComponentType<AlanGirisiProps>> = {
  metin: MetinGirisi,
  personel: PersonelGirisi,
  tarih: TarihGirisi,
  opsiyonelTarih: OpsiyonelTarihGirisi,
  oncelik: OncelikGirisi,
  sinirliMetin: SinirliMetinGirisi,
  ilgiCinsi: IlgiCinsiGirisi,
  ilgili: IlgiliGirisi,
  depo: DepoGirisi,
  teslimatAdresi: TeslimatAdresiGirisi,
  teslimatBicimi: TeslimatBicimiGirisi,
  teslimatSekli: TeslimatSekliGirisi,
  teslimatSuresiTarih: TeslimatSuresiTarihGirisi,
  teslimatSuresi: TeslimatSuresiGirisi,
  alimYeri: AlimYeriGirisi,
}
