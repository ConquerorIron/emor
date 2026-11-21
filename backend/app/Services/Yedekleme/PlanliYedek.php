<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Planların yedeği (README §4.2, §6.1 6. adım; BY-009): aynı ana düşen
 * planlar için TEK yedek alınır ve planların hedeflerine kopyalanır. Aynı
 * hedefi paylaşan planlar tek kopyaya bağlanır (F6). Hiçbir planın hedefinde
 * yerel disk (yedek klasörünün kendisi) yoksa, uzak kopyalar doğrulanınca
 * yerel dosya silinir.
 *
 * Kilit çağıranda. Kopyadan sonra saklama çalışır (BY-010). Sınama geri
 * yüklemesi (açıksa) yedeğin hemen ardından, kopyadan önce YedekAlici'nin
 * içinde çalışır (BY-026): saklamanın "doğrulanmış en yeni" koruması onu görür.
 */
final class PlanliYedek
{
    public function __construct(
        private readonly YedekAlici $alici,
        private readonly YedekKopyalayici $kopyalayici,
        private readonly HedefKatalogu $katalog,
        private readonly YerelDosyaSilici $yerelSilici,
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

            // Her yedekten sonra temizlik (README §4.3); temizlik hatası yedeği bozmaz
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
                $hedefler[$hedef->id]['hedef'] = $hedef;
                $hedefler[$hedef->id]['planlar'][] = $plan->id;
            }
        }

        $anaKlasorHedefiVar = false;
        $kopyaVar = false;

        foreach ($hedefler as ['hedef' => $hedef, 'planlar' => $planIdleri]) {
            if (! $hedef->aktif || ! $this->katalog->destekleniyorMu($hedef->tur)) {
                continue;
            }

            $baglayici = $this->katalog->olustur($hedef);
            if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
                $anaKlasorHedefiVar = true;
            }

            $kopya = $this->kopyalayici->kopyala($yedek, $hedef, $planIdleri, $kullaniciId);
            $kopyaVar = $kopyaVar || $kopya->durum === KopyaDurumu::Tamam;
        }

        // Yerel dosya yalnız başka bir yerde doğrulanmış kopya varsa silinir (F7)
        if (! $anaKlasorHedefiVar && $kopyaVar) {
            $this->yerelSilici->sil($yedek->refresh(), uzakKopyaSart: true);
        }
    }
}
