<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use RuntimeException;
use Throwable;

/**
 * Entegratör çağrısı hatası. `kod` API hata sözleşmesindeki makine okunur
 * koddur (bootstrap/app.php render eder, frontend api/errors.ts çevirir).
 * Mesaj ve bağlam sır içermez: token, şifre ve yanıt gövdesi taşınmaz.
 */
final class EntegratorHatasi extends RuntimeException
{
    public const ERISILEMEDI = 'ENTEGRATOR_ERISILEMEDI';

    public const KIMLIK_HATALI = 'ENTEGRATOR_KIMLIK_HATALI';

    /** Önceki giriş reddedildi; otomatik giriş bekletiliyor (EntegratorTokenOnbellegi) — istek gönderilmedi */
    public const GIRIS_BEKLETILIYOR = 'ENTEGRATOR_GIRIS_BEKLETILIYOR';

    public const YANIT_GECERSIZ = 'ENTEGRATOR_YANIT_GECERSIZ';

    public const HATA = 'ENTEGRATOR_HATA';

    public const AKTIF_YOK = 'ENTEGRATOR_AKTIF_YOK';

    /** Protel modülü kapalı (PROTEL_AKTIF): Protel'e hiçbir istek gitmez */
    public const PROTEL_KAPALI = 'PROTEL_KAPALI';

    /**
     * UBL okuma turu kesildi: turda hiç fatura okunmadan art arda "faturaya
     * özgü" hata geldi — sistemik sayılır, faturalar işaretlenmez (UblOkumaTuru).
     * Yalnız konsol komutunda oluşur, API'ye çıkmaz.
     */
    public const UBL_TUR_KESILDI = 'ENTEGRATOR_UBL_TUR_KESILDI';

    /**
     * @param  bool  $girisHatasi  hata giriş (token) adımından geldi; isteğin
     *                             kendisinden değil (UBL döngüsü faturaya özgü saymaz — C-3)
     */
    private function __construct(
        public readonly string $kod,
        string $mesaj,
        public readonly int $httpDurumu,
        public readonly ?string $saglayiciKodu = null,
        public readonly ?int $saglayiciHttpDurumu = null,
        public readonly bool $girisHatasi = false,
        ?Throwable $onceki = null,
    ) {
        parent::__construct($mesaj, 0, $onceki);
    }

    /** Aynı hata, giriş (token) adımından geldiği işaretiyle; kod ve durumlar değişmez. */
    public function giristen(): self
    {
        if ($this->girisHatasi) {
            return $this;
        }

        return new self($this->kod, $this->getMessage(), $this->httpDurumu, $this->saglayiciKodu, $this->saglayiciHttpDurumu, true, $this);
    }

    public static function erisilemedi(?int $saglayiciHttpDurumu = null): self
    {
        return new self(self::ERISILEMEDI, __('hata.entegrator_erisilemedi'), 502, saglayiciHttpDurumu: $saglayiciHttpDurumu);
    }

    public static function kimlikHatali(?string $saglayiciKodu = null): self
    {
        return new self(self::KIMLIK_HATALI, __('hata.entegrator_kimlik_hatali'), 422, $saglayiciKodu);
    }

    /**
     * Kimlik hatasından ayrı mesaj: kullanıcı beklemenin süresini ve nasıl
     * kaldırılacağını görsün; Protel yanlışlıkla reddettiyse şifre doğruyken
     * "şifre hatalı" sanılmasın (Fable incelemesi).
     */
    public static function girisBekletiliyor(int $saat): self
    {
        return new self(self::GIRIS_BEKLETILIYOR, __('hata.entegrator_giris_bekletiliyor', ['saat' => $saat]), 422);
    }

    public static function yanitGecersiz(): self
    {
        return new self(self::YANIT_GECERSIZ, __('hata.entegrator_yanit_gecersiz'), 502);
    }

    public static function saglayiciHatasi(string $saglayiciKodu, ?int $saglayiciHttpDurumu = null): self
    {
        return new self(self::HATA, __('hata.entegrator_hata', ['kod' => $saglayiciKodu]), 502, $saglayiciKodu, $saglayiciHttpDurumu);
    }

    public static function ublTuruKesildi(): self
    {
        return new self(self::UBL_TUR_KESILDI, __('hata.entegrator_ubl_tur_kesildi'), 502);
    }

    public static function aktifYok(): self
    {
        return new self(self::AKTIF_YOK, __('hata.entegrator_aktif_yok'), 422);
    }

    public static function protelKapali(): self
    {
        return new self(self::PROTEL_KAPALI, __('hata.protel_kapali'), 409);
    }

    /**
     * Kullanıcı kaynaklı durumlar (hatalı şifre, seçilmemiş ortam) raporlanmaz;
     * true dönmek varsayılan raporlamayı bastırır. Diğerleri loga düşer.
     */
    public function report(): bool
    {
        return in_array($this->kod, [self::KIMLIK_HATALI, self::GIRIS_BEKLETILIYOR, self::AKTIF_YOK, self::PROTEL_KAPALI], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'kod' => $this->kod,
            'saglayici_kodu' => $this->saglayiciKodu,
            'saglayici_http_durumu' => $this->saglayiciHttpDurumu,
        ], fn (mixed $deger): bool => $deger !== null);
    }
}
