<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Kurtarma dosyalarının sunucu dışına gönderilmesi (README §4.6 son madde,
 * §9 "Kurtarma paketi"; BY-024). Sunucu kaybında yedekler uzak hedefte kalır
 * ama geçmişleri (sabitleme, doğrulama, kopya bilgisi), ayarlar ve denetim
 * kaydı sunucuyla giderdi. Başarılı her sunucu dışı kopyadan sonra o hedefe:
 *
 * - olay günlüğü (`olaylar.jsonl`, imzalı satırlar),
 * - ayar kopyası (`ayarlar.json`, imzalı; SMTP parolası APP_KEY ile şifreli),
 * - denetim günlüğü (`yedekleme` kanalının bütün günlük dosyaları, birleşik),
 * - yedek klasöründeki bütün imzalı manifestler (`erp_….json`, tar arşivi):
 *   uzak hedefe yalnız şifreli dump gider; boş sunucuda özet doğrulaması,
 *   kodlama / sıralama kuralı ve "güvenilir" sayılma manifestle olur
 *   (kurtarma.md). Yerel dump silinse de manifest yerinde kalır (YerelDosyaSilici).
 *
 * yedeklerle AYNI açık anahtarla şifrelenip sabit adlarla yazılır; her
 * seferinde üstüne yazılır. Adlar `erp_` ile başlamaz: bağlayıcıların
 * listesine (`erp_….dump|gpg`), dizine ve saklamaya girmez.
 *
 * Adla üstüne yazmayan hedeflerde (Google Drive her yüklemede yeni dosya
 * açar) bir önceki dosya, durum klasöründe imzalı tutulan son uzak kimlikten
 * bulunup silinir. Kayıt yoksa / imzası tutmuyorsa hiçbir şey silinmez.
 *
 * Buradaki hata yedeğin kopyasını başarısız SAYMAZ: istisna dışarı taşmaz,
 * denetim kaydına (dosya + veritabanı) yazılır.
 */
final class KurtarmaPaketi
{
    public const OLAYLAR = 'emor-kurtarma-olaylar.jsonl.gpg';

    public const AYARLAR = 'emor-kurtarma-ayarlar.json.gpg';

    public const DENETIM = 'emor-kurtarma-denetim.log.gpg';

    public const MANIFESTLER = 'emor-kurtarma-manifestler.tar.gpg';

