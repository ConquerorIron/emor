<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Üstüne geri yüklemede onaydan önce yöneticiye gösterilenler (README §6.4
 * 9. adım, §7.6, §5.3; BY-029): aktif ERP ortamı, e-Fatura entegratörleri
 * (senkron), alarm kuralları, ayar farkları, kullanıcı ve rol farkı,
 * kaybolacak kesinti yüklemesi sayısı.
 *
 * Karşılaştırmanın iki yanı:
 * - Önceki: canlı veritabanının, bağlantıları kesilmeden HEMEN önceki özeti
 *   (6. adımdan önce). O anda uygulama bakım modunda, kuyruk ve zamanlayıcı
 *   durmuş, geri yükleme öncesi yedek alınmış: veri `erp_onceki_*`'ninkiyle
 *   aynıdır. Böylece bağlantıya kapalı `erp_onceki_*` hiç açılmaz.
 * - Geri yüklenen: yer değiştirmeden sonra canlı adın (geri yüklenen
 *   veritabanı) özeti.
 *
 * İkisi de durum klasöründe DOSYADA durur: ilerleme ucu bilgiyi veritabanına
 * dayanmadan okur (README §7.2); süreç ölse de önceki özet kaybolmaz.
 */
final class OnayBilgisi
{
    private const ONCEKI_DOSYASI = 'onay-onceki.json';

