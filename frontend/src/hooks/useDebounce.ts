import { useEffect, useState } from 'react'

/** Arama girdileri için: değer değişimini gecikmeyle yayar (rules.md §5). */
export function useDebounce<T>(deger: T, gecikmeMs = 300): T {
  const [debounced, setDebounced] = useState(deger)

  useEffect(() => {
    const id = setTimeout(() => setDebounced(deger), gecikmeMs)

    return () => clearTimeout(id)
  }, [deger, gecikmeMs])

  return debounced
}
