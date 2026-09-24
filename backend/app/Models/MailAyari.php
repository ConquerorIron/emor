<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Giden mail (SMTP) tanımı — tek satır. Şifre encrypted cast ile saklanır ve
 * serileştirmede gizlidir (SqlBaglanti deseni).
 *
 * @property int $id
 * @property string $anahtar
 * @property string $sunucu
 * @property int $port
 * @property string $sifreleme
 * @property string|null $kullanici_adi
 * @property string|null $sifre
 * @property string $gonderen_adres
 * @property string $gonderen_ad
 * @property string|null $yonlendirme_adresi
 */
final class MailAyari extends Model
{
    public const ANAHTAR = 'varsayilan';

    public const SIFRELEME_TLS = 'tls';

    public const SIFRELEME_SSL = 'ssl';

    public const SIFRELEME_YOK = 'yok';

    public const SIFRELEMELER = [self::SIFRELEME_TLS, self::SIFRELEME_SSL, self::SIFRELEME_YOK];

    protected $table = 'mail_ayarlari';

    protected $fillable = [
        'anahtar',
        'sunucu',
        'port',
        'sifreleme',
        'kullanici_adi',
        'sifre',
        'gonderen_adres',
        'gonderen_ad',
        'yonlendirme_adresi',
    ];

    /** @var list<string> */
    protected $hidden = ['sifre'];

    /**
     * Kayıtlı şifre yalnız kayıtlı hedefe (sunucu + port + kullanıcı +
     * şifreleme) gönderilir; hedef değişiyorsa şifre yeniden girilmelidir
     * (EFAT-16 kuralı). Şifreleme dahil: STARTTLS'ten "yok"a geçiş kayıtlı
     * şifreyi açık metin AUTH ile göndermesin.
     */
    public function hedefFarkli(string $sunucu, int $port, ?string $kullaniciAdi, string $sifreleme): bool
    {
        return strcasecmp(trim($sunucu), trim($this->sunucu)) !== 0
            || $port !== $this->port
            || trim((string) $kullaniciAdi) !== trim((string) $this->kullanici_adi)
            || $sifreleme !== $this->sifreleme;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'sifre' => 'encrypted',
        ];
    }
}
