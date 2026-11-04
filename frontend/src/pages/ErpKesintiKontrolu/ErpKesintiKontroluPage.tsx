import { ErpRaporSayfasi } from '@/features/rapor/ErpRaporSayfasi'
import { paraKodunaGoreTopla, type ErpRaporTanimi } from '@/features/rapor/raporTanimi'

import { TUTARLAR, erpKesintiKontroluApi, type KesintiKontrolSatiri } from './erpKesintiKontroluApi'

/** Kuruş düzeyinde fark (ondalık hesap artıkları fark sayılmaz) */
const farkli = (a: number, b: number) => Math.round(a * 100) !== Math.round(b * 100)

/**
 * Raporlar → ERP Kesinti Kontrolü (kullanıcı isteği 2026-11-04): kesinti
 * icmalleri Taşeron + Proje + Para Birimi bazında, sağında ERP'deki hakediş
 * yansıtılacak gideri ve gider yansıtma faturası toplamı. Satırlar zaten
 * özet olduğu için özet sekmesi yok; toplamlar para birimine göre.
 */
const ERP_KESINTI_KONTROLU: ErpRaporTanimi<KesintiKontrolSatiri> = {
  anahtar: 'erpKesintiKontrolu',
  api: erpKesintiKontroluApi,
  kolonlar: [
    { alan: 'taseron', tur: 'metin' },
    { alan: 'proje', tur: 'metin' },
    { alan: 'para_kodu', tur: 'metin' },
    ...TUTARLAR.map((alan) => ({ alan, tur: 'tutar' as const })),
  ],
  satirAnahtari: (satir) => satir.anahtar,
  aramaAlanlari: ['taseron', 'proje'],
  projeAlani: 'proje',
  firmaAlani: 'taseron',
  // Tam ticari unvanlar uzun: diğer raporlardaki firma kolonu genişliği
  sabitGenislik: { taseron: 'w-112' },
  ortali: ['para_kodu'],
  toplamlar: (satirlar) => paraKodunaGoreTopla(satirlar, TUTARLAR),
  pivotlar: [],
  // Kontrol switch'leri (kullanıcı isteği 2026-11-04)
  kosulSuzgecleri: [
    {
      anahtar: 'hakedisFaturaFarkli',
      kosul: (s) => farkli(s.hakedis_yansitilacak_gider, s.yansitma_faturasi),
    },
    {
      anahtar: 'yansitilanFaturaFarkli',
      kosul: (s) => farkli(s.yansitilan_tutar, s.yansitma_faturasi),
    },
  ],
}

export function ErpKesintiKontroluPage() {
  return <ErpRaporSayfasi tanim={ERP_KESINTI_KONTROLU} />
}
