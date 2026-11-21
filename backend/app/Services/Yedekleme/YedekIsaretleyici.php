<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;

/**
 * Yedeğin sonradan değişen işaretleri: sabitleme ve not (README §5.3).
 * Doğruluk kaynağı olay günlüğüdür; önce oraya, sonra dizine yazılır —
 * dizin yeniden kurulsa da işaret kaybolmaz (BY-011). Her değişiklik
 * denetim kaydına.
 */
final class YedekIsaretleyici
{
    public function __construct(
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
    ) {}

    public function sabitle(Yedek $yedek, bool $sabit, ?int $kullaniciId): void
    {
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, $sabit ? OlayTuru::Sabitlendi : OlayTuru::SabitCozuldu, [], $kullaniciId));
        $yedek->update(['sabit' => $sabit]);
        $this->denetim->kaydet($sabit ? 'sabitle' : 'sabit_coz', IslemSonucu::Basarili, $yedek->id, $kullaniciId);
    }

    public function aciklamaYaz(Yedek $yedek, ?string $aciklama, ?int $kullaniciId): void
    {
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Aciklama, ['aciklama' => $aciklama], $kullaniciId));
        $yedek->update(['aciklama' => $aciklama]);
        $this->denetim->kaydet('aciklama', IslemSonucu::Basarili, $yedek->id, $kullaniciId);
    }
}
