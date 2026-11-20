<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekDenetimi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Yedek klasörünü tarayıp dizini kurar / günceller (BY-011). Geri
 * yüklemeden sonra ve ekrandaki "Klasörü Tara" ile çalışır. Dosyalara
 * dokunmaz; yalnız veritabanındaki dizini günceller.
 */
#[Signature('yedek:tara {--kullanici= : İsteyen kullanıcının kimliği}')]
#[Description('Yedek klasöründeki manifestlerden yedek listesini kurar')]
final class YedekTara extends Command
{
    public function handle(DizinKurucu $kurucu, YedekDenetimi $denetim): int
    {
        $kullanici = $this->option('kullanici');
        $kullaniciId = is_string($kullanici) && preg_match('/^[1-9][0-9]*$/D', $kullanici) === 1 ? (int) $kullanici : null;

        try {
            $sonuc = $kurucu->tara();
        } catch (Throwable $hata) {
            $denetim->kaydet('klasor_tara', IslemSonucu::Basarisiz, kullaniciId: $kullaniciId, hata: $hata->getMessage());
            $this->error($hata->getMessage());

            return self::FAILURE;
        }

        $denetim->kaydet('klasor_tara', IslemSonucu::Basarili, kullaniciId: $kullaniciId, ayrinti: $sonuc);
        $this->line(sprintf(
            'Tarama: %d imzalı, %d güvenilmeyen (%d yeni), %d dosyası yok, %d bozuk manifest, %d geçersiz olay satırı, %d bulunamayan hedef.',
            $sonuc['guvenilir'], $sonuc['guvenilmeyen'], $sonuc['eklenen'], $sonuc['dosyasi_yok'], $sonuc['bozuk'], $sonuc['gecersiz_olay'], $sonuc['bulunamayan_hedef'],
        ));

        return self::SUCCESS;
    }
}
