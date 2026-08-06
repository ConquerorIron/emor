import { useTranslation } from 'react-i18next'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'

/**
 * Teslimat biçimi — ERP'de sabit değerli alan (view sorgusu yoktur):
 * Tam = 0, Parçalı = 1. SOHOM_SIPARIS_KAYDET @TESLIMAT_BICIMI (TUR tipi).
 */
export const TESLIMAT_BICIMLERI = ['0', '1'] as const

export type TeslimatBicimi = (typeof TESLIMAT_BICIMLERI)[number]

export const VARSAYILAN_TESLIMAT_BICIMI: TeslimatBicimi = '0' // Tam

interface TeslimatBicimiSecimiProps {
  id: string
  label: string
  deger: string
  degisti: (deger: TeslimatBicimi) => void
  hata?: string
}

/** Teslimat biçimi seçimi — sipariş/talep ekranlarında ortak kullanılır. */
export function TeslimatBicimiSecimi({
  id,
  label,
  deger,
  degisti,
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
      hata={hata}
    />
  )
}
