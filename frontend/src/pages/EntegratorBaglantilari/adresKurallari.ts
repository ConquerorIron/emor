// İzibiz ve Protel bağlantı formlarının ortak kuralları

// Yalnız https://alan-adı[:port] — izinli alan adı listesi backend'de denetlenir
export const API_ADRESI = /^https:\/\/[A-Za-z0-9.-]+(:\d+)?\/?$/

// Backend EntegratorBaglanti ile aynı: zamanlayıcı en az 15 dk, en çok günde bir
export const ARALIK = { enAz: 15, enCok: 1440 }

/** Boş adres = ortamın varsayılanı (null gönderilir). */
export function adresGovdesi(adres: string): string | null {
  return adres.trim() === '' ? null : adres.trim()
}
