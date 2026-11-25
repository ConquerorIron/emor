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

    /**
     * İşlenmemiş fatura hatırlatması (EK-10): N günden eski, hâlâ "İşlenecek"
     * sınıfındaki gelen faturaların portal bazında özeti; her M günde bir.
     * Sınıf tanımı panoyla aynıdır (EFatura::islenecek).
     */
    public const ISLENMEMIS_HATIRLATMA = 'islenmemis_hatirlatma';

    public const TURLER = [self::SENKRON_ARIZASI, self::GUNLUK_OZET, self::ERP_OKUMADI, self::ISLENMEMIS_HATIRLATMA];

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
            // Belge tarihi N günden eski işlenecek faturalar, her `aralik_gun` günde bir;
            // portallar virgüllü (EntegratorBaglanti::GELEN_SAGLAYICILARI)
            self::ISLENMEMIS_HATIRLATMA => ['gun' => 3, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => implode(',', EntegratorBaglanti::GELEN_SAGLAYICILARI)],
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
