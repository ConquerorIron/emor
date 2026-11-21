<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * `deploy.sh`'ın yayın koruması (BY-013-R1, BY-029-R1; README §5.2, §6.4).
 * Betik `.env` okumaz (G3): modül durumunu ve kilidin yolunu buradan öğrenir.
 *
 * - `kilit-yolu`: modül açıksa yayın kilidi dosyasının yolunu yazar (yoksa
 *   açar — geçiş kipinde; sunucuda kurulum betiği açar), kapalıysa hiçbir şey
 *   yazmaz. Kilidi `deploy.sh` kendisi alır ve betik bitene kadar tutar
 *   (`flock`); üstüne geri yükleme o sürece başlamaz.
 * - `denetle`: kilit alındıktan SONRA çağrılır. Açık bir üstüne geri yükleme
 *   varsa ya da işlem durumu okunamıyorsa çıkış 1: yayın bakım modu, kod
 *   çekme, migration ve `artisan up` olmadan durur. Modül kapalıysa 0.
 */
#[Signature('yedek:yayin-korumasi {adim : kilit-yolu | denetle}')]
#[Description('Yayın kilidinin yolunu verir ya da açık geri yüklemeyi denetler (deploy.sh)')]
final class YedekYayinKorumasi extends Command
{
    public function handle(YedeklemeYapilandirmasi $yapilandirma): int
    {
        return match ($this->argument('adim')) {
            'kilit-yolu' => $this->kilitYolu($yapilandirma),
            'denetle' => $this->denetle($yapilandirma),
            default => $this->gecersiz(),
        };
    }

    private function kilitYolu(YedeklemeYapilandirmasi $yapilandirma): int
    {
        if (! $yapilandirma->aktif()) {
            return self::SUCCESS;
        }

        try {
            $yol = $yapilandirma->yayinKilidiYolu();

            if (! is_file($yol)) {
                // Yalnız dosyanın açılması için; meşgulse dosya zaten var demektir
                $kilit = $yapilandirma->yayinKilidi();
                $kilit->al();
                $kilit->birak();
            }
        } catch (Throwable $hata) {
            $this->error('Yayın kilidi dosyası hazırlanamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        $this->line($yol);

        return self::SUCCESS;
    }

    private function denetle(YedeklemeYapilandirmasi $yapilandirma): int
    {
        if (! $yapilandirma->aktif()) {
            return self::SUCCESS;
        }

        try {
            if ($yapilandirma->islemDurumu()->acikGeriYuklemeVarMi()) {
                $this->error('Açık bir üstüne geri yükleme var (onay bekleniyor ya da sürüyor); yayın yapılmaz. Önce işlemi onaylayın ya da geri dönün (docs/yedekleme/isletim.md §5–6).');

                return self::FAILURE;
            }
        } catch (Throwable $hata) {
            $this->error('İşlem durumu okunamadı; yayın yapılmaz: '.$hata->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function gecersiz(): int
    {
        $this->error('Adım "kilit-yolu" ya da "denetle" olmalı.');

        return self::INVALID;
    }
}
