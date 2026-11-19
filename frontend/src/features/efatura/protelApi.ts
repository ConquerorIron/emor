import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

import type {
  EFaturaListesi,
  ErpOkunduFiltresi,
  ExcelKolonu,
  FaturaPdfi,
  SenkronDurumu,
  Siralama,
} from './efaturaApi'

/**
 * Protel Gelen Faturalar (PRT-12) — otelin posta kutusu. Sunucu e-Fatura
 * uçlarını Protel tanımıyla çalıştırır: yalnız gelen, XML / gizleme / eMOR yok.
 */
export interface ProtelFaturaFiltresi {
  baslangic: string
  bitis: string
  ara: string
  durum: string
  erp_okundu: ErpOkunduFiltresi
  para_birimi: string
  tip: string
}

function parametreler(
  filtre: ProtelFaturaFiltresi,
  siralama: Siralama | null,
): Record<string, string> {
  const sonuc: Record<string, string> = { baslangic: filtre.baslangic, bitis: filtre.bitis }
  for (const anahtar of ['ara', 'durum', 'erp_okundu', 'para_birimi', 'tip'] as const) {
    const deger = filtre[anahtar].trim()
    if (deger !== '') {
      sonuc[anahtar] = deger
    }
  }
  if (siralama !== null) {
    sonuc['sirala'] = siralama.anahtar
    sonuc['yon'] = siralama.yon
  }

  return sonuc
}

export async function protelFaturalariGetir(
  filtre: ProtelFaturaFiltresi,
  siralama: Siralama | null,
  sayfa: number,
): Promise<EFaturaListesi> {
  const yanit = await api.get<EFaturaListesi>('/api/v1/protel/gelen/faturalar', {
    params: { ...parametreler(filtre, siralama), page: sayfa },
  })

  return yanit.data
}

export async function protelDurumuGetir(): Promise<SenkronDurumu> {
  const yanit = await api.get<{ data: SenkronDurumu }>('/api/v1/protel/durum')

  return yanit.data.data
}

export async function protelSenkronBaslat(aralik: {
  baslangic: string
  bitis: string
}): Promise<void> {
  await api.post('/api/v1/protel/senkron', aralik)
}

/** PDF yalnız Protel'de "yazdırıldı" görünen faturada açılır (sunucu da denetler). */
export async function protelPdfGetir(faturaId: number): Promise<FaturaPdfi> {
  const yanit = await api
    .get<Blob>(`/api/v1/protel/faturalar/${faturaId}/pdf`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  return { pdf: yanit.data, kaynak: 'entegrator' }
}

/** Tablonun o anki hâli (aynı filtre, sıralama, sayfa; görünen kolonlar) .xlsx olarak */
export async function protelExcelIndir(
  filtre: ProtelFaturaFiltresi,
  siralama: Siralama | null,
  sayfa: number,
  kolonlar: ExcelKolonu[],
): Promise<void> {
  const yanit = await api
    .get<Blob>('/api/v1/protel/gelen/faturalar/excel', {
      params: {
        ...parametreler(filtre, siralama),
        page: sayfa,
        kolonlar: kolonlar.map((kolon) => kolon.anahtar),
        basliklar: kolonlar.map((kolon) => kolon.baslik),
      },
      responseType: 'blob',
    })
    .catch(blobHatasiniCoz)

  dosyayiIndir(yanit.data, `protel-gelen-${filtre.baslangic}-${filtre.bitis}.xlsx`)
}
