import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { DataTable } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Pagination } from '@/components/Pagination'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'
import { LISTE_AZAMI_GUN, listeAraligiHatasi } from '@/features/efatura/bicim'
import {
  type EFaturaIslemTuru,
  GELEN_SAGLAYICILARI,
  ISLEM_TURLERI,
  type Saglayici,
} from '@/features/efatura/efaturaApi'
import { islemGecmisiGetir, type IslemGecmisiSuzgeci } from '@/features/efatura/islemGecmisiApi'
import { islemAyrintisi } from '@/features/efatura/islemMetni'
import { useDebounce } from '@/hooks/useDebounce'
import { bugunIso, zamanGoster } from '@/utils/tarih'

/** Bugünden 30 gün geri (İstanbul günü) */
function otuzGunOnce(bugun: string): string {
  const tarih = new Date(`${bugun}T00:00:00`)
  tarih.setDate(tarih.getDate() - 29)

  return `${tarih.getFullYear()}-${String(tarih.getMonth() + 1).padStart(2, '0')}-${String(tarih.getDate()).padStart(2, '0')}`
}

/**
 * e-Fatura İşlem Geçmişi (EK-03; kullanıcı isteği g, 2026-11-25): ekranlarda
 * yapılan elle işlemler (RED'e çekme / geri alma, İşlendi, gizleme, içe
 * aktarım) kullanıcı bazında. Kayıtlar değiştirilemez, silinemez.
 */
