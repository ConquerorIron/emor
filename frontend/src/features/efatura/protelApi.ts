import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

import type {
  EFatura,
  EFaturaListesi,
  ErpOkunduFiltresi,
  ExcelKolonu,
  FaturaPdfi,
  SenkronDurumu,
  Siralama,
} from './efaturaApi'

/**
 * Protel Gelen Faturalar (PRT-12) — otelin posta kutusu. Sunucu e-Fatura
 * uçlarını Protel tanımıyla çalıştırır: yalnız gelen, XML / gizleme / eMOR yok;
 * kendine özgü tek yazma "İşlendi" işaretidir (eMOR'da).
 */
export interface ProtelFaturaFiltresi {
  baslangic: string
  bitis: string
  ara: string
  durum: string
  /** Otelin ERP'si aldı mı (Protel `isErpRecieved`) */
  otel_erp_okundu: ErpOkunduFiltresi
  para_birimi: string
  tip: string
}

function parametreler(
  filtre: ProtelFaturaFiltresi,
  siralama: Siralama | null,
): Record<string, string> {
  const sonuc: Record<string, string> = { baslangic: filtre.baslangic, bitis: filtre.bitis }
  for (const anahtar of ['ara', 'durum', 'otel_erp_okundu', 'para_birimi', 'tip'] as const) {
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

/** PDF anlık okunur; istek yalnız izinli alanları taşır (sunucu kilidi). */
export async function protelPdfGetir(faturaId: number): Promise<FaturaPdfi> {
  const yanit = await api
    .get<Blob>(`/api/v1/protel/faturalar/${faturaId}/pdf`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  return { pdf: yanit.data, kaynak: 'entegrator' }
}

/**
 * "İşlendi" işareti — yalnız eMOR'da tutulur (Tüm Faturalar → SAP kolonu);
 * Protel'e istek gitmez. Sunucunun güncel faturasını döndürür.
 */
export async function protelIslendiDegistir(faturaId: number, islendi: boolean): Promise<EFatura> {
  const yanit = await api.put<{ data: EFatura }>(`/api/v1/protel/faturalar/${faturaId}/islendi`, {
    islendi,
  })

  return yanit.data.data
}

/** Tablonun o anki hâli (aynı filtre, sıralama, sayfa; görünen kolonlar) .xlsx olarak */
export async function protelExcelIndir(
  filtre: ProtelFaturaFiltresi,
  siralama: Siralama | null,
  sayfa: number,
  kolonlar: ExcelKolonu[],
  /** Ekrandaki listenin ortamı — sunucuda aktif ortam farklıysa indirme reddedilir */
  ortam: string,
): Promise<void> {
  const yanit = await api
    .get<Blob>('/api/v1/protel/gelen/faturalar/excel', {
      params: {
        ...parametreler(filtre, siralama),
        page: sayfa,
        ortam,
        kolonlar: kolonlar.map((kolon) => kolon.anahtar),
        basliklar: kolonlar.map((kolon) => kolon.baslik),
      },
      responseType: 'blob',
    })
    .catch(blobHatasiniCoz)

  dosyayiIndir(yanit.data, `protel-gelen-${filtre.baslangic}-${filtre.bitis}.xlsx`)
}
