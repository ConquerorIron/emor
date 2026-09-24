import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { Modal } from '@/components/Modal'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { MultiSelectField } from '@/components/SelectField'
import { Switch } from '@/components/Switch'
import {
  kullaniciGuncelle,
  kullanicilariGetir,
  lokalKullaniciOlustur,
  rolleriGetir,
  type KullaniciGuncelleGovdesi,
  type Rol,
  type YonetilenKullanici,
} from '@/features/ayarlar/yetkiApi'
import { useIzin } from '@/hooks/useIzin'

// Şifre serbesttir (kullanıcı isteği): kural yok, yalnız boş olamaz. Düzenlemede
// boş bırakılırsa gönderilmez — mevcut şifre korunur (duzenleSemasi)
const SIFRE = z
  .string()
  .min(1, 'ayarlar.kullanicilar.dogrulama.sifreZorunlu')
  .max(255, 'ayarlar.kullanicilar.dogrulama.cokUzun')

const EPOSTA = z.union([z.literal(''), z.email('ayarlar.kullanicilar.dogrulama.epostaGecersiz')])

const yeniSemasi = z.object({
  kullanici_adi: z
    .string()
    .regex(/^[A-Za-z0-9._-]{3,64}$/, 'ayarlar.kullanicilar.dogrulama.kullaniciAdiBicimi'),
  ad: z
    .string()
    .trim()
    .min(1, 'ayarlar.kullanicilar.dogrulama.adZorunlu')
    .max(255, 'ayarlar.kullanicilar.dogrulama.cokUzun'),
  email: EPOSTA,
  sifre: SIFRE,
  rol_idleri: z.array(z.number()),
})

const duzenleSemasi = z.object({
  ad: z.string().max(255, 'ayarlar.kullanicilar.dogrulama.cokUzun'),
  email: EPOSTA,
  sifre: z.union([z.literal(''), SIFRE]),
  aktif_mi: z.boolean(),
  rol_idleri: z.array(z.number()),
})

type YeniGirdisi = z.infer<typeof yeniSemasi>
type DuzenleGirdisi = z.infer<typeof duzenleSemasi>

function rolSecenekleri(roller: Rol[]) {
  return roller.map((rol) => ({ value: String(rol.id), label: rol.ad }))
}

function RolSecimi({
  id,
  roller,
  deger,
  degistir,
}: {
  id: string
  roller: Rol[]
  deger: number[]
  degistir: (idler: number[]) => void
}) {
  const { t } = useTranslation()
  const secenekler = rolSecenekleri(roller)

  return (
    <MultiSelectField
      id={id}
      label={t('ayarlar.kullanicilar.roller')}
      options={secenekler}
      value={secenekler.filter((secenek) => deger.includes(Number(secenek.value)))}
      onChange={(secilenler) => degistir(secilenler.map((secenek) => Number(secenek.value)))}
      placeholder={t('ayarlar.kullanicilar.rolSec')}
    />
  )
}

function HataKutusu({ mesaj }: { mesaj?: string }) {
  return mesaj ? (
    <p
      role="alert"
      className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
    >
      {mesaj}
    </p>
  ) : null
}

function YeniKullaniciFormu({ roller, kapat }: { roller: Rol[]; kapat: () => void }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<YeniGirdisi>({
    resolver: standardSchemaResolver(yeniSemasi),
    defaultValues: { kullanici_adi: '', ad: '', email: '', sifre: '', rol_idleri: [] },
  })

  const olustur = useMutation({
    mutationFn: (girdi: YeniGirdisi) =>
      lokalKullaniciOlustur({ ...girdi, email: girdi.email === '' ? null : girdi.email }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.kullanicilar })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.roller })
      toast.success(t('ayarlar.kullanicilar.olusturuldu'))
      kapat()
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await olustur.mutateAsync(girdi).catch(() => undefined)
  })
  const alanHatasi = (anahtar?: string) => (anahtar ? t(anahtar) : undefined)

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      <p className="text-xs text-slate-500 dark:text-slate-400">
        {t('ayarlar.kullanicilar.lokalAciklama')}
      </p>
      <Input
        id="yeni-kullanici-adi"
        label={t('ayarlar.kullanicilar.kullaniciAdi')}
        autoComplete="off"
        hata={alanHatasi(errors.kullanici_adi?.message)}
        {...register('kullanici_adi')}
      />
      <Input
        id="yeni-ad"
        label={t('ayarlar.kullanicilar.ad')}
        hata={alanHatasi(errors.ad?.message)}
        {...register('ad')}
      />
      <Input
        id="yeni-email"
        type="email"
        label={t('ayarlar.kullanicilar.email')}
        hata={alanHatasi(errors.email?.message)}
        {...register('email')}
      />
      <Input
        id="yeni-sifre"
        type="password"
        autoComplete="new-password"
        label={t('ayarlar.kullanicilar.sifre')}
        hata={alanHatasi(errors.sifre?.message)}
        {...register('sifre')}
      />
      <Controller
        control={control}
        name="rol_idleri"
        render={({ field }) => (
          <RolSecimi
            id="yeni-roller"
            roller={roller}
            deger={field.value}
            degistir={field.onChange}
          />
        )}
      />
      <HataKutusu mesaj={errors.root?.message} />
      <div className="flex justify-end gap-3">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

