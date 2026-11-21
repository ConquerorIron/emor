import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { Rozet } from '@/features/yedekleme/Rozet'
import type { KullaniciOzeti, OnayBilgisi, Taraf } from '@/features/yedekleme/ustuneGeriYuklemeApi'

type Ceviri = (anahtar: string, secenekler?: Record<string, unknown>) => string

function liste(degerler: string[] | null | undefined, cevir: (deger: string) => string): string {
  return degerler && degerler.length > 0 ? degerler.map(cevir).join(', ') : '—'
}

function KarsilastirmaSatiri<T>({
  baslik,
  taraf,
  goster,
}: {
  baslik: string
  taraf: Taraf<T>
  goster: (deger: T | null) => string
}) {
  const { t } = useTranslation()

  return (
    <tr
      className={
        taraf.farkli
          ? 'bg-amber-50 dark:bg-amber-950/40'
          : 'border-b border-slate-100 dark:border-slate-800'
      }
    >
      <th scope="row" className="px-3 py-2 text-left font-semibold whitespace-nowrap">
        {baslik}
        {taraf.farkli ? (
          <span className="ml-2">
            <Rozet renk="sari">{t('yedekleme.ustune.bilgi.farkli')}</Rozet>
          </span>
        ) : null}
      </th>
      <td className="px-3 py-2">{goster(taraf.onceki)}</td>
      <td className="px-3 py-2">{goster(taraf.geri_yuklenen)}</td>
    </tr>
  )
}

function kullaniciAlani(
  alan: 'aktif' | 'sistem_yoneticisi' | 'roller',
  ozet: KullaniciOzeti | null,
  t: Ceviri,
): string {
  if (ozet === null) {
    return t('yedekleme.ustune.bilgi.yok')
  }
  if (alan === 'roller') {
    return liste(ozet.roller, (rol) => rol)
  }

  return t(ozet[alan] ? 'yedekleme.ustune.bilgi.evet' : 'yedekleme.ustune.bilgi.hayir')
}

function Bolum({ baslik, children }: { baslik: string; children: ReactNode }) {
  return (
    <section>
      <h4 className="font-semibold text-slate-800 dark:text-slate-100">{baslik}</h4>
      <div className="mt-1">{children}</div>
    </section>
  )
}

/**
 * Onay öncesi gösterilecekler (README §6.4 9. adım, §7.6): "bugün" yanı yer
 * değiştirmeden hemen önce canlıdan alınmıştır, "geri yüklenen" yanı şu an
 * canlıdadır. Yedekten sonra pasife alınan kullanıcılar ve kaldırılan
 * izinler geri gelmiştir — yönetici bunları onaylamadan önce görmeli.
 */
