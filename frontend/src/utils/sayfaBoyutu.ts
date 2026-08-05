/**
 * Liste sayfa boyutu tercihi (kullanıcı isteği 2026-07-09): tüm liste
 * ekranları için ORTAK tercih — localStorage'da saklanır, axios interceptor
 * `page` parametreli isteklere `sayfa_boyutu` olarak ekler (client.ts).
 * 0 = "Hepsi" (backend üst sınırla karşılar).
 */
const ANAHTAR = 'erp.sayfaBoyutu'

export const SAYFA_BOYUTLARI = [25, 50, 100, 200, 0] as const

export const VARSAYILAN_BOYUT = 25

export function sayfaBoyutuOku(): number {
  const ham = Number(localStorage.getItem(ANAHTAR))

  return (SAYFA_BOYUTLARI as readonly number[]).includes(ham) ? ham : VARSAYILAN_BOYUT
}

export function sayfaBoyutuYaz(boyut: number): void {
  localStorage.setItem(ANAHTAR, String(boyut))
}
