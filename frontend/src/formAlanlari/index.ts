/**
 * ERP form alanları modülünün DIŞ YÜZÜ. Sayfalar yalnız buradan import eder;
 * modülün içine (bilesenler/, veri/) doğrudan erişilmez.
 *
 * Klasör düzeni:
 *   AlanGirisi.tsx   → motor: tasarım kurallarını uygular (etiket, zorunluluk,
 *                      salt okunur, hata çevirisi)
 *   girisKaydi.tsx   → giris_tipi → bileşen eşlemesi
 *   ortakTipler.ts   → motor ile bileşenler arasındaki sözleşme
 *   bilesenler/      → ERP BİLEN alan bileşenleri (.tsx)
 *   veri/            → ERP uçları, sabit değer tabloları, saf mantık (.ts)
 */

export { AlanGirisi, girisTipiTanimliMi } from './AlanGirisi'
export type { GirisBaglami, GirisTanimi, OrtakGirisProps } from './ortakTipler'

// ERP alan bileşenleri — motor dışında da kullanılabilir (ör. tasarım
// editöründeki "varsayılan değer" seçicisi aynı bileşenleri gösterir)
export { AktiviteSecimi } from './bilesenler/AktiviteSecimi'
export { AlimYeriSecimi } from './bilesenler/AlimYeriSecimi'
export { AmbalajSecimi } from './bilesenler/AmbalajSecimi'
export { ButceBolumuSecimi } from './bilesenler/ButceBolumuSecimi'
export { ButceKalemiSecimi } from './bilesenler/ButceKalemiSecimi'
export { CokYuzluSecim, type KayitSecimProps, type KayitYuzleri } from './bilesenler/CokYuzluSecim'
export { DepoSecimi } from './bilesenler/DepoSecimi'
export { DuranVarlikSecimi } from './bilesenler/DuranVarlikSecimi'
export { EkipmanSecimi } from './bilesenler/EkipmanSecimi'
export { ParaSecimi } from './bilesenler/ParaSecimi'
export { PartiYamasiPersonelSecimi } from './bilesenler/PartiYamasiPersonelSecimi'
export { kurGetir, paraSecenekleriGetir, type ParaSecenegi } from './veri/secenekApi'
export { MasrafMerkeziSecimi } from './bilesenler/MasrafMerkeziSecimi'
export { UrunSecimi } from './bilesenler/UrunSecimi'
export { FirmamizAdresiSecimi } from './bilesenler/FirmamizAdresiSecimi'
export { IlgiliSecimi } from './bilesenler/IlgiliSecimi'
export { OncelikSecimi } from './bilesenler/OncelikSecimi'
export { PersonelSecimi } from './bilesenler/PersonelSecimi'
export { TabloMaddesiSecimi } from './bilesenler/TabloMaddesiSecimi'
export { TeslimatBicimiSecimi } from './bilesenler/TeslimatBicimiSecimi'
export { TeslimatSekliSecimi } from './bilesenler/TeslimatSekliSecimi'
export { TeslimatSuresiSecimi } from './bilesenler/TeslimatSuresiSecimi'

// ERP sabit değerleri — form varsayılanlarında da kullanılır
export {
  ALIM_YERLERI,
  ILGI_CINSI_PROJEMIZ,
  ILGI_CINSLERI,
  TESLIMAT_BICIMLERI,
  TESLIMAT_SURESI_BIRIMLERI,
  VARSAYILAN_ALIM_YERI,
  VARSAYILAN_BIRIM,
  VARSAYILAN_ILGI_CINSI,
  VARSAYILAN_TESLIMAT_BICIMI,
  type AlimYeri,
  type IlgiCinsi,
  type TeslimatBicimi,
  type TeslimatSuresiBirimi,
} from './veri/sabitler'
