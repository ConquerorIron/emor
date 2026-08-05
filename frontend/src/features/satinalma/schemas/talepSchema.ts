import { z } from 'zod'

/**
 * Satınalma Talebi doğrulaması — ilk adımda gevşek: yalnız Ürün kodu zorunlu
 * (ERP formunda * ile işaretli tek kolon). SOHOM_SIPARIS_KAYDET parametre
 * eşlemesi netleşince kurallar sıkılaşacak (tarih/sayı biçimleri vb.).
 */
export const talepSatiriSchema = z.object({
  projemiz: z.string(),
  aktivite_kodu: z.string(),
  aktivite_aciklamasi: z.string(),
  urun_kodu: z.string().min(1, 'satinalma.dogrulama.urunKoduZorunlu'),
  urun_adi: z.string(),
  urun_tarifi: z.string(),
  miktar: z.string(),
  kullanim_amaci: z.string(),
})

export const talepSchema = z.object({
  personel_adi: z.string(),
  no: z.string(),
  tarih: z.string(),
  termin: z.string(),
  oncelik: z.string(),
  aciklama: z.string(),
  ilgi_konusu: z.string(),
  projemiz: z.string(),

  depo_adi: z.string(),
  teslimat_adresi: z.string(),
  teslimat_bicimi: z.string(),
  teslimat_sekli: z.string(),
  teslimat_suresi_tarih: z.string(),
  teslimat_suresi_sure: z.string(),
  alim_yeri: z.string(),

  satirlar: z.array(talepSatiriSchema).min(1, 'satinalma.dogrulama.enAzBirSatir'),
})

export type TalepSatiri = z.infer<typeof talepSatiriSchema>
export type TalepGirdisi = z.infer<typeof talepSchema>

export const BOS_SATIR: TalepSatiri = {
  projemiz: '',
  aktivite_kodu: '',
  aktivite_aciklamasi: '',
  urun_kodu: '',
  urun_adi: '',
  urun_tarifi: '',
  miktar: '',
  kullanim_amaci: '',
}

export const BOS_TALEP: TalepGirdisi = {
  personel_adi: '',
  no: '',
  // ERP ekranındaki gibi bugünün tarihi ile açılır
  tarih: new Date().toLocaleDateString('tr-TR'),
  termin: '',
  oncelik: '',
  aciklama: '',
  ilgi_konusu: '',
  projemiz: '',
  depo_adi: '',
  teslimat_adresi: '',
  teslimat_bicimi: '',
  teslimat_sekli: '',
  teslimat_suresi_tarih: '',
  teslimat_suresi_sure: '',
  alim_yeri: '',
  satirlar: [{ ...BOS_SATIR }],
}
