/**
 * ERP MIKTAR / ORAN tipleri için sayı girişi. Türkçe biçim: ondalık ayracı
 * virgül. Değer forma NOKTALI (makine okunur) biçimde yazılır ki proc'a ve
 * SQL'e dönüştürmeden gidebilsin.
 *
 * Alan MASKELİDİR: ondalık basamaklar her zaman görünür (ERP ızgarasındaki
 * "0,000000" gibi) ve hiç kaybolmaz. Tam kısma yazmak rakam ekler, ondalık
 * kısma yazmak üzerine yazar — basamak sayısı sabit kaldığı için. Kullanıcı
 * ondalık haneye sağ ok ya da virgül tuşuyla geçer.
 */

import { useLayoutEffect, useRef } from 'react'

import { ALAN_ETIKETI, ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'

interface SayiAlaniProps {
  id: string
  label: string
  /** Noktalı biçim ("12.5"); boş bırakılabilir */
  value: string
  onChange: (deger: string) => void
  hata?: string
  disabled?: boolean
  /** Yüzde alanları için sonda % gösterilir (ERP ORAN tipi) */
  yuzde?: boolean
  /**
   * Sayının sağında gösterilen birim (ERP ızgarasındaki "1,000000 Ad" gibi).
   * Yalnız gösterimdir, değere karışmaz.
   */
  sonEk?: string
  /** İzin verilen ondalık basamak (MIKTAR 4, ORAN 2) */
  ondalik?: number
  /** Izgara hücrelerinde etiket thead'de durur; erişilebilirlik için DOM'da kalır */
  etiketGizli?: boolean
}

/** Saklanan değeri maskeye çevirir: "1.5" + 4 basamak → "1,5000" */
function maskele(deger: string, ondalik: number): string {
  const sayi = Number(deger)
  const temel = deger === '' || !Number.isFinite(sayi) ? 0 : sayi

  return temel.toFixed(ondalik).replace('.', ',')
}

/** Maskeyi saklanan biçime çevirir: "1,5000" → "1.5000" */
function maskeden(gosterim: string): string {
  return gosterim.replace(',', '.')
}

export function SayiAlani({
  id,
  label,
  value,
  onChange,
  hata,
  disabled = false,
  yuzde = false,
  sonEk,
  ondalik = 4,
  etiketGizli = false,
}: SayiAlaniProps) {
  const girdiRef = useRef<HTMLInputElement>(null)
  // Değer forma yazılıp geri geldiğinde imleç yerinde kalmalı
  const imlecRef = useRef<number | null>(null)

  useLayoutEffect(() => {
    if (imlecRef.current !== null && girdiRef.current) {
      girdiRef.current.setSelectionRange(imlecRef.current, imlecRef.current)
      imlecRef.current = null
    }
  })

  const ek = yuzde ? '%' : (sonEk ?? '')
  const gosterim = maskele(value, ondalik)
  const virgul = gosterim.indexOf(',')

  const uygula = (yeniGosterim: string, imlec: number) => {
    // Değer değişmediyse yeniden çizim olmaz; imleci doğrudan taşırız
    if (yeniGosterim === gosterim) {
      girdiRef.current?.setSelectionRange(imlec, imlec)

      return
    }
    imlecRef.current = imlec
    onChange(maskeden(yeniGosterim))
  }

  /**
   * Tuşları elle işliyoruz: maske korunacaksa tarayıcının serbest metin
   * düzenlemesine bırakılamaz (ondalık hane silinip kayardı).
   */
  const tusaBasildi = (olay: React.KeyboardEvent<HTMLInputElement>) => {
    if (olay.ctrlKey || olay.metaKey || olay.altKey) {
      return
    }

    const bas = olay.currentTarget.selectionStart ?? 0
    const son = olay.currentTarget.selectionEnd ?? bas
    const tumuSecili = bas === 0 && son === gosterim.length
    const tamKisim = virgul === -1 ? gosterim : gosterim.slice(0, virgul)
    const kesirKisim = virgul === -1 ? '' : gosterim.slice(virgul)

    if (/^[0-9]$/.test(olay.key)) {
      olay.preventDefault()

      // Tamamı seçiliyken yazmak baştan yazmaktır
      if (tumuSecili) {
        uygula(olay.key + kesirKisim.replace(/\d/g, '0'), 1)

        return
      }

      // Tam kısım: rakam araya girer; tek başına "0" ise yerini bırakır
      if (virgul === -1 || bas <= virgul) {
        const yeniTam =
          tamKisim === '0' ? olay.key : tamKisim.slice(0, bas) + olay.key + tamKisim.slice(son)
        uygula(yeniTam + kesirKisim, tamKisim === '0' ? 1 : bas + 1)

        return
      }

      // Ondalık kısım: basamak sayısı sabit, üzerine yazılır
      if (bas < gosterim.length) {
        uygula(gosterim.slice(0, bas) + olay.key + gosterim.slice(bas + 1), bas + 1)
      }

      return
    }

    // Virgül/nokta imleci ondalık haneye taşır (ERP'deki alışkanlık)
    if (olay.key === ',' || olay.key === '.') {
      olay.preventDefault()
      if (virgul !== -1) {
        olay.currentTarget.setSelectionRange(virgul + 1, virgul + 1)
      }

      return
    }

    if (olay.key === 'Backspace') {
      olay.preventDefault()

      if (tumuSecili) {
        uygula(maskele('0', ondalik), 1)

        return
      }

      // Ondalık hanede silmek basamağı sıfırlar (hane kaybolmaz)
      if (virgul !== -1 && bas > virgul + 1) {
        uygula(gosterim.slice(0, bas - 1) + '0' + gosterim.slice(bas), bas - 1)

        return
      }

      if (bas > 0 && bas <= virgul) {
        const yeniTam = tamKisim.slice(0, bas - 1) + tamKisim.slice(son)
        uygula((yeniTam === '' ? '0' : yeniTam) + kesirKisim, Math.max(bas - 1, 1))
      }

      return
    }
  }

  return (
    <div>
      <label htmlFor={id} className={etiketGizli ? 'sr-only' : ALAN_ETIKETI}>
        {label}
      </label>
      {/* Etiket gizliyken üst boşluk da olmamalı; ızgarada komşu hücrelerle
          aynı hizada başlar */}
      <div className={etiketGizli ? 'relative' : 'relative mt-1'}>
        <input
          id={id}
          ref={girdiRef}
          inputMode="decimal"
          autoComplete="off"
          disabled={disabled}
          value={gosterim}
          onKeyDown={tusaBasildi}
          // Yapıştırma gibi doğrudan değişimler: maskeye geri oturtulur
          onChange={(olay) => onChange(maskeden(maskele(maskeden(olay.target.value), ondalik)))}
          // Alana girildiğinde imleç tam kısmın sonunda durur: yazılan rakam
          // ondalık haneye değil, virgülün soluna gider
          onFocus={(olay) => {
            const yer = virgul === -1 ? gosterim.length : virgul
            olay.currentTarget.setSelectionRange(yer, yer)
          }}
          aria-invalid={hata ? true : undefined}
          // Birim metni sayının üstüne binmesin diye sağ boşluk uzunluğa göre
          style={ek === '' ? undefined : { paddingRight: `${ek.length + 1.5}ch` }}
          className={`block w-full py-2 pl-3 text-right ${ek === '' ? 'pr-3' : ''} ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)}`}
        />
        {ek === '' ? null : (
          <span className="pointer-events-none absolute inset-y-0 right-2 flex items-center text-sm text-slate-400 dark:text-slate-500">
            {ek}
          </span>
        )}
      </div>
      {hata && !etiketGizli ? (
        <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p>
      ) : null}
    </div>
  )
}
