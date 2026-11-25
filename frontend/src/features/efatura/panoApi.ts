import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

import type { FaturaSinifi, Saglayici } from './efaturaApi'

/** Panodaki sayılar: sınıflar Tüm Faturalar'ın `sinif` süzgeciyle aynı */
export interface PanoSayilari {
  gelen: number
  islenen: number
  red: number
  islenecek: number
  red_islenmis: number
  gizlenen: number
}

export interface PanoOzeti {
  portallar: (PanoSayilari & { saglayici: Saglayici })[]
  toplam: PanoSayilari
  /** İşlenecek faturaların belge tarihi ayına göre adedi ('2026-01' → n) */
  aylik: { saglayici: Saglayici; aylar: Record<string, number> }[]
}

export interface PanoFirmasi {
  vkn: string | null
  unvan: string | null
  para_birimi: string
  adet: number
  tutar: string
}

export interface PanoAraligi {
  baslangic: string
  bitis: string
}

export interface FirmaSuzgeci extends PanoAraligi {
  entegrator: '' | Saglayici
  sinif: FaturaSinifi
}

function firmaParametreleri(suzgec: FirmaSuzgeci): Record<string, string> {
  return {
    baslangic: suzgec.baslangic,
    bitis: suzgec.bitis,
    ...(suzgec.entegrator === '' ? {} : { entegrator: suzgec.entegrator }),
    ...(suzgec.sinif === '' ? {} : { sinif: suzgec.sinif }),
  }
}

export async function panoGetir(aralik: PanoAraligi): Promise<PanoOzeti> {
  const yanit = await api.get<{ data: PanoOzeti }>('/api/v1/efatura/pano', { params: aralik })

  return yanit.data.data
}

/** Gönderen firma bazlı döküm (para birimi başına; farklı para birimleri toplanmaz) */
export async function panoFirmalariGetir(suzgec: FirmaSuzgeci): Promise<PanoFirmasi[]> {
  const yanit = await api.get<{ data: PanoFirmasi[] }>('/api/v1/efatura/pano/firmalar', {
    params: firmaParametreleri(suzgec),
  })

  return yanit.data.data
}

export async function panoFirmalariExcelIndir(suzgec: FirmaSuzgeci): Promise<void> {
  const yanit = await api
    .get<Blob>('/api/v1/efatura/pano/firmalar/excel', {
      params: firmaParametreleri(suzgec),
      responseType: 'blob',
    })
    .catch(blobHatasiniCoz)

  dosyayiIndir(yanit.data, `efatura-firmalar-${suzgec.baslangic}-${suzgec.bitis}.xlsx`)
}
