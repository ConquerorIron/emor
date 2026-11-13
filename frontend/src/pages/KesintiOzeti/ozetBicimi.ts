/** "%57,2"; payda sıfırsa %0,0 */
export function oranGoster(pay: string, payda: string): string {
  const bolen = Number(payda)
  const oran = bolen === 0 ? 0 : (Number(pay) / bolen) * 100

  return `%${oran.toLocaleString('tr-TR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}`
}

/** Tarih ayın son günü mü ("Eylül sonu itibarıyla" başlığı için) */
export function aySonuMu(iso: string): boolean {
  const [yil, ay, gun] = iso.split('-').map(Number)

  return new Date(yil, ay, 0).getDate() === gun
}
