<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Yedek;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\DepoIsaretiYok;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\OlayTuru;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekDeposu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Services\Yedekleme\YedekManifesti;
use App\Services\Yedekleme\YedekOlayi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * `deploy.sh`'ın bugüne kadar aldığı imzasız yedekleri bir kereye mahsus
 * içeri alır (BY-013; README §4.5 "159 imzasız yedek"). Yönetici komutudur:
 * çalıştıran, dosyaların bu sistemin kendi yedekleri olduğunu onaylar.
 *
 * Her dosya `pg_restore --list` ve `--file=/dev/null` ile okunur; okunamayan
 * atlanır. Okunan dosya yedek klasörüne kopyalanır ve "içe alındı" işaretli
 * imzalı manifest yazılır. Anlık görüntüye bağlı bilgiler (sayımlar,
 * migration listesi, veritabanı özellikleri) bilinmez: bu yedekler farklı
 * ada geri yüklenebilir, şema uyumu denetlenemediği için üstüne geri
 * yüklenemez. Saklama kuralı yayın öncesi yedeklerinkidir.
 * İki kez çalıştırmak yedeği çoğaltmaz (kimlik dosya adı + özetten).
 *
 * Yedekleme kilidi altında çalışır (meşgulse çıkış 75). Yedek klasöründe aynı
 * adla FARKLI bir yedek (dosya, manifest ya da kayıt) varsa arşiv içe
 * alınmaz, hiçbir dosyaya dokunulmaz, sonda başarısız çıkılır (D4; BY-013-R2).
 * Yedek klasörü depo işaretiyle doğrulanamazsa (bağlanmamış disk; YedekDeposu,
 * BY-011-R3) hiçbir şey yazılmaz.
 */
