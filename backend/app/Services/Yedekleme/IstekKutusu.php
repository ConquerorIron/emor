<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Services\Yedekleme\Istekler\Istek;
use App\Services\Yedekleme\Istekler\IstekReddi;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * İstek klasörü (README §5.2: "işlem web isteğinde çalışmaz"; BY-013,
 * BY-014, BY-025). Ekran ve `deploy.sh` isteği DOSYA olarak bırakır;
 * çalıştırıcı (`yedek:calistir`) okur, işi yapar, sonucu yazar.
 *
 * - `bekleyen/<kimlik>.json`: istek (web / yayın kullanıcısı yazar).
 * - `isleniyor/<kimlik>.json`: çalıştırıcının üstlendiği istek. Üstlenme tek
 *   adımlık taşımadır (rename): iki çalıştırıcı aynı isteği alamaz. Klasöre
 *   yalnız çalıştırıcı yazar (0750): dosya taşındıktan sonra web süreci onu
 *   bağlantıyla değiştiremez, denetim ondan sonra yapılır (D5).
 * - `sonuc/<kimlik>.json`: sonuç (çalıştırıcı yazar 0750; ekran yoklar).
 *
 * İstek imzalı değildir: web süreci imza anahtarını okuyamaz (servis
 * hesabı). Çalıştırıcı isteğe GÜVENMEZ (oku()): düz dosya, boyut, JSON,
 * sürüm, kimlik = dosya adı, tür izin listesinden, parametre anahtarları
 * şemayla BİREBİR ve her değerin türü / biçimi (G15). Yazan da aynı
 * denetimden geçer (G10). Göndereni dosyanın çekirdekteki sahibi (uid)
 * belirler, kullanıcının yetkisi çalıştırıcıda veritabanından yeniden
 * doğrulanır (IstekCalistiricisi; BY-025-R1).
 */
final class IstekKutusu
{
    public const SURUM = 1;

    /**
     * İzinli istek türleri ve parametre şemaları: anahtar => değer türü
     * (parametreGecerliMi). Yeni tür = buraya bir satır + IsleyiciKatalogu'na
     * bir işleyici.
     */
    public const TURLER = [
        'yedek_al' => ['kaynak' => 'kaynak', 'aciklama' => 'aciklama'],
        'dogrula' => ['yedek_kimligi' => 'uuid'],
        'sabitle' => ['yedek_kimligi' => 'uuid'],
        'sabit_coz' => ['yedek_kimligi' => 'uuid'],
        'aciklama' => ['yedek_kimligi' => 'uuid', 'aciklama' => 'aciklama'],
        'sil' => ['yedek_kimligi' => 'uuid'],
        'tara' => [],
        'plan_calistir' => ['plan_id' => 'kayit_no'],
        'kopya_geri_yukle' => ['yedek_kimligi' => 'uuid', 'ek' => 'ad_eki'],
        'kopya_sil' => ['veritabani' => 'kopya_veritabani'],
        'ustune_geri_yukle' => ['yedek_kimligi' => 'uuid', 'onay_metni' => 'onay_metni', 'erisim_anahtari_ozeti' => 'sha256'],
        // Yetki erişim anahtarıdır (README §7.2; BY-029), ama anahtar dosyaya
        // YAZILMAZ: web ucu anahtarı doğrulayıp kısa ömürlü, tek kullanımlık,
        // karara bağlı kanıt yazar; çalıştırıcı kanıtı işlem durumundaki özetle
        // doğrular (ErisimAnahtari::kanit, BY-030-R1)
        'onayla' => ['islem_kimligi' => 'uuid', 'son_gecerlilik' => 'unix_zaman', 'nonce' => 'nonce', 'ayar_secimleri' => 'ayar_secimleri', 'kanit' => 'sha256'],
        'geri_don' => ['islem_kimligi' => 'uuid', 'son_gecerlilik' => 'unix_zaman', 'nonce' => 'nonce', 'kanit' => 'sha256'],
    ];

    /** İstek dosyasının en büyük boyutu (bayt); en uzun geçerli istek ~2 KB */
    public const EN_BUYUK_BOYUT = 16_384;

    /** Bundan eski istek işlenmez (çalıştırıcı uzun süre durduysa bayat iş başlamasın) */
    public const GECERLILIK_SAAT = 24;

