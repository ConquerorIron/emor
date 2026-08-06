import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import { firmamizAdresleriGetir } from './api'

export interface AdresSecim {
  /** ERP ADRES_ID — proc'ta @TESLIMAT_ADRESI_ID */
  kayitId: string
  /** Adres metni — proc'ta @TESLIMAT_ADRESI (ACIKLAMA200) */
  adres: string
}

interface FirmamizAdresiSecimiProps {
  id: string
  label: string
  /** Seçili adresin ADRES_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: AdresSecim | null) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
  /** Tek seçenek varsa açılışta otomatik seçilir (ERP formu adresi dolu açar) */
  tekSeceneginiSec?: boolean
}

/**
 * Firmamızın adresleri arasından seçim — görünen değer ADRES metnidir.
 * Program genelinde teslimat/ödeme adresi seçilen her yerde kullanılır.
 */
export function FirmamizAdresiSecimi({
  id,
  label,
  deger,
  degisti,
  hata,
  disabled = false,
  tekSeceneginiSec = false,
}: FirmamizAdresiSecimiProps) {
  const { t } = useTranslation()

  const adresler = useQuery({
    queryKey: queryKeys.secenekler.firmamizAdresleri,
    queryFn: firmamizAdresleriGetir,
    staleTime: 5 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (adresler.data ?? []).map((adres) => ({
        value: String(adres.kayit_id),
        label: adres.adres,
      })),
    [adresler.data],
  )

  // ERP ekranı tek adresli firmalarda alanı dolu açar; aynı davranış
  useEffect(() => {
    if (tekSeceneginiSec && deger === '' && secenekler.length === 1) {
      const tek = secenekler[0]
      degisti({ kayitId: tek.value, adres: tek.label })
    }
  }, [tekSeceneginiSec, deger, secenekler, degisti])

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => {
        degisti(secim ? { kayitId: secim.value, adres: secim.label } : null)
      }}
      placeholder={t('ortak.secVeyaAra')}
      isClearable
      disabled={disabled}
      yukleniyor={adresler.isPending}
      hata={hata ?? (adresler.isError ? t(apiErrorKey(adresler.error)) : undefined)}
    />
  )
}