#[Signature('yedek:eski-yedekleri-al {--klasor=/var/backups/emor : deploy.sh yedeklerinin klasörü}')]
#[Description('deploy.sh\'ın eski (imzasız) yayın öncesi yedeklerini içeri alır')]
final class YedekEskiYedekleriAl extends Command
{
    public function handle(YedeklemeYapilandirmasi $yapilandirma, PgAraclari $pg, ManifestDeposu $manifestler, OlayGunlugu $olaylar, YedekDenetimi $denetim, YedekDeposu $depo): int
    {
        $kaynakKlasor = rtrim((string) $this->option('klasor'), '/');

        // "Yok" ile "erişemiyorum" ayrı (D6; YF-67): glob okunamayan klasörde de
        // boş döner, komut "dosya yok" deyip başarıyla biterdi. Servis hesabının
        // klasörü okuyabilmesi kurulumun işidir (sunucu-kurulumu.md §3).
        if (! is_dir($kaynakKlasor) || ! is_readable($kaynakKlasor) || @scandir($kaynakKlasor) === false) {
            $this->error("Kaynak klasör okunamıyor (yok ya da bu kullanıcının okuma izni yok): {$kaynakKlasor}");

            return self::FAILURE;
        }

        $dosyalar = glob($kaynakKlasor.'/*_predeploy_*.dump') ?: [];

        if ($dosyalar === []) {
            $this->line("İçeri alınacak dosya yok: {$kaynakKlasor}");

            return self::SUCCESS;
        }

        $hedefKlasor = $yapilandirma->klasor();
        if (! is_dir($hedefKlasor) && ! @mkdir($hedefKlasor, 0750, true) && ! is_dir($hedefKlasor)) {
            $this->error("Yedek klasörü açılamadı: {$hedefKlasor}");

            return self::FAILURE;
        }

        try {
            $kilit = $yapilandirma->islemDurumu()->kilit();
            if (! $kilit->bekleyerekAl((float) $yapilandirma->sureSiniri('kilit_bekleme'))) {
                $this->error('Başka bir yedekleme / geri yükleme / yayın işlemi sürüyor; içe alma yapılmadı.');

                return YedekAl::KILIT_MESGUL;
            }
        } catch (Throwable $hata) {
            $this->error('Yedekleme kilidi alınamadı: '.$hata->getMessage());

            return self::FAILURE;
        }

        $sayac = ['alinan' => 0, 'var' => 0, 'okunamayan' => 0, 'cakisan' => 0];

        try {
            // Bağlanmamış diskin boş bağlama noktasına (kök diske) yazılmaz (BY-011-R3)
            $depo->hazirla();
            $this->iceAl($dosyalar, $yapilandirma, $pg, $hedefKlasor, $manifestler, $olaylar, $sayac);
        } catch (DepoIsaretiYok $hata) {
            $this->error($hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        $denetim->kaydet('eski_yedekleri_al', $sayac['cakisan'] === 0 ? IslemSonucu::Basarili : IslemSonucu::Basarisiz, ayrinti: $sayac + ['klasor' => $kaynakKlasor]);
        $this->info(sprintf('İçeri alınan %d, zaten olan %d, okunamayan %d, çakışan %d.', $sayac['alinan'], $sayac['var'], $sayac['okunamayan'], $sayac['cakisan']));

        if ($sayac['cakisan'] > 0) {
            $this->error('Yedek klasöründe aynı adlı FARKLI içerikli dosya olan arşivler içe alınmadı; iki dosyayı elle karşılaştırın (hiçbirine dokunulmadı).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $dosyalar
     * @param  array{alinan: int, var: int, okunamayan: int, cakisan: int}  $sayac
     */
    private function iceAl(array $dosyalar, YedeklemeYapilandirmasi $yapilandirma, PgAraclari $pg, string $hedefKlasor, ManifestDeposu $manifestler, OlayGunlugu $olaylar, array &$sayac): void
    {
        $sure = $yapilandirma->sureSiniri('yedek');

        $pg->parolaylaCalis(function () use ($dosyalar, $pg, $sure, $hedefKlasor, $manifestler, $olaylar, &$sayac): void {
            foreach ($dosyalar as $kaynak) {
                $ad = basename($kaynak);

                try {
                    $ozet = (string) hash_file('sha256', $kaynak);
                    $kimlik = $this->kimlik($ad, $ozet);

                    if (Yedek::query()->where('kimlik', $kimlik)->exists()) {
                        $sayac['var']++;

                        continue;
                    }

                    // Hedef ad ve manifest yazılmadan ÖNCE (kilit altında): aynı adda
                    // yedek varsa üstüne yazılmaz (D4; BY-013-R2)
                    $durum = $this->hedefDurumu($hedefKlasor.'/'.$ad, $kimlik, $manifestler);
                    if ($durum === 'ayni') {
                        $sayac['var']++;

                        continue;
                    }
                    if ($durum === 'cakisan') {
                        $sayac['cakisan']++;
                        $this->warn("Çakışma, içe alınmadı: {$ad} — yedek klasöründe bu adla başka bir yedek var.");

                        continue;
                    }

                    if (! $pg->calistir(['pg_restore', '--list', $kaynak], $sure)->successful()
                        || ! $pg->calistir(['pg_restore', '--file=/dev/null', $kaynak], $sure)->successful()) {
                        $sayac['okunamayan']++;
                        $this->warn("Okunamadı, atlandı: {$ad}");

                        continue;
                    }

                    AtomikDosya::kopyala($kaynak, $hedefKlasor.'/'.$ad);
                    $zaman = $this->addanZaman($ad) ?? CarbonImmutable::createFromTimestamp((int) filemtime($kaynak));
                    $veritabani = preg_match('/^([a-z_][a-z0-9_]{0,62})_predeploy_/D', $ad, $p) === 1 ? $p[1] : 'erp';

                    $manifestler->yaz(new YedekManifesti(
                        kimlik: $kimlik,
                        alinmaZamani: $zaman,
                        kaynak: YedekKaynagi::YayinOncesi,
                        planAdlari: [],
                        veritabani: $veritabani,
                        dosyaAdi: $ad,
                        boyut: (int) filesize($kaynak),
                        sha256: $ozet,
                        pgSunucuSurumu: null,
                        pgDumpSurumu: null,
                        uygulamaSurumu: null,
                        migrationlar: null,
                        satirSayilari: null,
                        verisiAlinmayanTablolar: [],
                        veritabaniOzellikleri: null,
                        sahipRol: null,
                        sifreli: false,
                        appKeyParmakIzi: null,
                        aciklama: 'deploy.sh yayın öncesi yedeği (sonradan içeri alındı)',
                        alanKullaniciId: null,
                        alanKullanici: null,
                        iceAlindi: true,
                    ), $hedefKlasor);

                    Yedek::query()->create([
                        'kimlik' => $kimlik,
                        'dosya_adi' => $ad,
                        'kaynak' => YedekKaynagi::YayinOncesi,
                        'durum' => YedekDurumu::Tamam,
                        'dogrulama_durumu' => DogrulamaDurumu::OzetTamam,
                        'imzali' => true,
                        'ice_alindi' => true,
                        'boyut' => (int) filesize($kaynak),
                        'sha256' => $ozet,
                        'alinma_zamani' => $zaman,
                        'bitti' => $zaman,
                        'aciklama' => 'deploy.sh yayın öncesi yedeği (sonradan içeri alındı)',
                    ]);
                    $olaylar->ekle(YedekOlayi::simdi($kimlik, OlayTuru::Dogrulandi, ['durum' => DogrulamaDurumu::OzetTamam->value]));
                    $sayac['alinan']++;
                } catch (Throwable $hata) {
                    $sayac['okunamayan']++;
                    $this->warn("İçeri alınamadı: {$ad} ({$hata->getMessage()})");
                }
            }
        });
    }

    /**
     * Hedef adın durumu: 'bos' (dosya, manifest ve kayıt yok), 'ayni' (aynı
     * arşiv daha önce içe alınmış: aynı özet + aynı kimlikli imzalı manifest;
     * kayıt eksikse `yedek:tara` kurar) ya da 'cakisan'. Çakışan ad ayrı ada
     * alınmaz: deploy.sh adı arşivin zamanını taşır ve aynı ana iki farklı
     * arşiv elle bakılması gereken bir durumdur — iki dosyaya da dokunulmaz.
     *
     * @return 'bos'|'ayni'|'cakisan'
     */
    private function hedefDurumu(string $hedef, string $kimlik, ManifestDeposu $manifestler): string
    {
        $json = ManifestDeposu::jsonYolu($hedef);
        $kayitVar = Yedek::query()->where('dosya_adi', basename($hedef))->exists();

        if (! $kayitVar && ! file_exists($hedef) && ! file_exists($json)) {
            return 'bos';
        }

        $manifest = $manifestler->oku($json);

        return ! $kayitVar && $manifest->guvenilirMi() && $manifest->manifest->kimlik === $kimlik && ManifestDeposu::dosyaTutuyorMu($manifest->manifest, $hedef)
            ? 'ayni'
            : 'cakisan';
    }

    /** Dosya adı + özetten UUID: aynı dosya ikinci çalıştırmada aynı kimliği alır */
    private function kimlik(string $ad, string $ozet): string
    {
        $h = hash('sha256', 'emor-yedek-ice-alinan:'.$ad.':'.$ozet);

        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12));
    }

    /**
     * `erp_predeploy_2026-08-05_101010.dump` → o an. deploy.sh adı sunucu
     * saatiyle yazıyordu; sunucu UTC'dir (2026-11-21 doğrulandı, ad = mtime).
     * Uygulama da UTC tutar, dönüştürülmez. Taşan tarih (30 Şubat) geri
     * çevrilip karşılaştırılarak reddedilir (Dikkat_Edilecekler G11);
     * o zaman dosyanın değişme zamanı kullanılır.
     */
    private function addanZaman(string $ad): ?CarbonImmutable
    {
        if (preg_match('/_predeploy_(\d{4}-\d{2}-\d{2})_(\d{6})\.dump$/D', $ad, $p) !== 1) {
            return null;
        }

        $metin = $p[1].' '.$p[2];
        $zaman = CarbonImmutable::createFromFormat('!Y-m-d His', $metin, 'UTC');

        return $zaman instanceof CarbonImmutable && $zaman->format('Y-m-d His') === $metin ? $zaman : null;
    }
}
