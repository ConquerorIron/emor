import type { Plan, PlanHedefi } from './planApi'

type Ceviri = (anahtar: string, secenekler?: Record<string, unknown>) => string

/** 1 = Pazartesi … 7 = Pazar → "Pzt, Çar" */
export function gunlerOzeti(gunler: number[], t: Ceviri): string {
  return gunler
    .toSorted((a, b) => a - b)
    .map((gun) => t(`yedekleme.plan.gunKisa.${gun}`))
    .join(', ')
}

/** Planın sıklığı tek satırda: "Her gün 02:00", "3 günde bir 02:00", "Haftalık (Paz) 03:00" … */
export function siklikOzeti(
  plan: Pick<Plan, 'siklik_turu' | 'saat' | 'siklik'>,
  t: Ceviri,
): string {
  const { siklik, saat } = plan

  switch (plan.siklik_turu) {
    case 'saatlik': {
      const ekler = [
        siklik.saat_baslangic || siklik.saat_bitis
          ? `${siklik.saat_baslangic ?? '00:00'}–${siklik.saat_bitis ?? '23:59'}`
          : null,
        siklik.gunler && siklik.gunler.length > 0 ? gunlerOzeti(siklik.gunler, t) : null,
      ].filter((ek) => ek !== null)

      return `${t('yedekleme.plan.ozet.saatlik', { aralik: siklik.aralik ?? 1, saat })}${
        ekler.length > 0 ? ` (${ekler.join('; ')})` : ''
      }`
    }
    case 'gunluk':
      return t('yedekleme.plan.ozet.gunluk', { saat })
    case 'n_gunluk':
      return t('yedekleme.plan.ozet.n_gunluk', { aralik: siklik.aralik ?? 1, saat })
    case 'haftalik':
      return t('yedekleme.plan.ozet.haftalik', {
        gunler: gunlerOzeti(siklik.gunler ?? [], t),
        saat,
      })
    case 'aylik':
      return t('yedekleme.plan.ozet.aylik', { gun: siklik.ayin_gunu ?? 1, saat })
  }
}

/** Hedefteki saklama kuralı: "son 14 yedek", "30 gün", "son 14 yedek ya da 30 gün" */
export function saklamaOzeti(
  hedef: Pick<PlanHedefi, 'sakla_son_adet' | 'sakla_gun'>,
  t: Ceviri,
): string {
  const parcalar = [
    hedef.sakla_son_adet === null
      ? null
      : t('yedekleme.plan.saklama.sonAdet', { adet: hedef.sakla_son_adet }),
    hedef.sakla_gun === null ? null : t('yedekleme.plan.saklama.gun', { gun: hedef.sakla_gun }),
  ].filter((parca) => parca !== null)

  return parcalar.join(t('yedekleme.plan.saklama.ya'))
}
