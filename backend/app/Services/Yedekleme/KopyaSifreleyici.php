<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekAyari;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sunucu dışına giden kopyanın gpg ile açık anahtarla şifrelenmesi ve geri
 * yüklemede çözülmesi (README §4.7, §4.6; BY-019).
 *
 * - Sunucuda yalnız AÇIK anahtar durur (`yedek_ayarlari`). Özel anahtar
 *   aranmaz, saklanmaz; yüklenen metinde özel anahtar varsa reddedilir.
 *   Çözme için özel anahtar o anda dosya olarak verilir (komut satırı).
 * - Açık anahtar tanımlı değilken şifreleme hata verir: kopya sessizce
 *   şifresiz gitmez. Anahtar eksik / geçersiz / süresi dolmuş kalıcı hatadır
 *   (yeniden denenmez); gpg süre aşımı ve geçici klasör / disk sorunu
 *   `gecici` işaretli SifrelemeHatasi'dır, kopya sınırlı denenir (D8).
 * - Her işlem kendi geçici anahtarlığında (GNUPGHOME, 0700) yapılır; işlem
 *   bitince (hata yolunda da) ajanı kapatılır ve klasör silinir; öldürülen
 *   bir işlemden kalan eski anahtarlıklar yenisi açılmadan silinir, kesme
 *   sinyalinde `yedek:sifre-coz` açık anahtarlığı siler (YF-28). Kullanıcının
 *   ya da servis hesabının kendi anahtarlığına dokunulmaz.
 * - gpg argüman dizisiyle, kabuksuz ve süre sınırıyla çalışır (D1). Parola
 *   komut satırına girmez, yalnız dosyasının yolu verilir (D2).
 * - Çıktı önce geçici ada yazılır, başarıda yerine taşınır: yarım şifreli /
 *   çözülmüş dosya asıl adla hiç görünmez (D3).
 * - Hata mesajı anahtar içeriği ve gpg'nin ham hata çıktısını taşımaz;
 *   neden, gpg'nin makine okunur durum satırlarından çıkarılır (D2).
 */
class KopyaSifreleyici
{
    /** Anahtar okuma / içe alma ve ajan kapatma: kısa işler */
    private const ANAHTAR_SURESI = 30;

    /** Ölçülü bir açık anahtar birkaç KB'tır; fazlası anahtar değildir */
    private const EN_BUYUK_ANAHTAR = 65536;

    private const PARMAK_IZI = '/^(?:[0-9A-F]{40}|[0-9A-F]{64})$/D';

    /** libgpg-error GPG_ERR_BAD_PASSPHRASE */
    private const GPG_HATA_PAROLA = 11;

