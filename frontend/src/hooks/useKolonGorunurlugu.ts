import { useState } from 'react'

function oku(depoAnahtari: string): Set<string> {
  try {
    const ham = localStorage.getItem(depoAnahtari)
    const deger: unknown = ham ? JSON.parse(ham) : []

    return new Set(
      Array.isArray(deger) ? deger.filter((x): x is string => typeof x === 'string') : [],
    )
  } catch {
    return new Set()
  }
}

function yaz(depoAnahtari: string, gizliler: Set<string>): void {
  try {
    localStorage.setItem(depoAnahtari, JSON.stringify([...gizliler]))
  } catch {
    // Depolama kapalı/doluysa tercih yalnız oturum içinde yaşar
  }
}

/**
 * Tablo kolonlarının göster/gizle tercihi — tarayıcıda (localStorage) sayfa
 * bazında saklanır (kullanıcı isteği 2026-09-24). GİZLENEN kolonlar tutulur:
 * ileride eklenen yeni kolon varsayılan olarak açık gelir; bozuk kayıt yok sayılır.
 */
export function useKolonGorunurlugu(sayfaAnahtari: string) {
  const depoAnahtari = `erp.kolonlar.${sayfaAnahtari}`
  const [gizliler, setGizliler] = useState<Set<string>>(() => oku(depoAnahtari))

  const guncelle = (yeni: Set<string>) => {
    yaz(depoAnahtari, yeni)
    setGizliler(yeni)
  }

  const degistir = (anahtar: string) => {
    const yeni = new Set(gizliler)
    if (yeni.has(anahtar)) {
      yeni.delete(anahtar)
    } else {
      yeni.add(anahtar)
    }
    guncelle(yeni)
  }

  return {
    gorunurMu: (anahtar: string) => !gizliler.has(anahtar),
    gizliler,
    degistir,
    hepsiniGoster: () => guncelle(new Set()),
  }
}
