import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Input } from '@/components/Input'
import { KolonSecici } from '@/components/KolonSecici'
import { Modal } from '@/components/Modal'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { Pagination } from '@/components/Pagination'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { SelectField } from '@/components/SelectField'
import { useDebounce } from '@/hooks/useDebounce'
import { useIzin } from '@/hooks/useIzin'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'
import { useKolonGorunurlugu } from '@/hooks/useKolonGorunurlugu'
import { tarihGoster } from '@/utils/tarih'

import { tutarGoster } from './bicim'
import {
  kesintiSecenekleriniGetir,
  type IcmalFiltresi,
  type IcmalSatiri,
  type KesintiTanimi,
} from './icmalApi'
import { IcmalFormu } from './IcmalFormu'
import { IcmalIceAktarimPenceresi } from './IcmalIceAktarimPenceresi'
import { IcmalYuklemelerPenceresi } from './IcmalYuklemelerPenceresi'
import {
  alanDegeri,
  ayAdi,
  basamak,
  listeKolonlari,
  satirTanimi,
  type AlanTuru,
  type IcmalTanimi,
  type TanimAlani,
} from './icmalTanimi'
import { ToplamOzeti } from './ToplamOzeti'

const BOS_FILTRE: IcmalFiltresi = { ara: '', firma_id: '', proje_id: '', baslangic: '', bitis: '' }

function tanimSecenekleri(liste: KesintiTanimi[] | undefined) {
  return (liste ?? []).map((tanim) => ({
    value: String(tanim.id),
    label: `${tanim.kod} — ${tanim.ad}`,
  }))
}

/**
 * Kesinti icmali ekranı: filtreler, para birimine göre toplamlar, kolon
 * seçimi ve sıralamalı liste; elle giriş, Excel/yapıştırma aktarımı ve
 * yüklemeler. Kolonlar ve sıraları icmal tanımından gelir.
 */
