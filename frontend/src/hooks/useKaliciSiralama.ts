import { useState } from 'react'

import type { TabloSiralamasi } from '@/components/DataTable'

function oku(depoAnahtari: string): TabloSiralamasi | null {
  try {
    const ham = localStorage.getItem(depoAnahtari)
    if (!ham) {
      return null
    }

    const deger = JSON.parse(ham) as Partial<TabloSiralamasi>
    if (typeof deger.anahtar === 'string' && (deger.yon === 'asc' || deger.yon === 'desc')) {
      return { anahtar: deger.anahtar, yon: deger.yon }
    }

    return null
  } catch {
    return null
  }
}

/**
 * Liste sıralama tercihi localStorage'da sayfa bazında saklanır (kullanıcı notu
 * 2026-07-07): kullanıcı en son neye göre sıraladıysa sayfa tekrar açıldığında
 * aynı sıralamayla gelir. Geçersiz/bozuk kayıt sessizce yok sayılır — backend
 * whitelist'i zaten bilinmeyen kolonu varsayılan sıralamaya düşürür.
 */
export function useKaliciSiralama(sayfaAnahtari: string) {
  const depoAnahtari = `puantaj.siralama.${sayfaAnahtari}`
  const [siralama, setSiralama] = useState<TabloSiralamasi | null>(() => oku(depoAnahtari))

  const siralamaDegistir = (anahtar: string) => {
    setSiralama((onceki) => {
      const yeni: TabloSiralamasi =
        onceki?.anahtar === anahtar
          ? { anahtar, yon: onceki.yon === 'asc' ? 'desc' : 'asc' }
          : { anahtar, yon: 'asc' }

      try {
        localStorage.setItem(depoAnahtari, JSON.stringify(yeni))
      } catch {
        // depolama dolu/kapalıysa tercih yalnızca oturum içinde yaşar
      }

      return yeni
    })
  }

  return { siralama, siralamaDegistir }
}
