import { ErpRaporSayfasi } from '@/features/rapor/ErpRaporSayfasi'
import { paraKodunaGoreTopla, topla, type ErpRaporTanimi } from '@/features/rapor/raporTanimi'

import {
  ORIJINAL_TUTARLAR,
  TL_TUTARLAR,
  gelenFaturaRaporuApi,
  type GelenFaturaSatiri,
} from './gelenFaturaRaporuApi'

const tutar = (alan: (typeof ORIJINAL_TUTARLAR)[number] | (typeof TL_TUTARLAR)[number]) => ({
  alan,
  tur: 'tutar' as const,
})

/**
 * Raporlar → Gelen Fatura Raporu (kullanıcı isteği 2026-11-26): bütün gelen
 * faturalar Logo'nun gelen fatura Excel'inin kolonlarıyla (Hareket No ve
 * Kesinti yok). Satırlar ERP'den değil uygulamanın tablolarından: proje / firma
 * parametre seçicileri yok, "proje" süzgeci entegratördür. Toplamlar: TL genel
 * toplamı + döviz cinsine göre orijinal tutarlar.
 */
const GELEN_FATURA_RAPORU: ErpRaporTanimi<GelenFaturaSatiri> = {
  anahtar: 'gelenFaturaRaporu',
  api: gelenFaturaRaporuApi,
  kolonlar: [
    { alan: 'fatura_tarihi', tur: 'tarih' },
    { alan: 'fatura_no', tur: 'metin' },
    { alan: 'fatura_profili', tur: 'metin' },
    { alan: 'entegrator', tur: 'metin' },
    { alan: 'firma_unvani', tur: 'metin' },
    { alan: 'vkn', tur: 'metin' },
    { alan: 'vergi_dairesi', tur: 'metin' },
    { alan: 'para_kodu', tur: 'metin' },
    tutar('orijinal_tutar'),
    tutar('orijinal_indirim'),
    tutar('net_tutar'),
    tutar('orijinal_kdv'),
    tutar('fatura_toplami'),
    tutar('orijinal_tevkifat'),
    tutar('net_kdv'),
    { alan: 'kur', tur: 'metin' },
    ...TL_TUTARLAR.map(tutar),
    { alan: 'oran_0', tur: 'metin' },
    tutar('kdv_0'),
    tutar('matrah_0'),
    { alan: 'oran_1', tur: 'metin' },
    tutar('kdv_1'),
    tutar('matrah_1'),
    { alan: 'oran_10', tur: 'metin' },
    tutar('kdv_10'),
    tutar('matrah_10'),
    { alan: 'oran_20', tur: 'metin' },
    tutar('kdv_20'),
    tutar('matrah_20'),
    tutar('matrah_toplami'),
    { alan: 'istisna_kodu', tur: 'metin' },
    { alan: 'istisna_adi', tur: 'metin' },
    { alan: 'tevkifat_kodu', tur: 'metin' },
    { alan: 'tevkifat_aciklama', tur: 'metin' },
  ],
  satirAnahtari: (satir) => String(satir.id),
  aramaAlanlari: [
    'fatura_no',
    'firma_unvani',
    'vkn',
    'vergi_dairesi',
    'istisna_kodu',
    'istisna_adi',
    'tevkifat_kodu',
    'tevkifat_aciklama',
  ],
  projeAlani: 'entegrator',
  projeSuzgeciEtiketi: 'raporlar.entegratoreGoreFiltrele',
  firmaAlani: 'firma_unvani',
  erpParametreleri: false,
  sabitGenislik: { firma_unvani: 'w-112', istisna_adi: 'w-64', tevkifat_aciklama: 'w-64' },
  ortali: ['fatura_no', 'para_kodu', 'kur', 'oran_0', 'oran_1', 'oran_10', 'oran_20'],
  toplamlar: (satirlar) =>
    satirlar.length === 0
      ? []
      : [
          {
            anahtar: 'genel-tl',
            etiket: 'raporlar.genelToplamTl',
            degerler: topla(satirlar, TL_TUTARLAR),
          },
          ...paraKodunaGoreTopla(satirlar, ORIJINAL_TUTARLAR),
        ],
  // Backend GelenFaturaRaporu::PIVOTLAR ile aynı
  pivotlar: [
    { anahtar: 'firma', gruplar: ['firma_unvani', 'para_kodu'] },
    { anahtar: 'entegrator', gruplar: ['entegrator', 'para_kodu'] },
    { anahtar: 'tarih', gruplar: ['yil', 'ay', 'para_kodu'] },
  ],
  tarihAlani: 'fatura_tarihi',
  kosulSuzgecleri: [{ anahtar: 'redHaric', kosul: (satir) => satir.red === 'hayir' }],
}

export function GelenFaturaRaporuPage() {
  return <ErpRaporSayfasi tanim={GELEN_FATURA_RAPORU} />
}
