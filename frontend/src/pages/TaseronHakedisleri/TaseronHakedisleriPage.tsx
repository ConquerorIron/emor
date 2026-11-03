import { ErpRaporSayfasi } from '@/features/rapor/ErpRaporSayfasi'
import { paraKodunaGoreTopla, type ErpRaporTanimi } from '@/features/rapor/raporTanimi'

import { TUTARLAR, taseronHakedisleriApi, type HakedisSatiri } from './taseronHakedisleriApi'

/**
 * Raporlar → Taşeron Hakedişleri (kullanıcı isteği 2026-11-03): ERP proc'unun
 * hakedişleri, hakediş başına bir satır, kolonlar kullanıcının verdiği
 * sırayla; para koduna göre toplamlarıyla. Proc ağır (tüm tarihlerde ~9 sn).
 */
const TASERON_HAKEDISLERI: ErpRaporTanimi<HakedisSatiri> = {
  anahtar: 'taseronHakedisleri',
  api: taseronHakedisleriApi,
  kolonlar: [
    { alan: 'firma_adi', tur: 'metin' },
    { alan: 'proje_adi', tur: 'metin' },
    { alan: 'sozlesme_no', tur: 'metin' },
    { alan: 'sozlesme_konusu', tur: 'metin' },
    { alan: 'hakedis_no', tur: 'metin' },
    { alan: 'hakedis_tarihi', tur: 'tarih' },
    { alan: 'donem_baslangici', tur: 'tarih' },
    { alan: 'donem_sonu', tur: 'tarih' },
    { alan: 'para_kodu', tur: 'metin' },
    { alan: 'imalat', tur: 'tutar' },
    { alan: 'tutanak', tur: 'tutar' },
    { alan: 'hakedis', tur: 'tutar' },
    { alan: 'imalat_kesintisi', tur: 'tutar' },
    { alan: 'kdv', tur: 'tutar' },
    { alan: 'kdvli_hakedis', tur: 'tutar' },
    { alan: 'kdv_tevkifati', tur: 'tutar' },
    { alan: 'cari_kesinti', tur: 'tutar' },
    { alan: 'yansitilacak_gider', tur: 'tutar' },
    { alan: 'taseron_adina_yapilan_is', tur: 'tutar' },
    { alan: 'net_odenecek', tur: 'tutar' },
    { alan: 'onay_durumu', tur: 'metin' },
    { alan: 'onaylayan', tur: 'metin' },
    { alan: 'onay_tarihi', tur: 'tarih' },
    { alan: 'onay_saati', tur: 'metin' },
    { alan: 'fatura_no', tur: 'metin' },
    { alan: 'hakedis_durumu', tur: 'metin' },
    { alan: 'odeme_talebi_tutari', tur: 'tutar' },
    { alan: 'odenen_tutar', tur: 'tutar' },
  ],
  satirAnahtari: (satir) => String(satir.hakedis_id),
  // Kullanıcı isteği 2026-11-03
  aramaAlanlari: [
    'firma_adi',
    'sozlesme_konusu',
    'proje_adi',
    'sozlesme_no',
    'fatura_no',
    'hakedis_durumu',
    'onay_durumu',
    'onaylayan',
  ],
  projeAlani: 'proje_adi',
  firmaAlani: 'firma_adi',
  // Kullanıcı istekleri 2026-11-03: firma / sözleşme konusu ~130 karaktere,
  // proje adı 29'a, hakediş no 16'ya kadar çıkıyor
  sabitGenislik: {
    firma_adi: 'w-112',
    sozlesme_konusu: 'w-112',
    proje_adi: 'w-32',
    hakedis_no: 'w-20',
  },
  ortali: ['hakedis_no', 'para_kodu', 'onay_saati'],
  toplamlar: (satirlar) => paraKodunaGoreTopla(satirlar, TUTARLAR),
  // Kesinti ekranlarının özet sekmeleri gibi (backend PIVOTLAR ile aynı)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['firma_adi', 'proje_adi', 'para_kodu'] },
    { anahtar: 'proje', gruplar: ['proje_adi', 'para_kodu'] },
    { anahtar: 'tarih', gruplar: ['yil', 'ay', 'proje_adi', 'para_kodu'] },
  ],
  tarihAlani: 'hakedis_tarihi',
}

export function TaseronHakedisleriPage() {
  return <ErpRaporSayfasi tanim={TASERON_HAKEDISLERI} />
}