    /** Durum klasöründe: hedef başına son yazılan uzak kimlikler (imzalı) */
    private const KIMLIK_KAYDI = 'kurtarma-kimlikleri.json';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly HedefKatalogu $katalog,
        private readonly OlayGunlugu $olaylar,
        private readonly KopyaSifreleyici $sifreleyici,
        private readonly YedekDenetimi $denetim,
        private readonly YedekImzasi $imza,
    ) {}

    /**
     * @return bool Bütün mevcut dosyalar gönderildi mi (hata istisna olarak dışarı taşmaz)
     */
    public function gonder(YedekHedefi $hedef, ?int $yedekId = null, ?int $kullaniciId = null): bool
    {
        $baslangic = hrtime(true);
        $calisma = null;
        $gonderilen = [];
        $atlanan = [];
        $hataMesaji = null;

        try {
            $calisma = sys_get_temp_dir().'/emor-kurtarma-'.bin2hex(random_bytes(6));
            if (! @mkdir($calisma, 0700)) {
                throw new RuntimeException('Geçici çalışma klasörü açılamadı.');
            }

            $baglayici = $this->katalog->olustur($hedef);

            foreach ($this->kaynaklar($calisma) as $ad => $yerel) {
                if ($yerel === null) {
                    $atlanan[] = $ad;

                    continue;
                }

                $this->bir($baglayici, $hedef, $ad, $this->sifreleyici->sifrele($yerel, $calisma.'/'.$ad), $calisma);
                $gonderilen[] = $ad;
            }
        } catch (Throwable $hata) {
            $hataMesaji = $hata instanceof HedefHatasi ? "{$hata->kod}: ".Str::limit($hata->getMessage(), 500) : $hata::class.': '.Str::limit($hata->getMessage(), 300);
        } finally {
            if ($calisma !== null) {
                File::deleteDirectory($calisma);
            }
        }

        try {
            $this->denetim->kaydet(
                'kurtarma_paketi',
                $hataMesaji === null ? IslemSonucu::Basarili : IslemSonucu::Basarisiz,
                $yedekId,
                $kullaniciId,
                $this->sureMs($baslangic),
                ['hedef' => $hedef->ad, 'gonderilen' => $gonderilen, 'atlanan' => $atlanan],
                $hataMesaji,
            );
        } catch (Throwable) {
            // Günlük dosyası da yazılamıyorsa yapılacak bir şey yok; kopyanın sonucu etkilenmez
        }

        return $hataMesaji === null;
    }

    /**
     * Yaz → geri oku → özeti karşılaştır → (kimlik değiştiyse) öncekini sil.
     *
     * @throws HedefHatasi
     */
    private function bir(HedefBaglayicisi $baglayici, YedekHedefi $hedef, string $ad, string $sifreli, string $calisma): void
    {
        $kimlik = $baglayici->yaz($sifreli, $ad);

        $geri = $calisma.'/geri';
        $baglayici->oku($kimlik, $geri);
        $okunan = is_file($geri) ? hash_file('sha256', $geri) : false;
        @unlink($geri);

        if (! is_string($okunan) || ! hash_equals((string) hash_file('sha256', $sifreli), $okunan)) {
            throw new HedefHatasi(HedefHatasi::OKUNAMADI, "Hedeften geri okunan {$ad} yazılanla aynı değil.");
        }

        $kimlikler = $this->kimlikler();
        $onceki = $kimlikler[(string) $hedef->id][$ad] ?? null;
        $kimlikler[(string) $hedef->id][$ad] = $kimlik;
        $this->kimlikleriYaz($kimlikler);

        if (is_string($onceki) && $onceki !== $kimlik) {
            $baglayici->sil($onceki);
        }
    }

    /**
     * Gönderilecek dosyaların anlık kopyaları (uzak ad => yerel yol; dosya
     * yoksa null). Yazılmakta olan günlükler kilit altında kopyalanır: gpg
     * büyüyen dosyayı okumaz.
     *
     * @return array<string, string|null>
     */
    private function kaynaklar(string $calisma): array
    {
        $ayarlar = $this->yapilandirma->ayarKopyasiYolu();

        return [
            self::OLAYLAR => $this->anlikKopya([$this->olaylar->yol()], $calisma.'/olaylar.jsonl'),
            self::AYARLAR => $this->anlikKopya([$ayarlar], $calisma.'/ayarlar.json'),
            self::DENETIM => $this->anlikKopya($this->denetimGunlukleri(), $calisma.'/denetim.log'),
            self::MANIFESTLER => $this->manifestArsivi($calisma.'/manifestler.tar'),
        ];
    }

    /**
     * Yedek klasöründeki manifestlerin tar arşivi (klasöre göre göreli adlar:
     * `tar -xf … -C <yedek klasörü>` ile yerlerine açılır). Manifest yoksa null.
     *
     * @throws RuntimeException
     */
    private function manifestArsivi(string $cikis): ?string
    {
        $klasor = $this->yapilandirma->klasor();
        $adlar = array_values(array_filter(
            array_map('basename', glob($klasor.'/erp_*.json') ?: []),
            fn (string $ad): bool => preg_match('/^erp_[A-Za-z0-9._+-]{1,200}\.json$/D', $ad) === 1,
        ));

        if ($adlar === []) {
            return null;
        }

        sort($adlar, SORT_STRING);
        $sonuc = Process::timeout($this->yapilandirma->sureSiniri('kopya'))
            // Devralınan TAR_OPTIONS arşivin biçimini değiştirmesin (G12)
            ->env(['TAR_OPTIONS' => false])
            ->run([$this->yapilandirma->arac('tar'), '--create', '--file='.$cikis, '--directory='.$klasor, '--', ...$adlar]);

        if (! $sonuc->successful() || ! is_file($cikis)) {
            throw new RuntimeException('Manifest arşivi oluşturulamadı (tar çıkış kodu '.($sonuc->exitCode() ?? '?').').');
        }

        return $cikis;
    }

    /**
     * Dosyaları sırayla tek dosyada birleştirir (paylaşılan kilitle okur).
     *
     * @param  list<string>  $kaynaklar
     *
     * @throws RuntimeException
     */
    private function anlikKopya(array $kaynaklar, string $cikis): ?string
    {
        $kaynaklar = array_values(array_filter($kaynaklar, 'is_file'));

        if ($kaynaklar === []) {
            return null;
        }

        $hedef = @fopen($cikis, 'xb');
        if ($hedef === false) {
            throw new RuntimeException('Kurtarma dosyasının geçici kopyası açılamadı.');
        }

        try {
            foreach ($kaynaklar as $kaynak) {
                $giris = @fopen($kaynak, 'rb');
                if ($giris === false) {
                    throw new RuntimeException('Kurtarma dosyası okunamadı: '.basename($kaynak));
                }

                try {
                    flock($giris, LOCK_SH);
                    $beklenen = fstat($giris)['size'] ?? null;
                    $kopyalanan = stream_copy_to_stream($giris, $hedef);

                    // feof() değil, bayt sayısı (G16)
                    if ($kopyalanan === false || $kopyalanan !== $beklenen) {
                        throw new RuntimeException('Kurtarma dosyası eksik kopyalandı: '.basename($kaynak));
                    }
                } finally {
                    flock($giris, LOCK_UN);
                    fclose($giris);
                }
            }
        } finally {
            fclose($hedef);
        }

        return $cikis;
    }

    /**
     * `yedekleme` günlük kanalının dosyaları, eskiden yeniye (günlük sürücüde
     * `yedekleme-YYYY-AA-GG.log`; tek dosya sürücüsünde kendisi).
     *
     * @return list<string>
     */
    private function denetimGunlukleri(): array
    {
        $yol = config('logging.channels.yedekleme.path');

        if (! is_string($yol) || $yol === '') {
            return [];
        }

        $bilgi = pathinfo($yol);
        $gunluk = glob(($bilgi['dirname'] ?? '.').'/'.$bilgi['filename'].'-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'.(isset($bilgi['extension']) ? '.'.$bilgi['extension'] : '')) ?: [];
        sort($gunluk, SORT_STRING);

        return [...$gunluk, $yol];
    }

    /**
     * İmzası tutan kimlik kaydı; yoksa / bozuksa boş (o durumda hiçbir şey silinmez).
     *
     * @return array<string, array<string, string>>
     */
    private function kimlikler(): array
    {
        $icerik = @file_get_contents($this->kimlikKaydiYolu());
        if (! is_string($icerik)) {
            return [];
        }

        try {
            $veri = json_decode($icerik, true, 8, JSON_THROW_ON_ERROR);

            return is_array($veri) && is_array($veri['kimlikler'] ?? null) && $this->imza->dogrula($veri['kimlikler'], $veri['imza'] ?? null)
                ? $veri['kimlikler'] : [];
        } catch (JsonException|ImzaAnahtariYok) {
            return [];
        }
    }

    /**
     * @param  array<string, array<string, string>>  $kimlikler
     *
     * @throws RuntimeException|ImzaAnahtariYok
     */
    private function kimlikleriYaz(array $kimlikler): void
    {
        $yol = $this->kimlikKaydiYolu();
        if (! is_dir(dirname($yol))) {
            KaliciKlasor::ac(dirname($yol), $this->yapilandirma->kaliciKok());
        }

        AtomikDosya::yaz($yol, json_encode(['kimlikler' => $kimlikler, 'imza' => $this->imza->imzala(json_decode(json_encode($kimlikler, JSON_THROW_ON_ERROR), true))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function kimlikKaydiYolu(): string
    {
        return $this->yapilandirma->durumKlasoru().'/'.self::KIMLIK_KAYDI;
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