export function OnayBilgisiPaneli({ bilgi }: { bilgi: OnayBilgisi }) {
  const { t } = useTranslation()
  const ortam = (deger: string | null) =>
    deger === null ? '—' : t(`ayarlar.sql.ortam.${deger}`, { defaultValue: deger })
  const alarmlar = (deger: string[] | null) =>
    liste(deger, (tur) => t(`ayarlar.alarm.kural.${tur}.ad`, { defaultValue: tur }))
  const duz = (deger: string[] | null) => liste(deger, (oge) => oge)
  const degisiklik = (deger: string) =>
    t(`yedekleme.ustune.bilgi.degisiklik.${deger}`, { defaultValue: deger })

  return (
    <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
      <h3 className="text-lg font-bold">{t('yedekleme.ustune.bilgi.baslik')}</h3>

      {bilgi.onceki_bilinmiyor ? (
        <p
          role="alert"
          className="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          {t('yedekleme.ustune.bilgi.oncekiBilinmiyor')}
        </p>
      ) : null}

      {bilgi.kaybolacak_yukleme_sayisi !== null && bilgi.kaybolacak_yukleme_sayisi > 0 ? (
        <p
          role="alert"
          className="rounded-lg bg-red-50 px-3 py-2 text-red-800 dark:bg-red-950 dark:text-red-200"
        >
          {t('yedekleme.ustune.bilgi.kaybolacakYukleme', {
            sayi: bilgi.kaybolacak_yukleme_sayisi,
          })}
        </p>
      ) : null}

      <div className="overflow-x-auto">
        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
              <th className="px-3 py-1" />
              <th className="px-3 py-1">{t('yedekleme.ustune.bilgi.bugun')}</th>
              <th className="px-3 py-1">{t('yedekleme.ustune.bilgi.geriYuklenen')}</th>
            </tr>
          </thead>
          <tbody>
            <KarsilastirmaSatiri<string | null>
              baslik={t('yedekleme.ustune.bilgi.erpOrtami')}
              taraf={bilgi.aktif_erp_ortami}
              goster={ortam}
            />
            <KarsilastirmaSatiri
              baslik={t('yedekleme.ustune.bilgi.entegratorler')}
              taraf={bilgi.entegratorler}
              goster={duz}
            />
            <KarsilastirmaSatiri
              baslik={t('yedekleme.ustune.bilgi.alarmKurallari')}
              taraf={bilgi.alarm_kurallari}
              goster={alarmlar}
            />
          </tbody>
        </table>
      </div>

      <Bolum baslik={t('yedekleme.ustune.bilgi.kullanicilar')}>
        {bilgi.kullanici_farklari.length === 0 ? (
          <p className="text-slate-500 dark:text-slate-400">
            {t('yedekleme.ustune.bilgi.farkYok')}
          </p>
        ) : (
          <ul className="space-y-1">
            {bilgi.kullanici_farklari.map((fark) => (
              <li
                key={fark.kullanici_adi}
                className="rounded-lg bg-amber-50 px-3 py-1.5 dark:bg-amber-950/40"
              >
                <span className="font-semibold">
                  {fark.ad} ({fark.kullanici_adi})
                </span>{' '}
                — {degisiklik(fark.degisiklik)}
                {fark.alanlar.map((alan) => (
                  <span key={alan} className="block text-xs">
                    {t(`yedekleme.ustune.bilgi.kullaniciAlani.${alan}`)}:{' '}
                    {kullaniciAlani(alan, fark.onceki, t)} →{' '}
                    <strong>{kullaniciAlani(alan, fark.geri_yuklenen, t)}</strong>
                  </span>
                ))}
              </li>
            ))}
          </ul>
        )}
      </Bolum>

      <Bolum baslik={t('yedekleme.ustune.bilgi.roller')}>
        {bilgi.rol_farklari.length === 0 ? (
          <p className="text-slate-500 dark:text-slate-400">
            {t('yedekleme.ustune.bilgi.farkYok')}
          </p>
        ) : (
          <ul className="space-y-1">
            {bilgi.rol_farklari.map((fark) => (
              <li
                key={fark.rol}
                className="rounded-lg bg-amber-50 px-3 py-1.5 dark:bg-amber-950/40"
              >
                <span className="font-semibold">{fark.rol}</span> — {degisiklik(fark.degisiklik)}
                {fark.geri_gelen_izinler.length > 0 ? (
                  <span className="block text-xs">
                    {t('yedekleme.ustune.bilgi.geriGelenIzinler')}:{' '}
                    {fark.geri_gelen_izinler.join(', ')}
                  </span>
                ) : null}
                {fark.kaybolan_izinler.length > 0 ? (
                  <span className="block text-xs">
                    {t('yedekleme.ustune.bilgi.kaybolanIzinler')}:{' '}
                    {fark.kaybolan_izinler.join(', ')}
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </Bolum>

      <Bolum baslik={t('yedekleme.ustune.bilgi.ayarlar')}>
        {bilgi.ayar_farklari.length === 0 ? (
          <p className="text-slate-500 dark:text-slate-400">
            {t('yedekleme.ustune.bilgi.farkYok')}
          </p>
        ) : (
          <ul className="space-y-1">
            {bilgi.ayar_farklari.map((fark) => (
              <li
                key={`${fark.bolum}-${fark.ad ?? ''}`}
                className="rounded-lg bg-slate-50 px-3 py-1.5 dark:bg-slate-800"
              >
                <span className="font-semibold">
                  {t(`yedekleme.ustune.bilgi.bolum.${fark.bolum}`, { defaultValue: fark.bolum })}
                  {fark.ad ? ` — ${fark.ad}` : ''}
                </span>
                : {degisiklik(fark.degisiklik)}
                {fark.alanlar.length > 0 ? (
                  <span className="block text-xs text-slate-500 dark:text-slate-400">
                    {fark.alanlar.join(', ')}
                  </span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </Bolum>
    </div>
  )
}
