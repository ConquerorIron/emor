import type { SecenekOgesi } from '@/components/SelectField'

interface KodluTanim {
  id: number
  kod: string
  ad: string
}

/**
 * Kesinti ekranlarının FİLTRE seçenekleri (kullanıcı isteği 2026-11-16):
 * taşeron ve kesinti türü yalnız koduyla, proje yalnız adıyla gösterilir —
 * listedeki kolonlar da taşeronu koduyla gösterir. Kayıt formlarının
 * seçimleri ("kod — ad") bundan etkilenmez.
 */
export function kodSecenekleri(liste: readonly KodluTanim[] | undefined): SecenekOgesi[] {
  return (liste ?? []).map((tanim) => ({ value: String(tanim.id), label: tanim.kod }))
}

/** Proje filtresi: adı (boşsa kodu), görünen ada göre sıralı */
export function adSecenekleri(liste: readonly KodluTanim[] | undefined): SecenekOgesi[] {
  return (liste ?? [])
    .map((tanim) => ({ value: String(tanim.id), label: tanim.ad.trim() || tanim.kod }))
    .toSorted((a, b) => a.label.localeCompare(b.label, 'tr'))
}
