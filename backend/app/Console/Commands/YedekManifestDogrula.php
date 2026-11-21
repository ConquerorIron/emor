<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\ManifestOkumaSonucu;
use App\Services\Yedekleme\YedekManifesti;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;

/**
 * Elle geri yüklemeden ÖNCE yedek dosyasını imzalı manifestine karşı
 * doğrular (kurtarma.md §4.8; BY-024-R2): manifestin imzası (imza anahtarıyla),
 * manifestin bu dosyaya ait olduğu, boyut ve SHA-256. Tarama ve ekrandaki
 * geri yüklemenin kullandığı kodla aynı (ManifestDeposu). Seçeneksiz
 * veritabanına bağlanmaz, hiçbir dosyayı değiştirmez. Başarıda `createdb`
 * için gereken veritabanı özelliklerini yazar; başarısızlıkta geri yükleme
 * yapılmaz.
 *
 * Bağlanma yetkileri (BY-031-R2): `pg_restore --no-owner` veritabanının
 * kendi ACL'sini (CONNECT / TEMP) getirmez. İmza ve özet doğrulandıktan
 * SONRA, imzalı manifestteki `baglanma_yetkileri`:
 * - `--yetkileri-uygula=<vt>` ile ekrandaki geri yüklemenin kullandığı
 *   GeriYuklemeVeritabani::yetkileriUygula() ile uygulanır (tek işlem;
 *   eşdeğerlik sağlanamazsa yetkiler hiç değişmez, hata);
 * - `--yetkileri-denetle=<vt>` ile değiştirilmeden karşılaştırılır
 *   (açılıştan önceki denetim; GeriYuklemeVeritabani::yetkilerEsdegerMi()).
 * Doğrulama başarısızsa veritabanına hiç bağlanılmaz.
 */
#[Signature('yedek:manifest-dogrula
    {dump : Çözülmüş yedek dosyası (erp_….dump)}
    {--manifest= : Manifest dosyası (boşsa dump ile aynı klasörde aynı adlı .json)}
    {--yetkileri-uygula= : Doğrulamadan sonra manifestteki bağlanma yetkilerini bu veritabanına uygula}
    {--yetkileri-denetle= : Doğrulamadan sonra bu veritabanının bağlanma yetkileri manifestle eşdeğer mi (değiştirmez)}')]
#[Description('Yedek dosyasını imzalı manifestine karşı doğrular (imza, boyut, SHA-256); istenirse bağlanma yetkilerini uygular / denetler')]
final class YedekManifestDogrula extends Command
{
    public function handle(ManifestDeposu $depo, GeriYuklemeVeritabani $veritabani): int
    {
        $uygula = $this->veritabaniSecenegi('yetkileri-uygula');
        $denetle = $this->veritabaniSecenegi('yetkileri-denetle');

        if ($uygula !== null && $denetle !== null) {
            $this->error('--yetkileri-uygula ve --yetkileri-denetle birlikte verilmez.');

            return self::FAILURE;
        }

        $dump = (string) $this->argument('dump');
        $secenek = $this->option('manifest');
        $manifestYolu = is_string($secenek) && $secenek !== '' ? $secenek : ManifestDeposu::jsonYolu($dump);

        $sonuc = $depo->oku($manifestYolu);
        $manifest = $sonuc->manifest;

        if ($manifest === null) {
            $this->error('GERI_YUKLEME_IMZA_GECERSIZ: '.self::neden((string) $sonuc->neden).' Geri yüklemeyin.');

            return self::FAILURE;
        }

        if ($manifest->dosyaAdi !== basename($dump)) {
            $this->error("GERI_YUKLEME_IMZA_GECERSIZ: manifest başka bir yedeğin ({$manifest->dosyaAdi}). Geri yüklemeyin.");

            return self::FAILURE;
        }

        if (! ManifestDeposu::dosyaTutuyorMu($manifest, $dump)) {
            $this->error('GERI_YUKLEME_OZET_TUTMUYOR: yedek dosyasının boyutu ya da SHA-256 özeti manifestle aynı değil (bozuk / eksik indirme ya da başka dosya). Geri yüklemeyin.');

            return self::FAILURE;
        }

        $ozellik = $manifest->veritabaniOzellikleri;
        $this->info('DOGRULANDI: manifest imzası geçerli; dosyanın boyutu ve SHA-256 özeti manifestle aynı.');
        $this->line("Yedek: {$manifest->dosyaAdi} · kimlik {$manifest->kimlik} · alınma {$manifest->alinmaZamani->toIso8601String()} · veritabanı {$manifest->veritabani}");
        $this->line('PostgreSQL: '.($manifest->pgSunucuSurumu ?? 'manifestte yok').' · sahip rol: '.($manifest->sahipRol ?? 'manifestte yok').' · APP_KEY parmak izi: '.($manifest->appKeyParmakIzi ?? 'manifestte yok'));
        $this->line($ozellik === null
            ? 'Veritabanı özellikleri manifestte yok: kodlama / sıralama canlıdaki değerlerle verilir (kurtarma.md §3).'
            : "createdb için: --encoding={$ozellik['kodlama']} --lc-collate={$ozellik['siralama']} --lc-ctype={$ozellik['karakter_turu']}"
                .($ozellik['ayarlar'] === [] ? '' : ' · veritabanı ayarları: '.implode(', ', $ozellik['ayarlar'])));
        if ($ozellik !== null) {
            $this->line('Bağlanma yetkileri (datacl): '.($ozellik['baglanma_yetkileri'] ?? 'varsayılan (sahibe her şey, PUBLIC\'e CONNECT + TEMP)'));
        }

        if ($uygula === null && $denetle === null) {
            return self::SUCCESS;
        }

        return $this->yetkiler($veritabani, $manifest, $uygula ?? $denetle, $uygula !== null);
    }

