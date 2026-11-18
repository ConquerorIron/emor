<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * İşlem durumu dosyasının içeriği (şema sürüm 1 — Backup-Jira.md BY-005).
 * Dosyadan okunurken her alan şemaya göre denetlenir; uymayan dosya
 * IslemDurumuBozuk'tur, sessizce "işlem yok" sayılmaz.
 */
final readonly class IslemKaydi
{
    public const SURUM = 1;

    private const ALANLAR = ['surum', 'kimlik', 'tur', 'adim', 'acik', 'baslangic', 'son_guncelleme', 'hata', 'erisim_anahtari_ozeti'];

    private const TARIH_BICIMI = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  string  $adim  İşlemi yürüten kodun adım adı (ör. "bakim_modu", "pg_restore", "onay_bekliyor")
     * @param  bool  $acik  İşlem uygulamayı kapalı tutuyor (bakım modu / kuyruk durmuş): yayın ve planlı yedek başlamaz
     * @param  array{kod: string, mesaj: string}|null  $hata
     * @param  string|null  $erisimAnahtariOzeti  İlerleme ve onay için verilen erişim anahtarının SHA-256 özeti (README §7.2)
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
            'baslangic' => $this->baslangic->utc()->format(self::TARIH_BICIMI),
            'son_guncelleme' => $this->sonGuncelleme->utc()->format(self::TARIH_BICIMI),
            'hata' => $this->hata,
            'erisim_anahtari_ozeti' => $this->erisimAnahtariOzeti,
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

        if (! is_string($veri['kimlik']) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $veri['kimlik']) !== 1) {
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
        if ($hata !== null && (! is_array($hata) || ! is_string($hata['kod'] ?? null) || ! is_string($hata['mesaj'] ?? null))) {
            throw $bozuk('hata alanı {kod, mesaj} değil');
        }

        $ozet = $veri['erisim_anahtari_ozeti'];
        if ($ozet !== null && (! is_string($ozet) || preg_match('/^[0-9a-f]{64}$/', $ozet) !== 1)) {
            throw $bozuk('erişim anahtarı özeti SHA-256 değil');
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
        );
    }

    /**
     * @param  \Closure(string): IslemDurumuBozuk  $bozuk
     */
    private static function tarih(mixed $deger, \Closure $bozuk): CarbonImmutable
    {
        if (! is_string($deger)) {
            throw $bozuk('tarih metin değil');
        }

        try {
            $tarih = CarbonImmutable::createFromFormat(self::TARIH_BICIMI, $deger);
        } catch (Throwable) {
            $tarih = null;
        }

        // Ham değer mesaja yazılmaz (Dikkat_Edilecekler D2)
        if (! $tarih instanceof CarbonImmutable) {
            throw $bozuk('tarih "YYYY-AA-GGTSS:DD:SS.mikrosaniye+00:00" biçiminde değil');
        }

        return $tarih->utc();
    }
}
