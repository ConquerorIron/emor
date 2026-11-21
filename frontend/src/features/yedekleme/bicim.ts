import { isAxiosError } from 'axios'

import { sayfaBoyutuOku } from '@/utils/sayfaBoyutu'

/**
 * Yedekleme uçlarının sayfa boyutu 10–100'dür; ortak tercih 200 ya da "Hepsi"
 * (0) olabilir — uca sınır içinde bir değer gider (aksi hâlde 422).
 */
export function yedeklemeSayfaBoyutu(): number {
  const boyut = sayfaBoyutuOku()

  return boyut === 0 ? 100 : Math.min(100, Math.max(10, boyut))
}

const BIRIMLER = ['B', 'KB', 'MB', 'GB', 'TB'] as const

/** Bayt → "1,6 MB" (1024 tabanı; Türkçe ondalık virgül). Boşta '—'. */
export function boyutGoster(bayt: number | null | undefined): string {
  if (bayt === null || bayt === undefined || !Number.isFinite(bayt) || bayt < 0) {
    return '—'
  }

  let deger = bayt
  let birim = 0
  while (deger >= 1024 && birim < BIRIMLER.length - 1) {
    deger /= 1024
    birim += 1
  }

  const metin =
    birim === 0
      ? String(deger)
      : deger.toLocaleString('tr-TR', { maximumFractionDigits: 1, minimumFractionDigits: 0 })

  return `${metin} ${BIRIMLER[birim]}`
}

/** Saatler İstanbul saatiyle tanımlanır ve gösterilir (README §4.2, §7.8). */
const ISTANBUL = 'Europe/Istanbul'

const ZAMAN_BICIMI = new Intl.DateTimeFormat('tr-TR', {
  timeZone: ISTANBUL,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

const SANIYELI_BICIM = new Intl.DateTimeFormat('tr-TR', {
  timeZone: ISTANBUL,
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hourCycle: 'h23',
})

/** ISO zaman → "21.11.2026 02:00" (İstanbul saati); boşta '—', geçersizde girdinin kendisi */
export function istanbulZamani(
  iso: string | null | undefined,
  { saniye = false }: { saniye?: boolean } = {},
): string {
  if (!iso) {
    return '—'
  }

  const zaman = new Date(iso)
  if (Number.isNaN(zaman.getTime())) {
    return iso
  }

  return (saniye ? SANIYELI_BICIM : ZAMAN_BICIMI).format(zaman).replace(',', '')
}

/** Açık anahtar parmak izi dörderli gruplarla: "ABCD 1234 …" */
export function parmakIziGoster(parmakIzi: string): string {
  return (
    parmakIzi
      .replace(/\s+/g, '')
      .toUpperCase()
      .match(/.{1,4}/g)
      ?.join(' ') ?? ''
  )
}

/** Milisaniye → "850 ms" / "1,2 sn" / "3 dk 5 sn" */
export function sureGoster(ms: number | null | undefined): string {
  if (ms === null || ms === undefined || !Number.isFinite(ms)) {
    return '—'
  }
  if (ms < 1000) {
    return `${Math.round(ms)} ms`
  }
  const saniye = ms / 1000
  if (saniye < 60) {
    return `${saniye.toLocaleString('tr-TR', { maximumFractionDigits: 1 })} sn`
  }
  const dakika = Math.floor(saniye / 60)

  return `${dakika} dk ${Math.round(saniye % 60)} sn`
}

/**
 * Form alanı hatasının metni: istemci doğrulaması i18n anahtarı
 * (`yedekleme.…`) yazar, sunucunun 422 mesajı zaten yerelleşmiştir — sunucu
 * metni `t()`'ye verilmez (içindeki ':' / '.' anahtar ayırıcısı sanılır).
 */
export function alanHataMetni(
  t: (anahtar: string) => string,
  mesaj: string | undefined,
): string | undefined {
  if (mesaj === undefined || mesaj === '') {
    return undefined
  }

  return mesaj.startsWith('yedekleme.') ? t(mesaj) : mesaj
}

/** Hata yanıtının gövdesi (ör. 409'un `plan_sayisi`, `hedefler` alanları); yoksa null */
export function hataGovdesi<T>(error: unknown): (T & { kod?: string }) | null {
  if (isAxiosError(error) && error.response?.data && typeof error.response.data === 'object') {
    return error.response.data as T & { kod?: string }
  }

  return null
}

/**
 * 422 doğrulama hatalarının tamamı (alan → mesajlar). Sunucu mesajları zaten
 * yerelleşmiştir; alanlara dağıtılır.
 */
export function dogrulamaHatalari(error: unknown): Record<string, string[]> {
  if (isAxiosError(error) && error.response?.status === 422) {
    const govde = error.response.data as { hatalar?: Record<string, string[]> } | undefined

    return govde?.hatalar ?? {}
  }

  return {}
}
