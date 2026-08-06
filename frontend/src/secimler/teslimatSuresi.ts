import { ayEkle, gunEkle, gunFarki } from '@/utils/tarih'

/**
 * Teslimat süresi birimi — SOHOM_SIPARIS_KAYDET @TESLIMAT_SURESI_BIRIMI
 * (ERP sabit değerleri, kullanıcı tanımı 2026-08-06).
 */
export const TESLIMAT_SURESI_BIRIMLERI = ['3', '4', '5'] as const

export type TeslimatSuresiBirimi = (typeof TESLIMAT_SURESI_BIRIMLERI)[number]

export const BIRIM_GUN: TeslimatSuresiBirimi = '3'
export const BIRIM_HAFTA: TeslimatSuresiBirimi = '4'
export const BIRIM_AY: TeslimatSuresiBirimi = '5'

export const VARSAYILAN_BIRIM = BIRIM_GUN

/**
 * Süre + birimi tarihe çevirir (temel tarih = talebin Tarih alanı).
 * Eksik/geçersiz girdide '' döner.
 */
export function sureyiTariheCevir(
  temelIso: string,
  sure: string,
  birim: TeslimatSuresiBirimi,
): string {
  const sayi = Number(sure)
  if (temelIso === '' || sure === '' || !Number.isFinite(sayi) || sayi < 0) {
    return ''
  }

  if (birim === BIRIM_AY) {
    return ayEkle(temelIso, sayi)
  }

  return gunEkle(temelIso, birim === BIRIM_HAFTA ? sayi * 7 : sayi)
}

/**
 * Tarihi süre + birime çevirir (ters yön). Kullanıcının seçtiği birim tam
 * bölüyorsa korunur (ör. hafta seçiliyken 14 gün → 2 hafta); bölmüyorsa
 * kayıpsız olsun diye güne düşülür.
 */
export function tarihiSureyeCevir(
  temelIso: string,
  hedefIso: string,
  mevcutBirim: TeslimatSuresiBirimi,
): { sure: string; birim: TeslimatSuresiBirimi } {
  if (temelIso === '' || hedefIso === '') {
    return { sure: '', birim: mevcutBirim }
  }

  const fark = gunFarki(temelIso, hedefIso)
  if (fark === null || fark < 0) {
    return { sure: '', birim: mevcutBirim }
  }

  if (mevcutBirim === BIRIM_AY) {
    // Takvim ayı sabit uzunlukta değil: hedefe birebir oturan ay sayısı aranır
    for (let ay = 1; ay <= 120; ay += 1) {
      const aday = ayEkle(temelIso, ay)
      if (aday === hedefIso) {
        return { sure: String(ay), birim: BIRIM_AY }
      }
      if (aday > hedefIso) {
        break
      }
    }
  }

  if (mevcutBirim === BIRIM_HAFTA && fark % 7 === 0) {
    return { sure: String(fark / 7), birim: BIRIM_HAFTA }
  }

  return { sure: String(fark), birim: BIRIM_GUN }
}
