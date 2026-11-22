import type { GoogleSeciciBilgisi } from '@/features/yedekleme/hedefApi'

/**
 * Google'ın klasör seçme penceresi (Google Picker). Pencere Google'ın kendi
 * arayüzüdür: kullanıcı Drive'ındaki klasörlerini orada görür ve seçer;
 * uygulama yalnız seçilen klasörün kimliğini alır (`drive.file` yetkisi
 * hesabın diğer dosyalarını açmaz). Betik npm paketi değildir, Google'ın
 * adresinden ilk kullanımda yüklenir.
 */

const BETIK = 'https://apis.google.com/js/api.js'
const KLASOR_TURU = 'application/vnd.google-apps.folder'

/** Picker'ın kullandığımız kısmı (Google'ın tür tanımı npm'de ayrı paket; eklenmedi) */
interface PickerGorunumu {
  setIncludeFolders(deger: boolean): PickerGorunumu
  setSelectFolderEnabled(deger: boolean): PickerGorunumu
  setMimeTypes(turler: string): PickerGorunumu
  setOwnedByMe(deger: boolean): PickerGorunumu
}

interface PickerVerisi {
  action: string
  docs?: { id: string; name?: string; mimeType?: string }[]
}

interface PickerKurucusu {
  setAppId(kimlik: string): PickerKurucusu
  setOAuthToken(anahtar: string): PickerKurucusu
  setDeveloperKey(anahtar: string): PickerKurucusu
  addView(gorunum: PickerGorunumu): PickerKurucusu
  setTitle(baslik: string): PickerKurucusu
  setLocale(dil: string): PickerKurucusu
  setCallback(geriCagri: (veri: PickerVerisi) => void): PickerKurucusu
  build(): { setVisible(deger: boolean): void }
}

interface GooglePicker {
  DocsView: new (gorunum: string) => PickerGorunumu
  PickerBuilder: new () => PickerKurucusu
  ViewId: { FOLDERS: string }
  Action: { PICKED: string; CANCEL: string }
}

interface GoogleGlobali {
  gapi?: {
    load(kutuphane: string, secenekler: { callback: () => void; onerror: () => void }): void
  }
  google?: { picker?: GooglePicker }
}

let yukleme: Promise<GooglePicker> | null = null

/** Betiği bir kez yükler; hata olursa sonraki deneme yeniden yükler */
function pickerYukle(): Promise<GooglePicker> {
  yukleme ??= new Promise<GooglePicker>((resolve, reject) => {
    const kuresel = window as unknown as GoogleGlobali
    const kutuphaneyiYukle = () => {
      if (!kuresel.gapi) {
        reject(new Error('Google betiği yüklenemedi.'))
        return
      }
      kuresel.gapi.load('picker', {
        callback: () => {
          const picker = kuresel.google?.picker
          if (picker) {
            resolve(picker)
          } else {
            reject(new Error('Google klasör seçme penceresi yüklenemedi.'))
          }
        },
        onerror: () => reject(new Error('Google klasör seçme penceresi yüklenemedi.')),
      })
    }

    if (kuresel.gapi) {
      kutuphaneyiYukle()
      return
    }
    const betik = document.createElement('script')
    betik.src = BETIK
    betik.async = true
    betik.onload = kutuphaneyiYukle
    betik.onerror = () => reject(new Error('Google betiği yüklenemedi.'))
    document.head.appendChild(betik)
  }).catch((hata: unknown) => {
    yukleme = null
    throw hata
  })

  return yukleme
}

export interface SecilenKlasor {
  id: string
  ad: string | null
}

/**
 * Pencereyi açar; seçilen klasörü ya da (vazgeçilirse) null döndürür. Yalnız
 * klasörler ve yalnız hesabın kendi klasörleri gösterilir (paylaşılmış klasör
 * başka hesabın deposudur; sunucu da reddeder).
 */
export async function googleKlasorSec(
  bilgi: GoogleSeciciBilgisi,
  secenekler: { baslik: string; dil: string },
): Promise<SecilenKlasor | null> {
  const picker = await pickerYukle()

  return new Promise<SecilenKlasor | null>((resolve) => {
    const gorunum = new picker.DocsView(picker.ViewId.FOLDERS)
      .setIncludeFolders(true)
      .setSelectFolderEnabled(true)
      .setMimeTypes(KLASOR_TURU)
      .setOwnedByMe(true)

    new picker.PickerBuilder()
      .setAppId(bilgi.proje_numarasi)
      .setOAuthToken(bilgi.erisim_anahtari)
      .setDeveloperKey(bilgi.api_anahtari)
      .addView(gorunum)
      .setTitle(secenekler.baslik)
      .setLocale(secenekler.dil)
      .setCallback((veri) => {
        if (veri.action === picker.Action.PICKED) {
          const secilen = veri.docs?.[0]
          resolve(secilen ? { id: secilen.id, ad: secilen.name ?? null } : null)
        } else if (veri.action === picker.Action.CANCEL) {
          resolve(null)
        }
      })
      .build()
      .setVisible(true)
  })
}
