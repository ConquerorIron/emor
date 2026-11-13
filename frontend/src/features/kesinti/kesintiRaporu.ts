import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'

import { queryKeys } from '@/api/queryKeys'
import { bugunIso } from '@/utils/tarih'

/** Kesinti raporlarının (Kesinti Özeti, Firma Özeti) ortak parametreleri (backend KesintiRaporuRequest) */
export interface KesintiRaporuParametreleri {
  /** O güne kadarki kesintiler ("itibarıyla") */
  tarih: string
  para_birimi_id: number
  /** Boş: tüm projeler */
  proje_id: number[]
  /** Boş: tüm firmalar */
  firma_id: number[]
}

/** Yalnız kesintide geçenler (backend TumKesintilerSorgusu::raporSecenekleri) */
export interface KesintiRaporuSecenekleri {
  projeler: { id: number; kod: string; ad: string }[]
  firmalar: { id: number; kod: string; ad: string }[]
  /** En çok kesinti satırı olan önce (ekranın varsayılanı) */
  para_birimleri: { id: number; kod: string }[]
}

/**
 * Rapor parametrelerinin durumu ve seçenekleri. `parametreler` tarih boşken ya
 * da para birimi yokken null (rapor istenmez). Para birimi seçilene kadar en
 * çok satırı olan (seçeneklerin ilki).
 */
export function useKesintiRaporuParametreleri(
  rapor: string,
  seceneklerGetir: () => Promise<KesintiRaporuSecenekleri>,
) {
  const [tarih, setTarih] = useState(bugunIso)
  const [projeIdleri, setProjeIdleri] = useState<number[]>([])
  const [firmaIdleri, setFirmaIdleri] = useState<number[]>([])
  const [paraBirimiId, setParaBirimiId] = useState<number | null>(null)

  const secenekler = useQuery({
    queryKey: queryKeys.kesinti.raporSecenekleri(rapor),
    queryFn: seceneklerGetir,
  })
  const seciliPara = paraBirimiId ?? secenekler.data?.para_birimleri[0]?.id ?? null
  const parametreler: KesintiRaporuParametreleri | null =
    seciliPara !== null && tarih !== ''
      ? { tarih, para_birimi_id: seciliPara, proje_id: projeIdleri, firma_id: firmaIdleri }
      : null

  return {
    parametreler,
    secenekler,
    tarih,
    setTarih,
    projeIdleri,
    setProjeIdleri,
    firmaIdleri,
    setFirmaIdleri,
    seciliPara,
    setParaBirimiId,
  }
}

export type KesintiRaporuDurumu = ReturnType<typeof useKesintiRaporuParametreleri>
