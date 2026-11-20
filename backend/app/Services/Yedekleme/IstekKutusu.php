<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * İstek klasörü (README §5.2: "işlem web isteğinde çalışmaz"; BY-013,
 * BY-014, BY-025). Ekran ve `deploy.sh` isteği DOSYA olarak bırakır;
 * çalıştırıcı (`yedek:calistir`) okur, işi yapar, sonucu yazar.
 *
 * - `bekleyen/<kimlik>.json`: istek (web / yayın kullanıcısı yazar).
 * - `sonuc/<kimlik>.json`: sonuç (çalıştırıcı yazar, ekran yoklar).
 *
 * İstek imzalı değildir: web süreci imza anahtarını okuyamaz (servis
 * hesabı). Çalıştırıcı isteğe GÜVENMEZ: türü izin listesinden, alanları
 * şemadan, kullanıcının yetkisini veritabanından yeniden doğrular (BY-025).
 * Kimlik UUID'dir; dosya adı kullanıcı girdisinden üretilmez (yol kaçışı yok).
 */
final class IstekKutusu
{
    public const SURUM = 1;

    /** İzinli istek türleri ve parametre şemaları (çalıştırıcı da denetler) */
    public const TURLER = [
        'yedek_al' => ['kaynak', 'aciklama'],
        'dogrula' => ['yedek_kimligi'],
        'sabitle' => ['yedek_kimligi'],
        'sabit_coz' => ['yedek_kimligi'],
        'aciklama' => ['yedek_kimligi', 'aciklama'],
        'sil' => ['yedek_kimligi'],
        'tara' => [],
        'plan_calistir' => ['plan_id'],
        'kopya_geri_yukle' => ['yedek_kimligi', 'ek'],
        'kopya_sil' => ['veritabani'],
        'ustune_geri_yukle' => ['yedek_kimligi', 'onay_metni', 'erisim_anahtari_ozeti'],
        'onayla' => ['islem_kimligi'],
        'geri_don' => ['islem_kimligi'],
    ];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @param  array<string, mixed>  $parametreler
     * @return string İsteğin kimliği (yoklama için)
     */
    public function yaz(string $tur, array $parametreler, ?int $kullaniciId): string
    {
        if (! isset(self::TURLER[$tur]) || array_diff(array_keys($parametreler), self::TURLER[$tur]) !== []) {
            throw new InvalidArgumentException("Geçersiz yedekleme isteği: {$tur}");
        }

        $kimlik = (string) Str::uuid();
        $klasor = $this->klasor('bekleyen');
        $icerik = json_encode([
            'surum' => self::SURUM,
            'kimlik' => $kimlik,
            'tur' => $tur,
            'parametreler' => $parametreler === [] ? new \stdClass : $parametreler,
            'kullanici_id' => $kullaniciId,
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        AtomikDosya::yaz($klasor.'/'.$kimlik.'.json', $icerik, 0660);

        return $kimlik;
    }

    /**
     * Bekleyen istekler, eskiden yeniye (dosya adı değil yazılma zamanı).
     *
     * @return list<string> Dosya yolları
     */
    public function bekleyenler(): array
    {
        $klasor = $this->klasor('bekleyen');
        $dosyalar = glob($klasor.'/*.json') ?: [];
        usort($dosyalar, fn (string $a, string $b): int => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));

        return array_values(array_filter($dosyalar, fn (string $yol): bool => preg_match(self::UUID, basename($yol, '.json')) === 1));
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    public function sonucYaz(string $kimlik, string $durum, ?string $kod = null, ?string $mesaj = null, array $ek = []): void
    {
        $this->kimlikDenetle($kimlik);
        AtomikDosya::yaz($this->klasor('sonuc').'/'.$kimlik.'.json', json_encode([
            'kimlik' => $kimlik,
            'durum' => $durum,
            'kod' => $kod,
            'mesaj' => $mesaj === null ? null : Str::limit($mesaj, 500),
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
            ...$ek,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0644);
    }

    /**
     * İsteğin durumu: sonuç varsa o; istek bekliyorsa `kuyrukta`; hiçbiri yoksa null.
     *
     * @return array<string, mixed>|null
     */
    public function durum(string $kimlik): ?array
    {
        $this->kimlikDenetle($kimlik);
        $sonuc = @file_get_contents($this->klasor('sonuc').'/'.$kimlik.'.json');

        if (is_string($sonuc)) {
            try {
                $veri = json_decode($sonuc, true, 8, JSON_THROW_ON_ERROR);

                return is_array($veri) ? $veri : null;
            } catch (JsonException) {
                return null;
            }
        }

        return is_file($this->klasor('bekleyen').'/'.$kimlik.'.json') ? ['kimlik' => $kimlik, 'durum' => 'kuyrukta'] : null;
    }

    /**
     * Sonuç `tamam` / `basarisiz` olana kadar bekler (yayın: `deploy.sh`).
     * Süre monoton saatle ölçülür (D1); her yoklama arasında 1 sn uyunur, en
     * çok `$enCokSaniye` yoklama yapılır. Süre dolduğunda istek hâlâ kuyruktaysa
     * geri çekilir: bekleyen vazgeçtikten sonra iş kendiliğinden çalışmasın.
     *
     * @return array<string, mixed>
     */
    public function bekle(string $kimlik, int $enCokSaniye): array
    {
        $son = hrtime(true) + $enCokSaniye * 1_000_000_000;

        for ($yoklama = 0; ; $yoklama++) {
            $durum = $this->durum($kimlik);
            if (in_array($durum['durum'] ?? null, ['tamam', 'basarisiz'], true)) {
                return $durum;
            }

            if ($yoklama >= $enCokSaniye || hrtime(true) >= $son) {
                @unlink($this->klasor('bekleyen').'/'.$kimlik.'.json');

                throw new RuntimeException('Yedekleme isteği zamanında işlenmedi (çalıştırıcı servisi çalışıyor mu?).');
            }

            Sleep::for(1)->second();
        }
    }

    public function sil(string $yol): void
    {
        @unlink($yol);
    }

    public function kimlikDenetle(string $kimlik): void
    {
        if (preg_match(self::UUID, $kimlik) !== 1) {
            throw new InvalidArgumentException('Geçersiz istek kimliği.');
        }
    }

    private function klasor(string $alt): string
    {
        $klasor = $this->yapilandirma->istekKlasoru().'/'.$alt;

        if (! is_dir($klasor) && ! @mkdir($klasor, 0770, true) && ! is_dir($klasor)) {
            throw new RuntimeException("İstek klasörü açılamadı: {$klasor}");
        }

        return $klasor;
    }
}
