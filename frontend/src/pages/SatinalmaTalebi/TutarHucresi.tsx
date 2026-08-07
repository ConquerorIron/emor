import { useQuery } from '@tanstack/react-query'
import type { Path, UseFormReturn } from 'react-hook-form'

import { queryKeys } from '@/api/queryKeys'
import { paraSecenekleriGetir } from '@/formAlanlari'

import { tutarHesapla, type FiyatGrubu } from './fiyatGruplari'
import type { TalepGirdisi } from './talepSchema'

/**
 * Tutar hücresi — SALT OKUNUR, hesaplanır (kullanıcı kararı 2026-08-07):
 *   Tutar      = Miktar × Fiyat × Kur   (yerel para karşılığı)
 *   Tutar (YP) = Miktar × Fiyat         (yabancı paranın kendisi)
 *
 * Değer satırda saklanmaz; kayıt sırasında aynı formülle üretilir.
 */
export function TutarHucresi({
  grup,
  indeks,
  form,
  etiket,
  kurUygula,
}: {
  grup: FiyatGrubu
  indeks: number
  form: UseFormReturn<TalepGirdisi>
  etiket: string
  /** false = yabancı para tutarı (Tutar YP): kur çarpanı uygulanmaz */
  kurUygula: boolean
}) {
  const paralar = useQuery({
    queryKey: queryKeys.secenekler.paralar,
    queryFn: paraSecenekleriGetir,
    staleTime: 60 * 60_000,
  })

  const anahtar = (son: string) => `satirlar.${indeks}.${son}` as Path<TalepGirdisi>
  const oku = (son: string) => String(form.watch(anahtar(son)) ?? '')

  const paraId = oku(grup.para)
  const para = (paralar.data ?? []).find((p) => String(p.kayit_id) === paraId)
  const basamak = para?.tutar_basamak ?? 2

  const tutar = tutarHesapla(oku('miktar'), oku(grup.fiyat), oku(grup.kur), kurUygula)
  const dolu = oku(grup.fiyat) !== '' && oku('miktar') !== ''

  return (
    <output
      aria-label={`${etiket} ${indeks + 1}`}
      className="block truncate px-2 py-2 text-right text-sm tabular-nums text-slate-600 dark:text-slate-300"
    >
      {dolu
        ? // Binlik ayracıyla, paranın kendi basamak sayısında
          tutar.toLocaleString('tr-TR', {
            minimumFractionDigits: basamak,
            maximumFractionDigits: basamak,
          }) + (kurUygula ? '' : ` ${para?.kod ?? ''}`)
        : '—'}
    </output>
  )
}
