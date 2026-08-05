import { useEffect, useRef, useState } from 'react'

import { bugunIso, gosterimdenIso, tarihGoster, tarihMaskele } from '@/utils/tarih'

interface TarihInputProps {
  id: string
  label: string
  hata?: string
  /** ISO (YYYY-MM-DD) veya boş — API sözleşmesi değişmez */
  value: string
  /** Tam ve geçerli tarihte ISO, aksi halde boş dizge alır */
  onChange: (iso: string) => void
  disabled?: boolean
  /** Etiket görsel olarak gizlenir (dış bileşen kendi etiketini basıyorsa); erişilebilirlik korunur */
  etiketGizli?: boolean
}

/** i18n ile aynı kaynak (localStorage 'locale'): bileşen bağımlılıksız kalır. */
function aktifDil(): string {
  return (localStorage.getItem('locale') ?? 'tr') === 'tr' ? 'tr-TR' : 'en-GB'
}

const iki = (sayi: number) => String(sayi).padStart(2, '0')

/**
 * Türkçe formatlı tarih girişi (kullanıcı isteği 2026-07-09): GG.AA.YYYY.
 * Native `input[type=date]` tarayıcı yerelini kullanır (İngilizce tarayıcıda
 * AA/GG/YYYY görünüyordu) — görünen kutu formatı yerelden bağımsız sabitler;
 * rakamlar yazılırken noktalar otomatik eklenir, değer ISO olarak yayılır.
 *
 * Takvim POPUP'ı da native değildir (kullanıcı bildirimi 2026-07-17: native
 * seçicinin AY ADLARI tarayıcının kendi dilinden gelir, sayfa dili işlemez —
 * Türkçe kullanımda İngilizce aylar görünüyordu): kendi popover'ımız seçili
 * uygulama diliyle çizilir, hafta Pazartesi başlar.
 */