function DuzenleFormu({
  kullanici,
  roller,
  kapat,
}: {
  kullanici: YonetilenKullanici
  roller: Rol[]
  kapat: () => void
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const lokal = kullanici.kaynak === 'lokal'
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<DuzenleGirdisi>({
    resolver: standardSchemaResolver(duzenleSemasi),
    defaultValues: {
      ad: kullanici.ad,
      email: kullanici.email ?? '',
      sifre: '',
      aktif_mi: kullanici.aktif_mi,
      rol_idleri: kullanici.rol_idleri,
    },
  })

  const guncelle = useMutation({
    mutationFn: (girdi: DuzenleGirdisi) => {
      const govde: KullaniciGuncelleGovdesi = {
        aktif_mi: girdi.aktif_mi,
        rol_idleri: girdi.rol_idleri,
      }
      // ERP kullanıcısının adı/e-postası/şifresi ERP'den gelir; gönderilmez
      if (lokal) {
        govde.ad = girdi.ad
        govde.email = girdi.email === '' ? null : girdi.email
        if (girdi.sifre !== '') {
          govde.sifre = girdi.sifre
        }
      }

      return kullaniciGuncelle(kullanici.id, govde)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.kullanicilar })
      await queryClient.invalidateQueries({ queryKey: queryKeys.ayarlar.roller })
      toast.success(t('ayarlar.kullanicilar.guncellendi'))
      kapat()
    },
    onError: (error: unknown) => {
      setError('root', { message: dogrulamaMesaji(error) ?? t(apiErrorKey(error)) })
    },
  })

  const onSubmit = handleSubmit(async (girdi) => {
    await guncelle.mutateAsync(girdi).catch(() => undefined)
  })
  const alanHatasi = (anahtar?: string) => (anahtar ? t(anahtar) : undefined)

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      <p className="text-sm text-slate-600 dark:text-slate-300">
        <strong>{kullanici.kullanici_adi}</strong> —{' '}
        {t(`ayarlar.kullanicilar.kaynak.${kullanici.kaynak}`)}
      </p>

      {lokal ? (
        <>
          <Input
            id="duzenle-ad"
            label={t('ayarlar.kullanicilar.ad')}
            hata={alanHatasi(errors.ad?.message)}
            {...register('ad')}
          />
          <Input
            id="duzenle-email"
            type="email"
            label={t('ayarlar.kullanicilar.email')}
            hata={alanHatasi(errors.email?.message)}
            {...register('email')}
          />
          <Input
            id="duzenle-sifre"
            type="password"
            autoComplete="new-password"
            label={t('ayarlar.kullanicilar.yeniSifre')}
            hata={alanHatasi(errors.sifre?.message)}
            {...register('sifre')}
          />
        </>
      ) : (
        <p className="text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.kullanicilar.erpBilgiNotu')}
        </p>
      )}

      <Controller
        control={control}
        name="aktif_mi"
        render={({ field }) => (
          <Switch
            id="duzenle-aktif"
            label={t('ayarlar.kullanicilar.aktif')}
            checked={field.value}
            onChange={field.onChange}
            disabled={kullanici.pasif_yapilamaz}
          />
        )}
      />
      {kullanici.pasif_yapilamaz ? (
        <p className="-mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.kullanicilar.pasifYapilamazNotu')}
        </p>
      ) : null}

      <Controller
        control={control}
        name="rol_idleri"
        render={({ field }) => (
          <RolSecimi
            id="duzenle-roller"
            roller={roller}
            deger={field.value}
            degistir={field.onChange}
          />
        )}
      />
      {kullanici.sistem_yoneticisi ? (
        <p className="-mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.kullanicilar.yoneticiNotu')}
        </p>
      ) : null}

      <HataKutusu mesaj={errors.root?.message} />
      <div className="flex justify-end gap-3">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={isSubmitting}>
          {isSubmitting ? t('ortak.kaydediliyor') : t('ortak.kaydet')}
        </Button>
      </div>
    </form>
  )
}

