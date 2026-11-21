<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Yedek klasörünü tarayıp dizini kurar / günceller (BY-011). Geri
 * yüklemeden sonra ve ekrandaki "Klasörü Tara" ile çalışır. Yedekleme
 * kilidini alır (en çok `kilit_bekleme` sn bekler, sonra çıkış 75): yürüyen
 * bir yedeğin yarım hâli taranmaz, ölen süreçten kalan `calisiyor` kayıt
 * sonuçlanır (BY-007-R1). Yedek dosyalarına dokunmaz; yalnız sahipsiz
 * çalışmanın geçici dosyasını ve dosyasız manifestini siler.
 */
#[Signature('yedek:tara {--kullanici= : İsteyen kullanıcının kimliği}')]
#[Description('Yedek klasöründeki manifestlerden yedek listesini kurar')]
final class YedekTara extends Command
{
    public function handle(DizinKurucu $kurucu, YedekDenetimi $denetim, YedeklemeYapilandirmasi $yapilandirma): int
    {
        $kullanici = $this->option('kullanici');
        $kullaniciId = is_string($kullanici) && preg_match('/^[1-9][0-9]*$/D', $kullanici) === 1 ? (int) $kullanici : null;

        try {
            $kilit = $yapilandirma->islemDurumu()->kilit();
            if (! $kilit->bekleyerekAl((float) $yapilandirma->sureSiniri('kilit_bekleme'))) {
                $this->error('Başka bir yedekleme / geri yükleme / yayın işlemi sürüyor; tarama yapılmadı.');

                return YedekAl::KILIT_MESGUL;
            }
        } catch (Throwable $hata) {
            $this->error('Yedekleme kilidi alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        try {
            $sonuc = $kurucu->tara($kilit);
        } catch (Throwable $hata) {
            $denetim->kaydet('klasor_tara', IslemSonucu::Basarisiz, kullaniciId: $kullaniciId, hata: $hata->getMessage());
            $this->error($hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        $denetim->kaydet('klasor_tara', IslemSonucu::Basarili, kullaniciId: $kullaniciId, ayrinti: $sonuc);
        $this->line(sprintf(
            'Tarama: %d imzalı, %d güvenilmeyen (%d yeni), %d dosyası yok, %d bozuk manifest, %d geçersiz olay satırı, %d bulunamayan hedef.',
            $sonuc['guvenilir'], $sonuc['guvenilmeyen'], $sonuc['eklenen'], $sonuc['dosyasi_yok'], $sonuc['bozuk'], $sonuc['gecersiz_olay'], $sonuc['bulunamayan_hedef'],
        ));

        return self::SUCCESS;
    }
}
