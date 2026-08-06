import { z } from 'zod'

import { VARSAYILAN_ILGI_CINSI } from '@/features/satinalma/ilgiCinsleri'
import { bugunIso } from '@/utils/tarih'

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
  // Seçilen personelin ERP PERSONEL_ID'si (VOHOM_ARAMA_PERSONEL KAYIT_ID).
  // Proc çağrısında @PARTI_YAMASI_ID'ye eşlenir — form tarafında adı hep
  // personel_id kalır (parti_yamasi_id proc'larda genel "taraf" kavramıdır).
  personel_id: z.string(),
  personel_adi: z.string(),
  no: z.string(),
  tarih: z.string(),
  // Opsiyonel: anahtar açılmadan seçilemez — proc'ta @OPSIYON_TARIHI
  termin: z.string(),
  // Seçili önceliğin TABLO_MADDESI_ID'si — proc'ta @ONCELIK_ID
  oncelik_id: z.string(),
  // ERP ACIKLAMA200 tipi — proc'ta @ACIKLAMA; giriş bileşeni de kırpar
  aciklama: z.string().max(200, 'satinalma.dogrulama.aciklamaUzun'),
  // İlgi konusu türü (7=Projemiz, 8=Uygulama Söz., 11=Arızalı Yedek Parça,
  // 12=İş Paketi, 13=Satınalma Söz.) — proc'ta @ILGI_CINSI
  ilgi_cinsi: z.string(),
  // Seçilen ilgi cinsine göre ilişkili kaydın ID'si — proc'ta @ILGILI_ID
  // (şimdilik serbest metin; cins bazlı view aramaları eklenecek)
  ilgili_id: z.string(),

  // Seçili deponun KAYIT_ID'si (parti yaması TUR=12) — sipariş SATIRLARININ
  // DEPOMUZ_ID alanına yazılır (başlık tablosunda depo yoktur)
  depomuz_id: z.string(),
  // Seçili adresin ADRES_ID'si — proc'ta @TESLIMAT_ADRESI_ID
  teslimat_adresi_id: z.string(),
  // Adres metni (seçimle dolar) — proc'ta @TESLIMAT_ADRESI (ACIKLAMA200)
  teslimat_adresi: z.string().max(200, 'satinalma.dogrulama.adresUzun'),
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
  personel_id: '',
  personel_adi: '',
  // Talep No'yu kayıt sırasında ERP üretir (SOHOM_NUMERATOR_URET 47 →
  // @SIPARIS_NO OUTPUT); kullanıcı yazamaz
  no: '',
  // ERP ekranındaki gibi bugünün tarihi ile açılır (ISO — TarihInput sözleşmesi)
  tarih: bugunIso(),
  termin: '',
  oncelik_id: '',
  aciklama: '',
  ilgi_cinsi: VARSAYILAN_ILGI_CINSI,
  ilgili_id: '',
  depomuz_id: '',
  teslimat_adresi_id: '',
  teslimat_adresi: '',
  teslimat_bicimi: '',
  teslimat_sekli: '',
  teslimat_suresi_tarih: '',
  teslimat_suresi_sure: '',
  alim_yeri: '',
  satirlar: [{ ...BOS_SATIR }],
}
