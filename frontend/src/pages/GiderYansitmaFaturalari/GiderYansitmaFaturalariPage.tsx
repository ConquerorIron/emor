import { ErpRaporSayfasi } from '@/features/rapor/ErpRaporSayfasi'
import { paraKodunaGoreTopla, topla, type ErpRaporTanimi } from '@/features/rapor/raporTanimi'

import {
  DOVIZ_TUTARLAR,
  TL_TUTARLAR,
  giderYansitmaFaturalariApi,
  type GiderYansitmaFaturasi,
} from './giderYansitmaFaturalariApi'

/**
 * Raporlar → Gider Yansıtma Faturaları (kullanıcı kararları 2026-11-03):
 * proc'un 22 kolonunun hepsi, proc sırasıyla; Para Kodu Döviz kolonlarının
 * hemen önünde. Toplamlar: TL kolonlarının tek genel toplamı + para koduna
 * göre Döviz kolonları.
 */
const GIDER_YANSITMA_FATURALARI: ErpRaporTanimi<GiderYansitmaFaturasi> = {
  anahtar: 'giderYansitmaFaturalari',
  api: giderYansitmaFaturalariApi,
  kolonlar: [
    { alan: 'tarih', tur: 'tarih' },
    { alan: 'fatura_no', tur: 'metin' },
    { alan: 'unvan', tur: 'metin' },
    { alan: 'proje_kodu', tur: 'metin' },
    { alan: 'aciklama', tur: 'metin' },
    ...TL_TUTARLAR.map((alan) => ({ alan, tur: 'tutar' as const })),
    { alan: 'para_kodu', tur: 'metin' },
    ...DOVIZ_TUTARLAR.map((alan) => ({ alan, tur: 'tutar' as const })),
    { alan: 'kayit_eden', tur: 'metin' },
    { alan: 'guncelleyen', tur: 'metin' },
  ],
  satirAnahtari: (satir) => satir.fatura_no,
  // Metin kolonlarının hepsi
  aramaAlanlari: ['fatura_no', 'unvan', 'proje_kodu', 'aciklama', 'kayit_eden', 'guncelleyen'],
  projeAlani: 'proje_kodu',
  firmaAlani: 'unvan',
  // Canlıda ünvan 126, açıklama 154, proje kodu 49 karaktere kadar
  sabitGenislik: { unvan: 'w-112', aciklama: 'w-112', proje_kodu: 'w-40' },
  ortali: ['fatura_no', 'para_kodu'],
  toplamlar: (satirlar) =>
    satirlar.length === 0
      ? []
      : [
          {
            anahtar: 'genel-tl',
            etiket: 'raporlar.genelToplamTl',
            degerler: topla(satirlar, TL_TUTARLAR),
          },
          ...paraKodunaGoreTopla(satirlar, DOVIZ_TUTARLAR),
        ],
  // Kesinti ekranlarının özet sekmeleri gibi, taşeron = ünvan (backend PIVOTLAR ile aynı)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['unvan', 'proje_kodu', 'para_kodu'] },
    { anahtar: 'proje', gruplar: ['proje_kodu', 'para_kodu'] },
    { anahtar: 'tarih', gruplar: ['yil', 'ay', 'proje_kodu', 'para_kodu'] },
  ],
  tarihAlani: 'tarih',
}

export function GiderYansitmaFaturalariPage() {
  return <ErpRaporSayfasi tanim={GIDER_YANSITMA_FATURALARI} />
}
