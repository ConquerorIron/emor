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
 * Faturanın sınıfını, sayılarını ya da görünürlüğünü değiştiren işlemden
 * (RED, "SAP (Manuel İşle)", gizleme, eMOR eşleştirmesi, içe aktarım) sonra
 * bütün listeler, pano ve işlem geçmişi yeniden çekilir: süzülmüş listede
 * (ör. "ERP İşlenmeyenler") satır artık yer almayabilir, sayfalar kayar;
 * özet ve Excel sunucuyla aynı kalmalı (F1). Etkin listeler hemen yeniden
 * istenir; dönen söz hepsi bitince çözülür.
 */
export function faturaListeleriniTazele(queryClient: QueryClient): Promise<unknown> {
  return Promise.all([
    ...LISTE_ANAHTARLARI.map((anahtar) => queryClient.invalidateQueries({ queryKey: anahtar })),
    queryClient.invalidateQueries({ queryKey: queryKeys.efaturaGecmis.hepsi }),
    queryClient.invalidateQueries({ queryKey: queryKeys.efaturaPano.hepsi }),
  ])
}

/**
 * "SAP (Manuel İşle)" gibi tek faturayı değiştiren işlem: satır sunucunun
 * döndürdüğü faturayla hemen yerinde güncellenir (anında geri bildirim),
 * ardından listeler de yeniden çekilir — işaret faturanın sınıfını
 * (işlenecek → işlendi) değiştirir; süzülmüş listede satır yeni veriyle
 * düşebilir (RED ile aynı davranış; kullanıcı kararı F-1, 2026-11-27).
 */
export function faturaSatiriniGuncelle(
  queryClient: QueryClient,
  guncel: EFatura,
): Promise<unknown> {
  for (const anahtar of LISTE_ANAHTARLARI) {
    queryClient.setQueriesData<EFaturaListesi>({ queryKey: anahtar }, (eski) =>
      eski?.data === undefined
        ? eski
        : { ...eski, data: eski.data.map((f) => (f.id === guncel.id ? { ...f, ...guncel } : f)) },
    )
  }

  return faturaListeleriniTazele(queryClient)
}
