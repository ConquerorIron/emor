<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Yedek hedefi (BY-016). Sırlar (`gizli_bilgiler`) HİÇBİR yanıtta dönmez;
 * yalnız kayıtlı olup olmadıkları (`gizli_kayitli`). `baglanti`'dan yalnız
 * formun sır olmayan alanları ve bağlayıcının gösterilebilir durum alanları
 * döner (ör. SFTP sunucu parmak izi, Google hesabı).
 *
 * @mixin YedekHedefi
 */
final class YedekHedefiResource extends JsonResource
{
    /** Bağlayıcıların `baglanti`'ya yazdığı, ekranda gösterilebilir alanlar */
    private const DURUM_ALANLARI = ['sunucu_parmak_izi', 'sunucu_anahtari_icin', 'durum', 'eposta', 'klasor_kimligi', 'klasor_adi'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $katalog = app(HedefKatalogu::class);
        $tanim = $katalog->destekleniyorMu($this->tur) ? $katalog->tanim($this->tur) : null;
        $alanlar = $tanim?->alanlar ?? [];
        $acik = array_map(fn (HedefAlani $a): string => $a->ad, array_values(array_filter($alanlar, fn (HedefAlani $a): bool => ! $a->gizli)));
        $gizli = array_map(fn (HedefAlani $a): string => $a->ad, array_values(array_filter($alanlar, fn (HedefAlani $a): bool => $a->gizli)));
        $sirlar = $this->gizli_bilgiler ?? [];
        $sonKopya = $this->relationLoaded('sonKopya') ? $this->sonKopya : null;

        return [
            'id' => $this->id,
            'ad' => $this->ad,
            'tur' => $this->tur->value,
            'aktif' => $this->aktif,
            'sunucu_disi' => $tanim?->sunucuDisi ?? true,
            'baglanti' => (object) array_intersect_key($this->baglanti ?? [], array_flip([...$acik, ...self::DURUM_ALANLARI])),
            'gizli_kayitli' => (object) array_combine($gizli, array_map(fn (string $ad): bool => ($sirlar[$ad] ?? '') !== '', $gizli)),
            'plan_sayisi' => $this->whenCounted('planlar'),
            // Kopyası duran hedefte değiştirilemeyen konum alanları (BY-016-R1); kopya yoksa boş
            'konum_kilitli_alanlar' => app(HedefKonumu::class)->kilitliAlanlar($this->resource),
            'son_kopya' => $sonKopya === null ? null : [
                'durum' => $sonKopya->durum->value,
                'zaman' => ($sonKopya->tamamlanma_zamani ?? $sonKopya->updated_at)?->toIso8601String(),
                'hata_kodu' => $sonKopya->hata_kodu,
            ],
        ];
    }
}
