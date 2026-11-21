<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Planların yedeği (README §4.2, §6.1 6. adım; BY-009): aynı ana düşen
 * planlar için TEK yedek alınır ve planların hedeflerine kopyalanır. Aynı
 * hedefi paylaşan planlar tek kopyaya bağlanır (F6).
 *
 * Yerel dosyanın silinmesi burada KARARLAŞTIRILMAZ: kopyalardan sonra
 * çalışan saklama verir (BY-010-R1). Hiçbir planın hedefinde yerel disk
 * (yedek klasörünün kendisi) yoksa ve zorunlu hedeflerin hepsi doğrulanmışsa
 * yerel dosya orada silinir. Zorunlu hedefler kopyalamadan ÖNCE `bekliyor`
 * kopya kaydıyla yazılır: süreç bir hedefin kopyasından önce ölse de
 * saklama o hedefi doğrulanmamış görür, yerel dosyayı tutar.
 *
 * Kilit çağıranda. Sınama geri yüklemesi (açıksa) yedeğin hemen ardından,
 * kopyadan önce YedekAlici'nin içinde çalışır (BY-026).
 */
final class PlanliYedek
{
    public function __construct(
        private readonly YedekAlici $alici,
        private readonly YedekKopyalayici $kopyalayici,
        private readonly HedefKatalogu $katalog,
        private readonly PlanTakvimi $takvim,
        private readonly SaklamaPolitikasi $saklama,
    ) {}

    /**
     * @param  Collection<int, YedekPlani>  $planlar
     */
    public function calistir(Collection $planlar, YedekKilidi $kilit, ?int $kullaniciId = null): Yedek
    {
        $planlar->loadMissing('hedefler');
        $yedek = $this->alici->al($kilit, YedekKaynagi::Plan, null, $kullaniciId, $planlar->pluck('ad')->values()->all());

        if ($yedek->durum === YedekDurumu::Tamam) {
            $this->kopyala($yedek, $planlar, $kullaniciId);

            // Her yedekten sonra temizlik (README §4.3); yerel dosyanın kararı
            // da orada verilir. Temizlik hatası yedeği bozmaz
            try {
                $this->saklama->uygula($kullaniciId);
            } catch (Throwable $hata) {
                Log::channel('yedekleme')->error('Yedekten sonraki temizlik çalışmadı', ['hata' => $hata->getMessage()]);
            }
        }

        $simdi = CarbonImmutable::now();
        foreach ($planlar as $plan) {
            // Kaçırılan çalışmalar birikmez: sıradaki an ŞİMDİDEN hesaplanır (README §4.2)
            try {
                $sonraki = $this->takvim->sonraki($plan, $simdi);
            } catch (Throwable) {
                $sonraki = null;
            }
            $plan->forceFill(['son_calisma' => $simdi, 'sonraki_calisma' => $sonraki])->save();
        }

        return $yedek;
    }

    /**
     * @param  Collection<int, YedekPlani>  $planlar
     */
    private function kopyala(Yedek $yedek, Collection $planlar, ?int $kullaniciId): void
    {
        /** @var array<int, array{hedef: YedekHedefi, planlar: list<int>}> $hedefler */
        $hedefler = [];
        foreach ($planlar as $plan) {
            foreach ($plan->hedefler as $hedef) {
                if (! $hedef->aktif || ! $this->katalog->destekleniyorMu($hedef->tur)) {
                    continue;
                }
                $hedefler[$hedef->id]['hedef'] = $hedef;
                $hedefler[$hedef->id]['planlar'][] = $plan->id;
            }
        }

        // Zorunlu hedefler kopyalamadan önce kayıtlı (BY-010-R1)
        foreach (array_keys($hedefler) as $hedefId) {
            YedekKopyasi::query()->firstOrCreate(
                ['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedefId],
                ['durum' => KopyaDurumu::Bekliyor, 'deneme' => 0],
            );
        }

        foreach ($hedefler as ['hedef' => $hedef, 'planlar' => $planIdleri]) {
            $this->kopyalayici->kopyala($yedek, $hedef, $planIdleri, $kullaniciId);
        }
    }
}
