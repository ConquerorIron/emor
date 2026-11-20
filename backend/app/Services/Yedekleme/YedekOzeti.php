<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use Carbon\CarbonImmutable;

/**
 * Ekranın özet şeridi ve uygulama içi sessizlik göstergesi (README §4.8;
 * BY-012, BY-015). Üç zaman AYRI gösterilir: son başarılı yerel yedeğin veri
 * zamanı, son doğrulanmış sunucu dışı kopyanın veri zamanı, son başarılı
 * sınama geri yüklemesinin zamanı. Asıl sessizlik alarmı uygulama dışındadır
 * (yaşam sinyali); bu yalnız ekrandaki göstergedir.
 */
final class YedekOzeti
{
    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @return array{son_yerel: string|null, son_uzak: string|null, son_sinama: string|null, sessizlik_saat: float|null, siradaki: string|null, toplam_adet: int, toplam_boyut: int, bos_alan: int|null, toplam_alan: int|null}
     */
    public function hesapla(): array
    {
        $sonYerel = Yedek::query()->where('durum', YedekDurumu::Tamam->value)->max('alinma_zamani');
        $sonUzak = YedekKopyasi::query()
            ->join('yedekler', 'yedekler.id', '=', 'yedek_kopyalari.yedek_id')
            ->where('yedek_kopyalari.durum', KopyaDurumu::Tamam->value)
            ->where('yedek_kopyalari.sifreli', true)
            ->max('yedekler.alinma_zamani');
        $sonSinama = Yedek::query()->where('dogrulama_durumu', DogrulamaDurumu::SinamaGecti->value)->max('updated_at');
        $siradaki = YedekPlani::query()->where('aktif', true)->min('sonraki_calisma');

        $klasor = $this->yapilandirma->klasor();
        $bos = is_dir($klasor) ? @disk_free_space($klasor) : false;
        $toplam = is_dir($klasor) ? @disk_total_space($klasor) : false;
        $iso = fn (mixed $z): ?string => $z === null ? null : CarbonImmutable::parse((string) $z)->toIso8601String();

        return [
            'son_yerel' => $iso($sonYerel),
            'son_uzak' => $iso($sonUzak),
            'son_sinama' => $iso($sonSinama),
            'sessizlik_saat' => $sonYerel === null ? null : round(CarbonImmutable::parse((string) $sonYerel)->diffInMinutes(CarbonImmutable::now()) / 60, 1),
            'siradaki' => $iso($siradaki),
            'toplam_adet' => Yedek::query()->where('durum', YedekDurumu::Tamam->value)->count(),
            'toplam_boyut' => (int) Yedek::query()->where('durum', YedekDurumu::Tamam->value)->whereNull('yerel_silinme_zamani')->sum('boyut'),
            'bos_alan' => $bos === false ? null : (int) $bos,
            'toplam_alan' => $toplam === false ? null : (int) $toplam,
        ];
    }
}
