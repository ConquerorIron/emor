import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu, type TabloSiralamasi } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { Pagination } from '@/components/Pagination'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { Switch } from '@/components/Switch'
import { boyutGoster, istanbulZamani } from '@/features/yedekleme/bicim'
import { kopyaGeriYukle } from '@/features/yedekleme/geriYuklemeApi'
import { IstekSeridi } from '@/features/yedekleme/IstekSeridi'
import { Rozet, type RozetRengi } from '@/features/yedekleme/Rozet'
import { useIsBirakma } from '@/features/yedekleme/useIsBirakma'
import { useIstekTakibi } from '@/features/yedekleme/useIstekTakibi'
import {
  aciklamaKaydet,
  DOGRULAMA_DURUMLARI,
  klasoruTara,
  sabitlemeyiKaldir,
  YEDEK_KAYNAKLARI,
  yedegiDogrula,
  yedegiSabitle,
  yedegiSil,
  yedekAl,
  yedekleriGetir,
  yedekOzetiGetir,
  type DogrulamaDurumu,
  type IstekKabulu,
  type KopyaDurumu,
  type UcluSecim,
  type Yedek,
  type YedekDurumu,
  type YedekFiltresi,
  type YedekKaynagi,
  type YedekSiralamasi,
} from '@/features/yedekleme/yedekApi'
import { useAuth } from '@/hooks/useAuth'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'

import { KopyaGeriYukleFormu } from './KopyaGeriYukleFormu'
import { OzetSeridi } from './OzetSeridi'
import { UstuneGeriYukleFormu } from './UstuneGeriYukleFormu'

/** Backend açıklama sınırı (YedekController: max 500) */
const ACIKLAMA_LIMITI = 500

const BOS_FILTRE: YedekFiltresi = {
  kaynak: '',
  dogrulama: '',
  sabit: '',
  guvenilmeyen: '',
  silinenler: false,
}

const VARSAYILAN_SIRALAMA: YedekSiralamasi = { anahtar: 'alinma_zamani', yon: 'desc' }

/** Saklanan tercih bu uçta geçersiz bir kolon olabilir: yalnız tarih / boyut */
function gecerliSiralama(siralama: TabloSiralamasi | null): YedekSiralamasi {
  return siralama?.anahtar === 'alinma_zamani' || siralama?.anahtar === 'boyut'
    ? { anahtar: siralama.anahtar, yon: siralama.yon }
    : VARSAYILAN_SIRALAMA
}

const DOGRULAMA_RENGI: Record<DogrulamaDurumu, RozetRengi> = {
  dogrulanmadi: 'gri',
  ozet_tamam: 'mavi',
  sinama_gecti: 'yesil',
  basarisiz: 'kirmizi',
}

const YEDEK_DURUM_RENGI: Record<YedekDurumu, RozetRengi> = {
  kuyrukta: 'mavi',
  calisiyor: 'mavi',
  tamam: 'yesil',
  basarisiz: 'kirmizi',
  silindi: 'gri',
}

const KOPYA_RENGI: Record<KopyaDurumu, RozetRengi> = {
  bekliyor: 'gri',
  kopyalaniyor: 'mavi',
  tamam: 'yesil',
  basarisiz: 'kirmizi',
  silindi: 'gri',
  konum_belirsiz: 'sari',
}

/** Çalıştırıcıya bırakılan Yedekler işleri */
type YedekIstegi =
  | { tur: 'yedek_al'; aciklama: string | null }
  | { tur: 'tara' }
  | { tur: 'dogrula' | 'sabitle' | 'sabit_coz' | 'sil'; kimlik: string }
  | { tur: 'aciklama'; kimlik: string; aciklama: string | null }
  | { tur: 'kopya_geri_yukle'; kimlik: string; ek: string | null }

