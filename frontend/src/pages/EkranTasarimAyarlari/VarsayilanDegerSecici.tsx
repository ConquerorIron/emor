import { useTranslation } from 'react-i18next'

import { Input } from '@/components/Input'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { FirmamizAdresiSecimi } from '@/formAlanlari/bilesenler/FirmamizAdresiSecimi'
import { DepoSecimi } from '@/formAlanlari/bilesenler/DepoSecimi'
import { PersonelSecimi } from '@/formAlanlari/bilesenler/PersonelSecimi'
import { AlimYeriSecimi } from '@/formAlanlari'
import { ILGI_CINSLERI } from '@/formAlanlari'
import { OncelikSecimi } from '@/formAlanlari/bilesenler/OncelikSecimi'
import { TeslimatSekliSecimi } from '@/formAlanlari/bilesenler/TeslimatSekliSecimi'
import { TeslimatBicimiSecimi } from '@/formAlanlari'

import type { KatalogAlani } from '@/features/ekranTasarim/types'

interface VarsayilanDegerSeciciProps {
  katalog: KatalogAlani
  deger: string
  degisti: (deger: string) => void
}

/**
 * Tasarımcının varsayılan değeri, formdaki gerçek giriş bileşeniyle seçmesini
 * sağlar — ERP kodu (0/1/7/12…) ezberlemek gerekmez. Saklanan değer yine ham
 * koddur; yalnız seçim yüzü insana okunur.
 */
export function VarsayilanDegerSecici({ katalog, deger, degisti }: VarsayilanDegerSeciciProps) {
  const { t } = useTranslation()
  const id = `ayar-varsayilan-${katalog.anahtar}`
  const etiket = t('tasarim.varsayilan')

  switch (katalog.giris_tipi) {
    case 'teslimatBicimi':
      return (
        <TeslimatBicimiSecimi id={id} label={etiket} deger={deger} degisti={(v) => degisti(v)} />
      )

    case 'alimYeri':
      return <AlimYeriSecimi id={id} label={etiket} deger={deger} degisti={(v) => degisti(v)} />

    case 'ilgiCinsi': {
      const secenekler: SecenekOgesi[] = ILGI_CINSLERI.map((cins) => ({
        value: cins,
        label: t(`satinalma.ilgiCinsi.${cins}`),
      }))

      return (
        <SelectField
          id={id}
          label={etiket}
          options={secenekler}
          value={secenekler.find((s) => s.value === deger) ?? null}
          onChange={(secim) => degisti(secim?.value ?? '')}
          isClearable
        />
      )
    }

    case 'oncelik':
      return (
        <OncelikSecimi
          id={id}
          label={etiket}
          deger={deger}
          degisti={(secim) => degisti(secim?.kayitId ?? '')}
        />
      )

    case 'teslimatSekli':
      return (
        <TeslimatSekliSecimi
          id={id}
          label={etiket}
          deger={deger}
          degisti={(secim) => degisti(secim?.kayitId ?? '')}
        />
      )

    case 'depo':
      return (
        <DepoSecimi
          id={id}
          label={etiket}
          deger={deger}
          degisti={(secim) => degisti(secim?.kayitId ?? '')}
        />
      )

    case 'personel':
      return (
        <PersonelSecimi
          id={id}
          label={etiket}
          deger={deger}
          degisti={(secim) => degisti(secim?.kayitId ?? '')}
        />
      )

    case 'teslimatAdresi':
      return (
        <FirmamizAdresiSecimi
          id={id}
          label={etiket}
          deger={deger}
          degisti={(secim) => degisti(secim?.kayitId ?? '')}
        />
      )

    case 'tarih':
    case 'opsiyonelTarih':
      return <TarihInput id={id} label={etiket} value={deger} onChange={degisti} />

    default:
      // Serbest metin ve sayısal alanlar (açıklama, teslimat süresi, no…)
      return (
        <Input
          id={id}
          label={etiket}
          autoComplete="off"
          value={deger}
          onChange={(olay) => degisti(olay.target.value)}
        />
      )
  }
}
