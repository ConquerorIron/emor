import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import en from './en.json'
import tr from './tr.json'

export const varsayilanDil = 'tr'

// Dil tercihi şimdilik localStorage'da; AUTH ile kullanıcı profiline taşınacak
void i18n.use(initReactI18next).init({
  resources: {
    tr: { translation: tr },
    en: { translation: en },
  },
  lng: localStorage.getItem('locale') ?? varsayilanDil,
  fallbackLng: 'en',
  interpolation: {
    // React zaten XSS'e karşı escape eder
    escapeValue: false,
  },
})

export default i18n
