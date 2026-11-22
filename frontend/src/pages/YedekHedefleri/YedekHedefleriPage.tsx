import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { hataGovdesi, istanbulZamani } from '@/features/yedekleme/bicim'
import {
  googleBaglantiAdresi,
  googleBaglantisiniKes,
  googleKlasorKaydet,
  googleSeciciBilgisi,
  hedefleriGetir,
  hedefSil,
  hedefTurleriGetir,
  sunucuAnahtariniSifirla,
  type Hedef,
  type HedefTuruTanimi,
} from '@/features/yedekleme/hedefApi'
import { Rozet, type RozetRengi } from '@/features/yedekleme/Rozet'
import type { KopyaDurumu } from '@/features/yedekleme/yedekApi'
import { useIzin } from '@/hooks/useIzin'

import { googleKlasorSec } from './googleKlasorSecici'
import { HedefFormu } from './HedefFormu'

const KOPYA_RENGI: Record<KopyaDurumu, RozetRengi> = {
  bekliyor: 'gri',
  kopyalaniyor: 'mavi',
  tamam: 'yesil',
  basarisiz: 'kirmizi',
  silindi: 'gri',
  konum_belirsiz: 'sari',
}

type OnayIslemi = 'sil' | 'google_kes' | 'anahtar_sifirla'

/** Google hesabı bağlantısının durumu (BY-021): bağlayıcının `baglanti`'ya yazdığı alanlardan */
function googleDurumu(hedef: Hedef): 'yeniden_baglan' | 'bagli' | 'bagli_degil' {
  if (hedef.baglanti['durum'] === 'yeniden_baglan') {
    return 'yeniden_baglan'
  }

  return typeof hedef.baglanti['eposta'] === 'string' ? 'bagli' : 'bagli_degil'
}

/** Google Picker betiği yüklenemedi / açılamadı (sunucu hatasından ayrı mesaj) */
class SeciciYuklenemedi extends Error {}

/** Google Drive dönüş sayfasının listeye bıraktığı istek: bu hedefte klasör seçimi açılsın */
interface DonusDurumu {
  klasorSecilecek?: number
}

const YENI_KLASOR_ADI = 'eMOR Yedekleri'

/** Yeni hedef: önce tür seçilir; form o türün tanımıyla çizilir */
function YeniHedefPenceresi({ turler, kapat }: { turler: HedefTuruTanimi[]; kapat: () => void }) {
  const { t } = useTranslation()
  const [tur, setTur] = useState<string | null>(null)
  const tanim = turler.find((aday) => aday.tur === tur) ?? null
  const secenekler: SecenekOgesi[] = turler.map((aday) => ({
    value: aday.tur,
    label: t(`yedekleme.hedef.tur.${aday.tur}`, { defaultValue: aday.tur }),
  }))

  return (
    <div className="space-y-4">
      <SelectField
        id="hedef-tur"
        label={t('yedekleme.hedef.turSec')}
        options={secenekler}
        value={secenekler.find((secenek) => secenek.value === tur) ?? null}
        onChange={(secenek) => setTur(secenek?.value ?? null)}
        isSearchable={false}
      />
      {tanim === null ? (
        <div className="flex justify-end">
          <Button variant="secondary" onClick={kapat}>
            {t('ortak.iptal')}
          </Button>
        </div>
      ) : (
        <HedefFormu key={tanim.tur} tanim={tanim} hedef={null} kapat={kapat} />
      )}
    </div>
  )
}

