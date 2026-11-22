# Google Drive yedek hedefi — Google Cloud kurulumu

eMOR'un yedekleri Google Drive'a gönderebilmesi için Google'a **bir kez**
tanıtılması gerekir. Bu belge o işlemi adım adım anlatır. Yaklaşık 10 dakika
sürer, **ücretsizdir**, kredi kartı istemez.

Bu işlemden sonra akış şöyle olur: Yedek Hedefleri → Yeni Hedef → Google Drive →
**"Google ile giriş yap"** → Gmail hesabıyla giriş → Drive'dan klasör seçimi.

> Google'ın ekranları zaman zaman değişir. Menü adları birebir tutmazsa en
> yakın seçeneği kullanın ya da ekran görüntüsünü gönderin.

## Başlamadan önce

- Yedeklerin gideceği **Gmail hesabıyla** girin (ör. kişisel Gmail ya da ortak
  şirket hesabı). Yedekler bu hesabın Drive'ına gider ve onun kotasını kullanır
  (ücretsiz hesapta 15 GB).
- Sitenin adresi: `https://erp.tersane-istanbul.com`

## 1. Proje açın

1. <https://console.cloud.google.com> adresine Gmail hesabınızla girin.
   İlk girişte kullanım koşullarını kabul edin.
2. Sol üstteki proje seçicisine tıklayın → **Yeni proje**.
3. Proje adı: `eMOR Yedek`. Kuruluş: **Kuruluş yok**. → **Oluştur**.
4. Proje açılınca sol üstteki seçicide **eMOR Yedek**'in seçili olduğundan emin olun.

## 2. İki API'yi etkinleştirin

1. Sol menü → **API'ler ve Hizmetler** → **Kitaplık**.
2. Arama kutusuna `Google Drive API` yazın → açın → **Etkinleştir**.
3. Kitaplığa dönün, `Google Picker API` yazın → açın → **Etkinleştir**.

Faturalandırma (kredi kartı) isteyen bir ekran çıkarsa geçin; bu iki API için
gerekmez.

## 3. Uygulamayı tanıtın (izin ekranı)

1. Sol menü → **Google Auth Platform** (bazı ekranlarda "OAuth izin ekranı")
   → **Başlayın**.
2. **Uygulama bilgileri**
   - Uygulama adı: `eMOR Yedek`
   - Kullanıcı destek e-postası: kendi Gmail adresiniz
3. **Kitle**: **Harici** (External).
4. **İletişim bilgileri**: kendi e-posta adresiniz.
5. Koşulları onaylayın → **Oluştur**.

**Logo yüklemeyin.** Logo yüklenirse Google ayrıca "marka doğrulaması" ister ve
bekletir; gerek yok.

## 4. İzni tanımlayın

1. Google Auth Platform → **Veri erişimi** → **Kapsam ekle veya kaldır**.
2. Filtre kutusuna `drive.file` yazın.
3. `.../auth/drive.file` satırını işaretleyin → **Güncelle** → **Kaydet**.

Bu, en dar Drive iznidir: uygulama yalnız sizin seçtiğiniz klasörü ve kendi
yüklediği yedekleri görür; Drive'ınızdaki diğer dosyalara erişemez. Google bu
izni "hassas olmayan" sınıfında tutar; inceleme / onay süreci gerekmez.

## 5. Uygulamayı yayınlayın (önemli)

1. Google Auth Platform → **Kitle**.
2. Yayınlanma durumu "Test" görünüyorsa → **Uygulamayı yayınla** → **Onayla**.
3. Durum **Üretimde** (In production) olmalı.

Bu adım atlanırsa uygulama "test" durumunda kalır ve Google bağlantıyı
**7 gün sonra** düşürür; yedekler sessizce durur. Test kullanıcısı eklemeye gerek yok.

## 6. Giriş istemcisini oluşturun

1. Google Auth Platform → **İstemciler** → **İstemci oluştur**.
2. Uygulama türü: **Web uygulaması**. Ad: `eMOR Yedek web`.
3. **Yetkili JavaScript kaynakları** → **URI ekle**:
   ```
   https://erp.tersane-istanbul.com
   ```
4. **Yetkili yönlendirme URI'leri** → **URI ekle** (tek harfi bile farklı olursa
   giriş hata verir):
   ```
   https://erp.tersane-istanbul.com/ayarlar/yedek-hedefleri/google-donus
   ```
