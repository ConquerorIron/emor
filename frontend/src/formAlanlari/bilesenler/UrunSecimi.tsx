import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { useDebounce } from '@/hooks/useDebounce'

import { urunSecenekleriGetir, type UrunSecenegi } from '../veri/secenekApi'
import { CokYuzluSecim } from './CokYuzluSecim'

interface UrunSecimiProps {
  id: string
  label: string
  /** Seçili ürünün ERP URUN_YAMASI_ID'si — satırda URUN_YAMASI_ID */
  deger: string
  degisti: (secim: UrunSecenegi | null) => void
  /** Hangi yüz çiziliyor: kod, ad ya da barkod sütunu */
  goster: 'kod' | 'ad' | 'barkod'
  /** Satırda saklı gösterim — seçili ürün o anki arama sonucunda olmayabilir */
  seciliEtiket?: string
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

/**
 * Ürün seçimi — kod, barkod ve ad aynı kaydın üç yüzüdür; üçünden birinden
 * seçmek yeterlidir, diğerleri dolar (URUN_YAMASI_ID saklanır).
 *
 * Diğer listelerden farkı: ürün sayısı on binlerle ölçülür, bu yüzden liste
 * tarayıcıya toptan indirilmez — arama sunucuda yapılır ve ilk 50 kayıt gelir.
 */
export function UrunSecimi({
  id,
  label,
  deger,
  degisti,
  goster,
  seciliEtiket,
  hata,
  disabled = false,
  etiketGizli = false,
}: UrunSecimiProps) {
  const { t } = useTranslation()
  const [arama, setArama] = useState('')
  // Her tuş vuruşunda istek atılmasın
  const gecikmeliArama = useDebounce(arama)

  const urunler = useQuery({
    queryKey: queryKeys.secenekler.urunler(gecikmeliArama),
    queryFn: () => urunSecenekleriGetir(gecikmeliArama),
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      deger={deger}
      degisti={degisti}
      secenekler={urunler.data ?? []}
      goster={goster}
      seciliEtiket={seciliEtiket}
      aramaDegisti={setArama}
      disabled={disabled}
      yukleniyor={urunler.isPending}
      hata={hata ?? (urunler.isError ? t(apiErrorKey(urunler.error)) : undefined)}
    />
  )
}
