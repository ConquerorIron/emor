<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * İşlem durumu dosyasının içeriği (şema sürüm 1 — Backup-Jira.md BY-005).
 * Dosyadan okunurken her alan şemaya göre denetlenir; uymayan dosya
 * IslemDurumuBozuk'tur, sessizce "işlem yok" sayılmaz.
 *
 * Kurtarma bağlamı (yedek kimliği, veritabanı adları, isteyen) dosyadadır:
 * üstüne geri yüklemede yer değiştirmeden sonra `geri_yuklemeler` satırı eski
 * veritabanında kalır; çökmeden sonra devam / geri sarma kararı ve geçmiş
 * kaydının yeniden yazılması yalnız bu dosyaya dayanabilir (README §6.4).
 *
 * Üstüne geri yüklemenin ek bağlamı (BY-028-R1/R2, BY-030-R1) — eski
 * dosyalarda yoktur, yoksa boş sayılır (sürüm aynı; yazılırken hep yazılır):
 * `bariyer` (yazıcı bariyerinden önceki ÖZGÜN bağlanma yetkileri), `ayar_secimleri`
 * (onayda yöneticinin ayar grubu seçimleri), `kullanilan_kanitlar` (tüketilmiş
 * karar kanıtlarının nonce'u → son geçerlilik anı; tek kullanım).
 */
final readonly class IslemKaydi
{
    public const SURUM = 1;

    private const ALANLAR = [
        'surum', 'kimlik', 'tur', 'adim', 'acik', 'baslangic', 'son_guncelleme', 'hata',
        'erisim_anahtari_ozeti', 'erisim_anahtari_bitis',
        'yedek_kimligi', 'hedef_veritabani', 'onceki_veritabani', 'isteyen_kullanici_id',
    ];

    /** Tutulan en çok tüketilmiş kanıt (süresi geçenler her yazımda düşer) */
    public const EN_COK_KANIT = 64;

    /** PostgreSQL `datacl::text` için üst sınır (bayt); veritabanı ACL'si bunun çok altındadır */
    private const ACL_EN_UZUN = 16_384;

    private const TARIH_BICIMI = 'Y-m-d\TH:i:s.uP';

    /** D bayrağı: onsuz `$` metin sonundaki satır sonunu da kabul eder */
    private const UUID_DESENI = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /** Tırnak gerektirmeyen PostgreSQL adı (en çok 63 bayt); ad SQL'e bu yüzden güvenle girer */
    private const VERITABANI_ADI_DESENI = '/^[a-z_][a-z0-9_]{0,62}$/D';

    /**
     * @param  string  $adim  İşlemi yürüten kodun adım adı (ör. "bakim_modu", "pg_restore", "onay_bekliyor")
     * @param  bool  $acik  İşlem uygulamayı kapalı tutuyor (bakım modu / kuyruk durmuş): yayın ve planlı yedek başlamaz
     * @param  array{kod: string, mesaj: string}|null  $hata
     * @param  string|null  $erisimAnahtariOzeti  İlerleme ve onay için verilen erişim anahtarının SHA-256 özeti (README §7.2)
     * @param  CarbonImmutable|null  $erisimAnahtariBitis  Anahtarın geçerliliğinin bittiği an; özetle birlikte verilir (süresiz anahtar yok)
     * @param  string|null  $yedekKimligi  İşlemin yedeği (alınan ya da geri yüklenen) — manifest kimliği
     * @param  string|null  $hedefVeritabani  Geri yüklemenin açtığı veritabanı (ör. erp_geri_*)
     * @param  string|null  $oncekiVeritabani  Üstüne geri yüklemede eski veritabanının yeni adı (erp_onceki_*)
     * @param  int|null  $isteyenKullaniciId  İşlemi isteyen; boşsa sistem / komut satırı
     * @param  array{baglanma_yetkileri: string|null}|null  $bariyer  Yazıcı bariyeri kurulmadan önceki özgün `datacl` (null = varsayılan yetkiler); bariyer kurulmadıysa null
     * @param  array<string, string>|null  $ayarSecimleri  Onayda seçilen ayar grupları: grup => guncel | geri_yuklenen (AyarSecimi)
     * @param  array<string, int>  $kullanilanKanitlar  Tüketilmiş karar kanıtları: nonce => son geçerlilik (Unix saniye)
     */
    public function __construct(
        public string $kimlik,
        public IslemTuru $tur,
        public string $adim,
        public bool $acik,
        public CarbonImmutable $baslangic,
        public CarbonImmutable $sonGuncelleme,
        public ?array $hata,
        public ?string $erisimAnahtariOzeti,
        public ?CarbonImmutable $erisimAnahtariBitis = null,
        public ?string $yedekKimligi = null,
        public ?string $hedefVeritabani = null,
        public ?string $oncekiVeritabani = null,
        public ?int $isteyenKullaniciId = null,
        public ?array $bariyer = null,
        public ?array $ayarSecimleri = null,
        public array $kullanilanKanitlar = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function diziye(): array
    {
        return [
            'surum' => self::SURUM,
            'kimlik' => $this->kimlik,
            'tur' => $this->tur->value,
            'adim' => $this->adim,
            'acik' => $this->acik,
            'baslangic' => self::metin($this->baslangic),
            'son_guncelleme' => self::metin($this->sonGuncelleme),
            'hata' => $this->hata,
            'erisim_anahtari_ozeti' => $this->erisimAnahtariOzeti,
            'erisim_anahtari_bitis' => $this->erisimAnahtariBitis === null ? null : self::metin($this->erisimAnahtariBitis),
            'yedek_kimligi' => $this->yedekKimligi,
            'hedef_veritabani' => $this->hedefVeritabani,
            'onceki_veritabani' => $this->oncekiVeritabani,
            'isteyen_kullanici_id' => $this->isteyenKullaniciId,
            'bariyer' => $this->bariyer,
            'ayar_secimleri' => $this->ayarSecimleri,
            // Boş nesne JSON'da `{}` kalsın (liste `[]` değil)
            'kullanilan_kanitlar' => $this->kullanilanKanitlar === [] ? new \stdClass : $this->kullanilanKanitlar,
        ];
    }

    /**
     * @param  mixed  $veri  json_decode sonucu
     *
     * @throws IslemDurumuBozuk
     */
    public static function diziden(mixed $veri, string $yol): self
    {
        $bozuk = fn (string $neden): IslemDurumuBozuk => IslemDurumuBozuk::neden($yol, $neden);

        if (! is_array($veri) || array_is_list($veri)) {
            throw $bozuk('JSON nesnesi değil');
        }

        $eksik = array_diff(self::ALANLAR, array_keys($veri));
        if ($eksik !== []) {
            throw $bozuk('eksik alan: '.implode(', ', $eksik));
        }

        if ($veri['surum'] !== self::SURUM) {
            throw $bozuk('bilinmeyen şema sürümü');
        }

        if (! self::uuidMi($veri['kimlik'])) {
            throw $bozuk('kimlik UUID değil');
        }

        $tur = is_string($veri['tur']) ? IslemTuru::tryFrom($veri['tur']) : null;
        if ($tur === null) {
            throw $bozuk('bilinmeyen işlem türü');
        }

        if (! is_string($veri['adim']) || trim($veri['adim']) === '') {
            throw $bozuk('adım boş');
        }

        if (! is_bool($veri['acik'])) {
            throw $bozuk('"acik" true / false değil');
        }

        $hata = $veri['hata'];
        // Yalnız {kod, mesaj}: fazladan alan şemada yok (BY-005-R7)
        if ($hata !== null && (! is_array($hata) || count($hata) !== 2 || ! is_string($hata['kod'] ?? null) || ! is_string($hata['mesaj'] ?? null))) {
            throw $bozuk('hata alanı yalnız {kod, mesaj} olmalı');
        }

        $ozet = $veri['erisim_anahtari_ozeti'];
        if ($ozet !== null && (! is_string($ozet) || preg_match('/^[0-9a-f]{64}$/D', $ozet) !== 1)) {
            throw $bozuk('erişim anahtarı özeti SHA-256 değil');
        }

        if (($ozet === null) !== ($veri['erisim_anahtari_bitis'] === null)) {
            throw $bozuk('erişim anahtarı özeti ile bitiş zamanı birlikte verilmeli (süresiz anahtar olmaz)');
        }

        if ($veri['yedek_kimligi'] !== null && ! self::uuidMi($veri['yedek_kimligi'])) {
            throw $bozuk('yedek kimliği UUID değil');
        }

        foreach (['hedef_veritabani', 'onceki_veritabani'] as $alan) {
            $ad = $veri[$alan];
            if ($ad !== null && (! is_string($ad) || preg_match(self::VERITABANI_ADI_DESENI, $ad) !== 1)) {
                throw $bozuk("{$alan} geçerli bir veritabanı adı değil (küçük harf, rakam, alt çizgi; en çok 63)");
            }
        }

        $isteyen = $veri['isteyen_kullanici_id'];
        if ($isteyen !== null && (! is_int($isteyen) || $isteyen < 1)) {
            throw $bozuk('isteyen kullanıcı kimliği pozitif tam sayı değil');
        }

        $bariyer = $veri['bariyer'] ?? null;
        if ($bariyer !== null && (! is_array($bariyer) || array_keys($bariyer) !== ['baglanma_yetkileri'] || ! self::aclMi($bariyer['baglanma_yetkileri']))) {
            throw $bozuk('bariyer alanı yalnız {baglanma_yetkileri: metin | null} olmalı');
        }

        $secimler = $veri['ayar_secimleri'] ?? null;
        if ($secimler !== null && (! is_array($secimler) || ($secimler !== [] && array_is_list($secimler)) || ! AyarSecimi::gecerliMi($secimler))) {
            throw $bozuk('ayar seçimleri grup => guncel | geri_yuklenen olmalı');
        }

        $kanitlar = $veri['kullanilan_kanitlar'] ?? [];
        $kanitlar = $kanitlar instanceof \stdClass ? (array) $kanitlar : $kanitlar;
        if (! is_array($kanitlar) || ($kanitlar !== [] && array_is_list($kanitlar)) || count($kanitlar) > self::EN_COK_KANIT) {
            throw $bozuk('kullanılan kanıtlar nonce => zaman nesnesi değil');
        }
        foreach ($kanitlar as $nonce => $son) {
            if (! is_string($nonce) || preg_match('/^[0-9a-f]{32}$/D', $nonce) !== 1 || ! is_int($son) || $son < 1) {
                throw $bozuk('kullanılan kanıtlar nonce => zaman nesnesi değil');
            }
        }

        return new self(
            kimlik: $veri['kimlik'],
            tur: $tur,
            adim: $veri['adim'],
            acik: $veri['acik'],
            baslangic: self::tarih($veri['baslangic'], $bozuk),
            sonGuncelleme: self::tarih($veri['son_guncelleme'], $bozuk),
            hata: $hata === null ? null : ['kod' => $hata['kod'], 'mesaj' => $hata['mesaj']],
            erisimAnahtariOzeti: $ozet,
            erisimAnahtariBitis: $veri['erisim_anahtari_bitis'] === null ? null : self::tarih($veri['erisim_anahtari_bitis'], $bozuk),
            yedekKimligi: $veri['yedek_kimligi'],
            hedefVeritabani: $veri['hedef_veritabani'],
            oncekiVeritabani: $veri['onceki_veritabani'],
            isteyenKullaniciId: $isteyen,
            bariyer: $bariyer === null ? null : ['baglanma_yetkileri' => $bariyer['baglanma_yetkileri']],
            ayarSecimleri: $secimler,
            kullanilanKanitlar: $kanitlar,
        );
    }

    /** `datacl::text` (ya da varsayılan yetkiler için null): kontrol karakteri yok, sınırlı uzunluk */
    private static function aclMi(mixed $deger): bool
    {
        return $deger === null
            || (is_string($deger) && strlen($deger) <= self::ACL_EN_UZUN && preg_match('/^\{[^\x00-\x1f\x7f]*\}$/D', $deger) === 1);
    }

    private static function uuidMi(mixed $deger): bool
    {
        return is_string($deger) && preg_match(self::UUID_DESENI, $deger) === 1;
    }

    private static function metin(CarbonImmutable $tarih): string
    {
        return $tarih->utc()->format(self::TARIH_BICIMI);
    }

    /**
     * @param  Closure(string): IslemDurumuBozuk  $bozuk
     */
    private static function tarih(mixed $deger, Closure $bozuk): CarbonImmutable
    {
        if (! is_string($deger)) {
            throw $bozuk('tarih metin değil');
        }

        try {
            $tarih = CarbonImmutable::createFromFormat(self::TARIH_BICIMI, $deger);
        } catch (Throwable) {
            $tarih = null;
        }

        // createFromFormat taşan değeri sessizce kaydırır (30 Şubat → 2 Mart) ve
        // kısa mikrosaniyeyi kabul eder: aynı biçime geri çevrilip girdiyle
        // birebir karşılaştırılır; saat farkı yalnız UTC. Ham değer mesaja
        // yazılmaz (Dikkat_Edilecekler D2).
        if (! $tarih instanceof CarbonImmutable || $tarih->getOffset() !== 0 || $tarih->format(self::TARIH_BICIMI) !== $deger) {
            throw $bozuk('tarih "YYYY-AA-GGTSS:DD:SS.mikrosaniye+00:00" (UTC) biçiminde değil');
        }

        return $tarih->utc();
    }
}
