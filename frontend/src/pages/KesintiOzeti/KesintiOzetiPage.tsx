import { keepPreviousData, skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ErrorState } from '@/components/ErrorState'
import { KesintiRaporuParametreleri } from '@/features/kesinti/KesintiRaporuParametreleri'
import { useKesintiRaporuParametreleri } from '@/features/kesinti/kesintiRaporu'

import { DagilimTablosu, type KolonGrubu } from './DagilimTablosu'
import { kesintiOzetiApi } from './kesintiOzetiApi'
import { OzetKartlari } from './OzetKartlari'

/** Backend KesintiOzeti::TASERON_SAYISI */
const TASERON_SAYISI = 10

const DAGILIM_GRUPLARI: KolonGrubu[] = [
  { baslik: 'genel', kolonlar: [{ alan: 'toplam', baslik: 'toplam' }] },
  { baslik: 'hab', kolonlar: [{ alan: 'hab', baslik: 'hab' }] },
  {
    baslik: 'taseron',
    kolonlar: [
      { alan: 'taseron', baslik: 'taseron' },
      { alan: 'iptal', baslik: 'iptal' },
      { alan: 'kesilen', baslik: 'kesilen' },
      { alan: 'kalan', baslik: 'kalan' },
    ],
  },
]

// Firmanın toplamı = taşeron payı: "Toplam Kesinti" başlığıyla
const TASERON_GRUPLARI: KolonGrubu[] = [
  {
    baslik: 'taseron',
    kolonlar: [
      { alan: 'taseron', baslik: 'toplam' },
      { alan: 'iptal', baslik: 'iptal' },
      { alan: 'kesilen', baslik: 'kesilen' },
      { alan: 'kalan', baslik: 'kalan' },
    ],
  },
]

/**
 * Kesinti Yönetimi → Kesinti Özeti (kullanıcı isteği 2026-11-13; örnek: Excel'deki
 * "… itibarıyla kesinti listesi"): Tüm Kesintiler'den, seçilen tarihe kadar ve
 * tek para biriminde genel özet, Zone (proje) ve kesinti türü dağılımı, en
 * yüksek kesintili taşeronlar. ERP okunmaz; parametre değişince anında yenilenir.
 *
 * 2026-11-16 (kullanıcı isteği): ekranda rapor üst bilgisi (şirket, "… itibarıyla
 * kesinti listesi" başlığı, bütçe / işveren) yok — parametreler zaten üstte;
 * Excel'in üst bilgisi duruyor. Kesinti türü süzgeci eklendi.
 */
export function KesintiOzetiPage() {
  const { t } = useTranslation()
  const parametreDurumu = useKesintiRaporuParametreleri('ozet', kesintiOzetiApi.secenekler)
  const { parametreler, secenekler } = parametreDurumu

  const ozet = useQuery({
    queryKey: queryKeys.kesinti.ozet(parametreler),
    queryFn: parametreler ? () => kesintiOzetiApi.getir(parametreler) : skipToken,
    placeholderData: keepPreviousData,
  })

  const excel = useMutation({
    mutationFn: kesintiOzetiApi.excel,
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const veri = ozet.data
  const bos = veri !== undefined && veri.proje_dagilimi.length === 0

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('kesinti.ozet.baslik')}</h2>
        <Button
          variant="secondary"
          yukleniyor={excel.isPending}
          disabled={parametreler === null || veri === undefined || bos}
          onClick={() => {
            if (parametreler) {
              excel.mutate(parametreler)
            }
          }}
        >
          {t('kesinti.icmal.excelAktar')}
        </Button>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('kesinti.ozet.aciklamaMetni')}
      </p>

      <KesintiRaporuParametreleri
        durum={parametreDurumu}
        idOneki="kesinti-ozeti"
        kesintiTuruSuzgeci
      />

      {secenekler.error || ozet.error ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(secenekler.error ?? ozet.error))}
            tekrarDene={() => {
              void secenekler.refetch()
              void ozet.refetch()
            }}
          />
        </div>
      ) : veri === undefined ? (
        <div
          role="status"
          className="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400"
        >
          {secenekler.data?.para_birimleri.length === 0
            ? t('kesinti.ozet.kesintiYok')
            : t('ortak.yukleniyor')}
        </div>
      ) : bos ? (
        <div className="mt-4 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
          {t('kesinti.ozet.secimdeKesintiYok')}
        </div>
      ) : (
        // Kâğıt görünümü: kullanıcının örnek raporu
        <article
          aria-label={t('kesinti.ozet.baslik')}
          className={`mt-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-700 dark:bg-slate-900 ${ozet.isPlaceholderData ? 'opacity-50' : ''}`}
        >
          <section>
            <h4 className="text-base font-bold text-slate-800 dark:text-slate-100">
              {t('kesinti.ozet.bolum.ozet')}
            </h4>
            <OzetKartlari genel={veri.genel} paraBirimi={veri.para_birimi} />
          </section>

          <section className="mt-5">
            <h4 className="text-base font-bold text-slate-800 dark:text-slate-100">
              {t('kesinti.ozet.bolum.dagilim')}
            </h4>
            <DagilimTablosu
              baslik={t('kesinti.ozet.bolum.projeBazinda')}
              ilkKolon={t('kesinti.ozet.kolon.proje')}
              gruplar={DAGILIM_GRUPLARI}
              satirlar={veri.proje_dagilimi}
              toplam={veri.genel}
            />
            <DagilimTablosu
              baslik={t('kesinti.ozet.bolum.turBazinda')}
              ilkKolon={t('kesinti.ozet.kolon.kesinti_turu')}
              gruplar={DAGILIM_GRUPLARI}
              satirlar={veri.tur_dagilimi}
              toplam={veri.genel}
            />
          </section>

          <section className="mt-6">
            <h4 className="text-base font-bold text-slate-800 dark:text-slate-100">
              {t('kesinti.ozet.bolum.taseronDurumu')}
            </h4>
            <DagilimTablosu
              baslik={t('kesinti.ozet.bolum.enYuksek', { adet: TASERON_SAYISI })}
              ilkKolon={t('kesinti.ozet.kolon.taseron_firma')}
              gruplar={TASERON_GRUPLARI}
              satirlar={veri.taseronlar}
            />
          </section>
        </article>
      )}
    </>
  )
}
