import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { boyutGoster, istanbulZamani } from '@/features/yedekleme/bicim'
import {
  geriYuklemeleriGetir,
  KOPYA_ADI,
  kopyaSil,
  type GeriYukleme,
  type GeriYuklemeDurumu,
} from '@/features/yedekleme/geriYuklemeApi'
import { IstekSeridi } from '@/features/yedekleme/IstekSeridi'
import { Rozet, type RozetRengi } from '@/features/yedekleme/Rozet'
import { useIstekTakibi } from '@/features/yedekleme/useIstekTakibi'
import {
  kayitliUstuneIslem,
  ustuneIlerlemeAdresi,
} from '@/features/yedekleme/ustuneGeriYuklemeDeposu'
import { useIzin } from '@/hooks/useIzin'

const GUN_MS = 24 * 60 * 60 * 1000

/** Süren geri yükleme varken liste bu aralıkla tazelenir (ms) */
const SUREN_TAZELEME = 5000

const DURUM_RENGI: Record<GeriYuklemeDurumu, RozetRengi> = {
  kuyrukta: 'mavi',
  calisiyor: 'mavi',
  onay_bekliyor: 'sari',
  tamam: 'yesil',
  basarisiz: 'kirmizi',
  geri_alindi: 'gri',
}

/** Otomatik silinmeye kalan süre: geçti / 1 günden az / daha uzun */
function silinmeDurumu(
  silinecegi: string | null,
  simdi: number,
): 'gecti' | 'yakin' | 'uzak' | null {
  if (silinecegi === null) {
    return null
  }
  const kalan = new Date(silinecegi).getTime() - simdi
  if (Number.isNaN(kalan)) {
    return null
  }

  return kalan <= 0 ? 'gecti' : kalan < GUN_MS ? 'yakin' : 'uzak'
}

function Adimlar({ geri }: { geri: GeriYukleme }) {
  const { t } = useTranslation()

  if (geri.adimlar.length === 0) {
    return <span className="text-slate-400">—</span>
  }

  return (
    <details>
      <summary className="cursor-pointer text-xs font-semibold text-blue-700 dark:text-blue-300">
        {t('yedekleme.geriYukleme.adimSayisi', { sayi: geri.adimlar.length })}
      </summary>
      <ol className="mt-1 space-y-0.5 text-xs">
        {geri.adimlar.map((adim, sira) => (
          <li key={`${adim.adim}-${sira}`} className="flex flex-wrap gap-x-2">
            <span className="tabular-nums text-slate-500 dark:text-slate-400">
              {istanbulZamani(adim.zaman, { saniye: true })}
            </span>
            <span className="font-semibold">
              {t(`yedekleme.geriYukleme.adim.${adim.adim}`, { defaultValue: adim.adim })}
            </span>
            <span
              className={
                adim.durum === 'basarisiz'
                  ? 'text-red-600 dark:text-red-400'
                  : 'text-slate-600 dark:text-slate-300'
              }
            >
              {t(`yedekleme.geriYukleme.adimDurumu.${adim.durum}`, { defaultValue: adim.durum })}
            </span>
          </li>
        ))}
      </ol>
    </details>
  )
}

/**
 * Ayarlar → Geri Yüklemeler (BY-027; README §4.10 (3)): geçmiş işlemler ve
 * farklı ada açılmış, silinmeyi bekleyen kopyalar. Kopya yalnız bu sistemin
 * açtığı `erp_geri_*` adlarında ve onayla silinir; `erp` ve kalıp dışı adlar
 * ekrandan silinemez (backend de reddeder). Üstüne geri yükleme kayıtları
 * burada yalnız okunur (BY-030).
 */
