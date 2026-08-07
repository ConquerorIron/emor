/**
 * ERP MIKTAR / ORAN tipleri için sayı girişi. Türkçe biçim: ondalık ayracı
 * virgül. Değer forma NOKTALI (makine okunur) biçimde yazılır ki proc'a ve
 * SQL'e dönüştürmeden gidebilsin.
 */

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

/** Kullanıcı virgül yazar, veri nokta tutar. */
function gosterime(deger: string): string {
  return deger.replace('.', ',')
}

function veriye(metin: string, ondalik: number): string {
  // Yalnız rakam, virgül/nokta ve baştaki eksi
  let temiz = metin.replace(/[^\d,.-]/g, '').replace(/(?!^)-/g, '')
  temiz = temiz.replace(/,/g, '.')

  // Birden fazla ondalık ayracı olmasın
  const ilk = temiz.indexOf('.')
  if (ilk !== -1) {
    temiz = temiz.slice(0, ilk + 1) + temiz.slice(ilk + 1).replace(/\./g, '')
    const [tam, kesir = ''] = temiz.split('.')
    temiz = ondalik === 0 ? tam : `${tam}.${kesir.slice(0, ondalik)}`
  }

  return temiz
}

/**
 * Alandan çıkıldığında ERP'deki gibi tam basamağa tamamlar: 6 basamaklı bir
 * ölçü sisteminde "1" → "1.000000". Yazarken tamamlanmaz, yoksa imleç kullanıcı
 * yazdıkça sıfırların arasında kalırdı.
 */
function basamagaTamamla(deger: string, ondalik: number): string {
  if (deger === '' || deger === '-') {
    return deger
  }
  const sayi = Number(deger)

  return Number.isFinite(sayi) ? sayi.toFixed(ondalik) : deger
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
  const ek = yuzde ? '%' : (sonEk ?? '')

  return (
    <div>
      <label htmlFor={id} className={etiketGizli ? 'sr-only' : ALAN_ETIKETI}>
        {label}
      </label>
      <div className="relative mt-1">
        <input
          id={id}
          inputMode="decimal"
          autoComplete="off"
          disabled={disabled}
          value={gosterime(value)}
          onChange={(olay) => onChange(veriye(olay.target.value, ondalik))}
          onBlur={() => onChange(basamagaTamamla(value, ondalik))}
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