5. **Oluştur**.
6. Açılan pencerede iki değer görünür. İkisini de bir yere kopyalayın:
   - **İstemci kimliği** (`….apps.googleusercontent.com` ile biter)
   - **İstemci gizli anahtarı** (`GOCSPX-` ile başlar)

   Gizli anahtar sonradan tam gösterilmeyebilir. Kopyalamayı kaçırırsanız
   **JSON'u indir** deyin ya da istemcide yeni bir gizli anahtar oluşturun.

## 7. Klasör seçme penceresi için API anahtarı

1. Sol menü → **API'ler ve Hizmetler** → **Kimlik bilgileri**.
2. **Kimlik bilgisi oluştur** → **API anahtarı**. Anahtar oluşur.
3. Anahtarın yanındaki **Düzenle** (kalem):
   - Ad: `eMOR Yedek Picker`
   - **Uygulama kısıtlamaları** → **Web siteleri** → **Ekle**:
     ```
     https://erp.tersane-istanbul.com/*
     ```
   - **API kısıtlamaları** → **Anahtarı kısıtla** → listeden yalnız
     **Google Picker API**'yi seçin.
   - **Kaydet**.
4. **API anahtarını** kopyalayın (`AIza` ile başlar).

## 8. Proje numarasını alın

1. Konsol ana sayfası (**Kontrol paneli**) → **Proje bilgileri** kartı, ya da
   sol menü → **IAM ve Yönetici** → **Ayarlar**.
2. **Proje numarası**nı kopyalayın (yalnız rakamlardan oluşur, genelde 12 hane).

   Proje **kimliği** (`emor-yedek-123456` gibi harfli olan) değil, **numarası**.

## 9. Bilgileri gönderin

Şu dört değeri gönderin, sunucuya ben yazarım:

| Bilgi | Neye benzer |
|---|---|
| İstemci kimliği | `123…-abc….apps.googleusercontent.com` |
| İstemci gizli anahtarı | `GOCSPX-…` |
| API anahtarı | `AIza…` |
| Proje numarası | `123456789012` |

Gmail şifrenizi **göndermeyin**; gerekmez.

## 10. Bağlanma (bilgiler sunucuya girildikten sonra)

1. eMOR → **Ayarlar → Yedek Hedefleri** → **Yeni Hedef**.
2. Hedef türü: **Google Drive**. Ad kendiliğinden "Google Drive" gelir
   (isterseniz değiştirin).
3. **Google ile giriş yap**.
4. Google'ın ekranında Gmail hesabınızı seçin → "eMOR Yedek, bu uygulamayla
   kullandığınız belirli Google Drive dosyalarını … istiyor" → **İzin ver**.
5. eMOR'a dönünce Google'ın **klasör seçme penceresi** açılır. Yedeklerin
   gideceği klasörü seçin. Yeni bir klasör isterseniz pencereyi kapatıp
   listedeki **Yeni klasör** düğmesini kullanın.
6. eMOR seçilen klasöre küçük bir deneme dosyası yazıp siler; listede klasörün
   adı görünür.
7. Hedefin **Düzenle** penceresinde **Bağlantıyı Sına** ile yazma–okuma–silme
   döngüsünü bir kez daha deneyin.

## Sorun çıkarsa

| Görülen | Nedeni / yapılacak |
|---|---|
| Google'da `redirect_uri_mismatch` | 6. adımdaki yönlendirme adresi birebir aynı değil (sonda `/` olmamalı, `https`). |
| `access_denied` ya da "bu uygulama kuruluşunuzla sınırlı" | 3. adımda kitle **Harici** seçilmemiş. |
| "Google bu uygulamayı doğrulamadı" uyarısı | Kendi uygulamanız; **Gelişmiş → eMOR Yedek'e git** ile devam edilebilir. Bize bildirin, izin listesine bakalım (4. adımda yalnız `drive.file` olmalı). |
| Giriş tamam ama klasör penceresi açılmıyor | 7. adımdaki API anahtarının web sitesi ya da API kısıtlaması yanlış; tarayıcı eklentisi (reklam engelleyici) de engelleyebilir. |
| Bir hafta sonra yedekler "yeniden bağlanın" diyor | 5. adım yapılmamış (uygulama "test" durumunda). Yayınlayıp yeniden bağlanın. |
| "Kota dolu" | Drive'ın depolama alanı dolmuş; eski dosyaları silin ya da alan satın alın. |

Teknik ayrıntı: [README §4.6 "Google Drive bağlantısı"](README.md).
