import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { registerSW } from 'virtual:pwa-register'

import App from './App'
import './i18n/i18n'
import './index.css'
import { AppProviders } from './providers/AppProviders'

// F2-8 PWA: service worker kaydı — autoUpdate: yeni sürüm arka planda
// indirilir, sonraki açılışta devreye girer (deploy sonrası sert yenileme gerekmez)
registerSW({ immediate: true })

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AppProviders>
      <App />
    </AppProviders>
  </StrictMode>,
)
