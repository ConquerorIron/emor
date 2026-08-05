import { useEffect, useState } from 'react'

/**
 * Toplu seçim state'i (Codex #9): liste bağlamı (arama/filtre/sıralama/pasif switch)
 * değiştiğinde seçim otomatik temizlenir — görünmeyen kayıtların yanlışlıkla toplu
 * silinmesini önler. Sayfa numarası bağlama bilinçli olarak DAHİL DEĞİLDİR:
 * sayfalar arası seçim korunur (aynı liste bağlamında gezinme).
 */
export function useTopluSecim(baglam: readonly unknown[]) {
  const [secili, setSecili] = useState<Set<string | number>>(new Set())
  const baglamAnahtari = JSON.stringify(baglam)

  useEffect(() => {
    setSecili(new Set())
  }, [baglamAnahtari])

  const secimDegistir = (anahtar: string | number) => {
    setSecili((onceki) => {
      const yeni = new Set(onceki)
      if (yeni.has(anahtar)) {
        yeni.delete(anahtar)
      } else {
        yeni.add(anahtar)
      }

      return yeni
    })
  }

  return { secili, setSecili, secimDegistir }
}
