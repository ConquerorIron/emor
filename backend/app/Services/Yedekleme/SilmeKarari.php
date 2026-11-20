<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Saklamanın bir kararı (README §6.2): ya bir planın bir kopyadaki bağı
 * kalkar (F6) ya da bir dosya silinir. `hedefId` null = yedek klasörü
 * (hiçbir hedefin kopyası olmayan yerel dosya).
 */
final readonly class SilmeKarari
{
    public const BAG = 'bag';

    public const DOSYA = 'dosya';

    public const NEDEN_SON_ADET = 'son_adet';

    public const NEDEN_GUN = 'gun';

    public const NEDEN_BAGSIZ = 'bagsiz';

    public const NEDEN_ELLE_GUN = 'elle_gun';

    public const NEDEN_YAYIN_ONCESI = 'yayin_oncesi';

    public const NEDEN_GERI_YUKLEME_ONCESI = 'geri_yukleme_oncesi';

    public const NEDEN_UZAK_KOPYA_VAR = 'uzak_kopya_var';

    public function __construct(
        public string $tur,
        public int $yedekId,
        public ?int $hedefId,
        public ?int $kopyaId,
        public ?int $planId,
        public string $neden,
    ) {}

    public function anahtar(): string
    {
        return implode(':', [$this->tur, $this->yedekId, $this->hedefId ?? 'klasor', $this->planId ?? '-']);
    }

    /**
     * @return array{tur: string, yedek_id: int, hedef_id: int|null, kopya_id: int|null, plan_id: int|null, neden: string}
     */
    public function diziye(): array
    {
        return [
            'tur' => $this->tur,
            'yedek_id' => $this->yedekId,
            'hedef_id' => $this->hedefId,
            'kopya_id' => $this->kopyaId,
            'plan_id' => $this->planId,
            'neden' => $this->neden,
        ];
    }
}
