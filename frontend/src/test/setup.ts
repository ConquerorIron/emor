import '@testing-library/jest-dom/vitest'
import { configure } from '@testing-library/react'

// findBy* / waitFor varsayılanı 1 sn: tam koşunun yükünde render + istek bunu
// aşabiliyordu. Yalnız başarısız bir beklemeyi uzatır; geçen testler aynı hızda.
configure({ asyncUtilTimeout: 5000 })

// jsdom matchMedia sağlamaz; ThemeProvider'ın başlangıç teması için gerekli
if (!window.matchMedia) {
  window.matchMedia = (query: string): MediaQueryList =>
    ({
      matches: false,
      media: query,
      onchange: null,
      addListener: () => undefined,
      removeListener: () => undefined,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      dispatchEvent: () => false,
    }) as MediaQueryList
}