function istegiBirak(istek: YedekIstegi, anahtar: string): Promise<IstekKabulu> {
  switch (istek.tur) {
    case 'kopya_geri_yukle':
      return kopyaGeriYukle(istek.kimlik, istek.ek, anahtar)
    case 'yedek_al':
      return yedekAl(istek.aciklama, anahtar)
    case 'tara':
      return klasoruTara(anahtar)
    case 'dogrula':
      return yedegiDogrula(istek.kimlik, anahtar)
    case 'sabitle':
      return yedegiSabitle(istek.kimlik, anahtar)
    case 'sabit_coz':
      return sabitlemeyiKaldir(istek.kimlik, anahtar)
    case 'sil':
      return yedegiSil(istek.kimlik, anahtar)
    case 'aciklama':
      return aciklamaKaydet(istek.kimlik, istek.aciklama, anahtar)
  }
}

/** "Şimdi Yedek Al" ve "Not" pencerelerinin ortak formu: tek açıklama alanı */
function AciklamaFormu({
  id,
  baslangic,
  onayEtiketi,
  yukleniyor,
  gonder,
  kapat,
}: {
  id: string
  baslangic: string
  onayEtiketi: string
  yukleniyor: boolean
  gonder: (aciklama: string | null) => void
  kapat: () => void
}) {
  const { t } = useTranslation()
  const [aciklama, setAciklama] = useState(baslangic)

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(olay) => {
        olay.preventDefault()
        gonder(aciklama.trim() === '' ? null : aciklama.trim())
      }}
    >
      <SinirliMetinInput
        id={id}
        label={t('yedekleme.yedekler.aciklama')}
        value={aciklama}
        onChange={setAciklama}
        limit={ACIKLAMA_LIMITI}
        rows={3}
        disabled={yukleniyor}
      />
      <div className="flex justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={yukleniyor}>
          {onayEtiketi}
        </Button>
      </div>
    </form>
  )
}

