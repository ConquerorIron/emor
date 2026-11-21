<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Illuminate\Support\Facades\Process;
use LogicException;
use Throwable;

/**
 * Üstüne geri yüklemede bekleyen migration'ları YENİ veritabanında çalıştırır
 * (README §6.4 5. adım, §7.4; BY-028).
 *
 * Migration AYRI bir süreçte çalışır ve o sürecin BÜTÜN veritabanı ayarı
 * yeni veritabanını gösterir. Yalnız bağlantı adını değiştirmek yetmez:
 * önbelleği, kuyruğu, oturumu ve varsayılan bağlantıyı kullanan kod canlıya
 * giderdi. Yöntem: bu sürecin yapılandırması bir yapılandırma önbelleği
 * dosyasına yazılır (APP_CONFIG_CACHE) — Laravel önbellek varken `.env`'i
 * okumaz, ortamdan da etkilenmez (G12) — ve dosyada:
 *
 * - canlı veritabanını gösteren HER pgsql bağlantısı yeni veritabanını gösterir
 *   (adresle — DB_URL — tanımlı bağlantı önce somut tanıma çevrilir),
 * - varsayılan bağlantı yedekleme bağlantısıdır,
 * - önbellek `array`, oturum `array`, kuyruk `sync`tir.
 *
 * Dosya parola ve APP_KEY taşır: yalnız bu sürecin okuyabildiği (0700)
 * geçici klasöre yazılır ve her durumda silinir (D2, T8).
 */
final class YeniVeritabanindaMigration
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly PgAraclari $pg,
    ) {}

    /**
     * @throws GeriYuklemeHatasi MIGRATION
     */
    public function calistir(string $hedef): void
    {
        $klasor = $this->geciciKlasor();

        try {
            $dosya = $this->yapilandirmaDosyasiYaz($hedef, $klasor);
            $sonuc = Process::timeout($this->yapilandirma->sureSiniri('geri_yukleme'))
                ->env(['APP_CONFIG_CACHE' => $dosya])
                ->run([PHP_BINARY, base_path('artisan'), 'migrate', '--force', '--no-interaction', '--database='.$this->yapilandirma->baglantiAdi()]);
        } catch (GeriYuklemeHatasi $hata) {
            throw $hata;
        } catch (Throwable $hata) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration süreci başlatılamadı: '.$hata::class);
        } finally {
            foreach (array_diff(@scandir($klasor) ?: [], ['.', '..']) as $ad) {
                @unlink($klasor.'/'.$ad);
            }
            @rmdir($klasor);
        }

        if (! $sonuc->successful()) {
            // Yalnız ilk satır: devamı SQL ve satır verisi taşıyabilir (D2)
            $satir = mb_substr(strtok(trim($sonuc->errorOutput() ?: $sonuc->output()), "\n") ?: '', 0, 200);

            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration yeni veritabanında başarısız (çıkış '.$sonuc->exitCode().'): '.$satir);
        }
    }

    /**
     * Çocuk sürecin yapılandırma önbelleği dosyasını yazar.
     *
     * @return string Dosyanın yolu
     *
     * @throws GeriYuklemeHatasi Yapılandırma yeni veritabanına güvenle çevrilemiyor
     */
    public function yapilandirmaDosyasiYaz(string $hedef, string $klasor): string
    {
        if (! VeritabaniAdi::kopyaMi($hedef)) {
            throw new LogicException('Migration yalnız geri yüklemenin açtığı veritabanında (erp_geri_*) çalışır.');
        }

        $canli = $this->pg->baglanti()->veritabani;
        $ayarlar = config()->all();
        $baglantiAdi = $this->yapilandirma->baglantiAdi();

        foreach ($ayarlar['database']['connections'] ?? [] as $ad => $tanim) {
            if (! is_array($tanim)) {
                continue;
            }

            // Adres (URL) ayrı alanların önüne geçer: içindeki veritabanı adı
            // değiştirilmeden çocuk süreç canlıya bağlanırdı. Tanım somut hâle
            // çevrilir (`url` kalmaz), veritabanı adı tek doğruluk kaynağı olur (BY-028-R4)
            $tanim = PgAraclari::somut($tanim);
            if (($tanim['driver'] ?? null) !== 'pgsql') {
                continue;
            }

            if (($tanim['database'] ?? null) === $canli) {
                $tanim['database'] = $hedef;
            }
            $ayarlar['database']['connections'][$ad] = $tanim;
        }

        if (($ayarlar['database']['connections'][$baglantiAdi]['database'] ?? null) !== $hedef) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Yedekleme bağlantısı yeni veritabanına çevrilemedi.');
        }

        $ayarlar['database']['default'] = $baglantiAdi;
        $ayarlar['cache']['default'] = 'array';
        $ayarlar['session']['driver'] = 'array';
        $ayarlar['queue']['default'] = 'sync';

        $yol = $klasor.'/config.php';
        $tutamac = @fopen($yol, 'xb');
        if ($tutamac === false) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration yapılandırma dosyası açılamadı.');
        }

        $icerik = '<?php return '.var_export($ayarlar, true).';'.PHP_EOL;
        $yazildi = chmod($yol, 0600) && fwrite($tutamac, $icerik) === strlen($icerik);
        fclose($tutamac);

        if (! $yazildi) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration yapılandırma dosyası yazılamadı.');
        }

        return $yol;
    }

    private function geciciKlasor(): string
    {
        $klasor = sys_get_temp_dir().'/emor-migration-'.bin2hex(random_bytes(6));

        if (! @mkdir($klasor, 0700)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration için geçici klasör açılamadı.');
        }

        return $klasor;
    }
}
