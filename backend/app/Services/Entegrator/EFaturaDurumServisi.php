<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * e-Fatura verisinin güncelliği (EFAT-11 ekranları, EFAT-13 teknik alarm).
 *
 * "Veri zamanı": başladığı günü (İstanbul) kapsayan en son TAM çalışmanın
 * bitişi. Eski bir ayı tamamlayan elle senkron, o günkü verinin güncel
 * olduğunu kanıtlamaz; eksik/başarısız çalışma güncellik sayılmaz.
 */
final class EFaturaDurumServisi
{
    /** Kuyruktaki elle senkron isteğinin bayrağı (tanım başına tek istek). */
    public static function manuelIstekAnahtari(int $tanimId): string
    {
        return "efatura-manuel-senkron:{$tanimId}";
    }

    /**
     * Bayrak yalnız aynı isteğe aitse kaldırılır: bayrak süresi dolup yeni bir
     * istek açıldıysa, geç biten eski iş yeni isteğin bayrağını silmez.
     */
    public static function manuelIstegiBitir(int $tanimId, string $istekId): void
    {
        $anahtar = self::manuelIstekAnahtari($tanimId);
        $istek = Cache::get($anahtar);

        if (is_array($istek) && ($istek['istek_id'] ?? null) === $istekId) {
            Cache::forget($anahtar);
        }
    }

    /**
     * @return array{istek_id: string, zaman: string, kullanici_id: int|null, baslangic: string, bitis: string}|null
     */
    public function manuelIstek(EntegratorBaglanti $tanim): ?array
    {
        $istek = Cache::get(self::manuelIstekAnahtari($tanim->id));

        /** @var array{istek_id: string, zaman: string, kullanici_id: int|null, baslangic: string, bitis: string}|null */
        return is_array($istek) ? $istek : null;
    }

    /**
     * @return array{veri_zamani: CarbonImmutable|null, guncel: bool, calisiyor: bool, son_calisma: EFaturaSenkronCalismasi|null, ardisik_hata: int}
     */
    public function yonDurumu(EntegratorBaglanti $tanim, FaturaYonu $yon): array
    {
        $simdi = CarbonImmutable::now();
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');

        $taban = EFaturaSenkronCalismasi::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value);

        // Çalışma, BAŞLADIĞI günü (İstanbul) kapsıyorsa o anki veriyi tazelemiştir.
        // (Gece yarısından sonraki ilk çalışma bitene kadar dünkü artımlı
        // çalışma geçerli sayılır; eski bir ayı tamamlayan elle senkron sayılmaz.)
        $sonTam = $taban->clone()
            ->where('durum', EFaturaSenkronCalismasi::DURUM_TAM)
            ->whereNotNull('bitti')
            // (tanım, yön, basladi) indeksiyle; çalışmalar sırayla bittiği için
            // başlama sırası bitiş sırasıyla aynıdır
            ->orderByDesc('basladi')
            ->limit(50)
            ->get()
            ->first(fn (EFaturaSenkronCalismasi $c): bool => $c->bitis->toDateString()
                >= $c->basladi->setTimezone($saatDilimi)->toDateString());

        /** @var EFaturaSenkronCalismasi|null $sonCalisma */
        $sonCalisma = $taban->clone()->orderByDesc('basladi')->orderByDesc('id')->first();

        $calisiyor = $taban->clone()
            ->where('durum', EFaturaSenkronCalismasi::DURUM_CALISIYOR)
            ->where('basladi', '>=', $simdi->subMinutes((int) config('efatura.yarim_kalma_dakika')))
            ->exists();

        $veriZamani = $sonTam?->bitti;

        return [
            'veri_zamani' => $veriZamani,
            'guncel' => $veriZamani !== null
                // Aralık uzatıldıysa eşik de uzar (en az üç aralık)
                && $veriZamani->gte($simdi->subMinutes(max((int) config('efatura.guncellik_dakika'), $tanim->senkron_araligi_dakika * 3))),
            'calisiyor' => $calisiyor,
            'son_calisma' => $sonCalisma,
            'ardisik_hata' => $this->ardisikHata($tanim, $yon),
        ];
    }

    /**
     * Son tam çalışmadan sonra art arda kaç çalışma başarısız/eksik bitti
     * (sürenler sayılmaz). Teknik alarm eşiği bununla karşılaştırılır.
     */
    public function ardisikHata(EntegratorBaglanti $tanim, FaturaYonu $yon): int
    {
        $sonuclar = EFaturaSenkronCalismasi::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value)
            ->where('durum', '!=', EFaturaSenkronCalismasi::DURUM_CALISIYOR)
            ->orderByDesc('basladi')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('durum');

        $sayi = 0;
        foreach ($sonuclar as $durum) {
            if ($durum === EFaturaSenkronCalismasi::DURUM_TAM) {
                break;
            }
            $sayi++;
        }

        return $sayi;
    }
}