export function YedekHedefleriPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const location = useLocation()
  const navigate = useNavigate()
  const guncelleyebilir = useIzin('yedek_planlari.guncelle')
  // null: kapalı; 'yeni': yeni hedef; Hedef: düzenleme
  const [formdaki, setFormdaki] = useState<Hedef | 'yeni' | null>(null)
  const [onayBekleyen, setOnayBekleyen] = useState<{ islem: OnayIslemi; hedef: Hedef } | null>(null)

  const hedefler = useQuery({ queryKey: queryKeys.yedekleme.hedefler, queryFn: hedefleriGetir })
  const turler = useQuery({
    queryKey: queryKeys.yedekleme.hedefTurleri,
    queryFn: hedefTurleriGetir,
    staleTime: Infinity,
  })
  const tanimBul = (tur: string) => turler.data?.find((tanim) => tanim.tur === tur) ?? null

  // Klasör seçimi: Google'ın penceresi açılır, seçilen klasör sunucuda denenip kaydedilir.
  // Pencere açıkken ikinci tıklama ikinci pencere / istek açmasın (F5: isPending bir çizim geç gelir)
  const klasorIsiSuruyor = useRef(false)
  const klasorSonucu = async (hedef: Hedef | null) => {
    if (hedef === null) {
      return
    }
    await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.hedefler })
    toast.success(
      t('yedekleme.hedef.google.klasorKaydedildi', { ad: hedef.baglanti['klasor_adi'] ?? '' }),
    )
  }
  const klasorHatasi = (error: unknown) =>
    toast.error(
      error instanceof SeciciYuklenemedi
        ? t('yedekleme.hedef.google.seciciYuklenemedi')
        : t(apiErrorKey(error)),
    )
  const klasorSec = useMutation({
    mutationFn: async (hedef: Hedef) => {
      const bilgi = await googleSeciciBilgisi(hedef.id)
      const secilen = await googleKlasorSec(bilgi, {
        baslik: t('yedekleme.hedef.google.seciciBasligi'),
        dil: i18n.language,
      }).catch(() => {
        throw new SeciciYuklenemedi()
      })

      return secilen === null ? null : googleKlasorKaydet(hedef.id, { klasor_kimligi: secilen.id })
    },
    onSuccess: klasorSonucu,
    onError: klasorHatasi,
    onSettled: () => {
      klasorIsiSuruyor.current = false
    },
  })
  const [yeniKlasorHedefi, setYeniKlasorHedefi] = useState<Hedef | null>(null)
  const [yeniKlasorAdi, setYeniKlasorAdi] = useState(YENI_KLASOR_ADI)
  const yeniKlasor = useMutation({
    mutationFn: ({ hedef, ad }: { hedef: Hedef; ad: string }) =>
      googleKlasorKaydet(hedef.id, { yeni_klasor_adi: ad }),
    onSuccess: async (hedef) => {
      setYeniKlasorHedefi(null)
      await klasorSonucu(hedef)
    },
    onError: klasorHatasi,
    onSettled: () => {
      klasorIsiSuruyor.current = false
    },
  })
  const klasorSecimiBaslat = (hedef: Hedef) => {
    if (klasorIsiSuruyor.current) {
      return
    }
    klasorIsiSuruyor.current = true
    klasorSec.mutate(hedef)
  }
  const yeniKlasoruAc = () => {
    const ad = yeniKlasorAdi.trim()
    if (yeniKlasorHedefi === null || ad === '' || klasorIsiSuruyor.current) {
      return
    }
    klasorIsiSuruyor.current = true
    yeniKlasor.mutate({ hedef: yeniKlasorHedefi, ad })
  }

  // "Google ile bağlan"dan dönüldü ve klasör henüz seçilmedi: seçim penceresi bir kez açılır
  const donusIstegi = (location.state as DonusDurumu | null)?.klasorSecilecek
  const donusIslendi = useRef(false)
  useEffect(() => {
    if (donusIstegi === undefined || !hedefler.data || donusIslendi.current) {
      return
    }
    donusIslendi.current = true
    void navigate(location.pathname, { replace: true, state: null })
    const hedef = hedefler.data.find((aday) => aday.id === donusIstegi)
    if (hedef && typeof hedef.baglanti['klasor_kimligi'] !== 'string') {
      klasorSecimiBaslat(hedef)
    }
    // klasorSecimiBaslat her çizimde yeniden tanımlanır; tetikleyici yalnız dönüş isteği ve liste
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [donusIstegi, hedefler.data])

  const onayliIslem = useMutation({
    mutationFn: async ({ islem, hedef }: { islem: OnayIslemi; hedef: Hedef }) => {
      if (islem === 'sil') {
        await hedefSil(hedef.id)
      } else if (islem === 'google_kes') {
        await googleBaglantisiniKes(hedef.id)
      } else {
        await sunucuAnahtariniSifirla(hedef.id)
      }
    },
    onSuccess: async (_sonuc, { islem }) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.hedefler })
      toast.success(t(`yedekleme.hedef.basarili.${islem}`))
    },
    onError: (error: unknown) => {
      const govde = hataGovdesi<{ plan_sayisi?: number; kopya_sayisi?: number }>(error)
      toast.error(
        t(apiErrorKey(error), { plan: govde?.plan_sayisi ?? 0, kopya: govde?.kopya_sayisi ?? 0 }),
      )
    },
    onSettled: () => setOnayBekleyen(null),
  })

  // Tarayıcı Google'a gider; dönüş /ayarlar/yedek-hedefleri/google-donus
  const googleBaglan = useMutation({
    mutationFn: (hedef: Hedef) => googleBaglantiAdresi(hedef.id),
    onSuccess: (adres) => window.location.assign(adres),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const tumKolonlar: DataTableKolonu<Hedef>[] = [
    {
      anahtar: 'ad',
      baslik: t('yedekleme.hedef.ad'),
      render: (hedef) => (
        <div className="flex flex-wrap items-center gap-2">
          <strong>{hedef.ad}</strong>
          {hedef.aktif ? null : <Rozet renk="gri">{t('yedekleme.hedef.pasif')}</Rozet>}
        </div>
      ),
    },
    {
      anahtar: 'tur',
      baslik: t('yedekleme.hedef.turEtiketi'),
      render: (hedef) => (
        <div className="space-y-1">
          <p className="whitespace-nowrap">
            {t(`yedekleme.hedef.tur.${hedef.tur}`, { defaultValue: hedef.tur })}
          </p>
          {hedef.sunucu_disi ? <Rozet renk="mavi">{t('yedekleme.hedef.sunucuDisi')}</Rozet> : null}
        </div>
      ),
    },
    {
      anahtar: 'baglanti',
      baslik: t('yedekleme.hedef.baglantiDurumu'),
      render: (hedef) => {
        const parmakIzi = hedef.baglanti['sunucu_parmak_izi']
        const eposta = hedef.baglanti['eposta']
        const klasorKimligi = hedef.baglanti['klasor_kimligi']
        const klasorAdi = hedef.baglanti['klasor_adi']
        const google = tanimBul(hedef.tur)?.oauth ? googleDurumu(hedef) : null

        return (
          <div className="max-w-xs space-y-1">
            {google === 'yeniden_baglan' ? (
              <p
                role="alert"
                className="rounded-lg bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-950 dark:text-red-300"
              >
                {t('yedekleme.hedef.google.yenidenBaglanin')}
              </p>
            ) : null}
            {google === 'bagli' ? (
              <Rozet renk="yesil">{t('yedekleme.hedef.google.bagli')}</Rozet>
            ) : null}
            {google === 'bagli_degil' ? (
              <Rozet renk="sari">{t('yedekleme.hedef.google.bagliDegil')}</Rozet>
            ) : null}
            {typeof eposta === 'string' ? <p className="text-xs break-all">{eposta}</p> : null}
            {google !== null && typeof eposta === 'string' ? (
              typeof klasorKimligi === 'string' ? (
                <p className="text-xs break-all">
                  {t('yedekleme.hedef.google.klasor')}:{' '}
                  <a
                    href={`https://drive.google.com/drive/folders/${encodeURIComponent(klasorKimligi)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-semibold text-blue-700 underline dark:text-blue-300"
                  >
                    {typeof klasorAdi === 'string' && klasorAdi !== '' ? klasorAdi : klasorKimligi}
                  </a>
                </p>
              ) : (
                <Rozet renk="sari">{t('yedekleme.hedef.google.klasorSecilmedi')}</Rozet>
              )
            ) : null}
            {typeof parmakIzi === 'string' && parmakIzi !== '' ? (
              <p className="font-mono text-xs break-all text-slate-500 dark:text-slate-400">
                {parmakIzi}
              </p>
            ) : null}
            {google === null && (typeof parmakIzi !== 'string' || parmakIzi === '') ? (
              <span className="text-slate-400">—</span>
            ) : null}
          </div>
        )
      },
    },
    {
      anahtar: 'son_kopya',
      baslik: t('yedekleme.hedef.sonKopya'),
      render: (hedef) =>
        hedef.son_kopya === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <div className="space-y-1">
            <Rozet renk={KOPYA_RENGI[hedef.son_kopya.durum]}>
              {t(`yedekleme.kopyaDurumu.${hedef.son_kopya.durum}`)}
            </Rozet>
            <p className="text-xs whitespace-nowrap tabular-nums">
              {istanbulZamani(hedef.son_kopya.zaman)}
            </p>
            {hedef.son_kopya.hata_kodu ? (
              <p className="max-w-xs text-xs text-red-600 dark:text-red-400">
                {t(`hata.${hedef.son_kopya.hata_kodu}`, {
                  defaultValue: hedef.son_kopya.hata_kodu,
                })}
              </p>
            ) : null}
          </div>
        ),
    },
    {
      anahtar: 'plan_sayisi',
      baslik: t('yedekleme.hedef.planSayisi'),
      hizala: 'sag',
      render: (hedef) => <span className="tabular-nums">{hedef.plan_sayisi}</span>,
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (hedef) => {
        const google = tanimBul(hedef.tur)?.oauth ? googleDurumu(hedef) : null
        const parmakIzi = hedef.baglanti['sunucu_parmak_izi']
        const klasorSecili = typeof hedef.baglanti['klasor_kimligi'] === 'string'
        // Kopyası duran hedefin klasörü değişmez (sunucu konum_belirsiz kopyayı da sayar, 409)
        const klasorKilitli = hedef.konum_kilitli_alanlar.includes('klasor_kimligi')

        return (
          <div className="flex flex-wrap justify-end gap-2">
            {google === null ? null : (
              <Button
                variant={google === 'bagli' ? 'secondary' : 'primary'}
                yukleniyor={googleBaglan.isPending && googleBaglan.variables.id === hedef.id}
                onClick={() => googleBaglan.mutate(hedef)}
              >
                {t(
                  google === 'bagli_degil'
                    ? 'yedekleme.hedef.google.baglan'
                    : 'yedekleme.hedef.google.yenidenBaglan',
                )}
              </Button>
            )}
            {google === 'bagli' ? (
              <>
                <Button
                  variant={klasorSecili ? 'secondary' : 'primary'}
                  disabled={klasorKilitli || klasorSec.isPending || yeniKlasor.isPending}
                  yukleniyor={klasorSec.isPending && klasorSec.variables.id === hedef.id}
                  title={klasorKilitli ? t('yedekleme.hedef.google.klasorKilitli') : undefined}
                  onClick={() => klasorSecimiBaslat(hedef)}
                >
                  {t('yedekleme.hedef.google.klasorSec')}
                </Button>
                <Button
                  variant="secondary"
                  disabled={klasorKilitli || klasorSec.isPending || yeniKlasor.isPending}
                  title={klasorKilitli ? t('yedekleme.hedef.google.klasorKilitli') : undefined}
                  onClick={() => {
                    setYeniKlasorAdi(YENI_KLASOR_ADI)
                    setYeniKlasorHedefi(hedef)
                  }}
                >
                  {t('yedekleme.hedef.google.yeniKlasor')}
                </Button>
                <Button
                  variant="secondary"
                  onClick={() => setOnayBekleyen({ islem: 'google_kes', hedef })}
                >
                  {t('yedekleme.hedef.google.kes')}
                </Button>
              </>
            ) : null}
            {typeof parmakIzi === 'string' && parmakIzi !== '' ? (
              <Button
                variant="secondary"
                onClick={() => setOnayBekleyen({ islem: 'anahtar_sifirla', hedef })}
              >
                {t('yedekleme.hedef.sftp.sifirla')}
              </Button>
            ) : null}
            <Button variant="turuncu" onClick={() => setFormdaki(hedef)}>
              {t('ortak.duzenle')}
            </Button>
            <Button variant="danger" onClick={() => setOnayBekleyen({ islem: 'sil', hedef })}>
              {t('ortak.sil')}
            </Button>
          </div>
        )
      },
    },
  ]
  // Yalnız görüntüleme izninde işlem kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  const duzenlenenTanim = formdaki !== null && formdaki !== 'yeni' ? tanimBul(formdaki.tur) : null
  const onayIslemi = onayBekleyen?.islem ?? 'sil'

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('yedekleme.hedef.baslik')}</h2>
        {guncelleyebilir ? (
          <Button variant="mor" disabled={!turler.data} onClick={() => setFormdaki('yeni')}>
            {t('yedekleme.hedef.yeni')}
          </Button>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.hedef.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4">
        {hedefler.isError || turler.isError ? (
          <ErrorState
            mesaj={t(apiErrorKey(hedefler.error ?? turler.error))}
            tekrarDene={() => {
              void hedefler.refetch()
              void turler.refetch()
            }}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={hedefler.data ?? []}
            satirAnahtari={(hedef) => hedef.id}
            yukleniyor={hedefler.isPending || turler.isPending}
          />
        )}
      </div>

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        boyut="genis"
        baslik={t(formdaki === 'yeni' ? 'yedekleme.hedef.yeni' : 'yedekleme.hedef.duzenle')}
      >
        {formdaki === 'yeni' && turler.data ? (
          <YeniHedefPenceresi turler={turler.data} kapat={() => setFormdaki(null)} />
        ) : null}
        {formdaki !== null && formdaki !== 'yeni' ? (
          duzenlenenTanim === null ? (
            <p className="text-sm text-red-700 dark:text-red-300">
              {t('yedekleme.hedef.turDesteklenmiyor')}
            </p>
          ) : (
            <HedefFormu
              key={formdaki.id}
              tanim={duzenlenenTanim}
              hedef={formdaki}
              kapat={() => setFormdaki(null)}
            />
          )
        ) : null}
      </Modal>

      <Modal
        acik={yeniKlasorHedefi !== null}
        kapat={() => {
          if (!yeniKlasor.isPending) {
            setYeniKlasorHedefi(null)
          }
        }}
        baslik={t('yedekleme.hedef.google.yeniKlasor')}
      >
        <form
          className="space-y-4"
          onSubmit={(olay) => {
            olay.preventDefault()
            yeniKlasoruAc()
          }}
        >
          <p className="text-sm text-slate-600 dark:text-slate-400">
            {t('yedekleme.hedef.google.yeniKlasorAciklama', {
              eposta: yeniKlasorHedefi?.baglanti['eposta'] ?? '',
            })}
          </p>
          <Input
            id="yeni-klasor-adi"
            label={t('yedekleme.hedef.google.klasorAdi')}
            value={yeniKlasorAdi}
            maxLength={100}
            readOnly={yeniKlasor.isPending}
            onChange={(olay) => setYeniKlasorAdi(olay.target.value)}
          />
          <div className="flex justify-end gap-2">
            <Button
              type="button"
              variant="secondary"
              disabled={yeniKlasor.isPending}
              onClick={() => setYeniKlasorHedefi(null)}
            >
              {t('ortak.iptal')}
            </Button>
            <Button
              type="submit"
              disabled={yeniKlasorAdi.trim() === ''}
              yukleniyor={yeniKlasor.isPending}
            >
              {t('yedekleme.hedef.google.yeniKlasorAc')}
            </Button>
          </div>
        </form>
      </Modal>

      <ConfirmDialog
        acik={onayBekleyen !== null}
        kapat={() => setOnayBekleyen(null)}
        baslik={t(`yedekleme.hedef.onay.${onayIslemi}.baslik`)}
        mesaj={t(`yedekleme.hedef.onay.${onayIslemi}.mesaj`, { ad: onayBekleyen?.hedef.ad ?? '' })}
        onayEtiketi={t(`yedekleme.hedef.onay.${onayIslemi}.etiket`)}
        yukleniyor={onayliIslem.isPending}
        onayla={() => {
          if (onayBekleyen !== null) {
            onayliIslem.mutate(onayBekleyen)
          }
        }}
      />
    </>
  )
}
