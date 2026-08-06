import { useTranslation } from 'react-i18next'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import {
  TESLIMAT_BICIMLERI,
  VARSAYILAN_TESLIMAT_BICIMI,
  type TeslimatBicimi,
} from '../veri/sabitler'

interface TeslimatBicimiSecimiProps {
  id: string
  label: string
  deger: string
  degisti: (deger: TeslimatBicimi) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/** Teslimat biçimi seçimi — sipariş/talep ekranlarında ortak kullanılır. */
export function TeslimatBicimiSecimi({
  id,
  label,
  deger,
  degisti,
  disabled = false,
  hata,
}: TeslimatBicimiSecimiProps) {
  const { t } = useTranslation()

  const secenekler: SecenekOgesi[] = TESLIMAT_BICIMLERI.map((bicim) => ({
    value: bicim,
    label: t(`teslimat.bicim.${bicim}`),
  }))

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => degisti((secim?.value as TeslimatBicimi) ?? VARSAYILAN_TESLIMAT_BICIMI)}
      isClearable={false}
      disabled={disabled}
      hata={hata}
    />
  )
}
