<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Services\Yedekleme\HedefTuru;

/**
 * Bir hedef türünün ekrana ve doğrulamaya verdiği tanım: alanlar, uyarı
 * (ör. düz FTP), sunucu dışı mı (şifreleme zorunlu — README §4.7).
 */
final readonly class HedefTuruTanimi
{
    /**
     * @param  list<HedefAlani>  $alanlar
     * @param  string|null  $uyari  i18n anahtarı; formda gösterilir
     * @param  bool  $oauthHazir  Tarayıcıdan bağlanan türde sunucu yapılandırması (istemci kimliği vb.) tam mı
     */
    public function __construct(
        public HedefTuru $tur,
        public array $alanlar,
        public bool $sunucuDisi,
        public ?string $uyari = null,
        public bool $oauth = false,
        public bool $oauthHazir = false,
    ) {}

    /**
     * @return array{tur: string, alanlar: list<array<string, mixed>>, sunucu_disi: bool, uyari: string|null, oauth: bool, oauth_hazir: bool}
     */
    public function diziye(): array
    {
        return [
            'tur' => $this->tur->value,
            'alanlar' => array_map(fn (HedefAlani $a): array => $a->diziye(), $this->alanlar),
            'sunucu_disi' => $this->sunucuDisi,
            'uyari' => $this->uyari,
            'oauth' => $this->oauth,
            'oauth_hazir' => $this->oauth && $this->oauthHazir,
        ];
    }
}