export function YedeklerPage() {
  const { t } = useTranslation()
  const alabilir = useIzin('yedekler.al')
  const silebilir = useIzin('yedekler.sil')
  const kopyalayabilir = useIzin('geri_yukleme.kopya')
  // Üstüne geri yükleme izinle verilmez: yalnız sistem yöneticisi (README §4.9)
  const kullanici = useAuth().user
  const yonetici = kullanici?.sistem_yoneticisi === true
  const { calisanlar, calisiyor, baslat } = useIstekTakibi('yedekler')

  const [filtre, setFiltre] = useState<YedekFiltresi>(BOS_FILTRE)
  const [sayfa, setSayfa] = useState(1)
  const { siralama: kaliciSiralama, siralamaDegistir } = useKaliciSiralama(
    'yedekler',
    VARSAYILAN_SIRALAMA,
  )
  const siralama = gecerliSiralama(kaliciSiralama)

  const [yeniYedekAcik, setYeniYedekAcik] = useState(false)
  const [notDuzenlenen, setNotDuzenlenen] = useState<Yedek | null>(null)
  const [kopyaYuklenecek, setKopyaYuklenecek] = useState<Yedek | null>(null)
  const [ustuneYuklenecek, setUstuneYuklenecek] = useState<Yedek | null>(null)
  const [onayBekleyen, setOnayBekleyen] = useState<{
    tur: 'sil' | 'sabit_coz'
    yedek: Yedek
  } | null>(null)

  const liste = useQuery({
    queryKey: queryKeys.yedekleme.yedekler({ filtre, siralama, sayfa }),
    queryFn: () => yedekleriGetir(filtre, siralama, sayfa),
    placeholderData: keepPreviousData,
  })
  const ozet = useQuery({ queryKey: queryKeys.yedekleme.ozet, queryFn: yedekOzetiGetir })

  // Ekrandaki satırlar yeni süzgecin sonucu değilse satır işlemleri kapalı (F1)
  const yenileniyor = liste.isFetching || liste.isPaused || liste.isPlaceholderData

  // Silme ya da süzgeçle kayıtlar azalınca boş sayfada kalınmaz
  const sonSayfa = liste.isPlaceholderData ? undefined : liste.data?.meta.last_page
  useEffect(() => {
    if (sonSayfa !== undefined && sayfa > Math.max(sonSayfa, 1)) {
      setSayfa(Math.max(sonSayfa, 1))
    }
  }, [sonSayfa, sayfa])

  // Çift tıklama kilidi + yeniden denemede aynı istek anahtarı (BY-015-R1,
  // BY-014-R2…R4): ortak akış useIsBirakma
  const { birak: isiBirak, gonderiliyor: isBirakiliyor } = useIsBirakma(
    istegiBirak,
    (kabul, gonderilen: YedekIstegi) => {
      baslat(kabul, gonderilen.tur)
      setYeniYedekAcik(false)
      setNotDuzenlenen(null)
      setOnayBekleyen(null)
      setKopyaYuklenecek(null)
    },
  )
  // Üstüne geri yükleme yalnız servis çalıştırıcısında başlatılabilir (YF-63)
  const ustuneHazir = ozet.data?.ustune_geri_yukleme_hazir === true
  // İş sürerken (yoklanırken) ve liste tazelenirken işlem başlatılmaz
  const kilitli = calisiyor || isBirakiliyor || yenileniyor

  const filtreDegistir = (degisiklik: Partial<YedekFiltresi>) => {
    setFiltre((onceki) => ({ ...onceki, ...degisiklik }))
    setSayfa(1)
  }

  const kaynakSecenekleri: SecenekOgesi[] = YEDEK_KAYNAKLARI.map((kaynak) => ({
    value: kaynak,
    label: t(`yedekleme.kaynak.${kaynak}`),
  }))
  const dogrulamaSecenekleri: SecenekOgesi[] = DOGRULAMA_DURUMLARI.map((durum) => ({
    value: durum,
    label: t(`yedekleme.dogrulama.${durum}`),
  }))
  const sabitSecenekleri: SecenekOgesi[] = [
    { value: '1', label: t('yedekleme.yedekler.yalnizSabitler') },
    { value: '0', label: t('yedekleme.yedekler.sabitOlmayanlar') },
  ]
  const guvenSecenekleri: SecenekOgesi[] = [
    { value: '1', label: t('yedekleme.yedekler.yalnizGuvenilmeyenler') },
    { value: '0', label: t('yedekleme.yedekler.yalnizImzalilar') },
  ]

  const islemYetkisi = alabilir || silebilir || kopyalayabilir || yonetici

  const tumKolonlar: DataTableKolonu<Yedek>[] = [
    {
      anahtar: 'alinma_zamani',
      baslik: t('yedekleme.yedekler.tarih'),
      siralamaAnahtari: 'alinma_zamani',
      render: (yedek) => (
        <div className="min-w-48">
          <p className="font-semibold whitespace-nowrap tabular-nums">
            {istanbulZamani(yedek.alinma_zamani)}
          </p>
          <p className="font-mono text-xs break-all text-slate-500 dark:text-slate-400">
            {yedek.dosya_adi}
          </p>
          {yedek.imzali && !yedek.ice_alindi ? null : (
            <div className="mt-1 flex flex-wrap gap-1">
              {yedek.imzali ? null : (
                <Rozet renk="kirmizi" title={t('yedekleme.yedekler.guvenilmeyenAciklama')}>
                  {t('yedekleme.yedekler.guvenilmeyen')}
                </Rozet>
              )}
              {yedek.ice_alindi ? (
                <Rozet renk="sari">{t('yedekleme.yedekler.iceAlindi')}</Rozet>
              ) : null}
            </div>
          )}
        </div>
      ),
    },
    {
      anahtar: 'kaynak',
      baslik: t('yedekleme.yedekler.kaynak'),
      render: (yedek) => <Rozet renk="gri">{t(`yedekleme.kaynak.${yedek.kaynak}`)}</Rozet>,
    },
    {
      anahtar: 'boyut',
      baslik: t('yedekleme.yedekler.boyut'),
      siralamaAnahtari: 'boyut',
      hizala: 'sag',
      render: (yedek) => (
        <span className="whitespace-nowrap tabular-nums">{boyutGoster(yedek.boyut)}</span>
      ),
    },
    {
      anahtar: 'dogrulama',
      baslik: t('yedekleme.yedekler.dogrulama'),
      render: (yedek) => (
        <div className="space-y-1">
          {yedek.durum === 'tamam' ? (
            <Rozet renk={DOGRULAMA_RENGI[yedek.dogrulama_durumu]}>
              {t(`yedekleme.dogrulama.${yedek.dogrulama_durumu}`)}
            </Rozet>
          ) : (
            <Rozet renk={YEDEK_DURUM_RENGI[yedek.durum]}>
              {t(`yedekleme.yedekDurumu.${yedek.durum}`)}
            </Rozet>
          )}
          {yedek.hata_kodu ? (
            <p className="max-w-xs text-xs text-red-600 dark:text-red-400">
              {t(`hata.${yedek.hata_kodu}`, { defaultValue: yedek.hata_kodu })}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'kopyalar',
      baslik: t('yedekleme.yedekler.kopyalar'),
      render: (yedek) => (
        <div className="space-y-1">
          {yedek.yerel_dosya_var || yedek.durum === 'silindi' ? null : (
            <p className="text-xs text-slate-500 dark:text-slate-400">
              {t('yedekleme.yedekler.yerelDosyaYok')}
            </p>
          )}
          {yedek.kopyalar.length === 0 ? (
            <span className="text-slate-400">—</span>
          ) : (
            <ul className="space-y-1">
              {yedek.kopyalar.map((kopya, sira) => (
                // Hedefi tanımsız kopyaların hedef_id'si boştur (BY-028-R9)
                <li
                  key={kopya.hedef_id ?? `tanimsiz-${sira}`}
                  className="flex flex-wrap items-center gap-1.5"
                >
                  <span className="text-xs font-semibold">{kopya.hedef_ad ?? '—'}</span>
                  <Rozet
                    renk={KOPYA_RENGI[kopya.durum]}
                    title={
                      kopya.hata_kodu
                        ? t(`hata.${kopya.hata_kodu}`, { defaultValue: kopya.hata_kodu })
                        : istanbulZamani(kopya.tamamlanma_zamani)
                    }
                  >
                    {t(`yedekleme.kopyaDurumu.${kopya.durum}`)}
                  </Rozet>
                  {kopya.sifreli ? (
                    <span className="text-xs text-slate-500 dark:text-slate-400">
                      {t('yedekleme.yedekler.sifreli')}
                    </span>
                  ) : null}
                  {kopya.durum === 'tamam' && !kopya.kurtarma_hazir ? (
                    <span className="text-xs text-amber-700 dark:text-amber-400">
                      {t('yedekleme.yedekler.kurtarmaPaketiEksik')}
                    </span>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </div>
      ),
    },
    {
      anahtar: 'sabit',
      baslik: t('yedekleme.yedekler.sabit'),
      hizala: 'orta',
      render: (yedek) =>
        yedek.sabit ? (
          <Rozet renk="mor">{t('yedekleme.yedekler.sabitRozet')}</Rozet>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
    {
      anahtar: 'aciklama',
      baslik: t('yedekleme.yedekler.not'),
      render: (yedek) => (
        <div className="max-w-xs">
          {yedek.aciklama === null ? (
            <span className="text-slate-400">—</span>
          ) : (
            <p className="whitespace-pre-line">{yedek.aciklama}</p>
          )}
          {yedek.alan_kullanici ? (
            <p className="text-xs text-slate-500 dark:text-slate-400">
              {t('yedekleme.yedekler.alan', { ad: yedek.alan_kullanici })}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (yedek) => {
        const silinmis = yedek.durum === 'silindi'

        return (
          <div className="flex flex-wrap justify-end gap-2">
            {/* Doğrulama da geri yükleme motorunu kullanır: yalnız şifreli kopyası kalan yedekte açılmaz (BY-027-R1) */}
            {alabilir && yedek.webden_geri_yuklenebilir ? (
              <Button
                variant="secondary"
                disabled={kilitli}
                onClick={() => isiBirak({ tur: 'dogrula', kimlik: yedek.kimlik })}
              >
                {t('yedekleme.yedekler.dogrula')}
              </Button>
            ) : null}
            {alabilir && !silinmis ? (
              <Button variant="turuncu" disabled={kilitli} onClick={() => setNotDuzenlenen(yedek)}>
                {t('yedekleme.yedekler.notDuzenle')}
              </Button>
            ) : null}
            {silebilir && !silinmis ? (
              yedek.sabit ? (
                <Button
                  variant="secondary"
                  disabled={kilitli}
                  onClick={() => setOnayBekleyen({ tur: 'sabit_coz', yedek })}
                >
                  {t('yedekleme.yedekler.sabitCoz')}
                </Button>
              ) : (
                <Button
                  variant="secondary"
                  disabled={kilitli}
                  onClick={() => isiBirak({ tur: 'sabitle', kimlik: yedek.kimlik })}
                >
                  {t('yedekleme.yedekler.sabitle')}
                </Button>
              )
            ) : null}
            {/*
             * Geri yükleme yalnız `yedek.webden_geri_yuklenebilir` satırda:
             * imzalı, tamam ve ekrandan okunabilir bir kaynağı (yerel dosya ya
             * da şifresiz kopya) olan; güvenilmeyen (imzasız) yedekte hiçbir
             * zaman. Kaynağı yalnız şifreli kopya olan yedekte eylem yerine
             * kurtarma yolu yazılır (BY-027-R1). Üstüne geri yükleme ayrıca
             * içe alınmış eski yedekte yok (migration listesi yok: şema uyumu
             * denetlenemez) ve yalnız sistem yöneticisinde.
             */}
            {(yonetici || kopyalayabilir) &&
            yedek.geri_yuklenebilir &&
            !yedek.webden_geri_yuklenebilir &&
            yedek.geri_yukleme_engeli ? (
              <p className="max-w-xs text-right text-xs text-amber-700 dark:text-amber-300">
                {yedek.geri_yukleme_engeli === 'GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI'
                  ? t('yedekleme.yedekler.yalnizSifreliKopya')
                  : t(`hata.${yedek.geri_yukleme_engeli}`, {
                      defaultValue: yedek.geri_yukleme_engeli,
                    })}
              </p>
            ) : null}
            {yonetici && yedek.webden_geri_yuklenebilir && !yedek.ice_alindi ? (
              <Button
                variant="danger"
                disabled={kilitli || !ustuneHazir}
                title={
                  ozet.data && !ustuneHazir ? t('hata.GERI_YUKLEME_SERVIS_GEREKLI') : undefined
                }
                onClick={() => setUstuneYuklenecek(yedek)}
              >
                {t('yedekleme.ustune.dugme')}
              </Button>
            ) : null}
            {kopyalayabilir && yedek.webden_geri_yuklenebilir ? (
              <Button
                variant="secondary"
                disabled={kilitli}
                onClick={() => setKopyaYuklenecek(yedek)}
              >
                {t('yedekleme.geriYukleme.kopya.dugme')}
              </Button>
            ) : null}
            {silebilir && !silinmis ? (
              <Button
                variant="danger"
                disabled={kilitli || yedek.sabit}
                title={yedek.sabit ? t('yedekleme.yedekler.sabitSilinmez') : undefined}
                onClick={() => setOnayBekleyen({ tur: 'sil', yedek })}
              >
                {t('ortak.sil')}
              </Button>
            ) : null}
          </div>
        )
      },
    },
  ]
  // Yalnız görüntüleme izninde işlem kolonu çizilmez
  const kolonlar = islemYetkisi
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('yedekleme.yedekler.baslik')}</h2>
        {alabilir ? (
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              disabled={calisiyor || isBirakiliyor}
              onClick={() => isiBirak({ tur: 'tara' })}
            >
              {t('yedekleme.yedekler.klasoruTara')}
            </Button>
            <Button
              variant="mor"
              disabled={calisiyor || isBirakiliyor}
              onClick={() => setYeniYedekAcik(true)}
            >
              {t('yedekleme.yedekler.simdiYedekAl')}
            </Button>
          </div>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.yedekler.aciklamaMetni')}
      </p>

      <IstekSeridi calisanlar={calisanlar} />

      {ozet.isError ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(ozet.error))} tekrarDene={() => void ozet.refetch()} />
        </div>
      ) : null}
      {ozet.data ? <OzetSeridi ozet={ozet.data} /> : null}

      <div className="mt-5 grid grid-cols-1 items-end gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <SelectField
          id="yedek-kaynak"
          label={t('yedekleme.yedekler.kaynak')}
          options={kaynakSecenekleri}
          value={kaynakSecenekleri.find((s) => s.value === filtre.kaynak) ?? null}
          onChange={(s) => filtreDegistir({ kaynak: (s?.value ?? '') as YedekKaynagi | '' })}
          isClearable
          isSearchable={false}
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="yedek-dogrulama"
          label={t('yedekleme.yedekler.dogrulama')}
          options={dogrulamaSecenekleri}
          value={dogrulamaSecenekleri.find((s) => s.value === filtre.dogrulama) ?? null}
          onChange={(s) => filtreDegistir({ dogrulama: (s?.value ?? '') as DogrulamaDurumu | '' })}
          isClearable
          isSearchable={false}
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="yedek-sabit"
          label={t('yedekleme.yedekler.sabit')}
          options={sabitSecenekleri}
          value={sabitSecenekleri.find((s) => s.value === filtre.sabit) ?? null}
          onChange={(s) => filtreDegistir({ sabit: (s?.value ?? '') as UcluSecim })}
          isClearable
          isSearchable={false}
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id="yedek-guven"
          label={t('yedekleme.yedekler.imza')}
          options={guvenSecenekleri}
          value={guvenSecenekleri.find((s) => s.value === filtre.guvenilmeyen) ?? null}
          onChange={(s) => filtreDegistir({ guvenilmeyen: (s?.value ?? '') as UcluSecim })}
          isClearable
          isSearchable={false}
          placeholder={t('ortak.hepsi')}
        />
        <Switch
          id="yedek-silinenler"
          alanHizali
          label={t('yedekleme.yedekler.silinenleriGoster')}
          checked={filtre.silinenler}
          onChange={(silinenler) => filtreDegistir({ silinenler })}
        />
      </div>

      <div className={`mt-4 ${liste.isPlaceholderData ? 'opacity-60' : ''}`}>
        {liste.isError ? (
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={liste.data?.data ?? []}
            satirAnahtari={(yedek) => yedek.kimlik}
            yukleniyor={liste.isPending}
            siralama={siralama}
            siralamaDegistir={(anahtar) => {
              siralamaDegistir(anahtar)
              setSayfa(1)
            }}
            satirSinifi={(yedek) =>
              yedek.imzali
                ? undefined
                : 'bg-red-50/60 hover:bg-red-50 dark:bg-red-950/30 dark:hover:bg-red-950/50'
            }
          />
        )}
      </div>

      {liste.data && liste.data.meta.last_page > 1 ? (
        <div className="mt-3">
          <Pagination
            sayfa={sayfa}
            toplamSayfa={liste.data.meta.last_page}
            sayfaDegistir={setSayfa}
            disabled={yenileniyor}
          />
        </div>
      ) : null}

      <Modal
        acik={yeniYedekAcik}
        kapat={() => setYeniYedekAcik(false)}
        baslik={t('yedekleme.yedekler.simdiYedekAl')}
      >
        {yeniYedekAcik ? (
          <>
            <p className="mb-4 text-sm text-slate-600 dark:text-slate-400">
              {t('yedekleme.yedekler.yeniYedekAciklama')}
            </p>
            <AciklamaFormu
              id="yeni-yedek-aciklama"
              baslangic=""
              onayEtiketi={t('yedekleme.yedekler.yedekAl')}
              yukleniyor={isBirakiliyor}
              gonder={(aciklama) => isiBirak({ tur: 'yedek_al', aciklama })}
              kapat={() => setYeniYedekAcik(false)}
            />
          </>
        ) : null}
      </Modal>

      <Modal
        acik={notDuzenlenen !== null}
        kapat={() => setNotDuzenlenen(null)}
        baslik={t('yedekleme.yedekler.notBaslik', { dosya: notDuzenlenen?.dosya_adi ?? '' })}
      >
        {notDuzenlenen !== null ? (
          <AciklamaFormu
            id="yedek-not"
            baslangic={notDuzenlenen.aciklama ?? ''}
            onayEtiketi={t('ortak.kaydet')}
            yukleniyor={isBirakiliyor}
            gonder={(aciklama) =>
              isiBirak({ tur: 'aciklama', kimlik: notDuzenlenen.kimlik, aciklama })
            }
            kapat={() => setNotDuzenlenen(null)}
          />
        ) : null}
      </Modal>

      <Modal
        acik={kopyaYuklenecek !== null}
        kapat={() => setKopyaYuklenecek(null)}
        baslik={t('yedekleme.geriYukleme.kopya.baslik')}
      >
        {kopyaYuklenecek !== null ? (
          <KopyaGeriYukleFormu
            yedek={kopyaYuklenecek}
            yukleniyor={isBirakiliyor}
            gonder={(ek) =>
              isiBirak({ tur: 'kopya_geri_yukle', kimlik: kopyaYuklenecek.kimlik, ek })
            }
            kapat={() => setKopyaYuklenecek(null)}
          />
        ) : null}
      </Modal>

      <Modal
        acik={ustuneYuklenecek !== null}
        kapat={() => setUstuneYuklenecek(null)}
        boyut="genis"
        baslik={t('yedekleme.ustune.baslik')}
      >
        {ustuneYuklenecek !== null ? (
          <UstuneGeriYukleFormu
            yedek={ustuneYuklenecek}
            canliVeritabani={ozet.data?.canli_veritabani ?? null}
            kapat={() => setUstuneYuklenecek(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        acik={onayBekleyen !== null}
        kapat={() => setOnayBekleyen(null)}
        baslik={t(
          onayBekleyen?.tur === 'sabit_coz'
            ? 'yedekleme.yedekler.sabitCozOnayBaslik'
            : 'yedekleme.yedekler.silOnayBaslik',
        )}
        mesaj={t(
          onayBekleyen?.tur === 'sabit_coz'
            ? 'yedekleme.yedekler.sabitCozOnayMesaj'
            : 'yedekleme.yedekler.silOnayMesaj',
          { dosya: onayBekleyen?.yedek.dosya_adi ?? '' },
        )}
        onayEtiketi={t(
          onayBekleyen?.tur === 'sabit_coz' ? 'yedekleme.yedekler.sabitCoz' : 'ortak.sil',
        )}
        yukleniyor={isBirakiliyor}
        onayla={() => {
          if (onayBekleyen !== null) {
            isiBirak({ tur: onayBekleyen.tur, kimlik: onayBekleyen.yedek.kimlik })
          }
        }}
      />
    </>
  )
}
