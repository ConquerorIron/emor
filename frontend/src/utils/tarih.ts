/**
 * Türkçe tarih formatı yardımcıları (kullanıcı isteği 2026-07-09):
 * tüm tarihler UI'da GG.AA.YYYY gösterilir; API sözleşmesi ISO (YYYY-MM-DD) kalır.
 * Tarayıcı yereline bağımlı formatlama bilinçli kullanılmaz — her kullanıcı
 * aynı biçimi görür.
 */

const ISO_DESENI = /^(\d{4})-(\d{2})-(\d{2})/

const iki = (sayi: number) => String(sayi).padStart(2, '0')

/** ISO tarih/zaman dizgesini GG.AA.YYYY gösterir; boşta '—', tanınmayanda girdiyi döndürür. */
export function tarihGoster(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }

  const eslesme = ISO_DESENI.exec(iso)
  if (!eslesme) {
    return iso
  }

  return `${eslesme[3]}.${eslesme[2]}.${eslesme[1]}`
}

/** ISO zaman damgasını GG.AA.YYYY SS:DD gösterir (yerel saat). */
export function zamanGoster(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }

  const zaman = new Date(iso)
  if (Number.isNaN(zaman.getTime())) {
    return iso
  }

  return `${iki(zaman.getDate())}.${iki(zaman.getMonth() + 1)}.${zaman.getFullYear()} ${iki(zaman.getHours())}:${iki(zaman.getMinutes())}`
}

/** Rakam dizisini GG.AA.YYYY maskesine oturtur (yazarken noktalar otomatik eklenir). */
export function tarihMaskele(girdi: string): string {
  const rakamlar = girdi.replace(/\D/g, '').slice(0, 8)
  const parcalar = [rakamlar.slice(0, 2), rakamlar.slice(2, 4), rakamlar.slice(4, 8)]

  return parcalar.filter((parca) => parca !== '').join('.')
}

/** Bugünün tarihi ISO (YYYY-MM-DD) — yerel saat diliminde. */
export function bugunIso(): string {
  return isoYaz(new Date())
}

function isoYaz(tarih: Date): string {
  return `${tarih.getFullYear()}-${iki(tarih.getMonth() + 1)}-${iki(tarih.getDate())}`
}

function isoOku(iso: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
    return null
  }

  return new Date(`${iso}T00:00:00`)
}

/** ISO tarihe gün ekler; geçersiz girdide '' döner. */
export function gunEkle(iso: string, gun: number): string {
  const tarih = isoOku(iso)
  if (tarih === null) {
    return ''
  }
  tarih.setDate(tarih.getDate() + gun)

  return isoYaz(tarih)
}

/**
 * ISO tarihe ay ekler. Ay sonu taşması SQL Server DATEADD gibi kırpılır
 * (31 Ocak + 1 ay = 28/29 Şubat), JS'in varsayılan taşması kullanılmaz.
 */
export function ayEkle(iso: string, ay: number): string {
  const tarih = isoOku(iso)
  if (tarih === null) {
    return ''
  }

  const gun = tarih.getDate()
  tarih.setDate(1)
  tarih.setMonth(tarih.getMonth() + ay)
  const ayinSonGunu = new Date(tarih.getFullYear(), tarih.getMonth() + 1, 0).getDate()
  tarih.setDate(Math.min(gun, ayinSonGunu))

  return isoYaz(tarih)
}

/** İki ISO tarih arasındaki tam gün farkı (bitis - baslangic); geçersizde null. */
export function gunFarki(baslangicIso: string, bitisIso: string): number | null {
  const baslangic = isoOku(baslangicIso)
  const bitis = isoOku(bitisIso)
  if (baslangic === null || bitis === null) {
    return null
  }

  // Yaz saati geçişlerinde saat kayması olmasın diye UTC gün sayısı üzerinden
  const gunMs = 24 * 60 * 60 * 1000
  const baslangicGun = Date.UTC(baslangic.getFullYear(), baslangic.getMonth(), baslangic.getDate())
  const bitisGun = Date.UTC(bitis.getFullYear(), bitis.getMonth(), bitis.getDate())

  return Math.round((bitisGun - baslangicGun) / gunMs)
}

/** GG.AA.YYYY gösterimini ISO'ya çevirir; eksik/geçersiz tarihte '' döner. */
export function gosterimdenIso(metin: string): string {
  const eslesme = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(metin)
  if (!eslesme) {
    return ''
  }

  const gun = Number(eslesme[1])
  const ay = Number(eslesme[2])
  const yil = Number(eslesme[3])

  // Gerçek takvim kontrolü (31.02 gibi girdiler elenir; Date taşmayı sessizce düzeltir)
  const tarih = new Date(yil, ay - 1, gun)
  const gecerli =
    tarih.getFullYear() === yil && tarih.getMonth() === ay - 1 && tarih.getDate() === gun

  if (!gecerli || yil < 1900) {
    return ''
  }

  return `${eslesme[3]}-${eslesme[2]}-${eslesme[1]}`
}
