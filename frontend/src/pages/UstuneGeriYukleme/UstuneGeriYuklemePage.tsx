import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { toast } from 'sonner'

import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { LanguageSwitcher } from '@/components/LanguageSwitcher'
import { ThemeToggle } from '@/components/ThemeToggle'
import { istanbulZamani } from '@/features/yedekleme/bicim'
import {
  BITMIS_DURUMLAR,
  ustuneDurumGetir,
  UstuneGeriYuklemeHatasi,
  ustuneKararVer,
  type UstuneGorunum,
} from '@/features/yedekleme/ustuneGeriYuklemeApi'
import {
  kayitliUstuneIslem,
  ustuneIslemiKaydet,
  ustuneIslemiUnut,
  type KayitliUstuneIslem,
} from '@/features/yedekleme/ustuneGeriYuklemeDeposu'

import { AyarSecimleri, type AyarSecimDurumu } from './AyarSecimleri'
import { OnayBilgisiPaneli } from './OnayBilgisiPaneli'

const YOKLAMA_ARALIGI = 2000

/** Sunucunun acil durum komutu (docs/yedekleme/sunucu-kurulumu.md: servis ortamıyla) */
const ACIL_KOMUT =
  'cd /var/www/emor/backend && sudo -u emor-yedek env $(systemctl show emor-yedek -p Environment --value) php artisan yedek:acil'

/** Anahtar reddedildi (yok / geçersiz / süresi doldu): yeniden denemek işe yaramaz */
function anahtarReddi(hata: unknown): boolean {
  return hata instanceof UstuneGeriYuklemeHatasi && (hata.durum === 401 || hata.durum === 403)
}

function hataAnahtari(hata: unknown): string {
  if (hata instanceof UstuneGeriYuklemeHatasi) {
    if (hata.kod) {
      return `hata.${hata.kod}`
    }
    if (hata.durum === 503) {
      return 'hata.GERI_YUKLEME_ISLEM_DURUMU_OKUNAMADI'
    }
  }

  return 'hata.BILINMEYEN'
}

const ADIM_ISARETI: Record<UstuneGorunum['adimlar'][number]['durum'], string> = {
  tamam: '✓',
  suruyor: '…',
  hata: '✗',
  bekliyor: '○',
}

function AcilDurumYolu({ vurgulu }: { vurgulu: boolean }) {
  const { t } = useTranslation()

  return (
    <section
      aria-label={t('yedekleme.ustune.acil.baslik')}
      className={`rounded-xl border px-4 py-3 text-sm ${
        vurgulu
          ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100'
          : 'border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'
      }`}
    >
      <h3 className="font-semibold">{t('yedekleme.ustune.acil.baslik')}</h3>
      <p className="mt-1">{t('yedekleme.ustune.acil.aciklama')}</p>
      <ul className="mt-2 space-y-1 font-mono text-xs break-all">
        {['durum', 'onayla', 'geri-don'].map((komut) => (
          <li key={komut} className="rounded bg-slate-900 px-2 py-1 text-slate-100">
            {ACIL_KOMUT} {komut}
          </li>
        ))}
      </ul>
      <p className="mt-2 text-xs">{t('yedekleme.ustune.acil.belge')}</p>
    </section>
  )
}

/**
 * Üstüne geri yüklemenin ilerlemesi ve kararı (BY-030; README §6.4, §7.2).
 * Oturum ve menü korumasının DIŞINDADIR: işlem sırasında uygulama bakım
 * modundadır, veritabanı yer değiştirir, oturumlar düşer. Tek yetki bu
 * tarayıcıda saklanan, işleme özel erişim anahtarıdır; tarayıcı kapanıp
 * açılınca işlem kaldığı yerden izlenir. Anahtar yoksa / süresi dolduysa
 * sunucudaki acil durum komutu anlatılır.
 */
