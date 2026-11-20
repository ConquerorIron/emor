<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Hedef türü → bağlayıcı sınıfı. Bağlayıcısı olmayan tür seçilemez; ekrandaki
 * tür listesi buradan gelir (BY-016). Yeni tür = yeni sınıf + bir satır.
 *
 * Her bağlayıcı sınıfı `static tanim(): HedefTuruTanimi` sağlar ve yapıcısında
 * `YedekHedefi` alır (gerisi kapsayıcıdan çözülür).
 */
final class HedefKatalogu
{
    /** @var array<string, class-string<HedefBaglayicisi>> */
    private array $siniflar = [
        'yerel' => YerelDiskBaglayicisi::class,
        'ftp' => FtpBaglayicisi::class,
        'ftps' => FtpBaglayicisi::class,
        'sftp' => SftpBaglayicisi::class,
        'google_drive' => GoogleDriveBaglayicisi::class,
        'nas' => NasBaglayicisi::class,
        's3' => S3Baglayicisi::class,
    ];

    public function __construct(private readonly Container $kapsayici) {}

    /**
     * Testler ve sonradan eklenen türler için.
     *
     * @param  class-string<HedefBaglayicisi>  $sinif
     */
    public function kaydet(HedefTuru $tur, string $sinif): void
    {
        $this->siniflar[$tur->value] = $sinif;
    }

    public function destekleniyorMu(HedefTuru $tur): bool
    {
        return isset($this->siniflar[$tur->value]) && class_exists($this->siniflar[$tur->value]);
    }

    public function olustur(YedekHedefi $hedef): HedefBaglayicisi
    {
        if (! $this->destekleniyorMu($hedef->tur)) {
            throw new InvalidArgumentException("Bu hedef türünün bağlayıcısı yok: {$hedef->tur->value}");
        }

        return $this->kapsayici->make($this->siniflar[$hedef->tur->value], ['hedef' => $hedef]);
    }

    public function tanim(HedefTuru $tur): HedefTuruTanimi
    {
        if (! $this->destekleniyorMu($tur)) {
            throw new InvalidArgumentException("Bu hedef türünün bağlayıcısı yok: {$tur->value}");
        }

        $sinif = $this->siniflar[$tur->value];

        return $sinif::tanim($tur);
    }

    /**
     * @return list<HedefTuruTanimi>
     */
    public function tanimlar(): array
    {
        return array_values(array_map(
            fn (HedefTuru $tur): HedefTuruTanimi => $this->tanim($tur),
            array_filter(HedefTuru::cases(), $this->destekleniyorMu(...)),
        ));
    }

    /** Sunucu dışı hedef (yerel disk dışındaki her tür): kopya şifreli gider (README §4.7). */
    public function sunucuDisiMi(HedefTuru $tur): bool
    {
        return $this->tanim($tur)->sunucuDisi;
    }
}
