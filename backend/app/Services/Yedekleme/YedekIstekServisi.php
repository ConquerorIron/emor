<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Ekranın uzun işlemleri: istek klasörüne bırakılır, işi çalıştırıcı yapar
 * (README §5.2 "işlem web isteğinde çalışmaz"; BY-014). İsteğin bırakılması
 * denetim kaydına ve yedekleme günlüğüne yazılır; işin sonucunu çalıştırıcı
 * ayrıca yazar.
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
     */
    public function birak(string $tur, array $parametreler, int $kullaniciId, ?int $yedekId = null): string
    {
        $kimlik = $this->kutu->yaz($tur, $parametreler, $kullaniciId);
        $this->denetim->kaydet('istek', IslemSonucu::Basarili, $yedekId, $kullaniciId, null, ['tur' => $tur, 'istek' => $kimlik]);

        return $kimlik;
    }
}
