<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Hedefin fiziksel konumu (BY-016-R1, R3): kopya kayıtları hedef kimliğine
 * bağlıdır ve dosyayı o hedefin konumunda arar. Kopyası duran (tamamlanmış
 * ya da sürmekte olan) hedefte konumu belirleyen alanlar değiştirilemez;
 * başka bir yer için yeni hedef açılır. Kimlik bilgisi (parola, anahtar) ve
 * konum belirlemeyen alanlar serbesttir. Hedef güncellemesi de üstüne geri
 * yüklemedeki hedef uzlaştırması da (AyarSecimi) bu sınıfı kullanır.
 *
 * Konum bileşenleri, bağlayıcıların değeri gerçekten nasıl kullandığına göre
 * seçilmiştir (alan → karar):
 * - `sunucu` (FTP/FTPS/SFTP): konum; alan adı büyük-küçük harf duyarsız.
 * - `port` (FTP/FTPS/SFTP): konum — aynı sunucuda başka port başka hizmet /
 *   depo olabilir. Boş = bağlayıcının varsayılan portu (tanımdaki varsayılan,
 *   bağlayıcıyla aynı sabit), "22" metni = 22.
 * - `kullanici_adi` (FTP/FTPS/SFTP): konum — göreli klasör kullanıcının ev
 *   klasörüne, mutlak klasör de chroot'lu sunucuda kullanıcının köküne göre
 *   çözülür.
 * - `klasor` (FTP/FTPS/SFTP/NAS/Yerel): konum; boş = giriş / ana klasör,
 *   sondaki "/" bağlayıcıda atılır. "001" ile "1" farklı klasördür.
 * - `baglanti_noktasi` (NAS), `bolge`, `kova` (S3): konum, birebir.
 * - `uc_nokta` (S3): konum; alan adı küçük harfe, `:443` ve sondaki "/"
 *   bağlayıcıda olduğu gibi atılır.
 * - `on_ek` (S3): konum (uzak kimlik ön ekle başlar); baştaki / sondaki "/" atılır.
 * - `yol_stili` (S3): konum — kapalıyken istek `kova.uc_nokta` alan adına
 *   gider, başka bir sunucuya çözülebilir. Bağlayıcı gibi yalnız `true` açıktır.
 * - `klasor_adi` (Drive): konum; boş = varsayılan klasör adı.
 * - `eposta` (Drive, bağlayıcının yazdığı alan): konum — dosya kimlikleri
 *   bağlı Google hesabındadır.
 * Serbest: sırlar, ad, aktiflik, FTP pasif kip (yalnız veri bağlantısının
 * yönü), S3 erişim anahtarı (kimlik; anahtar döndürme engellenmez — sır
 * kuralı yine uygulanır: adresDegistiMi).
 *
 * Karşılaştırma alan biçimine göre normalleştirilmiş değerler arasında
 * KESİN eşitliktir (`===`).
 *
 * Aynı bileşenler konum izini de verir (`iz`, BY-028-R5): kopya kaydı ve
 * imzalı kopya olayı yapıldığı yerin izini taşır; dizin kurulurken izi
 * hedefin bugünkü iziyle uyuşmayan (ya da izsiz) kopya hedefe `tamam`
 * bağlanmaz (KopyaDurumu::KonumBelirsiz). Kilit kopyası duran hedefin yerini
 * sabit tutar; iz, kilidin göremediği yolu kapatır: veritabanı geri
 * yüklenince hedef eski yerine döner, olay günlüğü sonraki yerdeki kopyaları
 * da taşır.
 *
 * İki hedefin aynı fiziksel depoya yazıp yazmadığı ayrı sorudur (ayniDepoMu;
 * BY-010-R7, R8): iz yazımı karşılaştırır, depo sorusu dosya sistemi
 * hedeflerinde gerçek konumu, uzak hedeflerde dosyanın adresini belirleyen
 * alanları (tür, port, bölge gibi aynı nesneye götüren farklar olmadan).
 */
final class HedefKonumu
{
    /** Alan → karşılaştırma biçimi (normallestir()) */
    private const BICIMLER = [
        'sunucu' => 'sunucu',
        'port' => 'port',
        'kullanici_adi' => 'metin',
        'klasor' => 'klasor',
        'baglanti_noktasi' => 'metin',
        'uc_nokta' => 'uc_nokta',
        'bolge' => 'metin',
        'kova' => 'metin',
        'on_ek' => 'on_ek',
        'yol_stili' => 'yol_stili',
        'klasor_adi' => 'klasor_adi',
        'erisim_anahtari' => 'metin',
        'eposta' => 'metin',
    ];

