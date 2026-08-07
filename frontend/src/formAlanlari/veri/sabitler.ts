/**
 * ERP'nin sabit değer tabloları (TUR tipli alanlar). View sorgusu yoktur;
 * değerler ERP'de sabittir. Bileşenler bunları yalnız GÖSTERİR — tanım burada
 * durur ki hem giriş bileşeni hem form varsayılanları aynı kaynağı kullansın.
 */

/** Teslimat biçimi — SOHOM_SIPARIS_KAYDET @TESLIMAT_BICIMI */
export const TESLIMAT_BICIMLERI = ['0', '1'] as const
export type TeslimatBicimi = (typeof TESLIMAT_BICIMLERI)[number]
export const VARSAYILAN_TESLIMAT_BICIMI: TeslimatBicimi = '0' // Tam

/** Alım yeri — @ALIM_YERI */
export const ALIM_YERLERI = ['0', '1', '2'] as const
export type AlimYeri = (typeof ALIM_YERLERI)[number]
export const VARSAYILAN_ALIM_YERI: AlimYeri = '0' // Merkez

/** Teslimat süresi birimi — @TESLIMAT_SURESI_BIRIMI */
export const TESLIMAT_SURESI_BIRIMLERI = ['3', '4', '5'] as const
export type TeslimatSuresiBirimi = (typeof TESLIMAT_SURESI_BIRIMLERI)[number]
export const BIRIM_GUN: TeslimatSuresiBirimi = '3'
export const BIRIM_HAFTA: TeslimatSuresiBirimi = '4'
export const BIRIM_AY: TeslimatSuresiBirimi = '5'
export const VARSAYILAN_BIRIM = BIRIM_GUN

/** İlgi cinsi — @ILGI_CINSI */
export const ILGI_CINSLERI = ['7', '8', '11', '12', '13'] as const
export type IlgiCinsi = (typeof ILGI_CINSLERI)[number]
/** Satır listeleri (aktivite, masraf merkezi) yalnız bu cinste projeye bağlanır */
export const ILGI_CINSI_PROJEMIZ: IlgiCinsi = '7'
export const VARSAYILAN_ILGI_CINSI: IlgiCinsi = ILGI_CINSI_PROJEMIZ

/**
 * Arama kaynağı (view sorgusu) tanımlanmış cinsler — diğerleri serbest metne
 * düşer. Sorgular eklendikçe genişler.
 */
export const ARAMALI_ILGI_CINSLERI: readonly IlgiCinsi[] = ['7', '8', '11', '12', '13']
