/**
 * Form alanlarının ortak kutu ölçüleri — TEK doğruluk kaynağı.
 *
 * Aynı satırdaki input, react-select ve textarea aynı yüksekliği tutmak
 * zorunda. Ölçü her bileşende ayrı yazıldığı sürece (h-[42px] kopyaları,
 * textarea'da hiç ölçü olmaması) kayma kaçınılmaz: 1 satırlık textarea 38px,
 * input 42px kalıyordu ve iki sütun birbirinden 4px kayıyordu.
 */

/** Tek satırlık alanların kutu yüksekliği (px) — react-select de bunu kullanır */
export const ALAN_YUKSEKLIGI = 42

/**
 * Tek satırlık girişlerin yüksekliği. Tailwind sınıfı DEĞİŞMEZ metin olmalı
 * (tarayıcıya giden CSS kaynak taramasıyla üretilir), bu yüzden 42 burada
 * elle yazılır — `alanStilleri.test.ts` ikisinin eşitliğini doğrular.
 */
export const ALAN_KUTUSU = 'h-[42px]'

/** Çok satırlı girişler: satır sayısı büyütür ama tek satır kutusunun altına düşemez */
export const ALAN_KUTUSU_ESNEK = 'min-h-[42px]'

/**
 * Kutu görünümü. Yerleşim (genişlik, padding) bilerek DIŞARIDA bırakıldı:
 * bileşenler kendi padding'ini yazıyor (TarihInput pr-10, SayiAlani pr-8,
 * TeslimatSuresiSecimi w-24) ve Tailwind'de aynı özelliği iki sınıfın
 * çekişmesi kaynak sırasına bağlı olduğu için burada tanımlanmamalı.
 */
export const ALAN_GORUNUMU =
  'rounded-xl border bg-white text-sm text-slate-900 shadow-sm transition-colors outline-none placeholder:text-slate-400 focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900'

/** Çerçeve ve odak rengi — doğrulama hatası varsa kırmızı */
export function alanCercevesi(hata?: string): string {
  return hata
    ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
    : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
}

/**
 * Etiket satırı. Sabit yükseklik, çünkü bazı alanların etiket satırında
 * switch var (Termin, Evet/Hayır — 24px); düz metin etiket 20px olduğunda
 * o alanların kutusu komşularından 4px aşağı kayıyordu.
 */
export const ALAN_ETIKET_SATIRI = 'flex h-6 items-center'

/** Düz metin etiket — etiket satırının içeriği */
export const ALAN_ETIKETI = `${ALAN_ETIKET_SATIRI} text-sm font-semibold text-slate-700 dark:text-slate-300`