    /** Dosyanın nerede durduğunu belirleyen form alanları (bağlayıcı tanımlarındaki adlar) */
    private const KONUM_ALANLARI = ['sunucu', 'port', 'kullanici_adi', 'klasor', 'baglanti_noktasi', 'uc_nokta', 'bolge', 'kova', 'on_ek', 'yol_stili', 'klasor_adi'];

    /**
     * Sırrın gideceği yeri belirleyen form alanları: biri değişirken kayıtlı
     * sır yeni yere gönderilmez (K8). Klasör, önek gibi alanlar sır istemez.
     */
    private const ADRES_ALANLARI = ['sunucu', 'port', 'kullanici_adi', 'uc_nokta', 'bolge', 'kova', 'erisim_anahtari'];

    /** Formda olmayan, bağlayıcının yazdığı konum (ve sır) bileşenleri */
    private const BAGLAYICI_ALANLARI = ['google_drive' => ['eposta']];

    /**
     * Tür → depo ailesi (ayniDepoMu): aynı ailedeki iki hedef aynı dosyalara
     * yazabilir. Google Drive ailesizdir (gerekçe ayniDepoMu'da).
     */
    private const DEPO_AILELERI = [
        'yerel' => 'dosya_sistemi',
        'nas' => 'dosya_sistemi',
        'ftp' => 'dosya_sunucusu',
        'ftps' => 'dosya_sunucusu',
        'sftp' => 'dosya_sunucusu',
        's3' => 's3',
    ];

    /** Uzak ailenin depo kimliğini veren alanlar (BY-010-R8; biçimler BICIMLER'den) */
    private const DEPO_ALANLARI = [
        'dosya_sunucusu' => ['sunucu', 'kullanici_adi', 'klasor'],
        's3' => ['uc_nokta', 'kova', 'on_ek'],
    ];

    /** `withExists` sütunu: liste sorgusunda hedef başına ayrı sorgu olmasın */
    public const KOPYALI_SUTUNU = 'konumda_kopya_var';

    /** Yeri hedefin bugünkü konumu olarak doğrulanamayan kopyanın hata kodu (KopyaDurumu::KonumBelirsiz) */
    public const DOGRULANAMADI = 'KOPYA_KONUMU_DOGRULANAMADI';

    /** Olaydaki hedefi veritabanında tanımlı olmayan kopyanın hata kodu (hedefsiz `konum_belirsiz`; BY-028-R9) */
    public const HEDEFI_TANIMSIZ = 'KOPYA_HEDEFI_TANIMSIZ';

