<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use Illuminate\Contracts\Container\Container;

/**
 * İstek türü → işleyici sınıfı (BY-025). Yeni tür = yeni sınıf + bir satır.
 * Şeması olup işleyicisi olmayan tür çalıştırıcıda `ISTEK_ISLEYICISI_YOK`
 * ile reddedilir.
 */
final class IsleyiciKatalogu
{
    /** @var array<string, class-string<IstekIsleyicisi>> */
    private const ISLEYICILER = [
        'yedek_al' => YedekAlIsleyicisi::class,
        'dogrula' => DogrulaIsleyicisi::class,
        'sabitle' => SabitleIsleyicisi::class,
        'sabit_coz' => SabitCozIsleyicisi::class,
        'aciklama' => AciklamaIsleyicisi::class,
        'sil' => SilIsleyicisi::class,
        'tara' => TaraIsleyicisi::class,
        'plan_calistir' => PlanCalistirIsleyicisi::class,
        'kopya_geri_yukle' => KopyaGeriYukleIsleyicisi::class,
        'kopya_sil' => KopyaSilIsleyicisi::class,
        'ustune_geri_yukle' => UstuneGeriYukleIsleyicisi::class,
        'onayla' => OnaylaIsleyicisi::class,
        'geri_don' => GeriDonIsleyicisi::class,
    ];

    public function __construct(private readonly Container $kapsayici) {}

    public function bul(string $tur): ?IstekIsleyicisi
    {
        $sinif = self::ISLEYICILER[$tur] ?? null;

        return $sinif === null ? null : $this->kapsayici->make($sinif);
    }
}