    /**
     * Manifestteki bağlanma yetkilerini uygular ya da (değiştirmeden) denetler.
     */
    private function yetkiler(GeriYuklemeVeritabani $veritabani, YedekManifesti $manifest, string $ad, bool $uygula): int
    {
        $ozellik = $manifest->veritabaniOzellikleri;
        if ($ozellik === null) {
            $this->error('GERI_YUKLEME_VERITABANI_YETKILERI: manifestte veritabanı özellikleri yok (içe alınmış eski yedek); bağlanma yetkileri bilinmiyor, elle karar verin.');

            return self::FAILURE;
        }

        $acl = $ozellik['baglanma_yetkileri'];

        try {
            if ($uygula) {
                $veritabani->parolaylaCalis(fn () => $veritabani->yetkileriUygula($ad, $acl));
                $this->info("YETKILER_UYGULANDI: {$ad} veritabanının bağlanma yetkileri manifestle eşdeğer.");

                return self::SUCCESS;
            }

            if (! $veritabani->parolaylaCalis(fn (): bool => $veritabani->yetkilerEsdegerMi($ad, $acl))) {
                $this->error("GERI_YUKLEME_VERITABANI_YETKILERI: {$ad} veritabanının bağlanma yetkileri manifestle eşdeğer DEĞİL. Uygulamayı açmayın; --yetkileri-uygula ile yeniden uygulayın.");

                return self::FAILURE;
            }
        } catch (GeriYuklemeHatasi|LogicException $hata) {
            $this->error('GERI_YUKLEME_VERITABANI_YETKILERI: '.$hata->getMessage());

            return self::FAILURE;
        }

        $this->info("YETKILER_ESDEGER: {$ad} veritabanının bağlanma yetkileri manifestle eşdeğer.");

        return self::SUCCESS;
    }

    private function veritabaniSecenegi(string $ad): ?string
    {
        $deger = $this->option($ad);

        return is_string($deger) && $deger !== '' ? $deger : null;
    }

    private static function neden(string $neden): string
    {
        return match ($neden) {
            ManifestOkumaSonucu::NEDEN_YOK => 'manifest dosyası yok.',
            ManifestOkumaSonucu::NEDEN_OKUNAMADI => 'manifest dosyası okunamadı.',
            ManifestOkumaSonucu::NEDEN_JSON, ManifestOkumaSonucu::NEDEN_SEMA => 'manifest bozuk.',
            ManifestOkumaSonucu::NEDEN_IMZA_YOK => 'manifest imzasız.',
            ManifestOkumaSonucu::NEDEN_IMZA_TUTMUYOR => 'manifest imzası tutmuyor (manifest değiştirilmiş ya da imza anahtarı yanlış).',
            ManifestOkumaSonucu::NEDEN_ANAHTAR_YOK => 'imza anahtarı yok (YEDEKLEME_IMZA_ANAHTARI).',
            default => "manifest güvenilmeyen ({$neden}).",
        };
    }
}