    /** @var array<string, true> O anda açık geçici anahtarlıklar (sinyalde silinir) */
    private array $acikAnahtarliklar = [];

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * Dosyayı kayıtlı açık anahtarla şifreler.
     *
     * @return string Şifreli dosyanın yolu ($cikis)
     *
     * @throws HedefHatasi Anahtar tanımlı değil / kayıtlıyla uyuşmuyor / süresi dolmuş ya da araç yapılandırılmamış (YAPILANDIRMA: kalıcı, yeniden denenmez — D8)
     * @throws SifrelemeHatasi gpg süre sınırını aştı, çalıştırılamadı ya da geçici klasör / disk sorunu (`gecici`: sınırlı yeniden denenir)
     */
    public function sifrele(string $kaynak, string $cikis): string
    {
        $ayar = YedekAyari::guncel();
        $anahtar = $ayar->sifreleme_acik_anahtari;
        $parmakIzi = $ayar->sifreleme_parmak_izi;

        if (! is_string($anahtar) || trim($anahtar) === '' || ! is_string($parmakIzi) || preg_match(self::PARMAK_IZI, $parmakIzi) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Sunucu dışı hedefe kopya için şifreleme açık anahtarı tanımlı değil; kopya şifresiz gönderilmez.');
        }

        try {
            $this->geciciAnahtarlikla(function (string $ev) use ($anahtar, $parmakIzi, $kaynak, $cikis): void {
                $dosya = $ev.'/acik.asc';
                file_put_contents($dosya, $anahtar);
                $durum = self::durumSatirlari($this->gpg($ev, ['--no-autostart', '--import', $dosya], self::ANAHTAR_SURESI)->output());
                $alinan = array_values(array_unique(array_map(fn (string $s): string => explode(' ', $s)[1] ?? '', $durum['IMPORT_OK'] ?? [])));

                if ($alinan !== [$parmakIzi]) {
                    throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'Kayıtlı açık anahtar, kayıtlı parmak iziyle aynı değil; anahtarı yeniden yükleyin.');
                }

                $this->geciciyeYazipTasi($cikis, function (string $gecici) use ($ev, $parmakIzi, $kaynak): void {
                    $sonuc = $this->gpg($ev, [
                        '--no-autostart', '--trust-model', 'always',
                        // pg_dump özel biçimi zaten sıkıştırılmış
                        '--compress-algo', 'none',
                        '--recipient', $parmakIzi, '--output', $gecici, '--encrypt', $kaynak,
                    ], $this->yapilandirma->sureSiniri('sifreleme'));
                    $durum = self::durumSatirlari($sonuc->output());

                    if (isset($durum['KEYEXPIRED']) || isset($durum['INV_RECP'])) {
                        throw new SifrelemeHatasi(SifrelemeHatasi::SURESI_DOLMUS, 'Açık anahtarın süresi dolmuş ya da şifrelemeye uygun değil; yeni anahtar yükleyin.');
                    }

                    // Anahtar sorunları yukarıda ayrıldı; kalan (disk dolu, yarıda kesilme) geçici sayılır
                    if (! $sonuc->successful() || ! isset($durum['END_ENCRYPTION']) || ! is_file($gecici)) {
                        throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, 'Kopya şifrelenemedi ('.self::neden($durum, $sonuc).').', gecici: true);
                    }
                });
            });
        } catch (SifrelemeHatasi $hata) {
            if ($hata->gecici) {
                throw $hata;
            }

            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, $hata->getMessage());
        } catch (YapilandirmaHatasi $hata) {
            // Geçersiz süre sınırı ayarı (G9): kalıcı
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, $hata->getMessage());
        }

        return $cikis;
    }

    /**
     * Yüklenmek istenen açık anahtarı doğrular ve bilgilerini döndürür
     * (ayarlar ekranı; kaydetmez). Kabul: ASCII zırhlı TEK açık anahtar,
     * süresi dolmamış, iptal edilmemiş, şifrelemeye uygun. Metinde özel anahtar
     * varsa (ayrı blok ya da açık anahtar bloğuna gömülü) reddedilir.
     *
     * @return array{parmak_izi: string, kullanici: string|null, bitis: CarbonImmutable|null}
     *
     * @throws SifrelemeHatasi
     */
    public function acikAnahtarBilgisi(string $metin): array
    {
        $metin = trim($metin);

        if (preg_match('/BEGIN PGP (?:PRIVATE|SECRET) KEY/i', $metin) === 1) {
            throw new SifrelemeHatasi(SifrelemeHatasi::OZEL_ANAHTAR, 'Metinde özel anahtar var. Sunucuya yalnız AÇIK anahtar yüklenir; özel anahtarı sunucu dışında saklayın.');
        }

        if (strlen($metin) > self::EN_BUYUK_ANAHTAR
            || substr_count($metin, '-----BEGIN PGP PUBLIC KEY BLOCK-----') !== 1
            || substr_count($metin, '-----END PGP PUBLIC KEY BLOCK-----') !== 1) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'ASCII zırhlı tek bir açık anahtar bloğu bekleniyor (-----BEGIN PGP PUBLIC KEY BLOCK-----).');
        }

        return $this->geciciAnahtarlikla(function (string $ev) use ($metin): array {
            $dosya = $ev.'/acik.asc';
            file_put_contents($dosya, $metin);
            $sonuc = $this->gpg($ev, ['--no-autostart', '--with-colons', '--import-options', 'show-only', '--import', $dosya], self::ANAHTAR_SURESI);

            return $this->anahtarListesiniCoz($sonuc);
        });
    }

    /**
     * Şifreli dosyayı, o anda verilen özel anahtarla çözer (geri yükleme).
     * Özel anahtar yalnız işlemin geçici anahtarlığına alınır ve işlem bitince
     * onunla birlikte silinir; uygulamanın klasörlerine kopyalanmaz.
     * Başarısızlıkta $hedef'e dokunulmaz, geride yarım dosya kalmaz.
     *
     * @param  string|null  $parolaDosyasi  Anahtar parolalıysa parolanın dosyası (ilk satır; izni 0600 olmalı)
     *
     * @throws SifrelemeHatasi
     */
    public function coz(string $sifreli, string $hedef, string $ozelAnahtarYolu, ?string $parolaDosyasi = null): void
    {
        if (! is_file($ozelAnahtarYolu) || ! is_readable($ozelAnahtarYolu)) {
            throw new SifrelemeHatasi(SifrelemeHatasi::OZEL_ANAHTAR_OKUNAMADI, 'Özel anahtar dosyası okunamadı.');
        }

        if ($parolaDosyasi !== null && (! is_file($parolaDosyasi) || ! is_readable($parolaDosyasi))) {
            throw new SifrelemeHatasi(SifrelemeHatasi::PAROLA, 'Parola dosyası okunamadı.');
        }

        if (! is_file($sifreli) || ! is_readable($sifreli)) {
            throw new SifrelemeHatasi(SifrelemeHatasi::COZULEMEDI, 'Şifreli dosya okunamadı.');
        }

        $parola = $parolaDosyasi === null ? [] : ['--passphrase-file', $parolaDosyasi];

        $this->geciciAnahtarlikla(function (string $ev) use ($sifreli, $hedef, $ozelAnahtarYolu, $parola): void {
            $durum = self::durumSatirlari($this->gpg($ev, ['--pinentry-mode', 'loopback', ...$parola, '--import', $ozelAnahtarYolu], self::ANAHTAR_SURESI)->output());
            // IMPORT_OK nedeni bit alanıdır; 16 = özel anahtar içeriyor
            $ozeller = array_filter($durum['IMPORT_OK'] ?? [], fn (string $s): bool => ((int) explode(' ', $s)[0] & 16) === 16);

            if ($ozeller === []) {
                throw new SifrelemeHatasi(SifrelemeHatasi::OZEL_ANAHTAR_OKUNAMADI, 'Verilen dosyada içe alınabilen bir özel anahtar yok.');
            }

            $this->geciciyeYazipTasi($hedef, function (string $gecici) use ($ev, $sifreli, $parola): void {
                $sonuc = $this->gpg($ev, ['--pinentry-mode', 'loopback', ...$parola, '--output', $gecici, '--decrypt', $sifreli], $this->yapilandirma->sureSiniri('sifreleme'));
                $durum = self::durumSatirlari($sonuc->output());

                // Parola dosyası verilmediyse gpg parolayı ister (NEED_PASSPHRASE) ve toplu kipte durur;
                // yanlış parolada ajan "ERROR pkdecrypt_failed <kod>" verir, kodun alt 16 biti 11 = GPG_ERR_BAD_PASSPHRASE
                $hataKodlari = array_map(fn (string $s): int => (int) (explode(' ', $s)[1] ?? 0) & 0xFFFF, $durum['ERROR'] ?? []);
                if (isset($durum['BAD_PASSPHRASE']) || isset($durum['MISSING_PASSPHRASE']) || in_array(self::GPG_HATA_PAROLA, $hataKodlari, true)
                    || (isset($durum['NEED_PASSPHRASE']) && ! isset($durum['DECRYPTION_OKAY']))) {
                    throw new SifrelemeHatasi(SifrelemeHatasi::PAROLA, 'Özel anahtarın parolası eksik ya da yanlış.');
                }

                if (isset($durum['NO_SECKEY']) && ! isset($durum['DECRYPTION_KEY'])) {
                    throw new SifrelemeHatasi(SifrelemeHatasi::COZULEMEDI, 'Dosya verilen özel anahtarla şifrelenmemiş; doğru anahtarı verin.');
                }

                if (! $sonuc->successful() || ! isset($durum['DECRYPTION_OKAY']) || isset($durum['DECRYPTION_FAILED']) || isset($durum['BADMDC']) || ! is_file($gecici)) {
                    throw new SifrelemeHatasi(SifrelemeHatasi::COZULEMEDI, 'Dosya çözülemedi; şifreli dosya bozuk ya da eksik olabilir ('.self::neden($durum, $sonuc).').');
                }
            });
        });
    }

    /**
     * `--with-colons --import-options show-only` çıktısından tek açık
     * anahtarın bilgisi.
     *
     * @return array{parmak_izi: string, kullanici: string|null, bitis: CarbonImmutable|null}
     *
     * @throws SifrelemeHatasi
     */
    private function anahtarListesiniCoz(ProcessResult $sonuc): array
    {
        $satirlar = array_map(fn (string $s): array => explode(':', $s), preg_split('/\r?\n/', $sonuc->output()) ?: []);
        $turler = array_column($satirlar, 0);

        if (array_intersect(['sec', 'ssb'], $turler) !== []) {
            throw new SifrelemeHatasi(SifrelemeHatasi::OZEL_ANAHTAR, 'Metinde özel anahtar var. Sunucuya yalnız AÇIK anahtar yüklenir; özel anahtarı sunucu dışında saklayın.');
        }

        $anahtarlar = array_keys($turler, 'pub', true);
        if (! $sonuc->successful() || count($anahtarlar) !== 1) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'Metin tek bir okunabilir açık anahtar içermiyor.');
        }

        $pub = $satirlar[$anahtarlar[0]];
        $parmakIzi = null;
        $kullanici = null;

        foreach (array_slice($satirlar, $anahtarlar[0] + 1) as $satir) {
            if ($satir[0] === 'sub') {
                break;
            }
            if ($satir[0] === 'fpr' && $parmakIzi === null) {
                $parmakIzi = strtoupper($satir[9] ?? '');
            }
            if ($satir[0] === 'uid' && $kullanici === null && ($satir[1] ?? '') !== 'r') {
                $kullanici = self::kullaniciKimligi($satir[9] ?? '');
            }
        }

        if ($parmakIzi === null || preg_match(self::PARMAK_IZI, $parmakIzi) !== 1) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'Açık anahtarın parmak izi okunamadı.');
        }

        $gecerlilik = $pub[1] ?? '';
        $bitis = preg_match('/^[1-9][0-9]{0,11}$/D', $pub[6] ?? '') === 1 ? CarbonImmutable::createFromTimestamp((int) $pub[6], 'UTC') : null;

        if ($gecerlilik === 'r') {
            throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'Açık anahtar iptal edilmiş.');
        }

        if ($gecerlilik === 'e' || ($bitis !== null && $bitis->isPast())) {
            throw new SifrelemeHatasi(SifrelemeHatasi::SURESI_DOLMUS, 'Açık anahtarın süresi dolmuş.');
        }

        // 12. alan: anahtarın bütününün kullanılabilir yetenekleri; büyük E = şifrelemeye uygun alt anahtar var
        if (! str_contains($pub[11] ?? '', 'E')) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ANAHTAR_GECERSIZ, 'Anahtarda şifrelemeye uygun (geçerli) bir alt anahtar yok.');
        }

        return ['parmak_izi' => $parmakIzi, 'kullanici' => $kullanici, 'bitis' => $bitis];
    }

    /**
     * İşi yeni bir geçici anahtarlıkta yapar; sonunda (hata yolunda da) ajanı
     * kapatır ve klasörü siler.
     *
     * @template T
     *
     * @param  Closure(string): T  $is
     * @return T
     *
     * @throws SifrelemeHatasi
     */
    private function geciciAnahtarlikla(Closure $is): mixed
    {
        // Geçersiz süre ayarı (YapilandirmaHatasi) kalıcıdır: yukarı taşınır (G9)
        $yas = $this->yapilandirma->artikKlasorYasi();

        try {
            // Anahtarlık küçük ve sır taşır (çözmede özel anahtar): sistem geçici klasöründe kalır.
            // Öldürülmüş bir işlemden kalan eski anahtarlıklar önce silinir (YF-28)
            $ev = GeciciKlasor::ac(sys_get_temp_dir(), 'emor-gpg-', $yas, 8);
        } catch (RuntimeException) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, 'Geçici anahtarlık klasörü açılamadı.', gecici: true);
        }

        $this->acikAnahtarliklar[$ev] = true;

        try {
            return $is($ev);
        } finally {
            $this->anahtarligiKapat($ev);
        }
    }

    /**
     * Bu nesnenin o anda açık geçici anahtarlıklarını kapatır ve siler.
     * Kesme sinyalinde (Ctrl-C, SIGTERM) `finally` çalışmaz: `yedek:sifre-coz`
     * sinyal yakalayıcısından çağrılır; içe alınan özel anahtar sunucuda kalmasın (YF-28).
     */
    public function acikAnahtarliklariSil(): void
    {
        foreach (array_keys($this->acikAnahtarliklar) as $ev) {
            $this->anahtarligiKapat($ev);
        }
    }

    /** Ajanı kapatır (hata yolunda da), klasörü siler */
    private function anahtarligiKapat(string $ev): void
    {
        foreach ([['--kill', 'all'], ['--remove-socketdir']] as $arguman) {
            try {
                Process::timeout(self::ANAHTAR_SURESI)->env(['GNUPGHOME' => false])
                    ->run([$this->yapilandirma->arac('gpgconf'), '--homedir', $ev, ...$arguman]);
            } catch (Throwable) {
                // Ajan zaten yoksa ya da kapatılamadıysa: klasör yine silinir, ajan soketi kaybolunca kendisi çıkar
            }
        }
        File::deleteDirectory($ev);
        unset($this->acikAnahtarliklar[$ev]);
    }

    /**
     * Çıktıyı hedefle aynı klasörde nokta önekli geçici ada yazdırır, başarıda
     * yerine taşır; hata yolunda geçici dosyayı siler (D3).
     *
     * @param  Closure(string): void  $yazici
     *
     * @throws SifrelemeHatasi
     */
    private function geciciyeYazipTasi(string $hedef, Closure $yazici): void
    {
        $gecici = AtomikDosya::geciciAd($hedef);

        try {
            $yazici($gecici);
            @chmod($gecici, 0600);

            if (! @rename($gecici, $hedef)) {
                throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, 'Çıktı dosyası yerine taşınamadı.', gecici: true);
            }
        } finally {
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }
    }

    /**
     * @param  list<string>  $argumanlar
     *
     * @throws SifrelemeHatasi
     */
    private function gpg(string $ev, array $argumanlar, int $sure): ProcessResult
    {
        try {
            return Process::timeout($sure)
                // Devralınan ortam kullanılan anahtarlığı ve dili gölgelemesin (G12)
                ->env(['GNUPGHOME' => false, 'GPG_AGENT_INFO' => false, 'LC_ALL' => 'C', 'LANGUAGE' => false])
                ->run([$this->yapilandirma->arac('gpg'), '--homedir', $ev, '--batch', '--no-tty', '--yes', '--status-fd', '1', ...$argumanlar]);
        } catch (YapilandirmaHatasi $hata) {
            // Araç yolu tanımlı değil: girdi değişmeden düzelmez
            throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, $hata->getMessage());
        } catch (ProcessTimedOutException) {
            throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, "gpg {$sure} sn süre sınırında bitmedi.", gecici: true);
        } catch (Throwable $hata) {
            // Süreç başlatılamadı (kaynak yetersizliği vb.)
            throw new SifrelemeHatasi(SifrelemeHatasi::ARAC, 'gpg çalıştırılamadı ('.class_basename($hata).').', gecici: true);
        }
    }

    /**
     * gpg `--status-fd` satırları: anahtar sözcük => argüman metinleri.
     *
     * @return array<string, list<string>>
     */
    private static function durumSatirlari(string $cikti): array
    {
        $durum = [];

        foreach (preg_split('/\r?\n/', $cikti) ?: [] as $satir) {
            if (preg_match('/^\[GNUPG:\] ([A-Z_]+)(?: (.*))?$/D', $satir, $parca) === 1) {
                $durum[$parca[1]][] = $parca[2] ?? '';
            }
        }

        return $durum;
    }

    /**
     * Hata nedeni: yalnız durum satırlarının anahtar sözcükleri ve çıkış kodu
     * (ham stderr değil; D2).
     *
     * @param  array<string, list<string>>  $durum
     */
    private static function neden(array $durum, ProcessResult $sonuc): string
    {
        $bilinen = array_intersect(array_keys($durum), ['NO_PUBKEY', 'NO_SECKEY', 'DECRYPTION_FAILED', 'BADMDC', 'NODATA', 'ERROR', 'FAILURE', 'INV_RECP', 'KEYEXPIRED']);

        return 'gpg çıkış kodu '.($sonuc->exitCode() ?? '?').($bilinen === [] ? '' : ', '.implode(', ', $bilinen));
    }

    /**
     * Sütun biçimindeki kullanıcı kimliği: `\x3a` kaçışları çözülür, denetim
     * karakterleri atılır; UTF-8 değilse yok sayılır.
     */
    private static function kullaniciKimligi(string $alan): ?string
    {
        $metin = (string) preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn (array $p): string => chr((int) hexdec($p[1])), $alan);
        $metin = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $metin));

        return $metin !== '' && mb_check_encoding($metin, 'UTF-8') ? Str::limit($metin, 250) : null;
    }
}
