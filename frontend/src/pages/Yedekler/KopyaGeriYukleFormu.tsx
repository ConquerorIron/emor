import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { istanbulZamani } from '@/features/yedekleme/bicim'
import { KOPYA_EKI } from '@/features/yedekleme/geriYuklemeApi'
import type { Yedek } from '@/features/yedekleme/yedekApi'

/**
 * "Farklı adla geri yükle" (BY-027): yedek yeni bir veritabanına açılır,
 * canlı veritabanına dokunulmaz. Ad sistem tarafından üretilir; kullanıcı
 * yalnız isteğe bağlı kısa bir ek verir (küçük harf / rakam, en çok 16).
 */
export function KopyaGeriYukleFormu({
  yedek,
  yukleniyor,
  gonder,
  kapat,
}: {
  yedek: Yedek
  yukleniyor: boolean
  gonder: (ek: string | null) => void
  kapat: () => void
}) {
  const { t } = useTranslation()
  const [ek, setEk] = useState('')
  const [hata, setHata] = useState<string | null>(null)
  const temiz = ek.trim()

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(olay) => {
        olay.preventDefault()
        if (temiz !== '' && !KOPYA_EKI.test(temiz)) {
          setHata(t('yedekleme.geriYukleme.kopya.ekGecersiz'))
          return
        }
        gonder(temiz === '' ? null : temiz)
      }}
    >
      <div className="space-y-2 text-sm text-slate-600 dark:text-slate-400">
        <p>
          {t('yedekleme.geriYukleme.kopya.yedek')}:{' '}
          <span className="font-mono break-all text-slate-800 dark:text-slate-100">
            {yedek.dosya_adi}
          </span>{' '}
          ({istanbulZamani(yedek.alinma_zamani)})
        </p>
        <p className="rounded-lg bg-blue-50 px-3 py-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
          {t('yedekleme.geriYukleme.kopya.canliyaDokunmaz')}
        </p>
        <p>{t('yedekleme.geriYukleme.kopya.otomatikSilinir')}</p>
        {yedek.yerel_dosya_var ? null : (
          <p
            role="note"
            className="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
          >
            {t('yedekleme.geriYukleme.kopya.yerelDosyaYok')}
          </p>
        )}
      </div>

      <Input
        id="kopya-ek"
        label={t('yedekleme.geriYukleme.kopya.ek')}
        value={ek}
        maxLength={16}
        autoComplete="off"
        placeholder="ornek1"
        readOnly={yukleniyor}
        hata={hata ?? undefined}
        onChange={(olay) => {
          setEk(olay.target.value)
          setHata(null)
        }}
      />
      <p className="text-xs text-slate-500 dark:text-slate-400">
        {t('yedekleme.geriYukleme.kopya.adOrnegi', {
          ad: `erp_geri_YYYYAAGG_SSDDss${temiz === '' ? '' : `_${temiz}`}`,
        })}
      </p>

      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={yukleniyor}>
          {t('yedekleme.geriYukleme.kopya.baslat')}
        </Button>
      </div>
    </form>
  )
}
