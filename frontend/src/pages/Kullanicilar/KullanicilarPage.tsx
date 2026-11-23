import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

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
  erpKullanicisiTanimla,
  kullaniciGuncelle,
  kullanicilariGetir,
  rolleriGetir,
  type Rol,
  type YonetilenKullanici,
} from '@/features/ayarlar/yetkiApi'
import { useAuth } from '@/hooks/useAuth'
import { useIzin } from '@/hooks/useIzin'

interface DuzenleGirdisi {
  aktif_mi: boolean
  rol_idleri: number[]
}

function RolSecimi({
  roller,
  deger,
  degistir,
}: {
  roller: Rol[]
  deger: number[]
  degistir: (idler: number[]) => void
}) {
  const { t } = useTranslation()
  const secenekler = roller.map((rol) => ({ value: String(rol.id), label: rol.ad }))

  return (
    <MultiSelectField
      id="duzenle-roller"
      label={t('ayarlar.kullanicilar.roller')}
      options={secenekler}
      value={secenekler.filter((secenek) => deger.includes(Number(secenek.value)))}
      onChange={(secilenler) => degistir(secilenler.map((secenek) => Number(secenek.value)))}
      placeholder={t('ayarlar.kullanicilar.rolSec')}
    />
  )
}

/**
 * Giriş izni ve roller. Henüz tanımlanmamış ERP kullanıcısı kaydedilince
 * uygulamaya tanımlanır (daha önce giriş yapmış olması gerekmez).
 */
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
  const {
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<DuzenleGirdisi>({
    defaultValues: {
      // Tanımlanmamış kullanıcı düzenlenmeye açıldıysa amaç izin vermektir
      aktif_mi: kullanici.id === null ? true : kullanici.aktif_mi,
      rol_idleri: kullanici.rol_idleri,
    },
  })

  const kaydet = useMutation({
    mutationFn: (girdi: DuzenleGirdisi) =>
      kullanici.id === null && kullanici.erp_kullanici_id !== null
        ? erpKullanicisiTanimla({ erp_kullanici_id: kullanici.erp_kullanici_id, ...girdi })
        : kullaniciGuncelle(kullanici.id ?? 0, girdi),
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
    await kaydet.mutateAsync(girdi).catch(() => undefined)
  })

  return (
    <form onSubmit={(e) => void onSubmit(e)} noValidate className="space-y-4">
      <div className="text-sm text-slate-600 dark:text-slate-300">
        <p className="font-semibold text-slate-800 dark:text-slate-100">{kullanici.ad}</p>
        <p>{kullanici.kullanici_adi}</p>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.kullanicilar.erpBilgiNotu')}
        </p>
      </div>

      <Controller
        control={control}
        name="aktif_mi"
        render={({ field }) => (
          <Switch
            id="duzenle-aktif"
            label={t('ayarlar.kullanicilar.girisIzni')}
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
          <RolSecimi roller={roller} deger={field.value} degistir={field.onChange} />
        )}
      />
      {kullanici.sistem_yoneticisi ? (
        <p className="-mt-2 text-xs text-slate-500 dark:text-slate-400">
          {t('ayarlar.kullanicilar.yoneticiNotu')}
        </p>
      ) : null}

      {errors.root?.message ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {errors.root.message}
        </p>
      ) : null}
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

function Rozet({ renk, children }: { renk: RozetRengi; children: string }) {
  const siniflar = {
    mavi: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    mor: 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    gri: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    kirmizi: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
  }

  return (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${siniflar[renk]}`}
    >
      {children}
    </span>
  )
}

type RozetRengi = 'mavi' | 'mor' | 'gri' | 'kirmizi'

/**
 * "Giriş" kolonundaki rozetler. Arama da aynı metinlerde yapılır ("var",
 * "yok", "lokal"…); kolon ile arama ayrışmasın diye tek yerde üretilir.
 */
function girisRozetleri(
  k: YonetilenKullanici,
  t: (anahtar: string) => string,
): { renk: RozetRengi; metin: string }[] {
  return [
    k.aktif_mi
      ? { renk: 'mavi' as const, metin: t('ayarlar.kullanicilar.izinli') }
      : { renk: 'gri' as const, metin: t('ayarlar.kullanicilar.izinYok') },
    ...(k.kaynak === 'lokal'
      ? [{ renk: 'gri' as const, metin: t('ayarlar.kullanicilar.kaynak.lokal') }]
      : []),
    ...(k.erpde_yok
      ? [{ renk: 'kirmizi' as const, metin: t('ayarlar.kullanicilar.erpdeYok') }]
      : []),
    ...(k.sistem_yoneticisi
      ? [{ renk: 'mor' as const, metin: t('ayarlar.kullanicilar.sistemYoneticisi') }]
      : []),
  ]
}

/**
 * ERP kullanıcıları listelenir; hangilerinin uygulamaya girebileceği ve
 * rolleri burada tanımlanır (kullanıcı kararı 2026-09-24). Giriş ERP
 * şifresiyle yapılır; lokal kullanıcı açılmaz.
 */
export function KullanicilarPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const guncelleyebilir = useIzin('kullanicilar.guncelle')
  const [arama, setArama] = useState('')
  const [yalnizIzinliler, setYalnizIzinliler] = useState(false)
  const [formdaki, setFormdaki] = useState<YonetilenKullanici | null>(null)

  const liste = useQuery({
    queryKey: queryKeys.ayarlar.kullanicilar,
    queryFn: kullanicilariGetir,
  })
  const roller = useQuery({ queryKey: queryKeys.ayarlar.roller, queryFn: rolleriGetir })

  const rolAdlari = useMemo(
    () => new Map((roller.data ?? []).map((rol) => [rol.id, rol.ad])),
    [roller.data],
  )

  const rolMetni = (k: YonetilenKullanici) =>
    k.rol_idleri.map((id) => rolAdlari.get(id) ?? `#${id}`).join(', ')

  // Ad, kullanıcı adı, "Giriş" rozetleri ve roller: ekranda görünen metinde aranır
  const aranan = arama.trim().toLocaleLowerCase('tr')
  const gorunenler = (liste.data?.kullanicilar ?? []).filter(
    (k) =>
      (!yalnizIzinliler || k.aktif_mi) &&
      (aranan === '' ||
        [k.ad, k.kullanici_adi, ...girisRozetleri(k, t).map((r) => r.metin), rolMetni(k)].some(
          (metin) => metin.toLocaleLowerCase('tr').includes(aranan),
        )),
  )

  // Sistem yöneticisi hesabını yalnız sistem yöneticisi değiştirebilir (backend de denetler)
  const duzenlenebilir = (k: YonetilenKullanici) =>
    !k.erpde_yok && (!k.sistem_yoneticisi || user?.sistem_yoneticisi === true)

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
      // ERP KULLANICI_ID (salt okunur): ERP'nin kullanıcı listesinden / girişten
      // gelir; rapor proc'larının @KullaniciId'si ve kaydı yapan kullanıcı
      anahtar: 'erp_kullanici_id',
      baslik: t('ayarlar.kullanicilar.erpKullaniciId'),
      hizala: 'orta',
      render: (k) =>
        k.erp_kullanici_id === null ? (
          <span className="text-slate-400">—</span>
        ) : (
          <span className="tabular-nums">{k.erp_kullanici_id}</span>
        ),
    },
    {
      anahtar: 'giris',
      baslik: t('ayarlar.kullanicilar.giris'),
      render: (k) => (
        <div className="flex flex-wrap gap-1">
          {girisRozetleri(k, t).map((rozet) => (
            <Rozet key={rozet.metin} renk={rozet.renk}>
              {rozet.metin}
            </Rozet>
          ))}
        </div>
      ),
    },
    {
      anahtar: 'roller',
      baslik: t('ayarlar.kullanicilar.roller'),
      render: (k) =>
        k.rol_idleri.length === 0 ? <span className="text-slate-400">—</span> : rolMetni(k),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (k) =>
        duzenlenebilir(k) ? (
          <Button variant="turuncu" onClick={() => setFormdaki(k)} disabled={!roller.isSuccess}>
            {t('ortak.duzenle')}
          </Button>
        ) : null,
    },
  ]
  // Yalnız görüntüleme izninde düzenleme kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  const hata = liste.error ?? roller.error

  return (
    <>
      <h2 className="text-2xl font-bold">{t('ayarlar.kullanicilar.baslik')}</h2>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('ayarlar.kullanicilar.aciklama')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      {liste.data?.erpOkunamadi ? (
        <p
          role="alert"
          className="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('ayarlar.kullanicilar.erpOkunamadi')}
        </p>
      ) : null}

      <div className="mt-4 flex flex-wrap items-end gap-4">
        <div className="w-full max-w-sm">
          <Input
            id="kullanici-ara"
            type="search"
            label={t('ayarlar.kullanicilar.ara')}
            value={arama}
            onChange={(e) => setArama(e.target.value)}
          />
        </div>
        <Switch
          id="yalniz-izinliler"
          label={t('ayarlar.kullanicilar.yalnizIzinliler')}
          checked={yalnizIzinliler}
          onChange={setYalnizIzinliler}
          vurgulu={false}
        />
      </div>

      {hata ? (
        <div className="mt-4">
          <ErrorState
            mesaj={t(apiErrorKey(hata))}
            tekrarDene={() => {
              void liste.refetch()
              void roller.refetch()
            }}
          />
        </div>
      ) : (
        <div className="mt-4">
          <DataTable
            kolonlar={kolonlar}
            satirlar={gorunenler}
            satirAnahtari={(k) => k.id ?? `erp-${k.erp_kullanici_id ?? ''}`}
            yukleniyor={liste.isPending}
          />
        </div>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={t('ayarlar.kullanicilar.duzenleBaslik')}
      >
        {formdaki !== null && roller.isSuccess ? (
          <DuzenleFormu kullanici={formdaki} roller={roller.data} kapat={() => setFormdaki(null)} />
        ) : null}
      </Modal>
    </>
  )
}
