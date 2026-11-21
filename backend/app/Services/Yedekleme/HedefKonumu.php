<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Illuminate\Database\Eloquent\Builder;

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

    /** `withExists` sütunu: liste sorgusunda hedef başına ayrı sorgu olmasın */
    public const KOPYALI_SUTUNU = 'konumda_kopya_var';

    public function __construct(private readonly HedefKatalogu $katalog) {}

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
        $varsayilanlar = [];
        foreach ($this->katalog->tanim($hedef->tur)->alanlar as $alan) {
            $varsayilanlar[$alan->ad] = $alan->varsayilan;
        }

        return array_values(array_filter($adlar, function (string $ad) use ($hedef, $yeniAcik, $varsayilanlar): bool {
            $bicim = self::BICIMLER[$ad] ?? 'metin';
            $varsayilan = $varsayilanlar[$ad] ?? null;

            return self::normallestir($bicim, $hedef->baglanti[$ad] ?? null, $varsayilan)
                !== self::normallestir($bicim, $yeniAcik[$ad] ?? null, $varsayilan);
        }));
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
