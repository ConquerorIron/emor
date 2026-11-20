<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Elle yedek (BY-007). Yönetici komutudur: modül anahtarı kapalıyken de
 * çalışır (zamanlayıcı ve ekran kapalıdır). Kilit başka işlemdeyse en çok
 * `kilit_bekleme` saniye bekler, sonra anlaşılır mesajla çıkar (çıkış 75);
 * ikinci yedek almaz.
 */
#[Signature('yedek:al
    {--kaynak=elle : elle | yayin_oncesi}
    {--aciklama= : Yedeğin notu}
    {--kullanici= : İsteyen kullanıcının kimliği (çalıştırıcı verir)}')]
#[Description('Veritabanının imzalı, doğrulanmış yedeğini alır')]
final class YedekAl extends Command
{
    public const KILIT_MESGUL = 75;

    public function handle(YedekAlici $alici, YedeklemeYapilandirmasi $yapilandirma, SaklamaPolitikasi $saklama): int
    {
        $kaynak = YedekKaynagi::tryFrom((string) $this->option('kaynak'));
        if (! in_array($kaynak, [YedekKaynagi::Elle, YedekKaynagi::YayinOncesi], true)) {
            $this->error('--kaynak yalnız elle ya da yayin_oncesi olabilir.');

            return self::INVALID;
        }

        $kullanici = $this->option('kullanici');
        if ($kullanici !== null && preg_match('/^[1-9][0-9]*$/D', (string) $kullanici) !== 1) {
            $this->error('--kullanici pozitif bir tam sayı olmalı.');

            return self::INVALID;
        }

        try {
            $kilit = $yapilandirma->islemDurumu()->kilit();
            if (! $kilit->bekleyerekAl((float) $yapilandirma->sureSiniri('kilit_bekleme'))) {
                $this->error('Başka bir yedekleme / geri yükleme / yayın işlemi sürüyor; yedek alınmadı.');

                return self::KILIT_MESGUL;
            }
        } catch (Throwable $hata) {
            $this->error('Yedekleme kilidi alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        try {
            $aciklama = $this->option('aciklama');
            $yedek = $alici->al($kilit, $kaynak, is_string($aciklama) && $aciklama !== '' ? mb_substr($aciklama, 0, 500) : null, $kullanici === null ? null : (int) $kullanici);

            // Her yedekten sonra temizlik (README §4.3) — modül açıksa; hatası yedeği bozmaz
            if ($yedek->durum === YedekDurumu::Tamam && $yapilandirma->aktif()) {
                try {
                    $saklama->uygula($kullanici === null ? null : (int) $kullanici);
                } catch (Throwable $hata) {
                    $this->warn('Temizlik çalışmadı: '.$hata->getMessage());
                }
            }
        } catch (YedekAlmaHatasi $hata) {
            $this->error($hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        if ($yedek->durum !== YedekDurumu::Tamam) {
            $this->error("Yedek alınamadı ({$yedek->hata_kodu}): {$yedek->hata_mesaji}");

            return self::FAILURE;
        }

        $this->info("Yedek alındı: {$yedek->dosya_adi} ({$yedek->boyut} bayt, doğrulandı, imzalı).");

        return self::SUCCESS;
    }
}