export function GeriYuklemelerPage() {
  const { t } = useTranslation()
  const kopyaYetkisi = useIzin('geri_yukleme.kopya')
  const { calisanlar, calisiyor, baslat } = useIstekTakibi('geri_yuklemeler')
  const [silinecek, setSilinecek] = useState<GeriYukleme | null>(null)

  const liste = useQuery({
    queryKey: queryKeys.yedekleme.geriYuklemeler,
    queryFn: geriYuklemeleriGetir,
    refetchInterval: (sorgu) =>
      sorgu.state.data?.some((geri) => geri.durum === 'kuyrukta' || geri.durum === 'calisiyor')
        ? SUREN_TAZELEME
        : false,
  })
  // Silme düğmesi eski listedeki satırda çalışmasın (F1)
  const kilitli = calisiyor || liste.isFetching || liste.isPaused

  const sil = useMutation({
    mutationFn: (geri: GeriYukleme) => kopyaSil(geri.kimlik),
    onSuccess: (kabul) => {
      baslat(kabul, 'kopya_sil')
      setSilinecek(null)
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const simdi = Date.now()
  const saklananIslem = kayitliUstuneIslem()?.islem_kimligi ?? null
  const kayitlar = liste.data ?? []
  const bekleyenler = kayitlar.filter((geri) => geri.bekleyen_kopya)

  const bekleyenKolonlari: DataTableKolonu<GeriYukleme>[] = [
    {
      anahtar: 'veritabani',
      baslik: t('yedekleme.geriYukleme.veritabani'),
      render: (geri) => (
        <span className="font-mono text-xs break-all">{geri.hedef_veritabani}</span>
      ),
    },
    {
      anahtar: 'yedek',
      baslik: t('yedekleme.geriYukleme.yedek'),
      render: (geri) =>
        geri.yedek === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <div>
            <p className="font-mono text-xs break-all">{geri.yedek.dosya_adi}</p>
            <p className="text-xs text-slate-500 tabular-nums dark:text-slate-400">
              {istanbulZamani(geri.yedek.alinma_zamani)}
            </p>
          </div>
        ),
    },
    {
      anahtar: 'boyut',
      baslik: t('yedekleme.geriYukleme.boyut'),
      hizala: 'sag',
      render: (geri) => (
        <span className="whitespace-nowrap tabular-nums">{boyutGoster(geri.boyut)}</span>
      ),
    },
    {
      anahtar: 'yas',
      baslik: t('yedekleme.geriYukleme.acildi'),
      render: (geri) => (
        <div className="whitespace-nowrap">
          <p className="tabular-nums">{istanbulZamani(geri.bitti)}</p>
          {geri.bitti ? (
            <p className="text-xs text-slate-500 dark:text-slate-400">
              {t('yedekleme.geriYukleme.gunOnce', {
                gun: Math.max(0, Math.floor((simdi - new Date(geri.bitti).getTime()) / GUN_MS)),
              })}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'silinecegi',
      baslik: t('yedekleme.geriYukleme.otomatikSilinme'),
      render: (geri) => {
        const durum = silinmeDurumu(geri.silinecegi_zaman, simdi)

        return (
          <div className="space-y-1 whitespace-nowrap">
            <p className="tabular-nums">{istanbulZamani(geri.silinecegi_zaman)}</p>
            {durum === 'yakin' ? (
              <Rozet renk="kirmizi">{t('yedekleme.geriYukleme.birGundenAz')}</Rozet>
            ) : null}
            {durum === 'gecti' ? (
              <Rozet renk="sari">{t('yedekleme.geriYukleme.suresiDoldu')}</Rozet>
            ) : null}
          </div>
        )
      },
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (geri) =>
        // Yalnız sistemin açtığı kopya adı: `erp` ya da kalıp dışı ad ekrandan silinmez
        KOPYA_ADI.test(geri.hedef_veritabani) ? (
          <Button variant="danger" disabled={kilitli} onClick={() => setSilinecek(geri)}>
            {t('ortak.sil')}
          </Button>
        ) : (
          <span className="text-xs text-slate-500 dark:text-slate-400">
            {t('yedekleme.geriYukleme.ekrandanSilinmez')}
          </span>
        ),
    },
  ]
  const bekleyenKolonlar = kopyaYetkisi
    ? bekleyenKolonlari
    : bekleyenKolonlari.filter((kolon) => kolon.anahtar !== 'islemler')

  const gecmisKolonlari: DataTableKolonu<GeriYukleme>[] = [
    {
      anahtar: 'basladi',
      baslik: t('yedekleme.geriYukleme.basladi'),
      render: (geri) => (
        <span className="whitespace-nowrap tabular-nums">{istanbulZamani(geri.basladi)}</span>
      ),
    },
    {
      anahtar: 'tur',
      baslik: t('yedekleme.geriYukleme.tur'),
      render: (geri) => (
        <Rozet renk={geri.tur === 'ustune' ? 'mor' : 'gri'}>
          {t(`yedekleme.geriYukleme.turler.${geri.tur}`, { defaultValue: geri.tur })}
        </Rozet>
      ),
    },
    {
      anahtar: 'durum',
      baslik: t('yedekleme.geriYukleme.durum'),
      render: (geri) => (
        <div className="space-y-1">
          <Rozet renk={DURUM_RENGI[geri.durum] ?? 'gri'}>
            {t(`yedekleme.geriYukleme.durumlar.${geri.durum}`, { defaultValue: geri.durum })}
          </Rozet>
          {geri.hata_kodu ? (
            <p className="max-w-xs text-xs text-red-600 dark:text-red-400">
              {t(`hata.${geri.hata_kodu}`, { defaultValue: geri.hata_kodu })}
            </p>
          ) : null}
          {/* Üstüne geri yüklemenin ilerlemesi: anahtar bu tarayıcıdaysa */}
          {geri.tur === 'ustune' && saklananIslem === geri.kimlik ? (
            <Link
              to={ustuneIlerlemeAdresi(geri.kimlik)}
              className="block text-xs font-semibold text-blue-700 underline dark:text-blue-300"
            >
              {t('yedekleme.ustune.ilerlemeyiAc')}
            </Link>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'yedek',
      baslik: t('yedekleme.geriYukleme.yedek'),
      render: (geri) =>
        geri.yedek === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <span className="font-mono text-xs break-all">{geri.yedek.dosya_adi}</span>
        ),
    },
    {
      anahtar: 'hedef',
      baslik: t('yedekleme.geriYukleme.veritabani'),
      render: (geri) => (
        <div className="space-y-0.5 text-xs">
          <p className="font-mono break-all">{geri.hedef_veritabani}</p>
          {geri.onceki_veritabani ? (
            <p className="text-slate-500 dark:text-slate-400">
              {t('yedekleme.geriYukleme.onceki')}:{' '}
              <span className="font-mono break-all">{geri.onceki_veritabani}</span>
            </p>
          ) : null}
          {geri.veritabani_silinme_zamani ? (
            <p className="text-slate-500 dark:text-slate-400">
              {t('yedekleme.geriYukleme.silindi', {
                zaman: istanbulZamani(geri.veritabani_silinme_zamani),
              })}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'isteyen',
      baslik: t('yedekleme.geriYukleme.isteyen'),
      render: (geri) =>
        geri.isteyen ?? (
          <span className="text-slate-500 dark:text-slate-400">{t('yedekleme.gunluk.sistem')}</span>
        ),
    },
    {
      anahtar: 'adimlar',
      baslik: t('yedekleme.geriYukleme.adimlar'),
      render: (geri) => <Adimlar geri={geri} />,
    },
  ]

  return (
    <>
      <h2 className="text-2xl font-bold">{t('yedekleme.geriYukleme.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.geriYukleme.aciklamaMetni')}
      </p>

      <IstekSeridi calisanlar={calisanlar} />

      {liste.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        </div>
      ) : (
        <>
          <h3 className="mt-6 text-lg font-bold">{t('yedekleme.geriYukleme.bekleyenler')}</h3>
          <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
            {t('yedekleme.geriYukleme.bekleyenlerAciklama')}
          </p>
          <div className="mt-3">
            <DataTable
              kolonlar={bekleyenKolonlar}
              satirlar={bekleyenler}
              satirAnahtari={(geri) => geri.kimlik}
              yukleniyor={liste.isPending}
            />
          </div>

          <h3 className="mt-8 text-lg font-bold">{t('yedekleme.geriYukleme.gecmis')}</h3>
          <div className="mt-3">
            <DataTable
              kolonlar={gecmisKolonlari}
              satirlar={kayitlar}
              satirAnahtari={(geri) => geri.kimlik}
              yukleniyor={liste.isPending}
            />
          </div>
        </>
      )}

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('yedekleme.geriYukleme.silOnayBaslik')}
        mesaj={t('yedekleme.geriYukleme.silOnayMesaj', {
          veritabani: silinecek?.hedef_veritabani ?? '',
        })}
        onayEtiketi={t('ortak.sil')}
        yukleniyor={sil.isPending}
        onayla={() => {
          if (silinecek !== null) {
            sil.mutate(silinecek)
          }
        }}
      />
    </>
  )
}