export function EFaturaIslemGecmisiPage() {
  const { t } = useTranslation()
  const [suzgec, setSuzgec] = useState<IslemGecmisiSuzgeci>(() => ({
    baslangic: otuzGunOnce(bugunIso()),
    bitis: bugunIso(),
    kullanici_id: '',
    islem: '',
    saglayici: '',
    ara: '',
  }))
  const [sayfa, setSayfa] = useState(1)
  const ara = useDebounce(suzgec.ara)
  const sorgu = { ...suzgec, ara }
  const tarihHatasi = listeAraligiHatasi(suzgec.baslangic, suzgec.bitis)

  const degistir = <K extends keyof IslemGecmisiSuzgeci>(
    alan: K,
    deger: IslemGecmisiSuzgeci[K],
  ) => {
    setSuzgec((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }

  const liste = useQuery({
    queryKey: queryKeys.efaturaGecmis.liste({ ...sorgu, sayfa }),
    queryFn: () => islemGecmisiGetir(sorgu, sayfa),
    enabled: tarihHatasi === null,
    placeholderData: keepPreviousData,
  })

  const kullaniciSecenekleri: SecenekOgesi[] = (liste.data?.secenekler.kullanicilar ?? []).map(
    (k) => ({
      value: String(k.id),
      label: k.ad,
    }),
  )
  const islemSecenekleri: SecenekOgesi[] = ISLEM_TURLERI.map((tur) => ({
    value: tur,
    label: t(`efatura.islem.tur.${tur}`),
  }))
  const kaynakSecenekleri: SecenekOgesi[] = GELEN_SAGLAYICILARI.map((s) => ({
    value: s,
    label: t(`efatura.saglayici.${s}`),
  }))
  const secili = (ogeler: SecenekOgesi[], deger: string) =>
    deger === '' ? null : (ogeler.find((oge) => oge.value === deger) ?? null)

  return (
    <>
      <h2 className="text-2xl font-bold">{t('efatura.islem.ekranBaslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('efatura.islem.ekranAciklama')}
      </p>

      <div className="mt-4 flex flex-wrap items-start gap-3">
        <div className="w-full sm:w-36">
          <TarihInput
            id="islem-baslangic"
            label={t('efatura.baslangic')}
            value={suzgec.baslangic}
            onChange={(deger) => degistir('baslangic', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-36">
          <TarihInput
            id="islem-bitis"
            label={t('efatura.bitis')}
            value={suzgec.bitis}
            onChange={(deger) => degistir('bitis', deger)}
            hata={tarihHatasi !== null ? t(tarihHatasi, { gun: LISTE_AZAMI_GUN }) : undefined}
          />
        </div>
        <div className="w-full sm:w-52">
          <SelectField
            id="islem-kullanici"
            label={t('efatura.islem.kullanici')}
            options={kullaniciSecenekleri}
            value={secili(kullaniciSecenekleri, suzgec.kullanici_id)}
            onChange={(secim) => degistir('kullanici_id', secim?.value ?? '')}
            placeholder={t('efatura.hepsi')}
            isClearable
          />
        </div>
        <div className="w-full sm:w-52">
          <SelectField
            id="islem-turu"
            label={t('efatura.islem.islem')}
            options={islemSecenekleri}
            value={secili(islemSecenekleri, suzgec.islem)}
            onChange={(secim) => degistir('islem', (secim?.value ?? '') as '' | EFaturaIslemTuru)}
            placeholder={t('efatura.hepsi')}
            isClearable
            isSearchable={false}
          />
        </div>
        <div className="w-full sm:w-40">
          <SelectField
            id="islem-kaynak"
            label={t('efatura.kolon.entegrator')}
            options={kaynakSecenekleri}
            value={secili(kaynakSecenekleri, suzgec.saglayici)}
            onChange={(secim) => degistir('saglayici', (secim?.value ?? '') as '' | Saglayici)}
            placeholder={t('efatura.hepsi')}
            isClearable
            isSearchable={false}
          />
        </div>
        <div className="w-full sm:min-w-56 sm:flex-1">
          <Input
            id="islem-ara"
            type="search"
            label={t('efatura.islem.ara')}
            maxLength={100}
            value={suzgec.ara}
            onChange={(olay) => degistir('ara', olay.target.value)}
          />
        </div>
      </div>

      {tarihHatasi !== null ? (
        <p
          role="alert"
          className="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {t(tarihHatasi, { gun: LISTE_AZAMI_GUN })}
        </p>
      ) : liste.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        </div>
      ) : (
        <div
          className={`mt-4 space-y-4 transition-opacity ${liste.isPlaceholderData ? 'opacity-50' : ''}`}
        >
          <DataTable
            kolonlar={[
              {
                anahtar: 'zaman',
                baslik: t('efatura.islem.zaman'),
                render: (k) => <span className="whitespace-nowrap">{zamanGoster(k.zaman)}</span>,
              },
              {
                anahtar: 'kullanici',
                baslik: t('efatura.islem.kullanici'),
                render: (k) => k.kullanici_adi,
              },
              {
                anahtar: 'islem',
                baslik: t('efatura.islem.islem'),
                render: (k) => (
                  <span className="font-semibold whitespace-nowrap">
                    {t(`efatura.islem.tur.${k.islem}`)}
                  </span>
                ),
              },
              {
                anahtar: 'kaynak',
                baslik: t('efatura.kolon.entegrator'),
                render: (k) => (k.saglayici === '' ? '—' : t(`efatura.saglayici.${k.saglayici}`)),
              },
              {
                anahtar: 'belge',
                baslik: t('efatura.kolon.faturaNo'),
                render: (k) => <span className="whitespace-nowrap">{k.belge_no ?? '—'}</span>,
              },
              {
                anahtar: 'ayrinti',
                baslik: t('efatura.islem.ayrinti'),
                render: (k) => islemAyrintisi(t, k) || '—',
              },
            ]}
            satirlar={liste.data?.data ?? []}
            satirAnahtari={(k) => k.id}
            yukleniyor={liste.isPending}
            yaziBoyutu="kucuk"
          />
          {liste.data ? (
            <Pagination
              sayfa={liste.data.meta.current_page}
              toplamSayfa={liste.data.meta.last_page}
              sayfaDegistir={setSayfa}
              disabled={liste.isFetching}
              boyutSeciciId="islem-gecmisi-sayfa-boyutu"
            />
          ) : null}
        </div>
      )}
    </>
  )
}
