import { z } from 'zod'

import { VARSAYILAN_ALIM_YERI } from '@/formAlanlari/AlimYeriSecimi'
import { VARSAYILAN_ILGI_CINSI } from '@/formAlanlari/ilgiCinsleri'
import { VARSAYILAN_TESLIMAT_BICIMI } from '@/formAlanlari/TeslimatBicimiSecimi'
import { VARSAYILAN_BIRIM } from '@/formAlanlari/teslimatSuresi'
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
  // ERP ACIKLAMA3072 tipi — proc'ta @HAKKINDA
  hakkinda: z.string().max(3072, 'satinalma.dogrulama.hakkindaUzun'),
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
  // Sabit değer: 0=Tam, 1=Parçalı — proc'ta @TESLIMAT_BICIMI
  teslimat_bicimi: z.string(),
  // Seçili şeklin TABLO_MADDESI_ID'si (TUR=53) — proc'ta @TESLIMAT_SEKLI_ID
  teslimat_sekli_id: z.string(),
  // Süre + birim birlikte saklanır; ekrandaki tarih bunlardan hesaplanan
  // gösterimdir — proc'ta @TESLIMAT_SURESI ve @TESLIMAT_SURESI_BIRIMI
  teslimat_suresi: z.string(),
  teslimat_suresi_birimi: z.string(),
  // Sabit değer: 0=Merkez, 1=Yerel, 2=İthalat — proc'ta @ALIM_YERI
  alim_yeri: z.string(),

  satirlar: z.array(talepSatiriSchema).min(1, 'satinalma.dogrulama.enAzBirSatir'),
})

export type TalepSatiri = z.infer<typeof talepSatiriSchema>
export type TalepGirdisi = z.infer<typeof talepSchema>

/**
 * Tasarımdaki "zorunlu" işaretlerini şemaya uygular. Temel tipler kodda
 * (yukarıdaki talepSchema), zorunluluk ise kullanıcının tasarımından gelir —
 * bu yüzden şema çalışma zamanında üretilir. Backend kayıt sırasında aynı
 * tasarımı okuyup tekrar doğrular (tarayıcı atlatılabilir).
 */
export function talepSemasiUret(zorunluAnahtarlar: string[]): typeof talepSchema {
  if (zorunluAnahtarlar.length === 0) {
    return talepSchema
  }

  const zorunlu = new Set(zorunluAnahtarlar)

  return talepSchema.superRefine((veri, ctx) => {
    for (const anahtar of zorunlu) {
      const deger = veri[anahtar as keyof TalepGirdisi]
      if (typeof deger === 'string' && deger.trim() === '') {
        ctx.addIssue({
          code: 'custom',
          path: [anahtar],
          message: 'satinalma.dogrulama.alanZorunlu',
        })
      }
    }
  }) as unknown as typeof talepSchema
}

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
  hakkinda: '',
  ilgi_cinsi: VARSAYILAN_ILGI_CINSI,
  ilgili_id: '',
  depomuz_id: '',
  teslimat_adresi_id: '',
  teslimat_adresi: '',
  // ERP formu "Tam" ile açılır
  teslimat_bicimi: VARSAYILAN_TESLIMAT_BICIMI,
  teslimat_sekli_id: '',
  teslimat_suresi: '',
  teslimat_suresi_birimi: VARSAYILAN_BIRIM,
  // ERP formu "Merkez" ile açılır
  alim_yeri: VARSAYILAN_ALIM_YERI,
  satirlar: [{ ...BOS_SATIR }],
}
