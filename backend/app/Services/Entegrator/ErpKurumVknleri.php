<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Services\ErpOkumaHatasi;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Support\Metin;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * "eMOR VKN Var" (kullanıcı isteği 2026-11-25): faturanın karşı tarafının
 * VKN'si eMOR'daki (canlı ERP) bir kurum kartında kayıtlı mı. Şantiye
 * muhasebecisi, bizim carilerimizin yanlışlıkla otelin Protel posta kutusuna
 * kestiği faturaları böyle bulur.
 *
 * Kaynak: canlı ERP'nin `WEB_ERP_TASERON_LISTESI` view'ı (adına rağmen bütün
 * kurum kartlarını — tedarikçiler dahil — döner; kullanıcı kararı), Kesinti
 * modülüyle ortak ErpFirmaKaynagi üzerinden; aktif ortamdan bağımsız canlı.
 *
 * Liste ÖNBELLEKTE_SN saniye önbellekte tutulur (her liste isteği ERP'ye
 * gitmesin). ERP okunamazsa sonuç null'dır (bilinmiyor) ve HATA_SN boyunca
 * yeniden denenmez: ERP erişilemezken her istek bağlantı zaman aşımını
 * beklemesin (D8). İstek boyunca tek kez okunur (Scoped).
 */
#[Scoped]
final class ErpKurumVknleri
{
    public const ONBELLEKTE_SN = 600;

    public const HATA_SN = 60;

    private const ANAHTAR = 'erp-kurum-vknleri:v1';

    /** @var array<string, array{kod: string, ad: string}>|null|false false: henüz okunmadı */
    private array|null|false $harita = false;

    private ?ErpOkumaHatasi $hata = null;

    public function __construct(private readonly ErpFirmaKaynagi $firmalar) {}

    /**
     * VKN (boşluksuz) → kurum kartı. ERP okunamazsa null.
     *
     * @return array<string, array{kod: string, ad: string}>|null
     */
    public function harita(): ?array
    {
        if ($this->harita !== false) {
            return $this->harita;
        }

        if (Cache::has(self::ANAHTAR.':hata')) {
            return $this->harita = null;
        }

        try {
            /** @var array<string, array{kod: string, ad: string}> $harita */
            $harita = Cache::remember(self::ANAHTAR, self::ONBELLEKTE_SN, fn (): array => $this->oku());

            return $this->harita = $harita;
        } catch (ErpOkumaHatasi $hata) {
            Log::warning('eMOR VKN listesi okunamadı', ['kod' => $hata->kod]);
            Cache::put(self::ANAHTAR.':hata', true, self::HATA_SN);
            $this->hata = $hata;

            return $this->harita = null;
        }
    }

    /**
     * Süzgeç için: liste bilinmeden "kayıtlı / değil" süzülemez, sessizce boş ya
     * da tam liste dönmesin.
     *
     * @return array<string, array{kod: string, ad: string}>
     *
     * @throws ErpOkumaHatasi ERP okunamıyor
     */
    public function gerekli(): array
    {
        return $this->harita() ?? throw ($this->hata ?? ErpOkumaHatasi::sorgudan(new RuntimeException('eMOR VKN listesi kısa süre önce okunamadı.')));
    }

    /**
     * Karşı tarafın kurum kartı. VKN boşsa ya da kayıtlı değilse null.
     *
     * @return array{kod: string, ad: string}|null
     */
    public function kurum(?string $vkn): ?array
    {
        $vkn = self::normallestir($vkn);

        return $vkn === '' ? null : ($this->harita()[$vkn] ?? null);
    }

    public static function normallestir(?string $vkn): string
    {
        return Metin::bosluksuz((string) $vkn);
    }

    /**
     * Aynı VKN birden çok kartta olabilir (ERP'de aynı firmanın birkaç kartı):
     * ilk kart gösterilir.
     *
     * @return array<string, array{kod: string, ad: string}>
     */
    private function oku(): array
    {
        $harita = [];
        foreach ($this->firmalar->firmalar() as $firma) {
            $vkn = self::normallestir($firma['vergi_kimlik_no']);
            if ($vkn !== '' && ! isset($harita[$vkn])) {
                $harita[$vkn] = ['kod' => $firma['kod'], 'ad' => $firma['ad']];
            }
        }

        return $harita;
    }
}
