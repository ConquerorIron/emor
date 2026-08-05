import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useRouteError } from 'react-router-dom'

/**
 * Router errorElement'i (kullanıcı isteği 2026-08-04). Bir sayfa render sırasında
 * patlarsa — tipik neden: deploy sonrası TARAYICIDA kalan ESKİ bundle (PWA service
 * worker cache'i) yeni backend'e vurup `undefined.x` okur — beyaz ekran yerine
 * "Uygulama güncellendi" mesajı gösterip TAZE koda geçer.
 *
 * Reload tek başına yetmez: eski service worker eski index.html + JS'i kendi
 * cache'inden sunmaya devam eder. Bu yüzden önce SW'yi kaldırıp cache'leri
 * temizler, sonra yeniler → reload doğrudan ağdan güncel sürümü çeker.
 *
 * Sonsuz döngü koruması: son otomatik yenilemeden bu yana KISA süre içinde yine
 * patladıysak (muhtemelen gerçek bir hata, güncelleme değil) otomatik yenilemeyi
 * bırakıp kullanıcıya elle seçenek sunarız.
 */
const YENILEME_DAMGASI = 'uygulama-hata-son-yenileme'
const DONGU_ESIGI_MS = 30_000

async function tazeleVeYenile(): Promise<void> {
  try {
    if ('serviceWorker' in navigator) {
      const kayitlar = await navigator.serviceWorker.getRegistrations()
      await Promise.all(kayitlar.map((kayit) => kayit.unregister().catch(() => undefined)))
    }
    if ('caches' in window) {
      const adlar = await caches.keys()
      await Promise.all(adlar.map((ad) => caches.delete(ad)))
    }
  } catch {
    // Temizlik başarısız olsa bile yine de yenilemeyi dene
  }
  window.location.reload()
}

export function UygulamaHatasi() {
  const hata = useRouteError()
  const { t } = useTranslation()
  const [dongu, setDongu] = useState(false)

  useEffect(() => {
    // Gerçek hatayı teşhis için konsola bırak
    console.error('Uygulama hata sınırı:', hata)

    const onceki = Number(sessionStorage.getItem(YENILEME_DAMGASI) ?? '0')
    const simdi = Date.now()
    if (onceki && simdi - onceki < DONGU_ESIGI_MS) {
      // Kısa süre içinde ikinci hata → güncelleme değil, gerçek sorun: döngüye girme
      setDongu(true)

      return
    }

    sessionStorage.setItem(YENILEME_DAMGASI, String(simdi))
    const zamanlayici = window.setTimeout(() => void tazeleVeYenile(), 1200)

    return () => window.clearTimeout(zamanlayici)
  }, [hata])

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-5 bg-slate-50 px-6 text-center dark:bg-slate-950">
      <div className="flex size-14 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400">
        <svg
          aria-hidden="true"
          className={`size-7 ${dongu ? '' : 'animate-spin'}`}
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          strokeWidth={1.8}
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
          />
        </svg>
      </div>
      <div>
        <h1 className="text-lg font-bold text-slate-800 dark:text-slate-100">
          {t('hata.guncellendiBaslik')}
        </h1>
        <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
          {dongu ? t('hata.guncellendiElle') : t('hata.guncellendiOtomatik')}
        </p>
      </div>
      <button
        type="button"
        onClick={() => void tazeleVeYenile()}
        className="cursor-pointer rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
      >
        {t('hata.simdiYenile')}
      </button>
    </div>
  )
}
