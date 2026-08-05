import { useState } from 'react'

/**
 * Rapor tablolarının istemci taraflı kolon sıralaması (kullanıcı isteği
 * 2026-07-18): rapor verisi zaten bütünüyle ekranda olduğundan sunucuya
 * gitmeden başlığa tıklayarak sıralanır. Liste sayfalarındaki kalıcı/sunucu
 * sıralamasından (useKaliciSiralama) bilinçli olarak ayrıdır.
 */
export interface RaporSiralamasi {
  anahtar: string
  yon: 'asc' | 'desc'
}

export function useRaporSiralama(varsayilan: RaporSiralamasi | null = null) {
  const [siralama, setSiralama] = useState<RaporSiralamasi | null>(varsayilan)

  const degistir = (anahtar: string) => {
    setSiralama((onceki) =>
      onceki?.anahtar === anahtar
        ? { anahtar, yon: onceki.yon === 'asc' ? 'desc' : 'asc' }
        : { anahtar, yon: 'asc' },
    )
  }

  return { siralama, degistir }
}

/**
 * Sıralamayı uygular. Kolon anahtarı satırın alanı değilse (birleşik ad,
 * iç içe değer…) `degerler` ile özel okuyucu verilir. Sayılar sayısal,
 * metinler Türkçe harf sırasıyla karşılaştırılır.
 */
export function siralaUygula<T>(
  satirlar: T[],
  siralama: RaporSiralamasi | null,
  degerler?: Record<string, (satir: T) => string | number | null | undefined>,
): T[] {
  if (siralama === null) {
    return satirlar
  }

  const oku = (satir: T): string | number | null | undefined =>
    degerler?.[siralama.anahtar]
      ? degerler[siralama.anahtar](satir)
      : ((satir as Record<string, unknown>)[siralama.anahtar] as string | number | null | undefined)

  return [...satirlar].sort((a, b) => {
    const x = oku(a)
    const y = oku(b)
    const fark =
      typeof x === 'number' && typeof y === 'number'
        ? x - y
        : String(x ?? '').localeCompare(String(y ?? ''), 'tr')

    return siralama.yon === 'asc' ? fark : -fark
  })
}

interface SiralanabilirBaslikProps {
  anahtar: string
  etiket: string
  siralama: RaporSiralamasi | null
  degistir: (anahtar: string) => void
  /** Sayısal kolonlar için sağa yaslı başlık */
  sagaYasli?: boolean
  /** Başlığı yatay ortala (sagaYasli önceliklidir) */
  ortala?: boolean
  /** Koyu zeminli başlık satırı için — hover rengi açık kalır (metin görünmez olmasın) */
  koyu?: boolean
  className?: string
}

/** Tıklanabilir tablo başlığı — aktif kolonda yön oku gösterir. */
export function SiralanabilirBaslik({
  anahtar,
  etiket,
  siralama,
  degistir,
  sagaYasli = false,
  ortala = false,
  koyu = false,
  className = '',
}: SiralanabilirBaslikProps) {
  const aktif = siralama?.anahtar === anahtar
  const hizalama = sagaYasli
    ? 'justify-end text-right'
    : ortala
      ? 'justify-center text-center'
      : 'text-left'
  // Koyu zeminde (ör. Tatil Çalışma pivotu) hover metni açık kalmalı
  const hoverSinifi = koyu
    ? 'hover:text-slate-200'
    : 'hover:text-slate-900 dark:hover:text-slate-100'

  return (
    <th className={`px-0 py-0 font-semibold ${className}`}>
      <button
        type="button"
        onClick={() => degistir(anahtar)}
        className={`flex w-full cursor-pointer items-center gap-1 px-3 py-2 font-semibold select-none ${hoverSinifi} ${hizalama}`}
      >
        {etiket}
        <span aria-hidden="true" className={aktif ? '' : 'opacity-25'}>
          {aktif && siralama.yon === 'desc' ? '▼' : '▲'}
        </span>
      </button>
    </th>
  )
}
