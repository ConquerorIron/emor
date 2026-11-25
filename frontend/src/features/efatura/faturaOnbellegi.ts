import type { QueryClient } from '@tanstack/react-query'

import { queryKeys } from '@/api/queryKeys'

import type { EFatura, EFaturaListesi } from './efaturaApi'

/** Bir faturanın görünebildiği bütün liste önbellekleri (ekran başına) */
const LISTE_ANAHTARLARI = [
  ['efatura', 'liste'],
  queryKeys.tumFaturalar.hepsi,
  queryKeys.kaynak.listeler('protel'),
  queryKeys.kaynak.listeler('logo'),
  queryKeys.kaynak.listeler('earsiv'),
] as const

/**
 * Satırı sunucunun döndürdüğü faturayla yerinde günceller (liste yeniden
 * çekilmez). Pano ve geçmiş tazelenir. Sınıfı değiştirmeyen işlemler için
 * ("İşlendi" anahtarı, gizleme).
 */
export function faturaSatiriniGuncelle(queryClient: QueryClient, guncel: EFatura): void {
  for (const anahtar of LISTE_ANAHTARLARI) {
    queryClient.setQueriesData<EFaturaListesi>({ queryKey: anahtar }, (eski) =>
      eski?.data === undefined
        ? eski
        : { ...eski, data: eski.data.map((f) => (f.id === guncel.id ? { ...f, ...guncel } : f)) },
    )
  }
  void queryClient.invalidateQueries({ queryKey: queryKeys.efaturaGecmis.hepsi })
  void queryClient.invalidateQueries({ queryKey: queryKeys.efaturaPano.hepsi })
}

/**
 * RED gibi faturanın sınıfını değiştiren işlemden sonra listeler yeniden
 * çekilir: süzülmüş listede (ör. "ERP İşlenmeyenler") satır artık yer almayabilir.
 */
export function faturaListeleriniTazele(queryClient: QueryClient): void {
  for (const anahtar of LISTE_ANAHTARLARI) {
    void queryClient.invalidateQueries({ queryKey: anahtar })
  }
  void queryClient.invalidateQueries({ queryKey: queryKeys.efaturaGecmis.hepsi })
  void queryClient.invalidateQueries({ queryKey: queryKeys.efaturaPano.hepsi })
}
