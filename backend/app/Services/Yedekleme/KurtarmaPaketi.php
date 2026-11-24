<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
 * 4. Ancak ondan sonra eski sürümler silinir: son SAKLANAN_SURUM tamam sürüm
 *    (yeni + bir önceki) kalır. Silmede önce işaretçi, sonra bileşenler;
 *    işaretçi silinemezse sürümün hiçbir bileşenine dokunulmaz (BY-024-R4):
 *    hedefte "tamam" görünen bir set hiçbir an eksik kalmaz.
 *    İstisna (BY-024-R6, BY-024-R8; D4): bu hedefte dosyası duran ya da
 *    durabilecek (`tamam`, `konum_belirsiz`: KopyaDurumu::yedegiTutanlar) bir
 *    kopyanın yedeğini doğrulanmış olarak taşıyan EN YENİ sürüm kalacaklardan
 *    değilse (yerel manifest kayboldu / bozuldu, yeni paketler o yedeği artık
 *    doğrulayamıyor) yaşına bakılmadan korunur: o uzak dump'ın tek kurtarma
 *    dayanağıdır. Koruma, yeni bir sürüm o yedeği yeniden doğrulayınca ya da
 *    kopya saklamadan çıkınca (silindi, başarısız) kalkar; korunan sürüm
 *    denetim kaydında (`korunan_eski_surum`) ve günlükte uyarı olarak görünür.
 *    Konumu belirsiz kopyanın dayanağı korunur ama kopya hazır sayılmaz (5).
 * 5. Kurtarmaya hazırlık hedefte KALAN tamam sürümlerden yeniden hesaplanır
 *    (`kurtarma_hazir`; BY-010-R2, BY-024-R3, BY-024-R5; D4): kopya, yedeğin
 *    kimliği ve katalogdaki SHA-256'sı kayıttaki tamam sürümlerden birinin
 *    DOĞRULANMIŞ manifest listesinde varsa hazırdır, yoksa değildir —
 *    dayanağı kalmayan (listesiz eski biçim, saklamadan çıkan kopya) işaret
 *    geri alınır. Saklama, hazır uzak kopyası olmayan yedeğin yerel arşivini
 *    silmez.
 *
 * Manifest arşivi yedek klasöründeki manifestlerin ANLIK KOPYASINDAN kurulur;
 * her kopya arşive girmeden önce taramayla aynı okuyucuyla (ManifestDeposu::oku:
 * imza, şema) doğrulanır. Doğrulanmış sayılan yalnız imzası tutan ve arşivdeki
 * adı kendi yedeğinin adından türeyen manifesttir (kurtarmada dump adıyla
 * aranır; başka yedeğin manifesti o adla bulunsa kurtarma onu reddeder).
 * Sürüm kaydı her sürüm için bu listeyi (yedek kimliği → manifestteki
 * SHA-256) tutar; listesi olmayan (eski biçim) sürüm hazırlığa dayanak olmaz.
 * Arşiv yine bütün manifestleri taşır: geçersiz olanı kurtarma reddeder.
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
 * değeridir. Kopya yine de kurtarmaya hazır OLMAZ; yerel arşivi saklama
 * tutar (5. adım). Başarılı paket de kopyayı tek başına hazır yapmaz: uzak
 * yaşam sinyali ayrıca kopyanın güncellenmiş `kurtarma_hazir`'ına bakar
 * (YedekKopyalayici; BY-024-R7). Kopyalanan yedeğin manifesti bu pakette
 * doğrulandı mı, denetim ayrıntısında `yedek_manifesti_dogrulandi`.
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
        private readonly ManifestDeposu $manifestDeposu,
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
        $temizlik = ['silinemeyen' => 0, 'korunan' => []];
        $yedekDogrulandi = null;
        $hataMesaji = null;

        try {
            // Öldürülmüş bir işlemden kalan (günlüklerin düz hâlini tutan) eski klasörler önce silinir (YF-28)
            $calisma = GeciciKlasor::ac(sys_get_temp_dir(), 'emor-kurtarma-', $this->yapilandirma->artikKlasorYasi());

            $baglayici = $this->katalog->olustur($hedef);

            // 1. Önce hepsi şifrelenir: şifreleme hatasında hedefe hiçbir şey gitmez.
            // Manifestler bir kez anlık kopyalanıp doğrulanır; arşive o baytlar girer (BY-024-R5)
            $manifestler = $this->manifestleriKopyala($calisma.'/manifestler');
            $sifreliler = [];
            foreach ($this->kaynaklar($calisma, $manifestler['adlar']) as $bilesen => $yerel) {
                if ($yerel === null) {
                    $atlanan[] = $bilesen;

                    continue;
                }

                $sifreliler[$bilesen] = $this->sifreleyici->sifrele($yerel, $calisma.'/'.$bilesen);
            }

            // Sürüm kayda yarım olarak girer; imza anahtarı yoksa hedefe hiçbir şey gitmeden durur
            $dogrulanmis = $manifestler['yedekler'];
            if ($yedekId !== null) {
                $yedek = Yedek::query()->find($yedekId, ['id', 'kimlik', 'sha256']);
                $yedekDogrulandi = $yedek?->sha256 !== null && ($dogrulanmis[$yedek->kimlik] ?? null) === $yedek->sha256;
            }
            $this->surumuKaydet($hedef, $surum, $yazilan, null, false, $dogrulanmis);

            // 2. Bileşenler: yaz → kayda ekle → geri oku → özeti denetle
            $dosyalar = [];
            foreach ($sifreliler as $bilesen => $yerel) {
                $ad = self::uzakAd($surum, $bilesen);
                $kimlik = $baglayici->yaz($yerel, $ad);
                $yazilan[] = $kimlik;
                $this->surumuKaydet($hedef, $surum, $yazilan, null, false, $dogrulanmis);
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
            $this->surumuKaydet($hedef, $surum, $yazilan, $isaretci, false, $dogrulanmis);
            $this->geriOkuDenetle($baglayici, $isaretci, $isaretciYolu, $calisma, $isaretciAdi);
            $this->surumuKaydet($hedef, $surum, $yazilan, $isaretci, true, $dogrulanmis);
            $tamamlandi = true;

            // 4. Eski sürümler: yenisi doğrulandıktan sonra; saklanan kopyanın son dayanağı korunur
            $temizlik = $this->eskileriSil($baglayici, $hedef);

            // 5. Hazırlık, hedefte kalan tamam sürümlerin doğrulanmış manifestlerinden
            $this->hazirligiGuncelle($hedef);
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
                [
                    'hedef' => $hedef->ad, 'surum' => $surum, 'gonderilen' => $gonderilen, 'atlanan' => $atlanan,
                    'silinemeyen_eski_surum' => $temizlik['silinemeyen'], 'korunan_eski_surum' => $temizlik['korunan'],
                    ...($yedekDogrulandi === null ? [] : ['yedek_manifesti_dogrulandi' => $yedekDogrulandi]),
                ],
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
     * tamam sürümden eskiler silinir; hedefte duran ya da durabilecek bir
     * kopyanın son kurtarma dayanağı olan eski sürüm silinmez (BY-024-R6,
     * BY-024-R8, korunacakDayanaklar).
     * Bir sürümün silinememesi diğerlerini durdurmaz; silinemeyen kayıtta kalır.
     *
     * @return array{silinemeyen: int, korunan: list<string>} Silinemeyen sürüm sayısı; dayanak olduğu için korunan sürümler
     */
    private function eskileriSil(HedefBaglayicisi $baglayici, YedekHedefi $hedef): array
    {
        $surumler = $this->kayitlar()[(string) $hedef->id] ?? [];
        $tamamlar = array_keys(array_filter($surumler, fn (array $s): bool => $s['tamam']));
        $kalacak = array_slice($tamamlar, -self::SAKLANAN_SURUM);
        $korunan = $this->korunacakDayanaklar($hedef, $surumler, $kalacak);
        $silinemeyen = 0;

        foreach (array_keys($surumler) as $sira) {
            if (in_array($sira, $kalacak, true) || isset($korunan[$sira])) {
                continue;
            }

            try {
                $this->surumuSil($baglayici, $hedef, $surumler[$sira]);
            } catch (Throwable) {
                $silinemeyen++;
            }
        }

        $korunanSurumler = array_map(fn (int $sira): string => $surumler[$sira]['surum'], array_keys($korunan));
        if ($korunanSurumler !== []) {
            Log::channel('yedekleme')->warning('Kurtarma paketi: hedefte duran ya da durabilecek (tamam / konumu belirsiz) uzak kopyaların tek doğrulanmış manifestini taşıyan eski sürüm silinmedi; yedek klasöründeki manifestlerini denetleyin', [
                'kod' => 'KURTARMA_ESKI_SURUM_KORUNDU',
                'hedef_id' => $hedef->id,
                'surumler' => $korunanSurumler,
                'yedekler' => array_merge(...array_values($korunan)),
            ]);
        }

        return ['silinemeyen' => $silinemeyen, 'korunan' => $korunanSurumler];
    }

    /**
     * Kalacaklar dışında tutulması gereken sürümler (BY-024-R6; D4): bu
     * hedefte dosyası duran ya da durabilecek (KopyaDurumu::yedegiTutanlar:
     * `tamam`, `konum_belirsiz`) her kopya için, yedeğinin kimliğini ve
     * katalogdaki SHA-256'sını doğrulanmış listesinde taşıyan EN YENİ tamam
     * sürüm. O sürüm kalacaklardan biriyse yenisi zaten dayanaktır (bir şey
     * korunmaz); değilse kalacakların hiçbiri o yedeği doğrulamıyordur ve
     * sürüm uzak dump'ın son kurtarma dayanağıdır. Eşleşme ölçütü
     * hazirligiGuncelle'ninkiyle aynıdır: korunan sürüm kayıtta tamam kaldığı
     * için `tamam` kopya hazır kalır.
     *
     * Kopya kümesi hazırlıktan GENİŞTİR (BY-024-R8; G21 "kullanılamaz ile yok
     * ayrı kümelerdir"): konumu belirsiz kopya kullanılamaz (hazır sayılmaz,
     * uzak yaşam sinyali getirmez) ama dosyası hedefte durabilir; dayanağını
     * silmek o şifreli dump'ı hedef doğrulandığında açılamaz bırakırdı.
     *
     * @param  list<array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null, yedekler: array<string, string>|null}>  $surumler
     * @param  list<int>  $kalacak
     * @return array<int, list<string>> Sürümün sırası => tek dayanağı olduğu yedek kimlikleri
     */
    private function korunacakDayanaklar(YedekHedefi $hedef, array $surumler, array $kalacak): array
    {
        $korunan = [];
        YedekKopyasi::query()
            ->where('yedek_hedefi_id', $hedef->id)
            ->whereIn('durum', KopyaDurumu::yedegiTutanlar())
            ->with('yedek:id,kimlik,sha256')
            ->get(['id', 'yedek_id'])
            ->each(function (YedekKopyasi $k) use ($surumler, $kalacak, &$korunan): void {
                $kimlik = $k->yedek?->kimlik;
                $ozet = $k->yedek?->sha256;
                if ($kimlik === null || $ozet === null) {
                    return;
                }

                $dayanak = null;
                foreach ($surumler as $sira => $s) {
                    if ($s['tamam'] && ($s['yedekler'][$kimlik] ?? null) === $ozet) {
                        $dayanak = $sira;
                    }
                }

                if ($dayanak !== null && ! in_array($dayanak, $kalacak, true)) {
                    $korunan[$dayanak][] = $kimlik;
                }
            });

        return $korunan;
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
            $this->surumuSil($baglayici, $hedef, ['surum' => $surum, 'tamam' => false, 'kimlikler' => $yazilan, 'isaretci' => $isaretci, 'yedekler' => null]);
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
     * Silinmeye başlanan sürüm artık tamam değildir: hazırlığa dayanak olmaz
     * (doğrulanmış manifest listesi kayıttan düşer).
     *
     * @param  array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null, yedekler: array<string, string>|null}  $kayit
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
            $this->surumuKaydet($hedef, $kayit['surum'], $kalan, $kayit['isaretci'], false, null, sil: $hedefeBirakildi || ($kalan === [] && $kayit['isaretci'] === null));
        }
    }

    /**
     * Bu hedefteki kopyaların kurtarmaya hazırlığını, sürüm kaydında KALAN
     * tamam sürümlerin doğrulanmış manifest listelerinden yeniden hesaplar
     * (BY-010-R2, BY-024-R5; D4). Kopya hazırdır ⇔ `tamam` ve yedeğin kimliği
     * ile katalogdaki SHA-256'sı o listelerden birinde aynen var. Yalnız
     * değişen kopyalar güncellenir; dayanağı kalmayan işaret geri alınır.
     * Konumu belirsiz kopya dayanağı korunsa da (korunacakDayanaklar) hazır
     * DEĞİLDİR: kullanılamaz, işareti varsa geri alınır (BY-024-R8).
     *
     * @throws RuntimeException|ImzaAnahtariYok|JsonException
     */
    private function hazirligiGuncelle(YedekHedefi $hedef): void
    {
        $dayanak = [];
        foreach ($this->kayitlar()[(string) $hedef->id] ?? [] as $s) {
            if ($s['tamam'] && $s['yedekler'] !== null) {
                foreach ($s['yedekler'] as $kimlik => $ozet) {
                    $dayanak[$kimlik.' '.$ozet] = true;
                }
            }
        }

        $degisen = ['hazir' => [], 'degil' => []];
        YedekKopyasi::query()
            ->where('yedek_hedefi_id', $hedef->id)
            ->where(fn (Builder $q): Builder => $q->where('kurtarma_hazir', true)->orWhere('durum', KopyaDurumu::Tamam->value))
            ->with('yedek:id,kimlik,sha256')
            ->get(['id', 'yedek_id', 'durum', 'kurtarma_hazir'])
            ->each(function (YedekKopyasi $k) use ($dayanak, &$degisen): void {
                $hazir = $k->durum === KopyaDurumu::Tamam && $k->yedek?->sha256 !== null
                    && isset($dayanak[$k->yedek->kimlik.' '.$k->yedek->sha256]);
                if ($hazir !== $k->kurtarma_hazir) {
                    $degisen[$hazir ? 'hazir' : 'degil'][] = $k->id;
                }
            });

        foreach (['hazir' => true, 'degil' => false] as $anahtar => $deger) {
            if ($degisen[$anahtar] !== []) {
                YedekKopyasi::query()->whereKey($degisen[$anahtar])->update(['kurtarma_hazir' => $deger]);
            }
        }
    }

    /**
     * Bir sürümün kaydını ekler / günceller ya da (sil) çıkarır.
     *
     * @param  list<string>  $kimlikler
     * @param  array<string, string>|null  $yedekler  Sürümün manifest arşivindeki doğrulanmış manifestler (yedek kimliği => SHA-256); null = bilinmiyor
     *
     * @throws RuntimeException|ImzaAnahtariYok|JsonException
     */
    private function surumuKaydet(YedekHedefi $hedef, string $surum, array $kimlikler, ?string $isaretci, bool $tamam, ?array $yedekler, bool $sil = false): void
    {
        $tum = $this->kayitlar();
        $surumler = $tum[(string) $hedef->id] ?? [];
        $yeni = ['surum' => $surum, 'tamam' => $tamam, 'kimlikler' => $kimlikler, 'isaretci' => $isaretci, 'yedekler' => $yedekler];
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
     * @param  list<string>  $manifestAdlari  manifestleriKopyala()['adlar'] (anlık kopyalar `$calisma/manifestler` altında)
     * @return array<string, string|null>
     */
    private function kaynaklar(string $calisma, array $manifestAdlari): array
    {
        $ayarlar = $this->yapilandirma->ayarKopyasiYolu();

        return [
            self::OLAYLAR => $this->anlikKopya([$this->olaylar->yol()], $calisma.'/olaylar.jsonl'),
            self::AYARLAR => $this->anlikKopya([$ayarlar], $calisma.'/ayarlar.json'),
            self::DENETIM => $this->anlikKopya($this->denetimGunlukleri(), $calisma.'/denetim.log'),
            self::MANIFESTLER => $this->manifestArsivi($calisma.'/manifestler.tar', $calisma.'/manifestler', $manifestAdlari),
        ];
    }

    /**
     * Yedek klasöründeki manifestleri (`erp_….json`, ada göre sıralı)
     * `$klasor`'e kopyalar ve her KOPYAYI taramanın okuyucusuyla doğrular
     * (ManifestDeposu::oku: imza, şema; G3). Arşiv bu kopyalardan kurulur:
     * doğrulanan baytlar arşive girenlerdir (yedek klasöründe arada değişen
     * dosya etkilemez). Doğrulanmış sayılan: imzası tutan VE adı kendi
     * yedeğinin dump adından türeyen manifest (ManifestDeposu::jsonYolu;
     * kurtarma manifesti dump adıyla arar, `yedek:manifest-dogrula` başka
     * yedeğinkini reddeder).
     *
     * @return array{adlar: list<string>, yedekler: array<string, string>} Bütün adlar; doğrulanmışlar (yedek kimliği => manifestteki SHA-256)
     *
     * @throws RuntimeException Manifest okunamadı / kopyalanamadı
     */
    private function manifestleriKopyala(string $klasor): array
    {
        $kaynak = $this->yapilandirma->klasor();
        $adlar = array_values(array_filter(
            array_map('basename', glob($kaynak.'/erp_*.json') ?: []),
            fn (string $ad): bool => preg_match('/^erp_[A-Za-z0-9._+-]{1,200}\.json$/D', $ad) === 1,
        ));
        sort($adlar, SORT_STRING);

        if ($adlar !== [] && ! @mkdir($klasor, 0700)) {
            throw new RuntimeException('Manifestlerin geçici klasörü açılamadı.');
        }

        $yedekler = [];
        foreach ($adlar as $ad) {
            $bayt = @file_get_contents($kaynak.'/'.$ad);
            if (! is_string($bayt) || @file_put_contents($klasor.'/'.$ad, $bayt) !== strlen($bayt)) {
                throw new RuntimeException("Manifest kurtarma paketine kopyalanamadı: {$ad}");
            }
            // Arşivde kaynağın izni ve zamanı kalsın (açılınca yedek klasöründeki gibi)
            @chmod($klasor.'/'.$ad, 0640);
            $zaman = @filemtime($kaynak.'/'.$ad);
            if ($zaman !== false) {
                @touch($klasor.'/'.$ad, $zaman);
            }

            $manifest = $this->manifestDeposu->oku($klasor.'/'.$ad)->manifest;
            if ($manifest !== null && ManifestDeposu::jsonYolu($manifest->dosyaAdi) === $ad) {
                $yedekler[$manifest->kimlik] = $manifest->sha256;
            }
        }

        return ['adlar' => $adlar, 'yedekler' => $yedekler];
    }

    /**
     * Verilen manifestlerin (anlık kopyalar, `$klasor` altında) tar arşivi:
     * göreli adlarla, `tar -xf … -C <yedek klasörü>` ile yerlerine açılır.
     * Manifest yoksa null.
     *
     * @param  list<string>  $adlar
     *
     * @throws RuntimeException
     */
    private function manifestArsivi(string $cikis, string $klasor, array $adlar): ?string
    {
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
     * @return array<string, list<array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null, yedekler: array<string, string>|null}>>
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

                // Eski biçimde (BY-024-R5 öncesi) liste yok: bilinmiyor, hazırlığa dayanak olmaz
                $yedekler = $s['yedekler'] ?? null;
                if ($yedekler !== null && ! $this->yedekListesiGecerliMi($yedekler)) {
                    return [];
                }

                $sonuc[(string) $hedefId][] = ['surum' => $s['surum'], 'tamam' => $s['tamam'], 'kimlikler' => $s['kimlikler'], 'isaretci' => $s['isaretci'], 'yedekler' => $yedekler];
            }
        }

        return $sonuc;
    }

    /**
     * Doğrulanmış manifest listesi: yedek kimliği (UUID) => SHA-256 (küçük hex).
     */
    private function yedekListesiGecerliMi(mixed $yedekler): bool
    {
        if (! is_array($yedekler)) {
            return false;
        }

        foreach ($yedekler as $kimlik => $ozet) {
            if (! is_string($kimlik) || ! Str::isUuid($kimlik) || ! is_string($ozet) || preg_match('/^[0-9a-f]{64}$/D', $ozet) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<array{surum: string, tamam: bool, kimlikler: list<string>, isaretci: string|null, yedekler: array<string, string>|null}>>  $hedefler
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
