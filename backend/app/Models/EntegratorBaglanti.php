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
 * API adresi saklanmaz; sağlayıcı + ortamdan türetilir (config/entegrator.php).
 *
 * @property int $id
 * @property string $saglayici
 * @property string $ortam
 * @property string $kullanici_adi
 * @property string $sifre
 * @property string $vkn
 * @property string|null $posta_kutusu
 * @property string|null $gonderici_birim
 * @property bool $aktif
 * @property int $kimlik_surumu
 */
final class EntegratorBaglanti extends Model
{
    /** @use HasFactory<EntegratorBaglantiFactory> */
    use HasFactory;

    public const SAGLAYICI_IZIBIZ = 'izibiz';

    public const ORTAM_TEST = 'test';

    public const ORTAM_CANLI = 'canli';

    public const ORTAMLAR = [self::ORTAM_TEST, self::ORTAM_CANLI];

    protected $table = 'entegrator_baglantilari';

    /** @var array<string, mixed> */
    protected $attributes = [
        'aktif' => false,
        'kimlik_surumu' => 1,
    ];

    protected $fillable = [
        'saglayici',
        'ortam',
        'kullanici_adi',
        'sifre',
        'vkn',
        'posta_kutusu',
        'gonderici_birim',
        'aktif',
    ];

    /** @var list<string> */
    protected $hidden = ['sifre'];

    public function apiUrl(): string
    {
        return (string) config("entegrator.{$this->saglayici}.ortamlar.{$this->ortam}.api_url");
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
        ];
    }
}
