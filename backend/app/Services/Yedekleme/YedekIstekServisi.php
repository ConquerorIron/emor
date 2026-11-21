<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Illuminate\Support\Str;
use Throwable;

/**
 * Ekranın uzun işlemleri: istek klasörüne bırakılır, işi çalıştırıcı yapar
 * (README §5.2 "işlem web isteğinde çalışmaz"; BY-014). İşin sonucunu
 * çalıştırıcı ayrıca yazar.
 *
 * Kayıtsız istek olmaz (BY-014-R1): kimlik önce üretilir, denetim kaydı
 * (dosya günlüğü + veritabanı) yazılır, istek ANCAK ondan sonra yayınlanır.
 * Denetim kaydı yazılamazsa istek hiç yayınlanmaz ve hata istemciye döner;
 * yeniden deneme ikinci bir iş yaratmaz. Yayın başarısız olursa aynı istek
 * kimliğiyle `basarisiz` bir izleme kaydı eklenir (denetim kaydı salt
 * eklemelidir — K3 — önceki satır değiştirilmez) ve hata yine istemciye döner.
 */
final class YedekIstekServisi
{
    public function __construct(
        private readonly IstekKutusu $kutu,
        private readonly YedekDenetimi $denetim,
    ) {}

    /**
     * @param  array<string, mixed>  $parametreler
     * @return string Yoklanacak istek kimliği
     *
     * @throws Throwable Denetim kaydı ya da istek yazılamadı (istek yayınlanmadı)
     */
    public function birak(string $tur, array $parametreler, int $kullaniciId, ?int $yedekId = null): string
    {
        $kimlik = (string) Str::uuid();
        $ayrinti = ['tur' => $tur, 'istek' => $kimlik];

        $this->denetim->kaydet('istek', IslemSonucu::Basarili, $yedekId, $kullaniciId, null, $ayrinti);

        try {
            $this->kutu->yaz($tur, $parametreler, $kullaniciId, $kimlik);
        } catch (Throwable $hata) {
            try {
                $this->denetim->kaydet('istek', IslemSonucu::Basarisiz, $yedekId, $kullaniciId, null, [...$ayrinti, 'kod' => 'ISTEK_YAYINLANAMADI'], $hata::class);
            } catch (Throwable) {
                // En iyi çaba: asıl hata istemciye döner ve raporlanır
            }

            throw $hata;
        }

        return $kimlik;
    }
}
