import type { TFunction } from 'i18next'

import type { EFaturaIslemi } from './efaturaApi'

/**
 * İşlem geçmişi kaydının ayrıntısı okunur metin olarak: RED nedeni, geri
 * alınan RED'in nedeni, içe aktarım partisinin dosyası ve sayıları.
 * Satır penceresi ve İşlem Geçmişi ekranı ortak kullanır.
 */
export function islemAyrintisi(t: TFunction, kayit: EFaturaIslemi): string {
  const ayrinti = kayit.ayrinti ?? {}

  switch (kayit.islem) {
    case 'red_cekildi':
      return kayit.neden ?? ''
    case 'red_geri_alindi':
      return [
        kayit.onceki === null ? null : t('efatura.islem.oncekiNeden', { neden: kayit.onceki }),
        kayit.neden,
      ]
        .filter((parca) => parca !== null && parca !== '')
        .join(' · ')
    case 'ice_aktarildi':
      return t('efatura.islem.yuklemeAyrintisi', {
        dosya: ayrinti.dosya_adi ?? '—',
        yeni: ayrinti.yeni ?? 0,
        guncellenen: ayrinti.guncellenen ?? 0,
        degismeyen: ayrinti.degismeyen ?? 0,
      })
    case 'ice_aktarim_geri_alindi':
      return t('efatura.islem.geriAlmaAyrintisi', {
        dosya: ayrinti.dosya_adi ?? '—',
        silinen: ayrinti.silinen ?? 0,
      })
    default:
      return ''
  }
}
