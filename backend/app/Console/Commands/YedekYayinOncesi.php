<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KalicilikDogrulanamadi;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * `deploy.sh`'ın yayın öncesi yedeği (BY-013; README §4.1, §5.2, §5.5).
 * Çıkış kodu 0 değilse yayın DURUR (migration çalışmaz).
 *
 * - Modül açık, servis çalıştırıcısı var: isteği istek klasörüne bırakır,
 *   sonucu bekler (yedek imzalı, kilitli, servis hesabıyla alınır).
 * - Modül açık, geçiş dönemi: kilidi alıp yedeği kendisi alır. Başka işlem
 *   kilidi tutuyorsa `kilit_bekleme` saniye bekler, sonra anlaşılır mesajla
 *   durur. Açık bir geri yükleme varsa başlamaz.
 * - Modül kapalı: eski davranış — `--eski-klasor`'e `<vt>_predeploy_<zaman>.dump`.
 *   Fark: bağlantı bilgisi `.env` elle ayrıştırılarak değil Laravel'in
 *   yapılandırmasından, parola PGPASSWORD ile değil parola dosyasından
 *   (BY-001-R1'deki hata; Dikkat_Edilecekler G2, G3, D2).
 */
#[Signature('yedek:yayin-oncesi {--eski-klasor=/var/backups/emor : Modül kapalıyken yedeğin yazılacağı klasör}')]
#[Description('Yayın öncesi veritabanı yedeğini alır (deploy.sh)')]
final class YedekYayinOncesi extends Command
{
    /** Servis çalıştırıcısının yedeği bitirmesi için en uzun bekleme (sn) */
    private const SERVIS_BEKLEME = 1800;

    public function handle(YedeklemeYapilandirmasi $yapilandirma, YedekAlici $alici, IstekKutusu $kutu, PgAraclari $pg): int
    {
        if (! $yapilandirma->aktif()) {
            return $this->eskiYedek($pg, $yapilandirma, (string) $this->option('eski-klasor'));
        }

        try {
            if ($yapilandirma->islemDurumu()->acikGeriYuklemeVarMi()) {
                $this->error('Açık bir geri yükleme işlemi var (onay bekleniyor ya da sürüyor); yayın yapılmaz.');

                return self::FAILURE;
            }
        } catch (Throwable $hata) {
            $this->error('İşlem durumu okunamadı; yayın yapılmaz: '.$hata->getMessage());

            return self::FAILURE;
        }

        return $yapilandirma->servisCalistiricisi()
            ? $this->servisle($kutu)
            : $this->dogrudan($yapilandirma, $alici);
    }

    private function servisle(IstekKutusu $kutu): int
    {
        $kimlik = (string) Str::uuid();

        try {
            try {
                $kutu->yaz('yedek_al', ['kaynak' => YedekKaynagi::YayinOncesi->value, 'aciklama' => null], null, $kimlik);
            } catch (KalicilikDogrulanamadi) {
                // İstek yayımlandı, yalnız klasör eşitlemesi doğrulanamadı: çalıştırıcı
                // onu işleyecek; durmak yayını bozar, yeniden istemek ikinci yedek alır (G1, BY-014-R2)
                $this->warn('Yayın öncesi yedek isteğinin diske eşitlendiği doğrulanamadı; istek görünür, sonucu bekleniyor.');
            }
            $this->line('Yayın öncesi yedek istendi; çalıştırıcı bekleniyor…');
            $sonuc = $kutu->bekle($kimlik, self::SERVIS_BEKLEME);
        } catch (Throwable $hata) {
            $this->error('Yayın öncesi yedek alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        if (($sonuc['durum'] ?? null) !== 'tamam') {
            $this->error('Yayın öncesi yedek alınamadı: '.($sonuc['mesaj'] ?? $sonuc['kod'] ?? 'bilinmeyen hata'));

            return self::FAILURE;
        }

        $this->info('Yayın öncesi yedek alındı (servis).');

        return self::SUCCESS;
    }

    private function dogrudan(YedeklemeYapilandirmasi $yapilandirma, YedekAlici $alici): int
    {
        $kilit = $yapilandirma->islemDurumu()->kilit();

        try {
            if (! $kilit->bekleyerekAl((float) $yapilandirma->sureSiniri('kilit_bekleme'))) {
                $this->error('Başka bir yedekleme / geri yükleme işlemi sürüyor; yayın yapılmadı. Biraz sonra tekrar deneyin.');

                return self::FAILURE;
            }

            $yedek = $alici->al($kilit, YedekKaynagi::YayinOncesi);
        } catch (YedekAlmaHatasi $hata) {
            $this->error($hata->getMessage());

            return self::FAILURE;
        } catch (Throwable $hata) {
            $this->error('Yayın öncesi yedek alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        if ($yedek->durum !== YedekDurumu::Tamam) {
            $this->error("Yayın öncesi yedek alınamadı ({$yedek->hata_kodu}): {$yedek->hata_mesaji}");

            return self::FAILURE;
        }

        $this->info("Yayın öncesi yedek alındı: {$yedek->dosya_adi}");

        return self::SUCCESS;
    }

    private function eskiYedek(PgAraclari $pg, YedeklemeYapilandirmasi $yapilandirma, string $klasor): int
    {
        if (! str_starts_with($klasor, '/')) {
            $this->error('--eski-klasor mutlak bir yol olmalı.');

            return self::INVALID;
        }

        if (! is_dir($klasor) && ! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            $this->error("Yedek klasörü açılamadı: {$klasor}");

            return self::FAILURE;
        }

        try {
            $baglanti = $pg->baglanti();
            $dosya = $klasor.'/'.$baglanti->veritabani.'_predeploy_'.CarbonImmutable::now()->format('Y-m-d_His').'.dump';
            $this->line("==> PostgreSQL yedeği: {$dosya}");

            $sonuc = $pg->parolaylaCalis(fn () => $pg->calistir(
                ['pg_dump', '--format=custom', '--file='.$dosya, ...$baglanti->argumanlar()],
                $yapilandirma->sureSiniri('yedek'),
            ));
        } catch (Throwable $hata) {
            $this->error('Yedek alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        if (! $sonuc->successful()) {
            @unlink($dosya);
            $this->error('pg_dump başarısız: '.Str::limit(trim($sonuc->errorOutput()), 400));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
