<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Models\YedekPlani;
use App\Services\Yedekleme\PlanliYedek;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/**
 * Planı şimdi çalıştır (`plan_calistir`; README §4.2): planın yedeği alınır,
 * hedeflerine kopyalanır, saklama uygulanır (PlanliYedek). Sıradaki çalışma
 * şimdiden yeniden hesaplanır.
 */
final class PlanCalistirIsleyicisi implements IstekIsleyicisi
{
    public function __construct(private readonly PlanliYedek $planli) {}

    public function izin(): ?Izin
    {
        return Izin::YedekPlanlariGuncelle;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $planlar = YedekPlani::query()->whereKey($istek->parametreler['plan_id'])->get();

        if ($planlar->isEmpty()) {
            throw new IstekReddi('YEDEK_PLANI_BULUNAMADI', 'Plan bulunamadı.');
        }

        $yedek = $this->planli->calistir($planlar, $kilit, $istek->kullaniciId);
        $ek = ['yedek_kimligi' => $yedek->kimlik];

        return $yedek->durum === YedekDurumu::Tamam
            ? IstekSonucu::tamam($ek)
            : IstekSonucu::basarisiz((string) ($yedek->hata_kodu ?? 'YEDEK_BEKLENMEYEN_HATA'), $yedek->hata_mesaji, $ek);
    }
}