    /** yoksaYayinla() kabul kilidini en çok bu kadar bekler (yayın bir fsync sürer) */
    private const KABUL_KILIDI_BEKLEME_SN = 10;

    private const ALANLAR = ['surum', 'kimlik', 'tur', 'parametreler', 'kullanici_id', 'zaman'];

    /**
     * İstek / işlem kimliğinin biçimi (küçük harfli UUID). Rotalar da bunu
     * kullanır: Laravel'in `whereUuid`'i büyük harfi kabul eder, kutu reddeder
     * (500 yerine 404 — YF-29).
     */
    public const KIMLIK_DESENI = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private const UUID = '/^'.self::KIMLIK_DESENI.'$/D';

    private const ZAMAN_BICIMI = 'Y-m-d\TH:i:sP';

    /** Sonuç dosyasında ek alanların ezemeyeceği anahtarlar */
    private const SONUC_ALANLARI = ['kimlik', 'durum', 'kod', 'mesaj', 'zaman'];

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @param  array<string, mixed>  $parametreler  Anahtarlar şemayla birebir
     * @param  string|null  $kimlik  Önceden üretilmiş kimlik (denetim kaydı yayından önce yazılır — YedekIstekServisi); boşsa üretilir
     * @return string İsteğin kimliği (yoklama için)
     *
     * @throws InvalidArgumentException Tür ya da parametreler şemaya uymuyor (çalıştırıcı da reddederdi)
     * @throws KalicilikDogrulanamadi İstek yayımlandı (çalıştırıcıya görünür), klasör eşitlenemedi
     */
    public function yaz(string $tur, array $parametreler, ?int $kullaniciId, ?string $kimlik = null): string
    {
        if (! isset(self::TURLER[$tur]) || ! self::parametrelerGecerliMi($tur, $parametreler) || ($kullaniciId !== null && $kullaniciId < 1)) {
            throw new InvalidArgumentException("Geçersiz yedekleme isteği: {$tur}");
        }

        $kimlik ??= (string) Str::uuid();
        $this->kimlikDenetle($kimlik);
        $klasor = $this->klasor('bekleyen', 0770);
        $icerik = json_encode([
            'surum' => self::SURUM,
            'kimlik' => $kimlik,
            'tur' => $tur,
            'parametreler' => $parametreler === [] ? new \stdClass : $parametreler,
            'kullanici_id' => $kullaniciId,
            'zaman' => CarbonImmutable::now('UTC')->format(self::ZAMAN_BICIMI),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 0640: çalıştırıcı grup olarak okur; aynı gruptaki başka hesap (web)
        // yayın kullanıcısının dosyasının içeriğini değiştiremez — gönderen
        // dosyanın sahibiyle doğrulanır (BY-025-R1)
        AtomikDosya::yaz($klasor.'/'.$kimlik.'.json', $icerik, 0640);

        return $kimlik;
    }

    /**
     * Aynı kimlikle en çok bir istek yayınlanır (BY-014-R2): kimlik bir
     * dosyada (bekleyen / işleniyor / sonuç — durum()) görünüyorsa $yayinla
     * çağrılmaz. Bakış ve yayın, `bekleyen/`'deki kabul kilidi altında tek
     * adımdır (G18): aynı kimlikle eşzamanlı iki istek, çalıştırıcı ilkini
     * üstlenmiş olsa da ikinci dosyayı yazamaz. Kilit en çok
     * KABUL_KILIDI_BEKLEME_SN beklenir (D1; monoton saat).
     *
     * @param  Closure(): void  $yayinla
     * @return bool Yayınlandı mı (false: bu kimlikle istek zaten vardı)
     *
     * @throws RuntimeException Kabul kilidi alınamadı (hiçbir şey yayınlanmadı)
     */
    public function yoksaYayinla(string $kimlik, Closure $yayinla): bool
    {
        $this->kimlikDenetle($kimlik);
        $kilit = $this->kabulKilidiniAl();

        try {
            if ($this->durum($kimlik) !== null) {
                return false;
            }

            $yayinla();

            return true;
        } finally {
            flock($kilit, LOCK_UN);
            fclose($kilit);
        }
    }

    /**
     * Bekleyen istekler, eskiden yeniye (dosya adı değil yazılma zamanı).
     *
     * @return list<string> Dosya yolları
     */
    public function bekleyenler(): array
    {
        return $this->listele($this->klasor('bekleyen', 0770), sirali: true);
    }

    /**
     * Üstlenilmiş ama sonucu yazılmamış istekler (süreç işlerken öldü).
     *
     * @return list<string>
     */
    public function isleniyorlar(): array
    {
        return $this->listele($this->klasor('isleniyor', 0750), sirali: false);
    }

    /**
     * İsteği üstlenir: bekleyen dosyayı (bağlantıysa bağlantının kendisini)
     * `isleniyor/`'a tek adımda taşır. Başka çalıştırıcı önce aldıysa null.
     */
    public function talepEt(string $yol): ?string
    {
        $kimlik = basename($yol, '.json');
        $this->kimlikDenetle($kimlik);
        $hedef = $this->klasor('isleniyor', 0750).'/'.$kimlik.'.json';

        return @rename($yol, $hedef) ? $hedef : null;
    }

    /**
     * Üstlenilmiş isteği okur ve doğrular. Hiçbir alanına güvenilmez.
     *
     * @throws IstekReddi Kod: ISTEK_DOSYASI_GECERSIZ, ISTEK_GECERSIZ, ISTEK_TURU_GECERSIZ, ISTEK_PARAMETRE_GECERSIZ, ISTEK_SURESI_DOLDU
     */
    public function oku(string $yol): Istek
    {
        $kimlik = basename($yol, '.json');
        $this->kimlikDenetle($kimlik);
        [$icerik, $sahipUid] = $this->guvenliOku($yol);

        try {
            $veri = json_decode($icerik, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IstekReddi('ISTEK_GECERSIZ', 'İstek dosyası geçerli JSON değil.');
        }

        if (! is_array($veri) || array_is_list($veri) || array_diff(self::ALANLAR, array_keys($veri)) !== [] || array_diff(array_keys($veri), self::ALANLAR) !== []) {
            throw new IstekReddi('ISTEK_GECERSIZ', 'İstek dosyasının alanları şemaya uymuyor.');
        }

        if ($veri['surum'] !== self::SURUM || $veri['kimlik'] !== $kimlik) {
            throw new IstekReddi('ISTEK_GECERSIZ', 'İstek sürümü bilinmiyor ya da kimliği dosya adıyla aynı değil.');
        }

        $tur = $veri['tur'];
        if (! is_string($tur) || ! isset(self::TURLER[$tur])) {
            throw new IstekReddi('ISTEK_TURU_GECERSIZ', 'İstek türü izin listesinde değil.');
        }

        $parametreler = $veri['parametreler'];
        if (! is_array($parametreler) || ! self::parametrelerGecerliMi($tur, $parametreler)) {
            throw new IstekReddi('ISTEK_PARAMETRE_GECERSIZ', 'İstek parametreleri şemaya uymuyor (anahtar, tür ya da biçim).');
        }

        $kullanici = $veri['kullanici_id'];
        if ($kullanici !== null && (! is_int($kullanici) || $kullanici < 1)) {
            throw new IstekReddi('ISTEK_GECERSIZ', 'İstekteki kullanıcı kimliği geçersiz.');
        }

        $zaman = $this->zaman($veri['zaman']);
        $simdi = CarbonImmutable::now('UTC');
        if ($zaman->lessThan($simdi->subHours(self::GECERLILIK_SAAT)) || $zaman->greaterThan($simdi->addMinutes(5))) {
            throw new IstekReddi('ISTEK_SURESI_DOLDU', 'İstek '.self::GECERLILIK_SAAT.' saatten eski (ya da zamanı ileride); işlenmedi.');
        }

        /** @var array<string, string|int|null> $parametreler */
        return new Istek($kimlik, $tur, $parametreler, $kullanici, $zaman, $sahipUid);
    }

    /**
     * @param  array<string, mixed>  $ek  Türe özgü sonuç alanları (yedek_kimligi …); temel alanları ezemez
     */
    public function sonucYaz(string $kimlik, string $durum, ?string $kod = null, ?string $mesaj = null, array $ek = []): void
    {
        $this->kimlikDenetle($kimlik);
        AtomikDosya::yaz($this->klasor('sonuc', 0750).'/'.$kimlik.'.json', json_encode([
            ...array_diff_key($ek, array_flip(self::SONUC_ALANLARI)),
            'kimlik' => $kimlik,
            'durum' => $durum,
            'kod' => $kod,
            'mesaj' => $mesaj === null ? null : Str::limit($mesaj, 500),
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0644);
    }

    /**
     * İsteğin durumu: sonuç varsa o; istek bekliyorsa `kuyrukta`; çalıştırıcı
     * üstlendiyse `calisiyor`; hiçbiri yoksa null. Klasör açmaz (web süreci
     * çalıştırıcının klasörlerini yaratmasın).
     *
     * @return array<string, mixed>|null
     */
    public function durum(string $kimlik): ?array
    {
        $this->kimlikDenetle($kimlik);
        $kok = $this->yapilandirma->istekKlasoru();

        // Sıra, dosyanın yolculuğunun tersi değil aynısıdır (bekleyen → isleniyor
        // → sonuç); arada taşınan istek, sonda sonuç bir kez daha okunarak yakalanır
        $sonuc = $this->sonucOku($kok, $kimlik);
        if ($sonuc !== null) {
            return $sonuc;
        }

        if (is_file($kok.'/bekleyen/'.$kimlik.'.json')) {
            return ['kimlik' => $kimlik, 'durum' => 'kuyrukta'];
        }

        if (is_file($kok.'/isleniyor/'.$kimlik.'.json')) {
            return ['kimlik' => $kimlik, 'durum' => 'calisiyor'];
        }

        return $this->sonucOku($kok, $kimlik);
    }

    /**
     * Sonuç `tamam` / `basarisiz` olana kadar bekler (yayın: `deploy.sh`).
     * Süre monoton saatle ölçülür (D1); her yoklama arasında 1 sn uyunur, en
     * çok `$enCokSaniye` yoklama yapılır. Süre dolduğunda istek hâlâ kuyruktaysa
     * geri çekilir: bekleyen vazgeçtikten sonra iş kendiliğinden çalışmasın.
     *
     * `$ustlenmeSaniye` verilirse iki aşamalıdır (YF-68; D9): istek o kadar
     * yoklama boyunca `bekleyen/`'de kalırsa (çalıştırıcı durmuş ya da başka
     * bir işle meşgul) geri çekilir ve beklemeden hata verilir; `$enCokSaniye`
     * ancak istek `bekleyen/`'den çıktıktan (üstlenildikten) sonra başlar.
     * Yayın kullanıcısı `isleniyor/`'u göremez: üstlenilen isteğin durumu
     * onun için `null`dur, `kuyrukta` değildir.
     *
     * @return array<string, mixed>
     */
    public function bekle(string $kimlik, int $enCokSaniye, ?int $ustlenmeSaniye = null): array
    {
        $bekleyen = $this->yapilandirma->istekKlasoru().'/bekleyen/'.$kimlik.'.json';
        $ustlenmeSonu = hrtime(true) + ($ustlenmeSaniye ?? 0) * 1_000_000_000;
        $ustlenildi = $ustlenmeSaniye === null;
        $ustlenmeYoklamasi = 0;
        $son = hrtime(true) + $enCokSaniye * 1_000_000_000;

        for ($yoklama = 0; ; $yoklama++) {
            $durum = $this->durum($kimlik);
            if (in_array($durum['durum'] ?? null, ['tamam', 'basarisiz'], true)) {
                return $durum;
            }

            if (! $ustlenildi && ($durum['durum'] ?? null) !== 'kuyrukta') {
                $ustlenildi = true;
                $ustlenmeYoklamasi = $yoklama;
                $son = hrtime(true) + $enCokSaniye * 1_000_000_000;
            }

            if (! $ustlenildi && ($yoklama >= $ustlenmeSaniye || hrtime(true) >= $ustlenmeSonu)) {
                if (@unlink($bekleyen)) {
                    throw new RuntimeException("Çalıştırıcı isteği üstlenmedi ({$ustlenmeSaniye} sn beklendi); istek geri çekildi. Servis durmuş ya da başka bir iş sürüyor olabilir: systemctl status emor-yedek");
                }
                if (is_file($bekleyen)) {
                    throw new RuntimeException('Çalıştırıcı isteği üstlenmedi ve istek geri çekilemedi (izin?); çalıştırıcı sonradan işleyebilir. systemctl status emor-yedek');
                }

                // Tam bu arada üstlenildi: sonraki yoklama bunu görür
                continue;
            }

            if ($ustlenildi && ($yoklama - $ustlenmeYoklamasi >= $enCokSaniye || hrtime(true) >= $son)) {
                @unlink($bekleyen);

                throw new RuntimeException('Yedekleme isteği zamanında işlenmedi (çalıştırıcı servisi çalışıyor mu?).');
            }

            Sleep::for(1)->second();
        }
    }

    /**
     * Ekranın artık yoklamadığı eski sonuçları siler.
     *
     * @return int Silinen dosya sayısı
     */
    public function eskiSonuclariSil(int $gun): int
    {
        $sinir = CarbonImmutable::now()->subDays($gun)->getTimestamp();
        $silinen = 0;

        foreach (glob($this->klasor('sonuc', 0750).'/*.json') ?: [] as $yol) {
            $zaman = @filemtime($yol);
            if ($zaman !== false && $zaman < $sinir && @unlink($yol)) {
                $silinen++;
            }
        }

        return $silinen;
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

    /**
     * Anahtarlar şemayla BİREBİR (eksik ve fazla yok), her değer türüne uygun.
     *
     * @param  array<mixed>  $parametreler
     */
    private static function parametrelerGecerliMi(string $tur, array $parametreler): bool
    {
        $sema = self::TURLER[$tur];

        if (($parametreler !== [] && array_is_list($parametreler))
            || array_diff(array_keys($sema), array_keys($parametreler)) !== []
            || array_diff(array_keys($parametreler), array_keys($sema)) !== []) {
            return false;
        }

        foreach ($sema as $anahtar => $deger) {
            if (! self::degerGecerliMi($deger, $parametreler[$anahtar])) {
                return false;
            }
        }

        return true;
    }

    /** AyarSecimi::metne() biçiminde mi (tek biçimli metin) */
    private static function ayarSecimiMi(string $girdi): bool
    {
        try {
            AyarSecimi::metindenDiziye($girdi);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function degerGecerliMi(string $deger, mixed $girdi): bool
    {
        return match ($deger) {
            'uuid' => is_string($girdi) && preg_match(self::UUID, $girdi) === 1,
            'kaynak' => in_array($girdi, [YedekKaynagi::Elle->value, YedekKaynagi::YayinOncesi->value], true),
            'aciklama' => $girdi === null || (is_string($girdi) && mb_check_encoding($girdi, 'UTF-8') && mb_strlen($girdi) <= 500),
            'kayit_no' => is_int($girdi) && $girdi >= 1 && $girdi <= 2_147_483_647,
            'ad_eki' => $girdi === null || (is_string($girdi) && preg_match(VeritabaniAdi::EK_DESENI, $girdi) === 1),
            'kopya_veritabani' => is_string($girdi) && VeritabaniAdi::kopyaMi($girdi),
            'onay_metni' => is_string($girdi) && mb_check_encoding($girdi, 'UTF-8') && mb_strlen($girdi) >= 1 && mb_strlen($girdi) <= 128,
            'sha256' => is_string($girdi) && preg_match('/^[0-9a-f]{64}$/D', $girdi) === 1,
            'unix_zaman' => is_int($girdi) && $girdi >= 1,
            'nonce' => is_string($girdi) && preg_match('/^[0-9a-f]{32}$/D', $girdi) === 1,
            'ayar_secimleri' => is_string($girdi) && self::ayarSecimiMi($girdi),
            default => false,
        };
    }

    /**
     * Yalnız tek bağlantılı düz dosya: sembolik / sabit bağlantı, klasör,
     * FIFO okunmaz (D5). Boyut, okunan bayt sayısıyla da sınırlanır. Sahip,
     * okunan (açılmış) dosyanın çekirdekteki sahibidir: istek içeriğinden
     * bağımsız gönderen kanıtı (BY-025-R1; `isleniyor/`'a yalnız çalıştırıcı yazar).
     *
     * @return array{0: string, 1: int} İçerik ve sahibin uid'si
     *
     * @throws IstekReddi
     */
    private function guvenliOku(string $yol): array
    {
        clearstatcache(true, $yol);
        $bilgi = @lstat($yol);

        if ($bilgi === false || ($bilgi['mode'] & 0170000) !== 0100000 || $bilgi['nlink'] !== 1) {
            throw new IstekReddi('ISTEK_DOSYASI_GECERSIZ', 'İstek düz bir dosya değil (bağlantı ya da özel dosya).');
        }

        if ($bilgi['size'] > self::EN_BUYUK_BOYUT) {
            throw new IstekReddi('ISTEK_DOSYASI_GECERSIZ', 'İstek dosyası izin verilenden büyük.');
        }

        $tutamac = @fopen($yol, 'rb');
        if ($tutamac === false) {
            throw new IstekReddi('ISTEK_DOSYASI_GECERSIZ', 'İstek dosyası okunamadı.');
        }

        try {
            $acilan = fstat($tutamac);
            if ($acilan === false || $acilan['ino'] !== $bilgi['ino'] || $acilan['dev'] !== $bilgi['dev']) {
                throw new IstekReddi('ISTEK_DOSYASI_GECERSIZ', 'İstek dosyası okunurken değişti.');
            }

            $icerik = stream_get_contents($tutamac, self::EN_BUYUK_BOYUT + 1);
        } finally {
            fclose($tutamac);
        }

        if (! is_string($icerik) || strlen($icerik) > self::EN_BUYUK_BOYUT) {
            throw new IstekReddi('ISTEK_DOSYASI_GECERSIZ', 'İstek dosyası izin verilenden büyük.');
        }

        return [$icerik, $acilan['uid']];
    }

    /**
     * Saat dilimi zorunlu; taşan tarih geri biçimlenip karşılaştırılarak reddedilir (G11).
     *
     * @throws IstekReddi
     */
    private function zaman(mixed $deger): CarbonImmutable
    {
        try {
            $zaman = is_string($deger) ? CarbonImmutable::createFromFormat(self::ZAMAN_BICIMI, $deger) : null;
        } catch (Throwable) {
            $zaman = null;
        }

        if (! $zaman instanceof CarbonImmutable || $zaman->format(self::ZAMAN_BICIMI) !== $deger) {
            throw new IstekReddi('ISTEK_GECERSIZ', 'İstek zamanı "YYYY-AA-GGTSS:DD:SS+SS:DD" biçiminde değil.');
        }

        return $zaman;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sonucOku(string $kok, string $kimlik): ?array
    {
        $sonuc = @file_get_contents($kok.'/sonuc/'.$kimlik.'.json');
        if (! is_string($sonuc)) {
            return null;
        }

        try {
            $veri = json_decode($sonuc, true, 8, JSON_THROW_ON_ERROR);

            return is_array($veri) ? $veri : null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function listele(string $klasor, bool $sirali): array
    {
        $dosyalar = array_values(array_filter(glob($klasor.'/*.json') ?: [], fn (string $yol): bool => preg_match(self::UUID, basename($yol, '.json')) === 1));

        if ($sirali) {
            usort($dosyalar, fn (string $a, string $b): int => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));
        }

        return $dosyalar;
    }

    /**
     * `bekleyen/.kabul.kilit` üzerinde dışlayıcı dosya kilidi (flock;
     * veritabanına dayanmaz — karar uçları veritabanı yokken de çalışır).
     * Nokta önekli ad: listele() yalnız UUID adlı istekleri görür.
     *
     * @return resource
     *
     * @throws RuntimeException
     */
    private function kabulKilidiniAl()
    {
        $yol = $this->klasor('bekleyen', 0770).'/.kabul.kilit';
        $tutamac = @fopen($yol, 'c');
        if ($tutamac === false) {
            throw new RuntimeException("İstek kabul kilidi açılamadı: {$yol}");
        }

        $son = hrtime(true) + self::KABUL_KILIDI_BEKLEME_SN * 1_000_000_000;
        $mesgul = 0;
        while (! flock($tutamac, LOCK_EX | LOCK_NB, $mesgul)) {
            if ($mesgul !== 1 || hrtime(true) >= $son) {
                fclose($tutamac);

                throw new RuntimeException($mesgul === 1 ? 'İstek kabul kilidi zamanında alınamadı.' : "İstek kabul kilidi alınamadı: {$yol}");
            }

            Sleep::usleep(50_000);
        }

        return $tutamac;
    }

    /**
     * Klasörü gerekirse açar. `bekleyen/` web sürecine yazılabilir (0770);
     * `isleniyor/` ve `sonuc/` yalnız çalıştırıcıya (0750; web okur).
     */
    private function klasor(string $alt, int $izin): string
    {
        $klasor = $this->yapilandirma->istekKlasoru().'/'.$alt;

        if (! is_dir($klasor) && ! @mkdir($klasor, $izin, true) && ! is_dir($klasor)) {
            throw new RuntimeException("İstek klasörü açılamadı: {$klasor}");
        }

        return $klasor;
    }
}
