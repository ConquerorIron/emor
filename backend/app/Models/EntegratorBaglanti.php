<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EntegratorBaglantiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * e-Belge entegratörü (İzibiz) bağlantı tanımı — ortam ('test' | 'canli')
 * başına tek satır (EFAT-15, S9: tek şirket / tek hesap). Şifre encrypted
 * cast ile saklanır ve serileştirmede gizlidir (SqlBaglanti deseni).
 *
 * Aktiflik sağlayıcı başına globaldir ve SQL'in aktif ortamından BAĞIMSIZ
 * seçilir (EFAT-15, S5); uyuşmazlık yalnız uyarı olarak gösterilir.
 *
 * API adresi ekrandan tanımlanabilir; boşsa sağlayıcı + ortamın varsayılanı
 * (config/entegrator.php). Yalnız https ve izinli alan adı kabul edilir.
 *
 * @property int $id
 * @property string $saglayici
 * @property string $ortam
 * @property string|null $api_url
 * @property string $kullanici_adi
 * @property string $sifre
 * @property string $vkn
 * @property string|null $posta_kutusu
 * @property string|null $gonderici_birim
 * @property bool $aktif
 * @property int $kimlik_surumu
 * @property int $senkron_araligi_dakika Otomatik senkron aralığı (en az ENAZ_SENKRON_ARALIGI)
 * @property int $sayfa_boyutu Tek istekteki fatura sayısı (en çok ENCOK_SAYFA_BOYUTU)
 */
final class EntegratorBaglanti extends Model
{
    /** @use HasFactory<EntegratorBaglantiFactory> */
    use HasFactory;

    public const SAGLAYICI_IZIBIZ = 'izibiz';

    public const ORTAM_TEST = 'test';

    public const ORTAM_CANLI = 'canli';

    public const ORTAMLAR = [self::ORTAM_TEST, self::ORTAM_CANLI];

    /** İzibiz kuralı: zamanlayıcı ile çekimde aralık en az 15 dakika */
    public const ENAZ_SENKRON_ARALIGI = 15;

    /** Bir günden seyrek otomatik senkron anlamsız */
    public const ENCOK_SENKRON_ARALIGI = 1440;

    /** İzibiz kuralı: tek çağrıda en çok 100 fatura */
    public const ENCOK_SAYFA_BOYUTU = 100;

    public const ENAZ_SAYFA_BOYUTU = 10;

    protected $table = 'entegrator_baglantilari';

    /** @var array<string, mixed> */
    protected $attributes = [
        'aktif' => false,
        'kimlik_surumu' => 1,
        'senkron_araligi_dakika' => self::ENAZ_SENKRON_ARALIGI,
        'sayfa_boyutu' => self::ENCOK_SAYFA_BOYUTU,
    ];

    protected $fillable = [
        'saglayici',
        'ortam',
        'api_url',
        'kullanici_adi',
        'sifre',
        'vkn',
        'posta_kutusu',
        'gonderici_birim',
        'aktif',
        'senkron_araligi_dakika',
        'sayfa_boyutu',
    ];

    /** @var list<string> */
    protected $hidden = ['sifre'];

    /** Kullanılan adres: tanımlanmışsa o, değilse ortamın varsayılanı. */
    public function apiUrl(): string
    {
        return $this->api_url ?? $this->varsayilanApiUrl();
    }

    public function varsayilanApiUrl(): string
    {
        return (string) config("entegrator.{$this->saglayici}.ortamlar.{$this->ortam}.api_url");
    }

    /**
     * `https://alan-adı[:port]` biçimine indirger (küçük harf, sondaki `/`
     * atılır). Yol, sorgu, parça ya da kullanıcı bilgisi varsa geçersizdir (null).
     */
    public static function adresNormallestir(string $adres): ?string
    {
        $parca = parse_url(trim($adres));

        if (! is_array($parca)
            || strtolower($parca['scheme'] ?? '') !== 'https'
            || ($parca['host'] ?? '') === ''
            || isset($parca['user']) || isset($parca['pass'])
            || isset($parca['query']) || isset($parca['fragment'])
            || ! in_array($parca['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return 'https://'.strtolower($parca['host']).(isset($parca['port']) ? ':'.$parca['port'] : '');
    }

    public function portalUrl(): string
    {
        return (string) config("entegrator.{$this->saglayici}.ortamlar.{$this->ortam}.portal_url");
    }

    /**
     * Token önbellek anahtarı. Kimlik sürümü, kullanıcı adı/şifre değişince
     * eski token'ın kullanılmamasını sağlar; anahtarda sır bulunmaz.
     */
    public function tokenOnbellekAnahtari(): string
    {
        return sprintf('entegrator-token:%s:%s:%d:%d', $this->saglayici, $this->ortam, $this->id, $this->kimlik_surumu);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sifre' => 'encrypted',
            'aktif' => 'boolean',
            'kimlik_surumu' => 'integer',
            'senkron_araligi_dakika' => 'integer',
            'sayfa_boyutu' => 'integer',
        ];
    }
}