export function UstuneGeriYuklemePage() {
  const { t } = useTranslation()
  const { islemKimligi = '' } = useParams()
  const [kayit, setKayit] = useState<KayitliUstuneIslem | null>(() => {
    const saklanan = kayitliUstuneIslem()

    return saklanan?.islem_kimligi === islemKimligi ? saklanan : null
  })
  const [kararOnayi, setKararOnayi] = useState<'onayla' | 'geri-don' | null>(null)
  const [ayarSecimleri, setAyarSecimleri] = useState<AyarSecimDurumu>({})
  const anahtar = kayit?.erisim_anahtari ?? null
  const kararIstegi = kayit?.karar_istegi ?? null

  const durum = useQuery({
    queryKey: ['ustuneGeriYukleme', islemKimligi, kararIstegi],
    queryFn: () => ustuneDurumGetir(islemKimligi, anahtar ?? '', kararIstegi),
    enabled: anahtar !== null,
    retry: false,
    staleTime: 0,
    placeholderData: keepPreviousData,
    // Bitmiş işlemde ve reddedilen anahtarda durur; ağ / 503'te sürer
    refetchInterval: (sorgu) =>
      (sorgu.state.data !== undefined && BITMIS_DURUMLAR.includes(sorgu.state.data.durum)) ||
      anahtarReddi(sorgu.state.error)
        ? false
        : YOKLAMA_ARALIGI,
    refetchIntervalInBackground: true,
  })
  const gorunum = durum.data
  const bitti = gorunum !== undefined && BITMIS_DURUMLAR.includes(gorunum.durum)

  // İşlem bitince anahtar bu tarayıcıdan silinir (8 saat geçerliydi)
  useEffect(() => {
    if (bitti) {
      ustuneIslemiUnut(islemKimligi)
    }
  }, [bitti, islemKimligi])

  // Farkı olan ayar grupları: her biri için açık seçim gerekir (BY-028-R2)
  const secimGerekenler = (gorunum?.onay_bilgisi?.ayar_gruplari ?? [])
    .filter((g) => g.farkli)
    .map((g) => g.grup)
  const secimlerTamam = secimGerekenler.every((grup) => ayarSecimleri[grup] !== undefined)

  const karar = useMutation({
    mutationFn: (secim: 'onayla' | 'geri-don') =>
      secim === 'onayla'
        ? ustuneKararVer(islemKimligi, anahtar ?? '', secim, ayarSecimleri)
        : ustuneKararVer(islemKimligi, anahtar ?? '', secim),
    onSuccess: (sonuc) => {
      if (kayit !== null) {
        const yeni = { ...kayit, karar_istegi: sonuc.istek_kimligi }
        ustuneIslemiKaydet(yeni)
        setKayit(yeni)
      }
    },
    onError: (hata: unknown) => toast.error(t(hataAnahtari(hata))),
    onSettled: () => setKararOnayi(null),
  })

  const reddedildi = anahtarReddi(durum.error)
  const kararSuruyor =
    karar.isPending || gorunum?.istek?.durum === 'kuyrukta' || gorunum?.istek?.durum === 'calisiyor'

  return (
    <div className="relative min-h-full px-4 py-6">
      <div className="absolute top-4 right-4 flex items-center gap-4">
        <LanguageSwitcher />
        <ThemeToggle />
      </div>
      <div className="mx-auto max-w-4xl space-y-4">
        <div>
          <h1 className="text-2xl font-bold">{t('yedekleme.ustune.ilerlemeBaslik')}</h1>
          <p className="mt-1 font-mono text-xs break-all text-slate-500 dark:text-slate-400">
            {islemKimligi}
          </p>
          {kayit !== null && !bitti ? (
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              {t('yedekleme.ustune.anahtarBitis', {
                zaman: istanbulZamani(kayit.erisim_anahtari_bitis),
              })}
            </p>
          ) : null}
        </div>

        {anahtar === null ? (
          <p
            role="alert"
            className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
          >
            {t('yedekleme.ustune.anahtarYok')}
          </p>
        ) : null}

        {durum.error ? (
          <p
            role="alert"
            className={`rounded-xl border px-4 py-3 text-sm ${
              reddedildi
                ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200'
                : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200'
            }`}
          >
            {t(hataAnahtari(durum.error))}
            {reddedildi ? null : ` ${t('yedekleme.ustune.yenidenDeneniyor')}`}
          </p>
        ) : null}

        {gorunum ? (
          <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            <div role="status">
              <p className="text-lg font-bold">
                {t(`yedekleme.ustune.durum.${gorunum.durum}`, { defaultValue: gorunum.durum })}
              </p>
              <p className="text-sm text-slate-600 dark:text-slate-400">
                {t(`yedekleme.ustune.durumAciklama.${gorunum.durum}`, { defaultValue: '' })}
              </p>
              {gorunum.adim ? (
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                  {t('yedekleme.ustune.simdikiAdim')}:{' '}
                  {t(`yedekleme.ustune.adim.${gorunum.adim}`, { defaultValue: gorunum.adim })}
                </p>
              ) : null}
            </div>

            {gorunum.hata ? (
              <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                {t(`hata.${gorunum.hata.kod}`, {
                  defaultValue: gorunum.hata.mesaj || gorunum.hata.kod,
                })}
              </p>
            ) : null}

            {gorunum.adimlar.length > 0 ? (
              <ol className="space-y-1 text-sm" aria-label={t('yedekleme.ustune.adimlar')}>
                {gorunum.adimlar.map((adim) => (
                  <li
                    key={adim.adim}
                    data-durum={adim.durum}
                    className={`flex items-center gap-2 ${
                      adim.durum === 'hata'
                        ? 'text-red-700 dark:text-red-300'
                        : adim.durum === 'bekliyor'
                          ? 'text-slate-400 dark:text-slate-500'
                          : adim.durum === 'suruyor'
                            ? 'font-semibold text-blue-700 dark:text-blue-300'
                            : 'text-slate-700 dark:text-slate-200'
                    }`}
                  >
                    <span aria-hidden="true" className="w-4 text-center font-bold">
                      {ADIM_ISARETI[adim.durum]}
                    </span>
                    <span>
                      {t(`yedekleme.ustune.adim.${adim.adim}`, { defaultValue: adim.adim })}
                    </span>
                    <span className="sr-only">
                      {t(`yedekleme.ustune.adimDurumu.${adim.durum}`)}
                    </span>
                  </li>
                ))}
              </ol>
            ) : null}

            {gorunum.istek ? (
              <p className="rounded-lg bg-blue-50 px-3 py-2 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                {gorunum.istek.durum === 'basarisiz'
                  ? t('yedekleme.ustune.kararBasarisiz', {
                      neden: gorunum.istek.kod
                        ? t(`hata.${gorunum.istek.kod}`, { defaultValue: gorunum.istek.kod })
                        : t('hata.BILINMEYEN'),
                    })
                  : t(`yedekleme.ustune.kararDurumu.${gorunum.istek.durum}`, {
                      defaultValue: gorunum.istek.durum,
                    })}
              </p>
            ) : null}

            {bitti ? (
              <p className="text-sm text-slate-600 dark:text-slate-400">
                {t('yedekleme.ustune.bittiNotu')}{' '}
                <a
                  href="/ayarlar/geri-yuklemeler"
                  className="font-semibold text-blue-700 underline dark:text-blue-300"
                >
                  {t('yedekleme.geriYukleme.baslik')}
                </a>
              </p>
            ) : null}
          </section>
        ) : null}

        {gorunum?.onay_bilgisi && (gorunum.yapilabilir.onayla || gorunum.yapilabilir.geri_don) ? (
          <OnayBilgisiPaneli bilgi={gorunum.onay_bilgisi} />
        ) : null}

        {gorunum?.yapilabilir.onayla && secimGerekenler.length > 0 ? (
          <AyarSecimleri
            gruplar={secimGerekenler}
            kopyaYok={gorunum.onay_bilgisi?.ayar_kopyasi_yok === true}
            secimler={ayarSecimleri}
            degistir={(grup, secim) => setAyarSecimleri((onceki) => ({ ...onceki, [grup]: secim }))}
            disabled={kararSuruyor}
          />
        ) : null}

        {gorunum && (gorunum.yapilabilir.onayla || gorunum.yapilabilir.geri_don) ? (
          <div className="flex flex-wrap justify-end gap-3">
            {gorunum.yapilabilir.geri_don ? (
              <Button
                variant="secondary"
                disabled={kararSuruyor}
                onClick={() => setKararOnayi('geri-don')}
              >
                {t('yedekleme.ustune.geriDon')}
              </Button>
            ) : null}
            {gorunum.yapilabilir.onayla ? (
              <Button
                variant="danger"
                disabled={kararSuruyor || !secimlerTamam}
                title={secimlerTamam ? undefined : t('yedekleme.ustune.ayarSecimi.eksik')}
                onClick={() => setKararOnayi('onayla')}
              >
                {t('yedekleme.ustune.onayla')}
              </Button>
            ) : null}
          </div>
        ) : null}

        <AcilDurumYolu vurgulu={reddedildi || anahtar === null} />
      </div>

      <ConfirmDialog
        acik={kararOnayi !== null}
        kapat={() => setKararOnayi(null)}
        baslik={t(
          kararOnayi === 'geri-don'
            ? 'yedekleme.ustune.geriDonOnayBaslik'
            : 'yedekleme.ustune.onaylaOnayBaslik',
        )}
        mesaj={t(
          kararOnayi === 'geri-don'
            ? 'yedekleme.ustune.geriDonOnayMesaj'
            : 'yedekleme.ustune.onaylaOnayMesaj',
        )}
        onayEtiketi={t(
          kararOnayi === 'geri-don' ? 'yedekleme.ustune.geriDon' : 'yedekleme.ustune.onayla',
        )}
        yukleniyor={karar.isPending}
        onayla={() => {
          if (kararOnayi !== null) {
            karar.mutate(kararOnayi)
          }
        }}
      />
    </div>
  )
}
