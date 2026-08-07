import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Controller, type Path, type UseFormReturn } from 'react-hook-form'

import { queryKeys } from '@/api/queryKeys'
import { SayiAlani } from '@/components/SayiAlani'
import { kurGetir, ParaSecimi, paraSecenekleriGetir, type ParaSecenegi } from '@/formAlanlari'

import type { FiyatGrubu } from './fiyatGruplari'
import type { TalepGirdisi } from './talepSchema'

/**
 * Fiyat hücresi: sayı + para birimi yan yana.
 *
 * Para seçilince iki şey olur — ondalık basamak sayısı o paranın tanımından
 * gelir (TL/USD için 6) ve KUR belge tarihine göre ERP'den okunup satıra
 * yazılır. Kur alanı ayrı bir sütundur; kullanıcı gerekirse üzerine yazabilir.
 */
export function FiyatHucresi({
  grup,
  indeks,
  form,
  id,
  etiket,
  tarih,
  hata,
}: {
  grup: FiyatGrubu
  indeks: number
  form: UseFormReturn<TalepGirdisi>
  id: string
  etiket: string
  /** Başlıktaki talep tarihi (ISO) — kur bu tarihe göre okunur */
  tarih: string
  hata?: string
}) {
  const istemci = useQueryClient()

  const paralar = useQuery({
    queryKey: queryKeys.secenekler.paralar,
    queryFn: paraSecenekleriGetir,
    staleTime: 60 * 60_000,
  })

  const anahtar = (son: keyof TalepGirdisi | string) =>
    `satirlar.${indeks}.${son}` as Path<TalepGirdisi>

  const paraId = String(form.watch(anahtar(grup.para)) ?? '')
  const secili = (paralar.data ?? []).find((para) => String(para.kayit_id) === paraId)

  const paraSecildi = async (para: ParaSecenegi | null) => {
    form.setValue(anahtar(grup.para), para ? String(para.kayit_id) : '')

    if (!para || tarih === '') {
      form.setValue(anahtar(grup.kur), '')

      return
    }

    // Kur belge tarihine göre gelir; okunamazsa alan boş kalır, elle girilir
    const kur = await istemci.fetchQuery({
      queryKey: queryKeys.secenekler.kur(String(para.kayit_id), tarih),
      queryFn: () => kurGetir(String(para.kayit_id), tarih),
      staleTime: 60 * 60_000,
    })
    form.setValue(anahtar(grup.kur), kur)
  }

  return (
    <div className="flex items-start gap-1">
      <div className="min-w-0 flex-1">
        <Controller
          control={form.control}
          name={anahtar(grup.fiyat)}
          render={({ field }) => (
            <SayiAlani
              id={id}
              label={etiket}
              etiketGizli
              value={typeof field.value === 'string' ? field.value : ''}
              onChange={field.onChange}
              ondalik={secili?.fiyat_basamak ?? 2}
              hata={hata}
            />
          )}
        />
      </div>
      <div className="w-[6.5rem] shrink-0">
        <ParaSecimi
          id={`${id}-para`}
          label={`${etiket} — para birimi`}
          etiketGizli
          deger={paraId}
          degisti={(para) => void paraSecildi(para)}
        />
      </div>
    </div>
  )
}
