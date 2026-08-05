import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * F2-8 PWA: bağlantı koptuğunda kalıcı uyarı şeridi. Uygulama kabuğu service
 * worker önbelleğinden açılır ama veri istekleri başarısız olur — kullanıcı
 * girişlerinin kaydedilmeyeceğini görmeli (offline taslak girişi bilinçli
 * kapsam dışı; ihtiyaç doğarsa ayrı iş olarak ele alınır).
 */
export function CevrimdisiBanner() {
  const { t } = useTranslation()
  const [cevrimdisi, setCevrimdisi] = useState(!navigator.onLine)

  useEffect(() => {
    const koptu = () => setCevrimdisi(true)
    const geldi = () => setCevrimdisi(false)
    window.addEventListener('offline', koptu)
    window.addEventListener('online', geldi)

    return () => {
      window.removeEventListener('offline', koptu)
      window.removeEventListener('online', geldi)
    }
  }, [])

  if (!cevrimdisi) {
    return null
  }

  return (
    <div
      role="alert"
      className="bg-amber-500 px-6 py-1.5 text-center text-sm font-semibold text-white"
    >
      {t('ortak.cevrimdisi')}
    </div>
  )
}