    /** Aynı hedefe bu yedeğin başka bir yaşayan kopyası bağlandığı için hedefsiz tutulan farklı nesnenin hata kodu (BY-028-R10) */
    public const HEDEFTE_BASKA_KOPYA = 'KOPYA_HEDEFTE_BASKA_KOPYA';

    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    /**
     * İki hedef aynı fiziksel depoya mı yazıyor (BY-010-R7). Öyleyse bir
     * dosya adı iki hedefte AYNI dosyadır: birinin saklama kuralı dosyayı
     * silince diğerinin kopyası `tamam` görünür, yerel dosya ona dayanılarak
     * silinir. Bu yüzden böyle ikinci hedef kaydedilmez, eskiden kalmışsa
     * ortak dosyaya kopya yazılmaz ve saklama onu silmez.
     *
     * Dosya sistemi hedefleri (Yerel, NAS): klasörler yazıma değil bu
     * sunucudaki gerçek konuma göre karşılaştırılır
     * (YerelDiskBaglayicisi::ayniKonumMu — G19 tek ölçüt; `/x`, `/x/.`,
     * `/x/../x`, sembolik bağlantı, bind mount; klasörü boş Yerel hedef yedek
     * klasörünün kendisidir).
     *
     * Uzak hedefler (BY-010-R8): bağlayıcının dosya adresini kurduğu alanların
     * mevcut normalleştirmesiyle (normallestir — izle aynı biçimler) depo
     * kimliği. Aynı nesneye götüren farklar kimliğe girmez; şüphede "aynı"
     * sayılır (D4: fazla engelleme bir 409'dur, eksik engelleme veri kaybı):
     * - S3: uç nokta (alan adı küçük harf, `:443` ve sondaki "/" atılır) +
     *   kova + önek (baştaki / sondaki "/" atılır; S3Baglayicisi::onEk ile
     *   aynı). Bölge yalnız imzaya, erişim anahtarı yalnız kimliğe girer; yol
     *   stili aynı kovaya giden iki adres biçimidir (`uc/kova/anahtar` ↔
     *   `kova.uc/anahtar`): üçü de kimliğe girmez.
     * - FTP / FTPS / SFTP tek ailedir (aynı sunucuda aynı hesap aynı
     *   klasöre üç protokolle de yazabilir): sunucu + kullanıcı adı + klasör;
     *   tür ve port girmez. Kullanıcı adı ve klasör harf duyarsız (Windows /
     *   IIS FTP sunucuları duyarsızdır); klasörün baştaki ve sondaki "/"'ı
     *   atılır (chroot'lu hesapta — ChrootDirectory %h, chroot_local_user —
     *   `/x` ile `x`, `/` ile giriş klasörü aynı yerdir).
     * - Zorunlu bileşeni (sunucu, kullanıcı, uç nokta, kova) eksik hedefin
     *   kimliği yoktur: bağlayıcısı hiçbir yere yazamaz.
     * Google Drive kapsam dışı: her yükleme yeni dosya kimliği açar (aynı adla
     * da olsa üstüne yazmaz), okuma ve silme o kimlikle yapılır
     * (GoogleDriveBaglayicisi::yaz / sil); iki hedefin kopyası iki ayrı dosyadır.
     * Kanıtlanamayan eşdeğerlikler bilinen sınırdır (README §4.6): alan adı /
     * IP takma adı (ağ çağrısı yapılmaz), türler arası (NAS bağlama noktası ↔
     * aynı sunucunun SFTP'si), chroot'u farklı iki hesap, S3 uç nokta takma adı
     * (`s3.amazonaws.com` ↔ bölgesel uç nokta).
     */
    public function ayniDepoMu(YedekHedefi $a, YedekHedefi $b): bool
    {
        $klasorA = $this->dosyaSistemiKlasoru($a);
        $klasorB = $this->dosyaSistemiKlasoru($b);

        if ($klasorA !== null && $klasorB !== null) {
            return YerelDiskBaglayicisi::ayniKonumMu($klasorA, $klasorB);
        }

        $kimlikA = $this->uzakDepoKimligi($a);

        return $kimlikA !== null && $kimlikA === $this->uzakDepoKimligi($b);
    }

    /**
     * Kayıtlı hedeflerden (pasifler dahil: kopyaları durur, yeniden açılabilir)
     * bu hedefle aynı depoya yazanlar; hedefin kendisi hariç.
     *
     * @return Collection<int, YedekHedefi>
     */
    public function ayniDepodakiler(YedekHedefi $hedef): Collection
    {
        if ($this->dosyaSistemiKlasoru($hedef) === null && $this->uzakDepoKimligi($hedef) === null) {
            return new Collection;
        }

        return YedekHedefi::query()
            ->whereIn('tur', array_keys(self::DEPO_AILELERI, self::DEPO_AILELERI[$hedef->tur->value], true))
            ->when($hedef->exists, fn (Builder $q) => $q->whereKeyNot($hedef->getKey()))
            ->orderBy('id')
            ->get()
            ->filter(fn (YedekHedefi $diger): bool => $this->ayniDepoMu($hedef, $diger))
            ->values();
    }

    /**
     * Uzak hedefin depo kimliği (ayniDepoMu'daki kurallar); dosya sistemi
     * hedefinde, Google Drive'da ve zorunlu bileşeni eksik hedefte null.
     *
     * @return list<string>|null
     */
    private function uzakDepoKimligi(YedekHedefi $hedef): ?array
    {
        $aile = self::DEPO_AILELERI[$hedef->tur->value] ?? null;

        if ($aile === null || ! isset(self::DEPO_ALANLARI[$aile]) || ! $this->katalog->destekleniyorMu($hedef->tur)) {
            return null;
        }

        $varsayilanlar = $this->varsayilanlar($hedef);
        $kimlik = [$aile];
        foreach (self::DEPO_ALANLARI[$aile] as $ad) {
            $deger = self::normallestir(self::BICIMLER[$ad], $hedef->baglanti[$ad] ?? null, $varsayilanlar[$ad] ?? null);
            if (! is_string($deger)) {
                return null;
            }
            $kimlik[] = match (true) {
                $aile !== 'dosya_sunucusu' => $deger,
                $ad === 'klasor' => strtolower(trim($deger, '/')),
                default => strtolower($deger),
            };
        }

        return $kimlik;
    }

