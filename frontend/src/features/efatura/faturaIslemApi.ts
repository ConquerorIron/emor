import { api } from '@/api/client'

import type { EFatura, EFaturaIslemi } from './efaturaApi'

/**
 * Faturanın açıldığı ekran: her ekranın kendi izni ve kapsamı vardır (sunucu
 * faturayı yalnız o ekranın kapsamında arar). İzibiz: `efatura`; birleşik
 * liste: `tum`; Protel ve statik kaynaklar: kendi önekleri.
 */
export type FaturaEkrani = 'efatura' | 'tum' | 'protel' | 'logo' | 'earsiv'

/** "İşlendi" (SAP) işareti olan ekranlar */
export type IslenebilirEkran = 'protel' | 'logo' | 'earsiv'

function faturaYolu(ekran: FaturaEkrani, faturaId: number): string {
  switch (ekran) {
    case 'tum':
      return `/api/v1/tum-faturalar/faturalar/${faturaId}`
    case 'efatura':
      return `/api/v1/efatura/faturalar/${faturaId}`
    default:
      return `/api/v1/${ekran}/faturalar/${faturaId}`
  }
}

/**
 * Yetkili kullanıcının faturayı RED'e çekmesi (neden zorunlu) ya da RED'i geri
 * alması. Sunucunun güncel faturasını döndürür; dış sisteme istek gitmez.
 */
export async function faturaRedDegistir(
  ekran: FaturaEkrani,
  faturaId: number,
  red: boolean,
  neden: string | null,
): Promise<EFatura> {
  const yanit = await api.put<{ data: EFatura }>(`${faturaYolu(ekran, faturaId)}/red`, {
    red,
    neden,
  })

  return yanit.data.data
}

/** "İşlendi" (SAP) işareti — yalnız eMOR'da tutulur. */
export async function faturaIslendiDegistir(
  ekran: IslenebilirEkran,
  faturaId: number,
  islendi: boolean,
): Promise<EFatura> {
  const yanit = await api.put<{ data: EFatura }>(`${faturaYolu(ekran, faturaId)}/islendi`, {
    islendi,
  })

  return yanit.data.data
}

/** Faturanın elle işlemleri (RED, İşlendi, gizleme), en yeni üstte. */
export async function faturaGecmisiGetir(
  ekran: FaturaEkrani,
  faturaId: number,
): Promise<EFaturaIslemi[]> {
  const yanit = await api.get<{ data: EFaturaIslemi[] }>(`${faturaYolu(ekran, faturaId)}/gecmis`)

  return yanit.data.data
}
