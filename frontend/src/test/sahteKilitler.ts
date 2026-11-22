/**
 * jsdom Web Locks (`navigator.locks`) sağlamaz; hedef tarayıcıların hepsi
 * güvenli bağlamda sağlar (Vite hedefi: Chrome 111+, Firefox 114+, Safari
 * 16.4+). Bu sahte, aynı ada gelen dışlayıcı istekleri gerçek LockManager gibi
 * geliş sırasıyla (FIFO) birer birer verir: kilit, geri çağrının döndürdüğü
 * söz bitene dek tutulur; kilit eşzamansız verilir.
 */
export interface SahteKilitYoneticisi extends LockManager {
  /**
   * Başka bir sekmenin (ayrı süreç) aynı kilit altındaki eşzamanlı işi:
   * kilit boşsa HEMEN, çağıranın eşzamanlı bloğunun ortasında çalışır (ayrı
   * süreç her an araya girebilir); kilit tutuluyorsa sıraya girer.
   */
  baskaSekmedenCalistir(ad: string, is: () => void): void
}

export function sahteKilitYoneticisi(): SahteKilitYoneticisi {
  const bekleyenler = new Map<string, Array<() => Promise<void>>>()
  const tutulanlar = new Set<string>()

  const siradakiniVer = (ad: string): void => {
    const is = bekleyenler.get(ad)?.shift()
    if (is === undefined) {
      tutulanlar.delete(ad)

      return
    }
    tutulanlar.add(ad)
    void is().finally(() => siradakiniVer(ad))
  }
  const siraya = (ad: string, is: () => Promise<void>): void => {
    bekleyenler.set(ad, [...(bekleyenler.get(ad) ?? []), is])
    if (!tutulanlar.has(ad)) {
      siradakiniVer(ad)
    }
  }

  return {
    request(ad: string, ...argumanlar: unknown[]) {
      const geriCagri = argumanlar.at(-1) as LockGrantedCallback<unknown>

      return new Promise<unknown>((coz, reddet) => {
        siraya(ad, () =>
          Promise.resolve()
            .then(() => geriCagri({ name: ad, mode: 'exclusive' }))
            .then(coz, reddet),
        )
      })
    },
    query: () => Promise.resolve({ held: [], pending: [] }),
    baskaSekmedenCalistir(ad, is) {
      siraya(ad, async () => {
        is()
      })
    },
  } as SahteKilitYoneticisi
}

export function sahteKilitleriKur(): SahteKilitYoneticisi {
  const kilitler = sahteKilitYoneticisi()
  Object.defineProperty(navigator, 'locks', { configurable: true, value: kilitler })

  return kilitler
}
