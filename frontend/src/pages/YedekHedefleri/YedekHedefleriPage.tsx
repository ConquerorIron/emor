import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { hataGovdesi, istanbulZamani } from '@/features/yedekleme/bicim'
import {
  googleBaglantiAdresi,
  googleBaglantisiniKes,
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

import { HedefFormu } from './HedefFormu'

const KOPYA_RENGI: Record<KopyaDurumu, RozetRengi> = {
  bekliyor: 'gri',
  kopyalaniyor: 'mavi',
  tamam: 'yesil',
  basarisiz: 'kirmizi',
  silindi: 'gri',
}

type OnayIslemi = 'sil' | 'google_kes' | 'anahtar_sifirla'

/** Google hesabı bağlantısının durumu (BY-021): bağlayıcının `baglanti`'ya yazdığı alanlardan */
function googleDurumu(hedef: Hedef): 'yeniden_baglan' | 'bagli' | 'bagli_degil' {
  if (hedef.baglanti['durum'] === 'yeniden_baglan') {
    return 'yeniden_baglan'
  }

  return typeof hedef.baglanti['eposta'] === 'string' ? 'bagli' : 'bagli_degil'
}

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
  const { t } = useTranslation()
  const queryClient = useQueryClient()
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
              <Button
                variant="secondary"
                onClick={() => setOnayBekleyen({ islem: 'google_kes', hedef })}
              >
                {t('yedekleme.hedef.google.kes')}
              </Button>
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
