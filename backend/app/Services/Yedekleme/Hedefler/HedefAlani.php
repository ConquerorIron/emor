<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use Closure;

/**
 * Hedef formundaki bir alanın tanımı. Ekran formu bu tanımlardan çizilir:
 * yeni bir tür ekranda kod değişikliği gerektirmez (BY-016).
 *
 * `gizli` alanlar `gizli_bilgiler`'e (şifreli) yazılır ve hiçbir yanıtta
 * dönmez; düzenlemede boş bırakılırsa değişmez. Zorunlu olmayan gizli alanın
 * kayıtlı değeri isteğin `silinecek_sirlar` listesiyle silinir (YF-49).
 * Evet / hayır (`EVET_HAYIR`) alanı yalnız boolean değer alır, `bool` saklanır.
 */
final readonly class HedefAlani
{
    public const METIN = 'metin';

    public const SAYI = 'sayi';

    public const PAROLA = 'parola';

    public const EVET_HAYIR = 'evet_hayir';

    public const UZUN_METIN = 'uzun_metin';

    /**
     * @param  string  $etiket  i18n anahtarı (frontend `yedekleme.hedef.alan.*`)
     * @param  list<string|Closure>  $kurallar  Laravel doğrulama kuralları (kapanış: dosya sistemi denetimi gibi bağlayıcıya özgü kural)
     */
    public function __construct(
        public string $ad,
        public string $etiket,
        public string $tur = self::METIN,
        public bool $zorunlu = false,
        public bool $gizli = false,
        public array $kurallar = [],
        public mixed $varsayilan = null,
    ) {}

    /**
     * @return array{ad: string, etiket: string, tur: string, zorunlu: bool, gizli: bool, varsayilan: mixed}
     */
    public function diziye(): array
    {
        return [
            'ad' => $this->ad,
            'etiket' => $this->etiket,
            'tur' => $this->tur,
            'zorunlu' => $this->zorunlu,
            'gizli' => $this->gizli,
            'varsayilan' => $this->varsayilan,
        ];
    }
}