function Rozet({ renk, children }: { renk: 'mavi' | 'mor' | 'gri' | 'kirmizi'; children: string }) {
  const siniflar = {
    mavi: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    mor: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    gri: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    kirmizi: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  }

  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${siniflar[renk]}`}>
      {children}
    </span>
  )
}

export function KullanicilarPage() {
  const { t } = useTranslation()
  const guncelleyebilir = useIzin('kullanicilar.guncelle')
  const [arama, setArama] = useState('')
  // null: kapalı; 'yeni': lokal kullanıcı ekleme; kullanıcı: düzenleme
  const [formdaki, setFormdaki] = useState<YonetilenKullanici | 'yeni' | null>(null)

  const kullanicilar = useQuery({
    queryKey: queryKeys.ayarlar.kullanicilar,
    queryFn: kullanicilariGetir,
  })
  const roller = useQuery({ queryKey: queryKeys.ayarlar.roller, queryFn: rolleriGetir })

  const rolAdlari = useMemo(
    () => new Map((roller.data ?? []).map((rol) => [rol.id, rol.ad])),
    [roller.data],
  )

  const aranan = arama.trim().toLocaleLowerCase('tr')
  const gorunenler = (kullanicilar.data ?? []).filter(
    (k) =>
      aranan === '' ||
      k.ad.toLocaleLowerCase('tr').includes(aranan) ||
      k.kullanici_adi.toLocaleLowerCase('tr').includes(aranan),
  )

  const tumKolonlar: DataTableKolonu<YonetilenKullanici>[] = [
    {
      anahtar: 'ad',
      baslik: t('ayarlar.kullanicilar.ad'),
      render: (k) => (
        <div>
          <p className="font-semibold">{k.ad}</p>
          <p className="text-xs text-slate-500 dark:text-slate-400">{k.kullanici_adi}</p>
        </div>
      ),
    },
    {
      anahtar: 'kaynak',
      baslik: t('ayarlar.kullanicilar.kaynakBaslik'),
      render: (k) => (
        <div className="flex flex-wrap gap-1">
          <Rozet renk={k.kaynak === 'erp' ? 'mavi' : 'gri'}>
            {t(`ayarlar.kullanicilar.kaynak.${k.kaynak}`)}
          </Rozet>
          {k.sistem_yoneticisi ? (
            <Rozet renk="mor">{t('ayarlar.kullanicilar.sistemYoneticisi')}</Rozet>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'roller',
      baslik: t('ayarlar.kullanicilar.roller'),
      render: (k) =>
        k.rol_idleri.length === 0 ? (
          <span className="text-slate-400">—</span>
        ) : (
          k.rol_idleri.map((id) => rolAdlari.get(id) ?? `#${id}`).join(', ')
        ),
    },
    {
      anahtar: 'durum',
      baslik: t('ayarlar.kullanicilar.durum'),
      render: (k) =>
        k.aktif_mi ? (
          <Rozet renk="mavi">{t('ayarlar.kullanicilar.aktif')}</Rozet>
        ) : (
          <Rozet renk="kirmizi">{t('ayarlar.kullanicilar.pasif')}</Rozet>
        ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (k) => (
        <Button variant="turuncu" onClick={() => setFormdaki(k)} disabled={!roller.isSuccess}>
          {t('ortak.duzenle')}
        </Button>
      ),
    },
  ]
  // Yalnız görüntüleme izninde düzenleme kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  const hata = kullanicilar.error ?? roller.error

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('ayarlar.kullanicilar.baslik')}</h2>
        {guncelleyebilir ? (
          <Button variant="mor" onClick={() => setFormdaki('yeni')} disabled={!roller.isSuccess}>
            {t('ayarlar.kullanicilar.lokalEkle')}
          </Button>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.kullanicilar.aciklama')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4 max-w-sm">
        <Input
          id="kullanici-ara"
          type="search"
          label={t('ayarlar.kullanicilar.ara')}
          value={arama}
          onChange={(e) => setArama(e.target.value)}
        />
      </div>

      {hata ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(hata))}
            tekrarDene={() => {
              void kullanicilar.refetch()
              void roller.refetch()
            }}
          />
        </div>
      ) : (
        <div className="mt-4">
          <DataTable
            kolonlar={kolonlar}
            satirlar={gorunenler}
            satirAnahtari={(k) => k.id}
            yukleniyor={kullanicilar.isPending}
          />
        </div>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={
          formdaki === 'yeni'
            ? t('ayarlar.kullanicilar.lokalEkle')
            : t('ayarlar.kullanicilar.duzenleBaslik')
        }
      >
        {formdaki === 'yeni' && roller.isSuccess ? (
          <YeniKullaniciFormu roller={roller.data} kapat={() => setFormdaki(null)} />
        ) : null}
        {formdaki !== null && formdaki !== 'yeni' && roller.isSuccess ? (
          <DuzenleFormu kullanici={formdaki} roller={roller.data} kapat={() => setFormdaki(null)} />
        ) : null}
      </Modal>
    </>
  )
}