export function IcmalSayfasi<T extends IcmalSatiri>({ tanim }: { tanim: IcmalTanimi<T> }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin(`${tanim.izin}.guncelle`)
  const [filtre, setFiltre] = useState<IcmalFiltresi>(BOS_FILTRE)
  const [sayfa, setSayfa] = useState(1)
  // null: kapalı; 'yeni': yeni satır; T: düzenleme
  const [formdaki, setFormdaki] = useState<T | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<T | null>(null)
  const [aktarimAcik, setAktarimAcik] = useState(false)
  const [yuklemelerAcik, setYuklemelerAcik] = useState(false)

  const { siralama, siralamaDegistir: kaliciSiralamaDegistir } = useKaliciSiralama(
    `${tanim.anahtar}Kesintileri`,
    { anahtar: 'tarih', yon: 'desc' },
  )
  const kolonGorunurlugu = useKolonGorunurlugu(`${tanim.anahtar}Kesintileri`)

  // Arama yazarken her tuşta istek atılmaz; diğer filtreler anında uygulanır
  const ara = useDebounce(filtre.ara)
  const sorguFiltresi = { ...filtre, ara }

  const secenekler = useQuery({
    queryKey: queryKeys.kesinti.secenekler,
    queryFn: kesintiSecenekleriniGetir,
  })
  const liste = useQuery({
    queryKey: queryKeys.kesinti.icmalListesi(tanim.anahtar, { ...sorguFiltresi, siralama, sayfa }),
    queryFn: () => tanim.api.listele(sorguFiltresi, siralama, sayfa),
    placeholderData: keepPreviousData,
  })

  const sil = useMutation({
    mutationFn: (satir: T) => tanim.api.sil(satir.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: queryKeys.kesinti.icmalListeleri(tanim.anahtar),
      })
      toast.success(t('kesinti.icmal.silindi'))
    },
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
    onSettled: () => setSilinecek(null),
  })

  // Filtredeki tüm satırlar, gizli kolonlar dahil tüm kolonlar (kullanıcı isteği)
  const excel = useMutation({
    mutationFn: () =>
      tanim.api.excel(sorguFiltresi, siralama, `${t(`kesinti.${tanim.anahtar}.baslik`)}.xlsx`),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const filtreDegistir = <K extends keyof IcmalFiltresi>(alan: K, deger: IcmalFiltresi[K]) => {
    setFiltre((onceki) => ({ ...onceki, [alan]: deger }))
    setSayfa(1)
  }
  const siralamaDegistir = (anahtar: string) => {
    kaliciSiralamaDegistir(anahtar)
    setSayfa(1)
  }

  const firmaSecenekleri = tanimSecenekleri(secenekler.data?.firmalar)
  const projeSecenekleri = tanimSecenekleri(secenekler.data?.projeler)

  const hucreIcerigi = (satir: T, alan: string, tur: AlanTuru) => {
    switch (tur.tur) {
      case 'tanim': {
        const kayit = satirTanimi(satir, alan as TanimAlani)

        return alan === 'taseron' ? <strong title={kayit.ad}>{kayit.kod}</strong> : kayit.kod
      }
      case 'tarih':
        return tarihGoster(satir.tarih)
      case 'yil':
        return satir.tarih.slice(0, 4)
      case 'ay':
        return ayAdi(satir.tarih, i18n.language)
      case 'secenek':
        return t(`kesinti.secenek.${alan}.${String(alanDegeri(satir, alan))}`)
      case 'sayi':
      case 'hesap':
        return (
          <span className="tabular-nums">
            {tutarGoster(String(alanDegeri(satir, alan)), basamak(tur))}
          </span>
        )
      case 'metin': {
        const deger = alanDegeri(satir, alan)

        return typeof deger === 'string' && deger !== '' ? (
          deger
        ) : (
          <span className="text-slate-400">—</span>
        )
      }
    }
  }

  // Kolon sırası Excel / kopyala-yapıştır sırası; tarihin sağında türetilen Yıl ve Ay
  const tumKolonlar: DataTableKolonu<T>[] = listeKolonlari(tanim.kolonlar).map((alan) => {
    const tur = tanim.alanlar[alan]
    // Yıl / Ay veritabanında yok: sunucuda sıralanamaz
    const turetilmis = tur.tur === 'yil' || tur.tur === 'ay'

    return {
      anahtar: alan,
      baslik: t(`kesinti.alan.${alan}`),
      siralamaAnahtari: turetilmis ? undefined : alan,
      hizala: tur.tur === 'sayi' || tur.tur === 'hesap' ? 'sag' : undefined,
      // Veri ne kadar genişse kolon o kadar (kullanıcı isteği): satır kaydırılmaz
      render: (satir) => (
        <span className="whitespace-nowrap">{hucreIcerigi(satir, alan, tur)}</span>
      ),
    }
  })
  const islemKolonu: DataTableKolonu<T> = {
    anahtar: 'islemler',
    baslik: '',
    hizala: 'sag',
    render: (satir) => (
      <div className="flex justify-end gap-2">
        <Button variant="turuncu" onClick={() => setFormdaki(satir)}>
          {t('ortak.duzenle')}
        </Button>
        <Button variant="danger" onClick={() => setSilinecek(satir)}>
          {t('ortak.sil')}
        </Button>
      </div>
    ),
  }
  const gorunenKolonlar = tumKolonlar.filter((kolon) => kolonGorunurlugu.gorunurMu(kolon.anahtar))
  // Yalnız görüntüleme izninde düzenle/sil kolonu çizilmez
  const kolonlar = guncelleyebilir ? [...gorunenKolonlar, islemKolonu] : gorunenKolonlar

  const sayfalama = (konum: 'ust' | 'alt') =>
    liste.data ? (
      <Pagination
        sayfa={liste.data.meta.current_page}
        toplamSayfa={liste.data.meta.last_page}
        sayfaDegistir={setSayfa}
        disabled={liste.isFetching}
        boyutSeciciId={`${tanim.anahtar}-sayfa-boyutu-${konum}`}
      />
    ) : null

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t(`kesinti.${tanim.anahtar}.baslik`)}</h2>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" yukleniyor={excel.isPending} onClick={() => excel.mutate()}>
            {t('kesinti.icmal.excelAktar')}
          </Button>
          <Button variant="secondary" onClick={() => setYuklemelerAcik(true)}>
            {t('kesinti.icmal.yuklemeler.baslik')}
          </Button>
          {guncelleyebilir ? (
            <>
              <Button variant="secondary" onClick={() => setAktarimAcik(true)}>
                {t('kesinti.icmal.iceAktarim.ac')}
              </Button>
              <Button
                variant="mor"
                onClick={() => setFormdaki('yeni')}
                disabled={!secenekler.isSuccess}
              >
                {t('kesinti.icmal.yeni')}
              </Button>
            </>
          ) : null}
        </div>
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t(`kesinti.${tanim.anahtar}.aciklamaMetni`)}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}

      <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Input
          id={`${tanim.anahtar}-ara`}
          type="search"
          label={t('kesinti.icmal.ara')}
          maxLength={100}
          value={filtre.ara}
          onChange={(olay) => filtreDegistir('ara', olay.target.value)}
        />
        <SelectField
          id={`${tanim.anahtar}-filtre-taseron`}
          label={t('kesinti.alan.taseron')}
          options={firmaSecenekleri}
          value={firmaSecenekleri.find((s) => s.value === filtre.firma_id) ?? null}
          onChange={(secenek) => filtreDegistir('firma_id', secenek?.value ?? '')}
          isClearable
          placeholder={t('ortak.hepsi')}
        />
        <SelectField
          id={`${tanim.anahtar}-filtre-proje`}
          label={t('kesinti.alan.proje')}
          options={projeSecenekleri}
          value={projeSecenekleri.find((s) => s.value === filtre.proje_id) ?? null}
          onChange={(secenek) => filtreDegistir('proje_id', secenek?.value ?? '')}
          isClearable
          placeholder={t('ortak.hepsi')}
        />
        <OpsiyonelTarihInput
          id={`${tanim.anahtar}-baslangic`}
          label={t('kesinti.icmal.baslangic')}
          value={filtre.baslangic}
          onChange={(iso) => filtreDegistir('baslangic', iso)}
        />
        <OpsiyonelTarihInput
          id={`${tanim.anahtar}-bitis`}
          label={t('kesinti.icmal.bitis')}
          value={filtre.bitis}
          onChange={(iso) => filtreDegistir('bitis', iso)}
        />
      </div>

      {liste.error ? (
        <div className="mt-4">
          <ErrorState mesaj={t(apiErrorKey(liste.error))} tekrarDene={() => void liste.refetch()} />
        </div>
      ) : (
        <>
          {liste.data && liste.data.ozet.length > 0 ? (
            <div
              className={`mt-4 rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700 ${liste.isPlaceholderData ? 'opacity-50' : ''}`}
            >
              <ToplamOzeti toplamlar={liste.data.ozet} />
            </div>
          ) : null}
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <KolonSecici
              kolonlar={tumKolonlar.map(({ anahtar, baslik }) => ({ anahtar, baslik }))}
              gorunurMu={kolonGorunurlugu.gorunurMu}
              degistir={kolonGorunurlugu.degistir}
              hepsiniGoster={kolonGorunurlugu.hepsiniGoster}
            />
            {sayfalama('ust')}
          </div>
          <div className={`mt-3 ${liste.isPlaceholderData ? 'opacity-50' : ''}`}>
            <DataTable
              kolonlar={kolonlar}
              satirlar={liste.data?.data ?? []}
              satirAnahtari={(satir) => satir.id}
              yukleniyor={liste.isPending}
              siralama={siralama}
              siralamaDegistir={siralamaDegistir}
              ustKaydirma
            />
          </div>
          <div className="mt-3">{sayfalama('alt')}</div>
        </>
      )}

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        baslik={formdaki === 'yeni' ? t('kesinti.icmal.yeni') : t('kesinti.icmal.duzenle')}
        boyut="devasa"
      >
        {formdaki !== null && secenekler.isSuccess ? (
          <IcmalFormu
            tanim={tanim}
            satir={formdaki === 'yeni' ? null : formdaki}
            secenekler={secenekler.data}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <IcmalIceAktarimPenceresi
        tanim={tanim}
        acik={aktarimAcik}
        kapat={() => setAktarimAcik(false)}
      />
      <IcmalYuklemelerPenceresi
        tanim={tanim}
        acik={yuklemelerAcik}
        kapat={() => setYuklemelerAcik(false)}
        geriAlabilir={guncelleyebilir}
      />

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('kesinti.icmal.silOnayBaslik')}
        mesaj={t('kesinti.icmal.silOnayMesaj', {
          ad: silinecek ? tanim.satirAdi(silinecek) : '',
          tarih: silinecek ? tarihGoster(silinecek.tarih) : '',
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