    /** Dosya sistemi hedefinin yazdığı klasör (bağlayıcıların okuduğu alan); diğer türlerde null */
    private function dosyaSistemiKlasoru(YedekHedefi $hedef): ?string
    {
        $klasor = $hedef->baglanti['klasor'] ?? null;

        return match ($hedef->tur) {
            HedefTuru::Yerel => YerelDiskBaglayicisi::hedefKlasoru($hedef, $this->yapilandirma),
            HedefTuru::Nas => is_string($klasor) && $klasor !== '' ? $klasor : null,
            default => null,
        };
    }

    /**
     * Hedefin bugünkü fiziksel konumunun değişmez kimliği (BY-028-R5): tür +
     * konum bileşenlerinin (aşağıdaki karşılaştırmayla AYNI normalleştirme)
     * kanonik JSON'unun SHA-256'sı. Kopya kaydına ve imzalı kopya olayına
     * yazılır; sır ve konum belirlemeyen alan girmez. Konum bilinemiyorsa
     * null: bağlayıcısı kurulu olmayan tür ya da bağlayıcının yazdığı
     * bileşeni (Google hesabı) henüz olmayan hedef.
     */
    public function iz(YedekHedefi $hedef): ?string
    {
        if (! $this->katalog->destekleniyorMu($hedef->tur)) {
            return null;
        }

        $varsayilanlar = $this->varsayilanlar($hedef);
        $baglayicininki = self::BAGLAYICI_ALANLARI[$hedef->tur->value] ?? [];
        $konum = [];
        foreach ($this->alanlar($hedef, self::KONUM_ALANLARI) as $ad) {
            $konum[$ad] = self::normallestir(self::BICIMLER[$ad] ?? 'metin', $hedef->baglanti[$ad] ?? null, $varsayilanlar[$ad] ?? null);
            if ($konum[$ad] === null && in_array($ad, $baglayicininki, true)) {
                return null;
            }
        }

        return hash('sha256', KanonikJson::kodla(['tur' => $hedef->tur->value, 'konum' => $konum]));
    }

    /**
     * Kopyanın konum izi hedefin bugünkü konumunu mu gösteriyor. İzsiz (eski)
     * kopya ve konumu bilinemeyen hedef doğrulanamaz: false.
     */
    public function konumdaMi(YedekHedefi $hedef, ?string $iz): bool
    {
        $bugunku = $iz === null ? null : $this->iz($hedef);

        return $bugunku !== null && hash_equals($bugunku, $iz);
    }

    /**
     * Liste sorgusuna "konumda kopya var mı" sütununu ekler.
     *
     * @param  Builder<YedekHedefi>  $sorgu
     * @return Builder<YedekHedefi>
     */
    public static function kopyaVarMiEkle(Builder $sorgu): Builder
    {
        return $sorgu->withExists(['kopyalar as '.self::KOPYALI_SUTUNU => fn ($q) => $q->whereIn('durum', self::kilitleyenDurumlar())]);
    }

    /**
     * Değiştirilemeyen alanlar; kopyası olmayan hedefte boş.
     *
     * @return list<string>
     */
    public function kilitliAlanlar(YedekHedefi $hedef): array
    {
        if (! $this->katalog->destekleniyorMu($hedef->tur) || ! $this->kopyaVarMi($hedef)) {
            return [];
        }

        return $this->alanlar($hedef, self::KONUM_ALANLARI);
    }

    /**
     * Yeni açık alanlarla değişecek kilitli alanlar.
     *
     * @param  array<string, mixed>  $yeniAcik  Formun açık alanları (varsayılanlar uygulanmış)
     * @return list<string>
     */
    public function degisenKilitliAlanlar(YedekHedefi $hedef, array $yeniAcik): array
    {
        $kilitli = $this->kilitliAlanlar($hedef);

        return $kilitli === [] ? [] : $this->farkli($hedef, $kilitli, $yeniAcik);
    }

