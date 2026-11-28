<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kopya–plan bağları (`yedek_kopya_planlari`) üzerindeki F6 engellerinin TEK
 * ölçütü (G1-2). Planlı yedekte bağ kopya kaydı açılırken (`bekliyor`) yazılır
 * ve başarısız kopyada da durur: yeniden denenen kopya yedeği alan planın
 * kuralıyla saklansın (PlanliYedek). Ama yalnız yedeği TUTAN kopyanın
 * (KopyaDurumu::yedegiTutanlar) bağı bir saklama kuralını korur; başarısız /
 * bekleyen kopyanın bağı planın, planın hedefinin ya da ayar geri yüklemesinin
 * önünü kesmez — o işlemlerden önce kaldırılır (bileşik yabancı anahtar
 * RESTRICT'i yüzünden).
 */
final class KopyaPlanBaglari
{
    /** Yedeği tutan kopyaların bağları (F6: bunlar varken plan / plan-hedef kuralı silinmez) */
    public static function tutanlar(): Builder
    {
        return DB::table('yedek_kopya_planlari')
            ->join('yedek_kopyalari', 'yedek_kopyalari.id', '=', 'yedek_kopya_planlari.yedek_kopyasi_id')
            ->whereIn('yedek_kopyalari.durum', KopyaDurumu::yedegiTutanlar());
    }

    /**
     * Planın (verilirse yalnız o hedeflerdeki) yedeği tutmayan kopyalarının
     * bağlarını kaldırır. Kural / plan silinmeden hemen önce, aynı işlemde.
     *
     * @param  list<int>|null  $hedefIdleri
     */
    public static function tutmayanlariKaldir(int $planId, ?array $hedefIdleri = null): void
    {
        DB::table('yedek_kopya_planlari')
            ->where('yedek_plani_id', $planId)
            ->when($hedefIdleri !== null, fn (Builder $q) => $q->whereIn('yedek_hedefi_id', $hedefIdleri))
            ->whereIn('yedek_kopyasi_id', DB::table('yedek_kopyalari')->select('id')->whereNotIn('durum', KopyaDurumu::yedegiTutanlar()))
            ->delete();
    }
}
