<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Kurtarma paketinin sunucu dışına gönderilmesi (README §4.6 son madde,
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
 * Paket SÜRÜMLÜDÜR (BY-024-R1): her gönderim yeni bir sürüm adıyla
 * (`emor-kurtarma-<sürüm>-<bileşen>`) yeni dosyalar yazar; hiçbir dosyanın
 * üstüne yazılmaz. Bileşenler yedeklerle AYNI açık anahtarla şifrelenir.
 * Sıra (D3, D4):
 *
 * 1. Bütün bileşenler yerelde şifrelenir (hata → hedefe hiçbir şey gitmez).
 * 2. Her bileşen yazılır, geri okunur, özeti denetlenir.
 * 3. Hepsi doğrulanınca sürümün işaretçisi (`emor-kurtarma-<sürüm>-tamam.json`:
 *    bileşenlerin adı, şifreli özeti, boyutu) yazılıp geri okunur. Hedefte
 *    "yetkili paket" = işaretçisi olan EN YENİ sürüm (sürüm adı UTC zamanla
 *    başlar, ada göre sıralanır). İşaretçisi olmayan sürüm yarımdır, okunmaz.
 * 4. Paket doğrulanınca manifest arşivine GİREN yedeklerin bu hedefteki
 *    `tamam` kopyaları KURTARMAYA HAZIR işaretlenir (`kurtarma_hazir`;
 *    BY-010-R2, BY-024-R3): boş sunucuda o kopya bu paketle açılabilir.
 *    Arşiv yedek klasöründeki bütün manifestleri taşıdığı için sonraki
 *    başarılı paket, önceki paketi yarım kalmış kopyaları da hazır yapar.
 *    Saklama, hazır uzak kopyası olmayan yedeğin yerel arşivini silmez.
 * 5. Ancak ondan sonra eski sürümler silinir: son SAKLANAN_SURUM tamam sürüm
 *    (yeni + bir önceki) kalır. Silmede önce işaretçi, sonra bileşenler;
 *    işaretçi silinemezse sürümün hiçbir bileşenine dokunulmaz (BY-024-R4):
 *    hedefte "tamam" görünen bir set hiçbir an eksik kalmaz.
 *
 * Arada hata olursa yeni sürümün yazılan dosyaları silinmeye çalışılır;
 * önceki tamam sürüm ve işaretçisi hiç değişmez. Silinemeyenler durum
 * klasöründeki imzalı sürüm kaydında kalır, sonraki başarılı gönderimde
 * yeniden silinir. Kayıt yoksa / imzası tutmuyorsa hiçbir uzak dosya silinmez.
 *
 * Yalnız ad, yaz, oku, sil kullanılır: adı değiştirme ya da üstüne yazma
 * gerekmez, bütün hedef türlerinde (yerel disk, NAS, FTP, SFTP, S3, Google
 * Drive) aynı çalışır. Adlar `erp_` ile başlamaz: bağlayıcıların listesine
 * (`erp_….dump|gpg`), dizine ve saklamaya girmez.
 *
 * Buradaki hata yedeğin kopyasını başarısız SAYMAZ: istisna dışarı taşmaz,
 * denetim kaydına (dosya + veritabanı) yazılır; sonuç `gonder()`'ün dönüş
 * değeridir (uzak yaşam sinyali buna bağlı — YedekKopyalayici). Kopya yine
 * de kurtarmaya hazır OLMAZ; yerel arşivi saklama tutar (4. adım).
 */
final class KurtarmaPaketi
{
    public const ON_EK = 'emor-kurtarma-';

    /** Bileşenler (uzak ad: ON_EK.<sürüm>-<bileşen>) */
    public const OLAYLAR = 'olaylar.jsonl.gpg';

    public const AYARLAR = 'ayarlar.json.gpg';

    public const DENETIM = 'denetim.log.gpg';

    public const MANIFESTLER = 'manifestler.tar.gpg';

    /** Sürümün tamam işaretçisi (şifresiz JSON: bileşen adları ve şifreli özetleri) */
    public const ISARETCI = 'tamam.json';

    /** Hedefte tutulan tamam sürüm sayısı: yeni + bir önceki (yenisi bozuk çıkarsa) */
    private const SAKLANAN_SURUM = 2;

    /** Durum klasöründe: hedef başına uzaktaki sürümler ve kimlikleri (imzalı) */
    private const SURUM_KAYDI = 'kurtarma-paketleri.json';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly HedefKatalogu $katalog,
        private readonly OlayGunlugu $olaylar,
        private readonly KopyaSifreleyici $sifreleyici,
        private readonly YedekDenetimi $denetim,
        private readonly YedekImzasi $imza,
    ) {}

    public static function uzakAd(string $surum, string $bilesen): string
    {
        return self::ON_EK.$surum.'-'.$bilesen;
    }

    /**
     * @return bool Yeni sürüm bütün bileşenleri ve işaretçisiyle yazılıp doğrulandı mı (hata istisna olarak dışarı taşmaz)
     */
    public function gonder(YedekHedefi $hedef, ?int $yedekId = null, ?int $kullaniciId = null): bool
    {
        $baslangic = hrtime(true);
        $surum = CarbonImmutable::now('UTC')->format('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
        $calisma = null;
        $baglayici = null;
        $gonderilen = [];
        $atlanan = [];
        /** @var list<string> $yazilan Yeni sürümün hedefe yazılan bileşenlerinin kimlikleri */
        $yazilan = [];
        $isaretci = null;
        $tamamlandi = false;
        $temizlenemeyen = 0;
        $hataMesaji = null;

        try {
            $calisma = sys_get_temp_dir().'/emor-kurtarma-'.bin2hex(random_bytes(6));
            if (! @mkdir($calisma, 0700)) {
                throw new RuntimeException('Geçici çalışma klasörü açılamadı.');
            }

            $baglayici = $this->katalog->olustur($hedef);

            // 1. Önce hepsi şifrelenir: şifreleme hatasında hedefe hiçbir şey gitmez.
            // Arşive giren manifestlerin listesi bir kez alınır: hazır işaretlenecek kopyalar bunlardır
            $manifestAdlari = $this->manifestAdlari();
            $sifreliler = [];
            foreach ($this->kaynaklar($calisma, $manifestAdlari) as $bilesen => $yerel) {
                if ($yerel === null) {
                    $atlanan[] = $bilesen;

                    continue;
                }

                $sifreliler[$bilesen] = $this->sifreleyici->sifrele($yerel, $calisma.'/'.$bilesen);
            }

            // Sürüm kayda yarım olarak girer; imza anahtarı yoksa hedefe hiçbir şey gitmeden durur
            $this->surumuKaydet($hedef, $surum, $yazilan, null, false);

            // 2. Bileşenler: yaz → kayda ekle → geri oku → özeti denetle
            $dosyalar = [];
            foreach ($sifreliler as $bilesen => $yerel) {
                $ad = self::uzakAd($surum, $bilesen);
                $kimlik = $baglayici->yaz($yerel, $ad);
                $yazilan[] = $kimlik;
                $this->surumuKaydet($hedef, $surum, $yazilan, null, false);
                $dosyalar[] = ['ad' => $ad, 'sha256' => $this->geriOkuDenetle($baglayici, $kimlik, $yerel, $calisma, $ad), 'boyut' => (int) filesize($yerel)];
                $gonderilen[] = $bilesen;
            }

            // 3. İşaretçi: ancak bütün bileşenler doğrulandıktan sonra
            $isaretciYolu = $calisma.'/'.self::ISARETCI;
            file_put_contents($isaretciYolu, json_encode([
                'bicim' => 1,
                'surum' => $surum,
                'olusturma' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
                'dosyalar' => $dosyalar,
                'atlanan' => $atlanan,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
            $isaretciAdi = self::uzakAd($surum, self::ISARETCI);
            $isaretci = $baglayici->yaz($isaretciYolu, $isaretciAdi);
            $this->surumuKaydet($hedef, $surum, $yazilan, $isaretci, false);
            $this->geriOkuDenetle($baglayici, $isaretci, $isaretciYolu, $calisma, $isaretciAdi);
            $this->surumuKaydet($hedef, $surum, $yazilan, $isaretci, true);
            $tamamlandi = true;

            // 4. Paketteki manifestlerin kopyaları kurtarmaya hazır (yalnız manifest bileşeni gittiyse)
            if (isset($sifreliler[self::MANIFESTLER])) {
                $this->hazirIsaretle($hedef, $manifestAdlari);
            }

            // 5. Eski sürümler: yenisi doğrulandıktan sonra
            $temizlenemeyen = $this->eskileriSil($baglayici, $hedef);
        } catch (Throwable $hata) {
            $hataMesaji = match (true) {
                $hata instanceof HedefHatasi, $hata instanceof SifrelemeHatasi => "{$hata->kod}: ".Str::limit($hata->getMessage(), 500),
                default => $hata::class.': '.Str::limit($hata->getMessage(), 300),
            };

            // Tamamlanmış sürüm (işaretçisi doğrulandı) sonraki bir adımın hatasıyla silinmez
            if (! $tamamlandi && $baglayici !== null && ($yazilan !== [] || $isaretci !== null)) {
                $this->yarimSurumuSil($baglayici, $hedef, $surum, $yazilan, $isaretci);
            }
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
                ['hedef' => $hedef->ad, 'surum' => $surum, 'gonderilen' => $gonderilen, 'atlanan' => $atlanan, 'silinemeyen_eski_surum' => $temizlenemeyen],
                $hataMesaji,
            );
        } catch (Throwable) {
            // Günlük dosyası da yazılamıyorsa yapılacak bir şey yok; kopyanın sonucu etkilenmez
        }

        return $hataMesaji === null;
    }

    /**
     * Hedeften geri okur, özeti yerel dosyanınkiyle karşılaştırır.
     *
     * @return string Doğrulanan SHA-256
     *
     * @throws HedefHatasi
     */
    private function geriOkuDenetle(HedefBaglayicisi $baglayici, string $kimlik, string $yerel, string $calisma, string $ad): string
    {
        $geri = $calisma.'/geri';
        $baglayici->oku($kimlik, $geri);
        $okunan = is_file($geri) ? hash_file('sha256', $geri) : false;
        @unlink($geri);
        $beklenen = (string) hash_file('sha256', $yerel);

        if (! is_string($okunan) || ! hash_equals($beklenen, $okunan)) {
            throw new HedefHatasi(HedefHatasi::OKUNAMADI, "Hedeften geri okunan {$ad} yazılanla aynı değil.");
        }

        return $beklenen;
    }

    /**
     * Tamamlanan sürümden sonra: yarım kalmış sürümler ve son SAKLANAN_SURUM
     * tamam sürümden eskiler silinir. Bir sürümün silinememesi diğerlerini
     * durdurmaz; silinemeyen kayıtta kalır.
     *
     * @return int Silinemeyen sürüm sayısı
     */
    private function eskileriSil(HedefBaglayicisi $baglayici, YedekHedefi $hedef): int
    {
        $surumler = $this->kayitlar()[(string) $hedef->id] ?? [];
        $tamamlar = array_keys(array_filter($surumler, fn (array $s): bool => $s['tamam']));
        $kalacak = array_slice($tamamlar, -self::SAKLANAN_SURUM);
        $silinemeyen = 0;

        foreach (array_keys($surumler) as $sira) {
            if (in_array($sira, $kalacak, true)) {
                continue;
            }

            try {
                $this->surumuSil($baglayici, $hedef, $surumler[$sira]);
            } catch (Throwable) {
                $silinemeyen++;
            }
        }

        return $silinemeyen;
    }

    /**
     * Yarıda kalan yeni sürümün yazılan dosyalarını silmeye çalışır. Hata
     * dışarı taşmaz: silinemeyenler kayıtta kalır (sonraki gönderimde silinir).
     *
     * Yazıldıysa işaretçi de (önce o) silinir: kayıt yazılamadığı için yarıda
     * kalan sürüm de "tamam" görünmesin.
     *
     * @param  list<string>  $yazilan
     */
    private function yarimSurumuSil(HedefBaglayicisi $baglayici, YedekHedefi $hedef, string $surum, array $yazilan, ?string $isaretci): void
    {
        try {
            $this->surumuSil($baglayici, $hedef, ['surum' => $surum, 'tamam' => false, 'kimlikler' => $yazilan, 'isaretci' => $isaretci]);
        } catch (Throwable) {
            // Silinemeyen kayıtta kalır; sonraki başarılı gönderim yeniden siler
        }
    }

    /**
     * Önce işaretçi (sürüm artık "tamam" görünmesin), sonra bileşenler.
     * Her silmeden sonra kayıt güncellenir; hepsi silinince sürüm kayıttan çıkar.
     *
     * İşaretçi silinemezse bileşenlere DOKUNULMAZ (BY-024-R4): işaretçi
     * hedefte durdukça sürüm kurtarmada "tamam" görünür, seti eksiltmek onu
     * okunamaz yapardı (D3).
     * - Geçici hata: sürüm işaretçisi ve bütün bileşenleriyle kayıtta kalır,
     *   sonraki başarılı gönderimde yeniden denenir.
     * - `SILME_YETKISI_YOK` (değiştirilemez saklama, silme izni yok): hata
     *   kendiliğinden düzelmez, her gönderimde yeniden denemek kaydı büyütürdü
     *   (D8). Set eksiksiz hâliyle hedefin kendi kuralına bırakılır (README
     *   §4.3 / §4.6 ile aynı karar) ve kayıttan çıkar.
     * İşaretçi silindikten sonra bileşende `SILME_YETKISI_YOK` gelirse bileşen
     * bizden çıkmış sayılır: sürüm artık "tamam" görünmez, kalan dosya zararsızdır.
     *
     * @param  array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null}  $kayit
     *
     * @throws Throwable İlk silinemeyen dosyada
     */
    private function surumuSil(HedefBaglayicisi $baglayici, YedekHedefi $hedef, array $kayit): void
    {
        $kalan = $kayit['kimlikler'];
        $hedefeBirakildi = false;

        try {
            if ($kayit['isaretci'] !== null) {
                try {
                    $baglayici->sil($kayit['isaretci']);
                } catch (HedefHatasi $hata) {
                    if ($hata->kod !== HedefHatasi::SILME_YETKISI_YOK) {
                        throw $hata;
                    }

                    $hedefeBirakildi = true;

                    return;
                }
                $kayit['isaretci'] = null;
            }

            while ($kalan !== []) {
                try {
                    $baglayici->sil($kalan[0]);
                } catch (HedefHatasi $hata) {
                    if ($hata->kod !== HedefHatasi::SILME_YETKISI_YOK) {
                        throw $hata;
                    }
                }
                array_shift($kalan);
            }
        } finally {
            $this->surumuKaydet($hedef, $kayit['surum'], $kalan, $kayit['isaretci'], false, sil: $hedefeBirakildi || ($kalan === [] && $kayit['isaretci'] === null));
        }
    }

    /**
     * Paketin manifest arşivine giren yedeklerin bu hedefteki `tamam`
     * kopyalarını kurtarmaya hazır işaretler (BY-010-R2). Manifest adı dump
     * adından türer (`erp_….json` ↔ `erp_….dump`; ManifestDeposu::jsonYolu).
     * Henüz hazır olmayan kopyalar azdır (son paketten sonra gelenler):
     * onlar okunup adla eşlenir.
     *
     * @param  list<string>  $manifestAdlari
     */
    private function hazirIsaretle(YedekHedefi $hedef, array $manifestAdlari): void
    {
        $arsivde = array_fill_keys($manifestAdlari, true);
        $idler = YedekKopyasi::query()
            ->where('yedek_hedefi_id', $hedef->id)
            ->where('durum', KopyaDurumu::Tamam->value)
            ->where('kurtarma_hazir', false)
            ->with('yedek:id,dosya_adi')
            ->get()
            ->filter(fn (YedekKopyasi $k): bool => $k->yedek !== null && isset($arsivde[ManifestDeposu::jsonYolu($k->yedek->dosya_adi)]))
            ->modelKeys();

        if ($idler !== []) {
            YedekKopyasi::query()->whereKey($idler)->update(['kurtarma_hazir' => true]);
        }
    }

    /**
     * Bir sürümün kaydını ekler / günceller ya da (sil) çıkarır.
     *
     * @param  list<string>  $kimlikler
     *
     * @throws RuntimeException|ImzaAnahtariYok|JsonException
     */
    private function surumuKaydet(YedekHedefi $hedef, string $surum, array $kimlikler, ?string $isaretci, bool $tamam, bool $sil = false): void
    {
        $tum = $this->kayitlar();
        $surumler = $tum[(string) $hedef->id] ?? [];
        $yeni = ['surum' => $surum, 'tamam' => $tamam, 'kimlikler' => $kimlikler, 'isaretci' => $isaretci];
        $bulundu = false;

        // Sıra = yazılış sırası (eskiden yeniye); var olan sürüm yerinde güncellenir
        foreach ($surumler as $i => $s) {
            if ($s['surum'] === $surum) {
                $bulundu = true;
                if ($sil) {
                    unset($surumler[$i]);
                } else {
                    $surumler[$i] = $yeni;
                }
            }
        }

        if (! $bulundu && ! $sil) {
            $surumler[] = $yeni;
        }

        $tum[(string) $hedef->id] = array_values($surumler);
        if ($tum[(string) $hedef->id] === []) {
            unset($tum[(string) $hedef->id]);
        }

        $this->kayitlariYaz($tum);
    }

    /**
     * Gönderilecek dosyaların anlık kopyaları (bileşen => yerel yol; dosya
     * yoksa null). Yazılmakta olan günlükler kilit altında kopyalanır: gpg
     * büyüyen dosyayı okumaz.
     *
     * @param  list<string>  $manifestAdlari  manifestAdlari()
     * @return array<string, string|null>
     */
    private function kaynaklar(string $calisma, array $manifestAdlari): array
    {
        $ayarlar = $this->yapilandirma->ayarKopyasiYolu();

        return [
            self::OLAYLAR => $this->anlikKopya([$this->olaylar->yol()], $calisma.'/olaylar.jsonl'),
            self::AYARLAR => $this->anlikKopya([$ayarlar], $calisma.'/ayarlar.json'),
            self::DENETIM => $this->anlikKopya($this->denetimGunlukleri(), $calisma.'/denetim.log'),
            self::MANIFESTLER => $this->manifestArsivi($calisma.'/manifestler.tar', $manifestAdlari),
        ];
    }

    /**
     * Yedek klasöründeki manifestlerin adları, sıralı.
     *
     * @return list<string>
     */
    private function manifestAdlari(): array
    {
        $adlar = array_values(array_filter(
            array_map('basename', glob($this->yapilandirma->klasor().'/erp_*.json') ?: []),
            fn (string $ad): bool => preg_match('/^erp_[A-Za-z0-9._+-]{1,200}\.json$/D', $ad) === 1,
        ));
        sort($adlar, SORT_STRING);

        return $adlar;
    }

    /**
     * Verilen manifestlerin tar arşivi (klasöre göre göreli adlar:
     * `tar -xf … -C <yedek klasörü>` ile yerlerine açılır). Manifest yoksa null.
     *
     * @param  list<string>  $adlar
     *
     * @throws RuntimeException
     */
    private function manifestArsivi(string $cikis, array $adlar): ?string
    {
        $klasor = $this->yapilandirma->klasor();

        if ($adlar === []) {
            return null;
        }

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
     * İmzası tutan sürüm kaydı (hedef kimliği => eskiden yeniye sürümler);
     * yoksa / bozuksa boş (o durumda hiçbir uzak dosya silinmez).
     *
     * @return array<string, list<array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null}>>
     */
    private function kayitlar(): array
    {
        $icerik = @file_get_contents($this->kayitYolu());
        if (! is_string($icerik)) {
            return [];
        }

        try {
            $veri = json_decode($icerik, true, 16, JSON_THROW_ON_ERROR);

            if (! is_array($veri) || ! is_array($veri['hedefler'] ?? null) || ! $this->imza->dogrula($veri['hedefler'], $veri['imza'] ?? null)) {
                return [];
            }
        } catch (JsonException|ImzaAnahtariYok) {
            return [];
        }

        $sonuc = [];
        foreach ($veri['hedefler'] as $hedefId => $surumler) {
            if (! is_array($surumler) || ! array_is_list($surumler)) {
                return [];
            }

            foreach ($surumler as $s) {
                if (! is_array($s) || ! is_string($s['surum'] ?? null) || ! is_bool($s['tamam'] ?? null)
                    || ! is_array($s['kimlikler'] ?? null) || ! array_is_list($s['kimlikler'])
                    || array_filter($s['kimlikler'], fn (mixed $k): bool => ! is_string($k)) !== []
                    || ! (is_string($s['isaretci'] ?? null) || ($s['isaretci'] ?? null) === null)) {
                    return [];
                }

                $sonuc[(string) $hedefId][] = ['surum' => $s['surum'], 'tamam' => $s['tamam'], 'kimlikler' => $s['kimlikler'], 'isaretci' => $s['isaretci']];
            }
        }

        return $sonuc;
    }

    /**
     * @param  array<string, list<array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null}>>  $hedefler
     *
     * @throws RuntimeException|ImzaAnahtariYok|JsonException
     */
    private function kayitlariYaz(array $hedefler): void
    {
        $yol = $this->kayitYolu();
        if (! is_dir(dirname($yol))) {
            KaliciKlasor::ac(dirname($yol), $this->yapilandirma->kaliciKok());
        }

        // İmza JSON'a çevrilip geri okunan biçim üzerinden (doğrulamayla aynı)
        $kanonik = json_decode(json_encode($hedefler, JSON_THROW_ON_ERROR), true);
        AtomikDosya::yaz($yol, json_encode(['hedefler' => $hedefler, 'imza' => $this->imza->imzala($kanonik)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function kayitYolu(): string
    {
        return $this->yapilandirma->durumKlasoru().'/'.self::SURUM_KAYDI;
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