export function TarihInput({
  id,
  label,
  hata,
  value,
  onChange,
  disabled,
  etiketGizli,
}: TarihInputProps) {
  const [metin, setMetin] = useState(value ? tarihGoster(value) : '')
  const [takvimAcik, setTakvimAcik] = useState(false)
  const [gorunen, setGorunen] = useState<{ yil: number; ay: number }>(() => bugunkuAy(value))
  const sonYayilan = useRef(value)
  const kokRef = useRef<HTMLDivElement>(null)

  function bugunkuAy(iso: string): { yil: number; ay: number } {
    const kaynak = /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T00:00:00`) : new Date()

    return { yil: kaynak.getFullYear(), ay: kaynak.getMonth() }
  }

  // Dış değer değişimi (düzenleme formu açılışı, reset) gösterime yansır;
  // kendi yaydığımız değer geri geldiğinde yazma akışı bozulmaz
  useEffect(() => {
    if (value !== sonYayilan.current) {
      sonYayilan.current = value
      setMetin(value ? tarihGoster(value) : '')
    }
  }, [value])

  // Popover dışına tıklama / Escape kapatır
  useEffect(() => {
    if (!takvimAcik) {
      return
    }
    const disariTiklama = (olay: MouseEvent) => {
      if (kokRef.current && !kokRef.current.contains(olay.target as Node)) {
        setTakvimAcik(false)
      }
    }
    const escBasildi = (olay: KeyboardEvent) => {
      if (olay.key === 'Escape') {
        setTakvimAcik(false)
      }
    }
    document.addEventListener('mousedown', disariTiklama)
    document.addEventListener('keydown', escBasildi)

    return () => {
      document.removeEventListener('mousedown', disariTiklama)
      document.removeEventListener('keydown', escBasildi)
    }
  }, [takvimAcik])

  function yay(iso: string, gosterim: string) {
    setMetin(gosterim)
    sonYayilan.current = iso
    onChange(iso)
  }

  function elleYazildi(ham: string) {
    const maskeli = tarihMaskele(ham)
    yay(gosterimdenIso(maskeli), maskeli)
  }

  function gunSecildi(gun: number) {
    const iso = `${gorunen.yil}-${iki(gorunen.ay + 1)}-${iki(gun)}`
    yay(iso, tarihGoster(iso))
    setTakvimAcik(false)
  }

  function bugunSecildi() {
    const iso = bugunIso()
    yay(iso, tarihGoster(iso))
    setGorunen(bugunkuAy(iso))
    setTakvimAcik(false)
  }

  function takvimiAcKapat() {
    if (!takvimAcik) {
      setGorunen(bugunkuAy(value))
    }
    setTakvimAcik((onceki) => !onceki)
  }

  function ayKaydir(fark: number) {
    setGorunen((onceki) => {
      const tarih = new Date(onceki.yil, onceki.ay + fark, 1)

      return { yil: tarih.getFullYear(), ay: tarih.getMonth() }
    })
  }

  const dil = aktifDil()
  // Hafta başlıkları Pazartesi'den (2024-01-01 Pazartesi — sabit referans)
  const haftaGunleri = Array.from({ length: 7 }, (_, i) =>
    new Date(2024, 0, 1 + i).toLocaleDateString(dil, { weekday: 'short' }),
  )
  const ayEtiketi = new Date(gorunen.yil, gorunen.ay, 1).toLocaleDateString(dil, {
    month: 'long',
    year: 'numeric',
  })
  const gunSayisi = new Date(gorunen.yil, gorunen.ay + 1, 0).getDate()
  const ilkGunOfseti = (new Date(gorunen.yil, gorunen.ay, 1).getDay() + 6) % 7 // Pzt=0
  const bugun = new Date()
  const bugunMu = (gun: number) =>
    bugun.getFullYear() === gorunen.yil &&
    bugun.getMonth() === gorunen.ay &&
    bugun.getDate() === gun
  const seciliMi = (gun: number) => value === `${gorunen.yil}-${iki(gorunen.ay + 1)}-${iki(gun)}`

  return (
    <div ref={kokRef} className="relative">
      <label
        htmlFor={id}
        className={
          etiketGizli ? 'sr-only' : 'block text-sm font-semibold text-slate-700 dark:text-slate-300'
        }
      >
        {label}
      </label>
      <div className={etiketGizli ? 'relative' : 'relative mt-1'}>
        <input
          id={id}
          type="text"
          inputMode="numeric"
          autoComplete="off"
          placeholder="GG.AA.YYYY"
          maxLength={10}
          disabled={disabled}
          value={metin}
          onChange={(olay) => elleYazildi(olay.target.value)}
          aria-invalid={hata ? true : undefined}
          className={`block h-[42px] w-full rounded-xl border bg-white py-2 pr-10 pl-3 text-sm text-slate-900 shadow-sm transition-colors outline-none placeholder:text-slate-400 focus:ring-2 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 ${
            hata
              ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
              : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
          }`}
        />
        <button
          type="button"
          tabIndex={-1}
          disabled={disabled}
          onClick={takvimiAcKapat}
          aria-label={`${label} — takvim`}
          className="absolute inset-y-0 right-0 flex w-10 cursor-pointer items-center justify-center rounded-r-xl text-slate-400 transition-colors hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-60 dark:text-slate-500 dark:hover:text-blue-400"
        >
          <svg
            aria-hidden="true"
            className="size-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={1.8}
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              d="M6.75 3v2.25M17.25 3v2.25M3.75 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h12A2.25 2.25 0 0 1 20.25 7.5v11.25m-16.5 0A2.25 2.25 0 0 0 6 21h12a2.25 2.25 0 0 0 2.25-2.25m-16.5 0v-7.5A2.25 2.25 0 0 1 6 9h12a2.25 2.25 0 0 1 2.25 2.25v7.5"
            />
          </svg>
        </button>
      </div>

      {takvimAcik ? (
        <div
          data-testid={`${id}-takvim`}
          className="absolute right-0 z-30 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-700 dark:bg-slate-900"
        >
          <div className="mb-2 flex items-center justify-between">
            <button
              type="button"
              onClick={() => ayKaydir(-1)}
              aria-label={dil === 'tr-TR' ? 'Önceki ay' : 'Previous month'}
              className="flex size-7 cursor-pointer items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
            >
              ‹
            </button>
            <span className="text-sm font-semibold text-slate-700 capitalize dark:text-slate-200">
              {ayEtiketi}
            </span>
            <button
              type="button"
              onClick={() => ayKaydir(1)}
              aria-label={dil === 'tr-TR' ? 'Sonraki ay' : 'Next month'}
              className="flex size-7 cursor-pointer items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
            >
              ›
            </button>
          </div>
          <div className="grid grid-cols-7 gap-0.5 text-center">
            {haftaGunleri.map((gunAdi) => (
              <span
                key={gunAdi}
                className="py-1 text-[10px] font-semibold text-slate-400 uppercase dark:text-slate-500"
              >
                {gunAdi}
              </span>
            ))}
            {Array.from({ length: ilkGunOfseti }, (_, i) => (
              <span key={`bos-${i}`} />
            ))}
            {Array.from({ length: gunSayisi }, (_, i) => {
              const gun = i + 1

              return (
                <button
                  key={gun}
                  type="button"
                  onClick={() => gunSecildi(gun)}
                  className={`flex size-8 cursor-pointer items-center justify-center rounded-lg text-sm transition-colors ${
                    seciliMi(gun)
                      ? 'bg-blue-600 font-semibold text-white'
                      : bugunMu(gun)
                        ? 'font-semibold text-blue-600 ring-1 ring-blue-300 hover:bg-blue-50 dark:text-blue-400 dark:ring-blue-800 dark:hover:bg-slate-800'
                        : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800'
                  }`}
                >
                  {gun}
                </button>
              )
            })}
          </div>
          <div className="mt-2 border-t border-slate-200 pt-2 dark:border-slate-700">
            <button
              type="button"
              onClick={bugunSecildi}
              className="w-full cursor-pointer rounded-lg px-3 py-1.5 text-sm font-semibold text-blue-600 transition-colors hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-slate-800"
            >
              {dil === 'tr-TR' ? 'Bugün' : 'Today'}
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