    private const BILGI_DOSYASI = 'onay-bilgisi.json';

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * Varsayılan bağlantıdaki (canlı ad) veritabanının özeti.
     *
     * @return array{aktif_erp_ortami: string|null, entegratorler: list<string>, alarm_kurallari: list<string>, kullanicilar: array<string, array{ad: string, aktif: bool, sistem_yoneticisi: bool, roller: list<string>}>, roller: array<string, list<string>>, yuklemeler: list<int>, ayarlar: array<string, array<string, mixed>>}
     */
    public function ozet(): array
    {
        $rolAdlari = DB::table('roller')->pluck('ad', 'id')->all();
        $kullaniciRolleri = [];
        foreach (DB::table('kullanici_rolleri')->get(['user_id', 'rol_id']) as $bag) {
            $kullaniciRolleri[(int) $bag->user_id][] = (string) ($rolAdlari[$bag->rol_id] ?? $bag->rol_id);
        }

        $kullanicilar = [];
        foreach (DB::table('users')->orderBy('kullanici_adi')->get(['id', 'kullanici_adi', 'ad', 'aktif_mi', 'sistem_yoneticisi']) as $k) {
            $roller = $kullaniciRolleri[(int) $k->id] ?? [];
            sort($roller);
            $kullanicilar[(string) $k->kullanici_adi] = ['ad' => (string) $k->ad, 'aktif' => (bool) $k->aktif_mi, 'sistem_yoneticisi' => (bool) $k->sistem_yoneticisi, 'roller' => $roller];
        }

        $roller = array_fill_keys(array_map('strval', array_values($rolAdlari)), []);
        foreach (DB::table('rol_izinleri')->orderBy('izin')->get(['rol_id', 'izin']) as $izin) {
            $roller[(string) ($rolAdlari[$izin->rol_id] ?? $izin->rol_id)][] = (string) $izin->izin;
        }

        $ortam = DB::table('sql_baglantilari')->where('aktif', true)->value('ortam');

        return [
            'aktif_erp_ortami' => $ortam === null ? null : (string) $ortam,
            'entegratorler' => DB::table('entegrator_baglantilari')->where('aktif', true)->orderBy('saglayici')->orderBy('ortam')
                ->get(['saglayici', 'ortam'])->map(fn (object $e): string => $e->saglayici.':'.$e->ortam)->values()->all(),
            'alarm_kurallari' => DB::table('alarm_kurallari')->where('aktif', true)->orderBy('tur')->pluck('tur')->map(fn (mixed $t): string => (string) $t)->values()->all(),
            'kullanicilar' => $kullanicilar,
            'roller' => $roller,
            'yuklemeler' => DB::table('kesinti_yuklemeleri')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'ayarlar' => $this->ayarlar(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $onceki  ozet() — bilinmiyorsa null (o kısımlar boş döner)
     * @param  array<string, mixed>  $geriYuklenen  ozet()
     * @return array<string, mixed> Ekrana giden bilgi (BY-030 API sözleşmesi)
     */
    public function karsilastir(?array $onceki, array $geriYuklenen): array
    {
        $taraf = fn (string $anahtar): array => [
            'onceki' => $onceki[$anahtar] ?? null,
            'geri_yuklenen' => $geriYuklenen[$anahtar],
            'farkli' => $onceki !== null && $onceki[$anahtar] !== $geriYuklenen[$anahtar],
        ];

        return [
            'onceki_bilinmiyor' => $onceki === null,
            'aktif_erp_ortami' => $taraf('aktif_erp_ortami'),
            'entegratorler' => $taraf('entegratorler'),
            'alarm_kurallari' => $taraf('alarm_kurallari'),
            'ayar_farklari' => $onceki === null ? [] : $this->ayarFarklari($onceki['ayarlar'], $geriYuklenen['ayarlar']),
            'kullanici_farklari' => $onceki === null ? [] : $this->kullaniciFarklari($onceki['kullanicilar'], $geriYuklenen['kullanicilar']),
            'rol_farklari' => $onceki === null ? [] : $this->rolFarklari($onceki['roller'], $geriYuklenen['roller']),
            'kaybolacak_yukleme_sayisi' => $onceki === null ? null : count(array_diff($onceki['yuklemeler'], $geriYuklenen['yuklemeler'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $ozet
     */
    public function oncekiYaz(string $islemKimligi, array $ozet): void
    {
        $this->yaz(self::ONCEKI_DOSYASI, $islemKimligi, $ozet);
    }

    /**
     * @return array<string, mixed>|null Bu işlemin önceki özeti; yoksa null
     */
    public function oncekiOku(string $islemKimligi): ?array
    {
        return $this->oku(self::ONCEKI_DOSYASI, $islemKimligi);
    }

    /**
     * @param  array<string, mixed>  $bilgi  karsilastir()
     */
    public function bilgiYaz(string $islemKimligi, array $bilgi): void
    {
        $this->yaz(self::BILGI_DOSYASI, $islemKimligi, $bilgi);
    }

    /**
     * @return array<string, mixed>|null Bu işlemin onay bilgisi; yoksa null
     */
    public function bilgiOku(string $islemKimligi): ?array
    {
        return $this->oku(self::BILGI_DOSYASI, $islemKimligi);
    }

    /**
     * Ayar farkları (README §5.3): önceki = bugünkü ayarlar, geri yüklenen =
     * yedekteki ayarlar. Sırlar karşılaştırılmaz.
     *
     * @param  array<string, array<string, mixed>>  $onceki
     * @param  array<string, array<string, mixed>>  $geri
     * @return list<array{bolum: string, ad: string|null, degisiklik: string, alanlar: list<string>}>
     */
    private function ayarFarklari(array $onceki, array $geri): array
    {
        $farklar = [];

        foreach (['yedek_ayarlari', 'mail'] as $bolum) {
            $alanlar = $this->farkliAlanlar($onceki[$bolum] ?? [], $geri[$bolum] ?? []);
            if ($alanlar !== []) {
                $farklar[] = ['bolum' => $bolum, 'ad' => null, 'degisiklik' => 'degisti', 'alanlar' => $alanlar];
            }
        }

        foreach (['planlar', 'hedefler'] as $bolum) {
            $o = $onceki[$bolum] ?? [];
            $g = $geri[$bolum] ?? [];
            foreach (array_unique([...array_keys($o), ...array_keys($g)]) as $ad) {
                $ad = (string) $ad;
                $degisiklik = match (true) {
                    ! isset($g[$ad]) => 'geri_yuklenende_yok',
                    ! isset($o[$ad]) => 'yalniz_geri_yuklenende',
                    default => $this->farkliAlanlar($o[$ad], $g[$ad]) === [] ? null : 'degisti',
                };
                if ($degisiklik !== null) {
                    $farklar[] = ['bolum' => $bolum, 'ad' => $ad, 'degisiklik' => $degisiklik, 'alanlar' => $degisiklik === 'degisti' ? $this->farkliAlanlar($o[$ad], $g[$ad]) : []];
                }
            }
        }

        return $farklar;
    }

    /**
     * Yedekten sonra pasife alınan, yöneticiliği kaldırılan ya da rolü
     * değişen kullanıcılar geri yüklenen veritabanında eski hâlindedir (§7.6).
     *
     * @param  array<string, array{ad: string, aktif: bool, sistem_yoneticisi: bool, roller: list<string>}>  $onceki
     * @param  array<string, array{ad: string, aktif: bool, sistem_yoneticisi: bool, roller: list<string>}>  $geri
     * @return list<array<string, mixed>>
     */
    private function kullaniciFarklari(array $onceki, array $geri): array
    {
        $farklar = [];
        foreach (array_unique([...array_keys($onceki), ...array_keys($geri)]) as $ad) {
            $ad = (string) $ad;
            $o = $onceki[$ad] ?? null;
            $g = $geri[$ad] ?? null;
            $alanlar = $o !== null && $g !== null ? $this->farkliAlanlar(array_diff_key($o, ['ad' => true]), array_diff_key($g, ['ad' => true])) : [];

            $degisiklik = match (true) {
                $g === null => 'geri_yuklenende_yok',
                $o === null => 'yalniz_geri_yuklenende',
                $alanlar !== [] => 'degisti',
                default => null,
            };

            if ($degisiklik !== null) {
                $farklar[] = ['kullanici_adi' => $ad, 'ad' => ($g ?? $o)['ad'], 'degisiklik' => $degisiklik, 'alanlar' => $alanlar, 'onceki' => $o, 'geri_yuklenen' => $g];
            }
        }

        return $farklar;
    }

    /**
     * @param  array<string, list<string>>  $onceki
     * @param  array<string, list<string>>  $geri
     * @return list<array{rol: string, degisiklik: string, geri_gelen_izinler: list<string>, kaybolan_izinler: list<string>}>
     */
    private function rolFarklari(array $onceki, array $geri): array
    {
        $farklar = [];
        foreach (array_unique([...array_keys($onceki), ...array_keys($geri)]) as $rol) {
            $rol = (string) $rol;
            $o = $onceki[$rol] ?? null;
            $g = $geri[$rol] ?? null;
            // Geri gelen: bugün yok, geri yüklenende var (kaldırılmış izin geri döner)
            $gelen = array_values(array_diff($g ?? [], $o ?? []));
            $giden = array_values(array_diff($o ?? [], $g ?? []));

            $degisiklik = match (true) {
                $g === null => 'geri_yuklenende_yok',
                $o === null => 'yalniz_geri_yuklenende',
                $gelen !== [] || $giden !== [] => 'degisti',
                default => null,
            };

            if ($degisiklik !== null) {
                $farklar[] = ['rol' => $rol, 'degisiklik' => $degisiklik, 'geri_gelen_izinler' => $gelen, 'kaybolan_izinler' => $giden];
            }
        }

        return $farklar;
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $g
     * @return list<string>
     */
    private function farkliAlanlar(array $o, array $g): array
    {
        $alanlar = [];
        foreach (array_unique([...array_keys($o), ...array_keys($g)]) as $alan) {
            if (($o[$alan] ?? null) !== ($g[$alan] ?? null)) {
                $alanlar[] = (string) $alan;
            }
        }
        sort($alanlar);

        return $alanlar;
    }

    /**
     * Ayarlar, sırlar hariç (mail parolası, hedef gizli bilgileri).
     *
     * @return array<string, array<string, mixed>>
     */
    private function ayarlar(): array
    {
        $json = fn (mixed $deger): mixed => is_string($deger) ? json_decode($deger, true) : $deger;

        $yedek = DB::table('yedek_ayarlari')->where('anahtar', 'varsayilan')->first([
            'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun', 'enaz_adet', 'enaz_bos_alan_yuzde', 'bildirim_alicilari',
        ]);
        $mail = DB::table('mail_ayarlari')->where('anahtar', 'varsayilan')->first([
            'sunucu', 'port', 'sifreleme', 'kullanici_adi', 'gonderen_adres', 'gonderen_ad', 'yonlendirme_adresi',
        ]);

        $planlar = [];
        foreach (DB::table('yedek_planlari')->orderBy('ad')->get(['ad', 'aktif', 'siklik_turu', 'saat', 'siklik']) as $p) {
            $planlar[(string) $p->ad] = ['aktif' => (bool) $p->aktif, 'siklik_turu' => (string) $p->siklik_turu, 'saat' => (string) $p->saat, 'siklik' => $json($p->siklik)];
        }

        $hedefler = [];
        foreach (DB::table('yedek_hedefleri')->orderBy('ad')->get(['ad', 'tur', 'aktif', 'baglanti']) as $h) {
            $hedefler[(string) $h->ad] = ['tur' => (string) $h->tur, 'aktif' => (bool) $h->aktif, 'baglanti' => $json($h->baglanti)];
        }

        $yedekDizi = $yedek === null ? [] : (array) $yedek;
        if (isset($yedekDizi['bildirim_alicilari'])) {
            $yedekDizi['bildirim_alicilari'] = $json($yedekDizi['bildirim_alicilari']);
        }

        return [
            'yedek_ayarlari' => $yedekDizi,
            'mail' => $mail === null ? [] : (array) $mail,
            'planlar' => $planlar,
            'hedefler' => $hedefler,
        ];
    }

    /**
     * @param  array<string, mixed>  $veri
     */
    private function yaz(string $dosya, string $islemKimligi, array $veri): void
    {
        $klasor = $this->yapilandirma->durumKlasoru();
        KaliciKlasor::ac($klasor, $this->yapilandirma->kaliciKok());

        AtomikDosya::yaz($klasor.'/'.$dosya, json_encode([
            'kimlik' => $islemKimligi,
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
            'veri' => $veri,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oku(string $dosya, string $islemKimligi): ?array
    {
        $icerik = @file_get_contents($this->yapilandirma->durumKlasoru().'/'.$dosya);
        if (! is_string($icerik)) {
            return null;
        }

        try {
            $kayit = json_decode($icerik, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($kayit) && ($kayit['kimlik'] ?? null) === $islemKimligi && is_array($kayit['veri'] ?? null) ? $kayit['veri'] : null;
    }
}
