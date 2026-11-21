import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { googleDonusunuTamamla } from '@/features/yedekleme/hedefApi'

const HEDEFLER = '/ayarlar/yedek-hedefleri'

/**
 * Google'ın tarayıcıyı döndürdüğü adres (BY-021): `code` ve `state` sunucuya
 * BİR KEZ iletilir (durum değeri tek kullanımlıktır; StrictMode'un çift etki
 * çağrısı ikinci isteği göndermesin), sonra Hedefler ekranına dönülür.
 */
export function GoogleDonusPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [parametreler] = useSearchParams()
  const gonderildi = useRef(false)

  useEffect(() => {
    if (gonderildi.current) {
      return
    }
    gonderildi.current = true

    const kod = parametreler.get('code')
    const durum = parametreler.get('state')

    const tamamla = async () => {
      // Kullanıcı Google'da izni reddetti / akışı yarıda bıraktı: sunucuya gidilmez
      if (parametreler.get('error') !== null || !kod || !durum) {
        toast.error(t('yedekleme.hedef.google.reddedildi'))
        return
      }
      await googleDonusunuTamamla(kod, durum)
      await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.hedefler })
      toast.success(t('yedekleme.hedef.google.baglandi'))
    }

    // Google dönüşünde uygulama bu sayfayla açılır: bildirim, Toaster'ın da
    // kurulduğu ilk çizim bittikten sonra verilir (aksi hâlde kaybolur)
    void Promise.resolve()
      .then(tamamla)
      .catch((error: unknown) => toast.error(t(apiErrorKey(error))))
      .finally(() => void navigate(HEDEFLER, { replace: true }))
  }, [parametreler, navigate, queryClient, t])

  return (
    <p role="status" className="text-sm text-slate-500 dark:text-slate-400">
      {t('yedekleme.hedef.google.tamamlaniyor')}
    </p>
  )
}
