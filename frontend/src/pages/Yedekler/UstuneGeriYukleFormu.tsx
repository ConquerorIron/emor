import { useMutation } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { istanbulZamani } from '@/features/yedekleme/bicim'
import { ustuneGeriYukle } from '@/features/yedekleme/ustuneGeriYuklemeApi'
import {
  ustuneIlerlemeAdresi,
  ustuneIslemiKaydet,
} from '@/features/yedekleme/ustuneGeriYuklemeDeposu'
import type { Yedek } from '@/features/yedekleme/yedekApi'

/**
 * "Üstüne geri yükle" onayı (BY-030; README §4.5-B): yedeğin tarihi ve
 * kaybolacak dönem açıkça yazılır; canlı veritabanının adı aynen yazılmadan
 * düğme açılmaz. Başlayınca erişim anahtarı (yalnız bu yanıtta gelir) bu
 * tarayıcıya kaydedilir ve oturumdan bağımsız ilerleme sayfasına gidilir;
 * anahtar gezinme durumuyla da taşınır (YF-10).
 */
export function UstuneGeriYukleFormu({
  yedek,
  canliVeritabani,
  kapat,
}: {
  yedek: Yedek
  canliVeritabani: string | null
  kapat: () => void
}) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [onayMetni, setOnayMetni] = useState('')
  const [hata, setHata] = useState<string | null>(null)
  const tutuyor = canliVeritabani !== null && onayMetni === canliVeritabani
  // Eşzamanlı kilit (F5, YF-10): ikinci gönderim ikinci işlem ve ikinci erişim
  // anahtarı üretir, tek kayıtlık depoda öncekini ezer. Başarıda bırakılmaz
  // (sayfa ilerleme ekranına geçer); yalnız hata sonrası yeniden denenebilir
  const gonderiliyor = useRef(false)

  const baslat = useMutation({
    mutationFn: () => ustuneGeriYukle(yedek.kimlik, onayMetni),
    onSuccess: (sonuc) => {
      const kayit = {
        islem_kimligi: sonuc.islem_kimligi,
        erisim_anahtari: sonuc.erisim_anahtari,
        erisim_anahtari_bitis: sonuc.erisim_anahtari_bitis,
        karar_istegi: null,
      }
      ustuneIslemiKaydet(kayit)
      // Depo yazılamasa da (kota, gizli pencere) anahtar ilerleme sayfasına ulaşır
      void navigate(ustuneIlerlemeAdresi(sonuc.islem_kimligi), { state: { kayit } })
    },
    onError: (error: unknown) => {
      gonderiliyor.current = false
      setHata(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
  })

  return (
    <form
      noValidate
      className="space-y-4 text-sm"
      onSubmit={(olay) => {
        olay.preventDefault()
        if (!tutuyor || gonderiliyor.current) {
          return
        }
        gonderiliyor.current = true
        baslat.mutate()
      }}
    >
      <p
        role="alert"
        className="rounded-xl border border-red-300 bg-red-50 px-4 py-3 font-semibold text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
      >
        {t('yedekleme.ustune.kayipUyarisi', {
          zaman: istanbulZamani(yedek.alinma_zamani),
          dosya: yedek.dosya_adi,
        })}
      </p>
      <p className="text-slate-600 dark:text-slate-400">
        {t('yedekleme.ustune.kayipDonemi', {
          baslangic: istanbulZamani(yedek.alinma_zamani),
          bitis: istanbulZamani(new Date().toISOString()),
        })}
      </p>

      <div>
        <p className="font-semibold text-slate-700 dark:text-slate-200">
          {t('yedekleme.ustune.neOlacak')}
        </p>
        <ol className="mt-1 list-decimal space-y-0.5 pl-5 text-slate-600 dark:text-slate-400">
          <li>{t('yedekleme.ustune.ozet.bakim')}</li>
          <li>{t('yedekleme.ustune.ozet.oncesiYedek')}</li>
          <li>{t('yedekleme.ustune.ozet.yeniVeritabani')}</li>
          <li>{t('yedekleme.ustune.ozet.oncekiSaklanir')}</li>
          <li>{t('yedekleme.ustune.ozet.onay')}</li>
        </ol>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('yedekleme.ustune.ozet.oturum')}
        </p>
      </div>

      {canliVeritabani === null ? (
        <p className="text-red-700 dark:text-red-300">{t('yedekleme.ustune.adBilinmiyor')}</p>
      ) : (
        <Input
          id="ustune-onay-metni"
          label={t('yedekleme.ustune.adiYazin', { ad: canliVeritabani })}
          value={onayMetni}
          autoComplete="off"
          spellCheck={false}
          readOnly={baslat.isPending}
          hata={hata ?? undefined}
          onChange={(olay) => {
            setOnayMetni(olay.target.value)
            setHata(null)
          }}
        />
      )}

      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" variant="danger" disabled={!tutuyor} yukleniyor={baslat.isPending}>
          {t('yedekleme.ustune.baslat')}
        </Button>
      </div>
    </form>
  )
}
