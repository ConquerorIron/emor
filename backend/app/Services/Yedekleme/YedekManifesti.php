<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Yedeğin yanındaki `.json` (README §5.3; şema sürüm 1 — Backup-Jira BY-006).
 * Yedeği tek başına tanımlar; yedek alındığı anda yazılır ve DEĞİŞMEZ.
 * Sonradan değişenler (sabitleme, doğrulama, kopyalar, silinme) olay
 * günlüğündedir (OlayGunlugu).
 *
 * İçe alınan eski yayın yedeklerinde (BY-013, `ice_alindi`) anlık görüntüye
 * bağlı alanlar (sayımlar, migration listesi, veritabanı özellikleri)
 * bilinmez: null'dır. Böyle bir yedek şema uyumu denetlenemediği için üstüne
 * geri yüklenemez; farklı ada geri yüklenebilir.
 */
final readonly class YedekManifesti
{
    public const SURUM = 1;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** Yol ayırıcısı yok: manifest başka bir dosyayı gösteremez */
    private const DOSYA_ADI = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,190}\.dump$/D';

    private const TARIH = 'Y-m-d\TH:i:sP';

    /**
     * @param  list<string>  $planAdlari  Yedeği alan planlar (aynı ana düşen planlar tek yedek alır)
     * @param  list<string>|null  $migrationlar  Uygulanmış migration'ların TAM listesi (anlık görüntüden)
     * @param  array<string, int>|null  $satirSayilari  Tablo => satır sayısı (anlık görüntüden; verisi alınmayanlar 0)
     * @param  list<string>  $verisiAlinmayanTablolar
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}|null  $veritabaniOzellikleri
     */
    public function __construct(
        public string $kimlik,
        public CarbonImmutable $alinmaZamani,
        public YedekKaynagi $kaynak,
        public array $planAdlari,
        public string $veritabani,
        public string $dosyaAdi,
        public int $boyut,
        public string $sha256,
        public ?string $pgSunucuSurumu,
        public ?string $pgDumpSurumu,
        public ?string $uygulamaSurumu,
        public ?array $migrationlar,
        public ?array $satirSayilari,
        public array $verisiAlinmayanTablolar,
        public ?array $veritabaniOzellikleri,
        public ?string $sahipRol,
        public bool $sifreli,
        public ?string $appKeyParmakIzi,
        public ?string $aciklama,
        public ?int $alanKullaniciId,
        public ?string $alanKullanici,
        public bool $iceAlindi = false,
        public ?string $geriYuklemeKimligi = null,
    ) {}

    /**
     * Şema uyumu (README §4.5) denetlenebilir mi: migration listesi ve
     * sayımlar anlık görüntüden alınmış olmalı.
     */
    public function semasiBiliniyor(): bool
    {
        return $this->migrationlar !== null && $this->satirSayilari !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function diziye(): array
    {
        return [
            'surum' => self::SURUM,
            'kimlik' => $this->kimlik,
            'alinma_zamani' => $this->alinmaZamani->format(self::TARIH),
            'kaynak' => $this->kaynak->value,
            'plan_adlari' => $this->planAdlari,
            'veritabani' => $this->veritabani,
            'dosya_adi' => $this->dosyaAdi,
            'boyut' => $this->boyut,
            'sha256' => $this->sha256,
            'pg_sunucu_surumu' => $this->pgSunucuSurumu,
            'pg_dump_surumu' => $this->pgDumpSurumu,
            'uygulama_surumu' => $this->uygulamaSurumu,
            'migrationlar' => $this->migrationlar,
            'satir_sayilari' => $this->satirSayilari,
            'verisi_alinmayan_tablolar' => $this->verisiAlinmayanTablolar,
            'veritabani_ozellikleri' => $this->veritabaniOzellikleri,
            'sahip_rol' => $this->sahipRol,
            'sifreli' => $this->sifreli,
            'app_key_parmak_izi' => $this->appKeyParmakIzi,
            'aciklama' => $this->aciklama,
            'alan_kullanici_id' => $this->alanKullaniciId,
            'alan_kullanici' => $this->alanKullanici,
            'ice_alindi' => $this->iceAlindi,
            'geri_yukleme_kimligi' => $this->geriYuklemeKimligi,
        ];
    }

    /**
     * Okunan (imzası doğrulanmış) verinin şema denetimi. Uymayan alan
     * ManifestGecersiz'dir; okuyucu bunu "güvenilmeyen" sınıflar, akışı kesmez.
     *
     * @param  mixed  $veri  json_decode sonucu
     *
     * @throws ManifestGecersiz
     */
    public static function diziden(mixed $veri): self
    {
        if (! is_array($veri) || array_is_list($veri)) {
            throw new ManifestGecersiz('manifest bir JSON nesnesi değil');
        }

        $alanlar = ['surum', 'kimlik', 'alinma_zamani', 'kaynak', 'plan_adlari', 'veritabani', 'dosya_adi', 'boyut',
            'sha256', 'pg_sunucu_surumu', 'pg_dump_surumu', 'uygulama_surumu', 'migrationlar', 'satir_sayilari',
            'verisi_alinmayan_tablolar', 'veritabani_ozellikleri', 'sahip_rol', 'sifreli', 'app_key_parmak_izi',
            'aciklama', 'alan_kullanici_id', 'alan_kullanici', 'ice_alindi', 'geri_yukleme_kimligi'];

        $eksik = array_diff($alanlar, array_keys($veri));
        if ($eksik !== []) {
            throw new ManifestGecersiz('eksik alan: '.implode(', ', $eksik));
        }

        $fazla = array_diff(array_keys($veri), $alanlar);
        if ($fazla !== []) {
            throw new ManifestGecersiz('şemada olmayan alan: '.implode(', ', $fazla));
        }

        if ($veri['surum'] !== self::SURUM) {
            throw new ManifestGecersiz('bilinmeyen şema sürümü');
        }

        foreach (['kimlik', 'geri_yukleme_kimligi'] as $alan) {
            if (($alan === 'kimlik' || $veri[$alan] !== null) && (! is_string($veri[$alan]) || preg_match(self::UUID, $veri[$alan]) !== 1)) {
                throw new ManifestGecersiz("{$alan} UUID değil");
            }
        }

        $kaynak = is_string($veri['kaynak']) ? YedekKaynagi::tryFrom($veri['kaynak']) : null;
        if ($kaynak === null) {
            throw new ManifestGecersiz('bilinmeyen kaynak');
        }

        if (! is_string($veri['dosya_adi']) || preg_match(self::DOSYA_ADI, $veri['dosya_adi']) !== 1) {
            throw new ManifestGecersiz('dosya adı geçersiz');
        }

        if (! is_int($veri['boyut']) || $veri['boyut'] < 1) {
            throw new ManifestGecersiz('boyut pozitif tam sayı değil');
        }

        if (! is_string($veri['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $veri['sha256']) !== 1) {
            throw new ManifestGecersiz('SHA-256 geçersiz');
        }

        if (! is_string($veri['veritabani']) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $veri['veritabani']) !== 1) {
            throw new ManifestGecersiz('veritabanı adı geçersiz');
        }

        foreach (['pg_sunucu_surumu', 'pg_dump_surumu', 'uygulama_surumu', 'sahip_rol', 'app_key_parmak_izi', 'aciklama', 'alan_kullanici'] as $alan) {
            if ($veri[$alan] !== null && ! is_string($veri[$alan])) {
                throw new ManifestGecersiz("{$alan} metin değil");
            }
        }

        foreach (['sifreli', 'ice_alindi'] as $alan) {
            if (! is_bool($veri[$alan])) {
                throw new ManifestGecersiz("{$alan} true / false değil");
            }
        }

        if ($veri['alan_kullanici_id'] !== null && (! is_int($veri['alan_kullanici_id']) || $veri['alan_kullanici_id'] < 1)) {
            throw new ManifestGecersiz('alan kullanıcı kimliği geçersiz');
        }

        $metinListesi = fn (mixed $liste): bool => is_array($liste) && array_is_list($liste)
            && array_filter($liste, fn (mixed $m): bool => ! is_string($m)) === [];

        if (! $metinListesi($veri['plan_adlari']) || ! $metinListesi($veri['verisi_alinmayan_tablolar'])) {
            throw new ManifestGecersiz('plan adları / verisi alınmayan tablolar metin listesi değil');
        }

        if ($veri['migrationlar'] !== null && ! $metinListesi($veri['migrationlar'])) {
            throw new ManifestGecersiz('migration listesi metin listesi değil');
        }

        $sayilar = $veri['satir_sayilari'];
        if ($sayilar !== null && (! is_array($sayilar) || ($sayilar !== [] && array_is_list($sayilar))
            || array_filter($sayilar, fn (mixed $s): bool => ! is_int($s) || $s < 0) !== [])) {
            throw new ManifestGecersiz('satır sayıları tablo => sayı değil');
        }

        if ($veri['migrationlar'] === null && ! $veri['ice_alindi']) {
            throw new ManifestGecersiz('migration listesi yalnız içe alınan eski yedekte boş olabilir');
        }

        return new self(
            kimlik: $veri['kimlik'],
            alinmaZamani: self::tarih($veri['alinma_zamani']),
            kaynak: $kaynak,
            planAdlari: $veri['plan_adlari'],
            veritabani: $veri['veritabani'],
            dosyaAdi: $veri['dosya_adi'],
            boyut: $veri['boyut'],
            sha256: $veri['sha256'],
            pgSunucuSurumu: $veri['pg_sunucu_surumu'],
            pgDumpSurumu: $veri['pg_dump_surumu'],
            uygulamaSurumu: $veri['uygulama_surumu'],
            migrationlar: $veri['migrationlar'],
            satirSayilari: $sayilar,
            verisiAlinmayanTablolar: $veri['verisi_alinmayan_tablolar'],
            veritabaniOzellikleri: self::ozellikler($veri['veritabani_ozellikleri']),
            sahipRol: $veri['sahip_rol'],
            sifreli: $veri['sifreli'],
            appKeyParmakIzi: $veri['app_key_parmak_izi'],
            aciklama: $veri['aciklama'],
            alanKullaniciId: $veri['alan_kullanici_id'],
            alanKullanici: $veri['alan_kullanici'],
            iceAlindi: $veri['ice_alindi'],
            geriYuklemeKimligi: $veri['geri_yukleme_kimligi'],
        );
    }

    /**
     * @return array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}|null
     *
     * @throws ManifestGecersiz
     */
    private static function ozellikler(mixed $deger): ?array
    {
        if ($deger === null) {
            return null;
        }

        if (! is_array($deger)
            || ! is_string($deger['kodlama'] ?? null)
            || ! is_string($deger['siralama'] ?? null)
            || ! is_string($deger['karakter_turu'] ?? null)
            || ! is_array($deger['ayarlar'] ?? null) || ! array_is_list($deger['ayarlar'])
            || array_filter($deger['ayarlar'], fn (mixed $a): bool => ! is_string($a)) !== []
            || ! array_key_exists('baglanma_yetkileri', $deger)
            || ($deger['baglanma_yetkileri'] !== null && ! is_string($deger['baglanma_yetkileri']))
            || count($deger) !== 5) {
            throw new ManifestGecersiz('veritabanı özellikleri şemaya uymuyor');
        }

        /** @var array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null} $deger */
        return $deger;
    }

    /**
     * Saat dilimi eki zorunlu; taşan değer (30 Şubat) geri biçimlenip
     * girdiyle karşılaştırılarak reddedilir (Dikkat_Edilecekler G11).
     *
     * @throws ManifestGecersiz
     */
    private static function tarih(mixed $deger): CarbonImmutable
    {
        if (! is_string($deger)) {
            throw new ManifestGecersiz('alınma zamanı metin değil');
        }

        try {
            $tarih = CarbonImmutable::createFromFormat(self::TARIH, $deger);
        } catch (Throwable) {
            $tarih = null;
        }

        if (! $tarih instanceof CarbonImmutable || $tarih->format(self::TARIH) !== $deger) {
            throw new ManifestGecersiz('alınma zamanı "YYYY-AA-GGTSS:DD:SS+SS:DD" biçiminde değil');
        }

        return $tarih;
    }
}
