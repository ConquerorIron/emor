import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { queryKeys } from '@/api/queryKeys'

import { paraSecenekleriGetir, type ParaSecenegi } from '../veri/secenekApi'

interface ParaSecimiProps {
  id: string
  label: string
  /** Seçili paranın PARA_ID'si */
  deger: string
  degisti: (para: ParaSecenegi | null) => void
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

/**
 * Para birimi seçimi (TOHOM_PARA) — fiyat alanlarının yanında durur. Seçilen
 * para hem fiyatın hem tutarın ondalık basamak sayısını, hem de kurun hangi
 * birim için okunacağını belirler.
 */
export function ParaSecimi({
  id,
  label,
  deger,
  degisti,
  hata,
  disabled = false,
  etiketGizli = false,
}: ParaSecimiProps) {
  const paralar = useQuery({
    queryKey: queryKeys.secenekler.paralar,
    queryFn: paraSecenekleriGetir,
    // Para birimleri neredeyse hiç değişmez
    staleTime: 60 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () => (paralar.data ?? []).map((para) => ({ value: String(para.kayit_id), label: para.kod })),
    [paralar.data],
  )

  return (
    <SelectField
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      options={secenekler}
      value={secenekler.find((oge) => oge.value === deger) ?? null}
      onChange={(secim) =>
        degisti(
          secim
            ? ((paralar.data ?? []).find((p) => String(p.kayit_id) === secim.value) ?? null)
            : null,
        )
      }
      isClearable={false}
      isSearchable={false}
      disabled={disabled}
      yukleniyor={paralar.isPending}
      hata={hata}
    />
  )
}
