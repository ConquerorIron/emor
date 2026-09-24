<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * e-Fatura alarm kuralı (EFAT-13). Tür başına tek satır; ekrandan açılıp
 * kapatılır, alıcı ve eşikleri düzenlenir. Varsayılan kapalıdır.
 *
 * @property int $id
 * @property string $tur
 * @property bool $aktif
 * @property list<string> $alicilar
 * @property array<string, int|string> $parametreler
 */
final class AlarmKurali extends Model
{
    public const SENKRON_ARIZASI = 'senkron_arizasi';

    public const GUNLUK_OZET = 'gunluk_ozet';

    public const ERP_OKUMADI = 'erp_okumadi';

    public const TURLER = [self::SENKRON_ARIZASI, self::GUNLUK_OZET, self::ERP_OKUMADI];

    protected $table = 'alarm_kurallari';

    /** @var list<string> */
    protected $fillable = ['tur', 'aktif', 'alicilar', 'parametreler'];

    /**
     * @return array<string, int|string>
     */
    public static function varsayilanParametreler(string $tur): array
    {
        return match ($tur) {
            // Art arda N çalışma başarısız/eksik ya da veri N saattir güncellenmedi
            self::SENKRON_ARIZASI => ['ardisik_hata' => 3, 'gecikme_saat' => 2],
            // Her sabah önceki günün yeni faturaları (İstanbul saati)
            self::GUNLUK_OZET => ['saat' => '08:00'],
            // Gelen fatura İzibiz'e ulaşalı N gün oldu, ERP hâlâ okumadı
            self::ERP_OKUMADI => ['gun' => 2, 'saat' => '09:00'],
            default => [],
        };
    }

    public function parametre(string $ad): int|string
    {
        return $this->parametreler[$ad] ?? self::varsayilanParametreler($this->tur)[$ad];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'alicilar' => 'array',
            'parametreler' => 'array',
        ];
    }
}
