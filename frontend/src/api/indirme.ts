import { isAxiosError } from 'axios'

/**
 * Blob yanıtlı isteklerde hata gövdesi de Blob gelir; `kod` alanı okunabilsin
 * diye JSON'a çevrilip hataya geri yazılır (apiErrorKey sözleşmesi).
 */
export async function blobHatasiniCoz(error: unknown): Promise<never> {
  if (isAxiosError(error) && error.response?.data instanceof Blob) {
    try {
      error.response.data = JSON.parse(await error.response.data.text()) as unknown
    } catch {
      // JSON değilse durum koduna göre çevrilir
    }
  }

  throw error
}

/** Sunucudan gelen dosyayı (Excel, XML…) tarayıcıya indirir */
export function dosyayiIndir(icerik: Blob, ad: string): void {
  const adres = URL.createObjectURL(icerik)
  const baglanti = document.createElement('a')
  baglanti.href = adres
  baglanti.download = ad
  document.body.appendChild(baglanti)
  baglanti.click()
  baglanti.remove()
  // Bazı tarayıcılar indirmeyi click'ten sonra başlatır; adres hemen bırakılmaz
  window.setTimeout(() => URL.revokeObjectURL(adres), 60_000)
}