    /**
     * Sırrın gideceği adres değişiyor mu: değişiyorsa kayıtlı sır yeni adrese
     * gönderilmez (güncellemede 422, uzlaştırmada sırsız + pasif). Bağlayıcısı
     * olmayan türde bilinemez: değişti sayılır.
     *
     * @param  array<string, mixed>  $yeniAcik
     */
    public function adresDegistiMi(YedekHedefi $hedef, array $yeniAcik): bool
    {
        if (! $this->katalog->destekleniyorMu($hedef->tur)) {
            return true;
        }

        return $this->farkli($hedef, $this->alanlar($hedef, self::ADRES_ALANLARI), $yeniAcik) !== [];
    }

    /**
     * Türün tanımındaki alanlardan istenenler (tanım sırasıyla) + bağlayıcının yazdığı bileşenler.
     *
     * @param  list<string>  $istenen
     * @return list<string>
     */
    private function alanlar(YedekHedefi $hedef, array $istenen): array
    {
        $adlar = array_values(array_filter(
            array_map(fn (HedefAlani $a): string => $a->ad, $this->katalog->tanim($hedef->tur)->alanlar),
            fn (string $ad): bool => in_array($ad, $istenen, true),
        ));

        return [...$adlar, ...(self::BAGLAYICI_ALANLARI[$hedef->tur->value] ?? [])];
    }

    /**
     * @param  list<string>  $adlar
     * @param  array<string, mixed>  $yeniAcik
     * @return list<string>
     */
    private function farkli(YedekHedefi $hedef, array $adlar, array $yeniAcik): array
    {
        $varsayilanlar = $this->varsayilanlar($hedef);

        return array_values(array_filter($adlar, function (string $ad) use ($hedef, $yeniAcik, $varsayilanlar): bool {
            $bicim = self::BICIMLER[$ad] ?? 'metin';
            $varsayilan = $varsayilanlar[$ad] ?? null;

            return self::normallestir($bicim, $hedef->baglanti[$ad] ?? null, $varsayilan)
                !== self::normallestir($bicim, $yeniAcik[$ad] ?? null, $varsayilan);
        }));
    }

    /**
     * Tanımdaki alan varsayılanları (boş değer bağlayıcıda bunlara döner).
     *
     * @return array<string, mixed>
     */
    private function varsayilanlar(YedekHedefi $hedef): array
    {
        $varsayilanlar = [];
        foreach ($this->katalog->tanim($hedef->tur)->alanlar as $alan) {
            $varsayilanlar[$alan->ad] = $alan->varsayilan;
        }

        return $varsayilanlar;
    }

    /**
     * Bağlayıcının değeri yorumladığı biçim. Tanınmayan biçimli değer olduğu
     * gibi kalır: kesin karşılaştırmada her fark "değişti" sayılır.
     */
    private static function normallestir(string $bicim, mixed $deger, mixed $varsayilan): mixed
    {
        if ($deger === null || $deger === '') {
            $deger = $varsayilan;
        }

        return match ($bicim) {
            'port' => is_string($deger) && preg_match('/^[1-9][0-9]{0,4}$/D', $deger) === 1 ? (int) $deger : $deger,
            'sunucu' => is_string($deger) ? strtolower($deger) : $deger,
            'klasor' => match (true) {
                $deger === null => '',
                is_string($deger) && $deger !== '/' => rtrim($deger, '/'),
                default => $deger,
            },
            'on_ek' => $deger === null ? '' : (is_string($deger) ? trim($deger, '/') : $deger),
            'uc_nokta' => is_string($deger) ? (string) preg_replace('#(?::443)?/?$#D', '', strtolower($deger)) : $deger,
            'klasor_adi' => is_string($deger) && trim($deger) !== '' ? trim($deger) : $varsayilan,
            'yol_stili' => $deger === true,
            default => $deger,
        };
    }

    private function kopyaVarMi(YedekHedefi $hedef): bool
    {
        if (array_key_exists(self::KOPYALI_SUTUNU, $hedef->getAttributes())) {
            return (bool) $hedef->getAttribute(self::KOPYALI_SUTUNU);
        }

        return $hedef->exists && $hedef->kopyalar()->whereIn('durum', self::kilitleyenDurumlar())->exists();
    }

    /** @return list<string> */
    private static function kilitleyenDurumlar(): array
    {
        return [KopyaDurumu::Kopyalaniyor->value, KopyaDurumu::Tamam->value];
    }
}
