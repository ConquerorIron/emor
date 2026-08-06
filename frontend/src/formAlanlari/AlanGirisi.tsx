import { Controller, type FieldValues, type Path, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { zorunluEtiket, type DuzenAlani, type KatalogAlani } from '@/features/ekranTasarim/types'

import { GIRIS_TANIMLARI } from './girisKaydi'
import type { GenelForm } from './ortakTipler'

export function girisTipiTanimliMi(girisTipi: string): boolean {
  return girisTipi in GIRIS_TANIMLARI
}

/**
 * Tasarımdaki bir alanı çizen TEK giriş noktası — HER ekran için ortaktır.
 *
 * Tasarım kurallarını burada uygular:
 *   • etiket + zorunluluk yıldızı
 *   • doğrulama mesajının çevirisi
 *   • salt okunur kilidi
 *
 * Alanın hangi form anahtarlarını yazdığı (`veri_anahtarlari`) ve başka bir
 * alana bağımlılığı (`bagli_veri_anahtari`) katalogdan gelir; bu yüzden burada
 * ekrana özgü sabit alan adı YOKTUR.
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

  const hamEtiket = tanim.etiket?.({ bagliOku, t }) ?? t(katalog.etiket_anahtari)

  return (
    <Controller
      name={katalog.veri_anahtarlari[0] as Path<T>}
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
