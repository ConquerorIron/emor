import { keepPreviousData, type QueryKey, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'

import type { EFaturaListesi, FaturaYonu, SenkronDurumu } from './efaturaApi'

/**
 * e-Fatura liste ekranlarının (İzibiz, Protel, Tüm Faturalar) ortak liste
 * sorgusu. Önceki ekranlarda her düzeltme (PRT-R3, R9) iki sayfada ayrı ayrı
 * yapılıyordu (Dikkat_Edilecekler K10).
 */
export function useFaturaListesi({
  queryKey,
  queryFn,
  enabled,
  sayfa,
  setSayfa,
}: {
  queryKey: QueryKey
  queryFn: () => Promise<EFaturaListesi>
  enabled: boolean
  sayfa: number
  setSayfa: (sayfa: number) => void
}) {
  const liste = useQuery({ queryKey, queryFn, enabled, placeholderData: keepPreviousData })

  // Son sayfadaki son fatura gizlenince (ya da kayıtlar azalınca) boş sayfada kalınmaz
  const sonSayfa = liste.isPlaceholderData ? undefined : liste.data?.meta.last_page
  useEffect(() => {
    if (sonSayfa !== undefined && sayfa > Math.max(sonSayfa, 1)) {
      setSayfa(Math.max(sonSayfa, 1))
    }
  }, [sonSayfa, sayfa, setSayfa])

  return {
    liste,
    /**
     * Filtre / sıralama değişince yeni sonuç gelene kadar önceki sonuç yerinde
     * kalır ama soluk ve "güncelleniyor" işaretlidir.
     */
    eskiVeri: liste.isPlaceholderData,
    /**
     * Ekrandaki ile inecek olan farklı olabilir: Excel kapalı (F1, PRT-R3).
     * Bağlantı yokken istek beklemeye alınır (paused): isFetching false olur,
     * eski veri durur — üç durum birlikte (PRT-R9).
     */
    yenileniyor: liste.isFetching || liste.isPaused || liste.isPlaceholderData,
  }
}

/**
 * Sunucudaki veri değişince listeler tazelenir: aktif ortam değişti (başka
 * sekme/yönetici), yeni bir senkron bitti (veri zamanı ilerledi) ya da
 * elle/zamanlanmış senkron sürüyor↔bitti. Yoklama arasına düşen kısa senkron
 * da veri zamanından yakalanır.
 */
export function useDurumlaTazele(
  durum: SenkronDurumu | undefined,
  yon: FaturaYonu,
  listeler: QueryKey,
): void {
  const queryClient = useQueryClient()
  const yonDurumu = durum?.yonler?.[yon]
  const tazelikIzi = durum
    ? `${durum.ortam ?? ''}|${yonDurumu?.veri_zamani ?? ''}|${String(
        durum.manuel_istek !== null || (yonDurumu?.calisiyor ?? false),
      )}`
    : null
  const oncekiTazelikIzi = useRef<string | null>(null)
  // Anahtar dizisi her çizimde yeni olabilir: etkinin bağımlılığı metin
  const listeAnahtari = JSON.stringify(listeler)

  useEffect(() => {
    if (tazelikIzi === null) {
      return
    }
    if (oncekiTazelikIzi.current !== null && oncekiTazelikIzi.current !== tazelikIzi) {
      void queryClient.invalidateQueries({ queryKey: JSON.parse(listeAnahtari) as QueryKey })
    }
    oncekiTazelikIzi.current = tazelikIzi
  }, [tazelikIzi, queryClient, listeAnahtari])
}
