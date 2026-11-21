/**
 * Üstüne geri yüklemenin erişim anahtarı bu tarayıcıda saklanır: tarayıcı
 * kapanıp açılınca işlem kaldığı yerden izlenir (BY-030). Aynı anda tek
 * üstüne geri yükleme olabilir; tek kayıt tutulur. Anahtar 8 saat geçerlidir,
 * işlem bitince silinir. Depo erişilemezse (gizli pencere, kota) anahtar yalnız
 * bu çizimde yaşar; o durumda acil durum komutu ekranda anlatılır.
 */
export interface KayitliUstuneIslem {
  islem_kimligi: string
  erisim_anahtari: string
  erisim_anahtari_bitis: string
  /** Son onay / geri dön isteği (yoklamada `?istek=`) */
  karar_istegi: string | null
}

const ANAHTAR = 'erp.yedekleme.ustuneGeriYukleme.v1'

/** İlerleme sayfası: oturum ve menü korumasının dışındadır (router) */
export function ustuneIlerlemeAdresi(islemKimligi: string): string {
  return `/ayarlar/geri-yuklemeler/ustune/${islemKimligi}`
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const ERISIM_ANAHTARI = /^[0-9a-f]{64}$/i

export function kayitliUstuneIslem(): KayitliUstuneIslem | null {
  try {
    const ham = localStorage.getItem(ANAHTAR)
    if (ham === null) {
      return null
    }
    const kayit = JSON.parse(ham) as Partial<KayitliUstuneIslem>
    if (
      typeof kayit.islem_kimligi !== 'string' ||
      !UUID.test(kayit.islem_kimligi) ||
      typeof kayit.erisim_anahtari !== 'string' ||
      !ERISIM_ANAHTARI.test(kayit.erisim_anahtari) ||
      typeof kayit.erisim_anahtari_bitis !== 'string'
    ) {
      return null
    }

    return {
      islem_kimligi: kayit.islem_kimligi,
      erisim_anahtari: kayit.erisim_anahtari,
      erisim_anahtari_bitis: kayit.erisim_anahtari_bitis,
      karar_istegi:
        typeof kayit.karar_istegi === 'string' && UUID.test(kayit.karar_istegi)
          ? kayit.karar_istegi
          : null,
    }
  } catch {
    return null
  }
}

export function ustuneIslemiKaydet(kayit: KayitliUstuneIslem): void {
  try {
    localStorage.setItem(ANAHTAR, JSON.stringify(kayit))
  } catch {
    // depo kapalı: anahtar yalnız bu çizimde yaşar
  }
}

/** İşlem bitince anahtar silinir (yalnız o işlemin kaydıysa) */
export function ustuneIslemiUnut(islemKimligi: string): void {
  try {
    if (kayitliUstuneIslem()?.islem_kimligi === islemKimligi) {
      localStorage.removeItem(ANAHTAR)
    }
  } catch {
    // depo kapalı
  }
}
