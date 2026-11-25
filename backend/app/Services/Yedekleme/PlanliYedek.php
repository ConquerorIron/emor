<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
 * saklama o hedefi doğrulanmamış görür, yerel dosyayı tutar. Hedef sonraki
 * bir çalışmada ulaşılabilir olunca önceki yedeklerin o hedefteki başarısız
 * kopyaları yeniden denenir (YF-04).
 *
 * Kilit çağıranda. Sınama geri yüklemesi (açıksa) yedeğin hemen ardından,
 * kopyadan önce YedekAlici'nin içinde çalışır (BY-026).
 */
final class PlanliYedek
{
    /** Bir çalışmada hedef başına yeniden denenecek önceki başarısız kopya sayısı (YF-04) */
    public const YENIDEN_DENEME_ADET = 3;

    /**
     * Bir kopya için en çok kaç kopyalama çağrısı (ilk çağrı dahil; her çağrı
     * `kopya_deneme` kez dener). Sonra yeniden denenmez, elle bakılır.
     */
    public const EN_COK_TUR = 3;

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
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
            $hedefler = $this->hedefPlanlari($planlar);
            $this->kopyala($yedek, $hedefler, $kullaniciId);
            $this->basarisizlariYenidenDene($yedek, $hedefler, $kullaniciId);

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
            // Son çalışma planla ilişkili (BY-017-R3): sonucu bu yedeğin, bu
            // çalışmada kopyalanan hedeflerindeki kopyalarından okunur
            $plan->forceFill([
                'son_calisma' => $simdi,
                'sonraki_calisma' => $sonraki,
                'son_yedek_id' => $yedek->id,
                'son_hedef_idleri' => $this->kopyalanacaklar($plan)->modelKeys(),
            ])->save();
        }

        return $yedek;
    }

    /**
     * Planın bu çalışmada kopyalanacak hedefleri: etkin ve desteklenen.
     *
     * @return EloquentCollection<int, YedekHedefi>
     */
    private function kopyalanacaklar(YedekPlani $plan): EloquentCollection
    {
        return $plan->hedefler->filter(fn (YedekHedefi $h): bool => $h->aktif && $this->katalog->destekleniyorMu($h->tur))->values();
    }

    /**
     * Planların hedefleri ve her hedefi kullanan planlar.
     *
     * @param  Collection<int, YedekPlani>  $planlar
     * @return array<int, array{hedef: YedekHedefi, planlar: list<int>}>
     */
    private function hedefPlanlari(Collection $planlar): array
    {
        $hedefler = [];
        foreach ($planlar as $plan) {
            foreach ($this->kopyalanacaklar($plan) as $hedef) {
                $hedefler[$hedef->id]['hedef'] = $hedef;
                $hedefler[$hedef->id]['planlar'][] = $plan->id;
            }
        }

        return $hedefler;
    }

    /**
     * @param  array<int, array{hedef: YedekHedefi, planlar: list<int>}>  $hedefler
     */
    private function kopyala(Yedek $yedek, array $hedefler, ?int $kullaniciId): void
    {
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

    /**
     * Başarısız kopyanın yerel dosyası yeniden denemenin kaynağıdır (README
     * §4.3; YF-04): bu çalışmada yeni yedeğin kopyası tamamlanan (yani şu an
     * ulaşılabilen) her hedefte, yerel dosyası duran önceki imzalı yedeklerin
     * o hedefteki başarısız kopyaları en yeniden başlayarak en çok
     * YENIDEN_DENEME_ADET kez yeniden denenir. Hedef hâlâ erişilemiyorsa
     * deneme yapılmaz (her çalışmayı zaman aşımlarıyla uzatmasın — D8).
     *
     * @param  array<int, array{hedef: YedekHedefi, planlar: list<int>}>  $hedefler
     */
    private function basarisizlariYenidenDene(Yedek $yeni, array $hedefler, ?int $kullaniciId): void
    {
        foreach ($hedefler as ['hedef' => $hedef, 'planlar' => $planIdleri]) {
            $ulasilabilir = YedekKopyasi::query()
                ->where(['yedek_id' => $yeni->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam->value])
                ->exists();
            if (! $ulasilabilir) {
                continue;
            }

            $basarisizlar = YedekKopyasi::query()
                ->where('yedek_hedefi_id', $hedef->id)
                ->where('durum', KopyaDurumu::Basarisiz->value)
                ->where('yedek_id', '!=', $yeni->id)
                // Kalıcı olarak düşen kopya (ör. yerel dosya bozuk) her çalışmada
                // yeniden yüklenmesin: ilk çağrı dahil en çok EN_COK_TUR çağrı (D8)
                ->where('deneme', '<', $this->yapilandirma->kopyaDenemeSayisi() * self::EN_COK_TUR)
                ->whereHas('yedek', fn ($q) => $q->where('durum', YedekDurumu::Tamam->value)->where('imzali', true)->whereNull('yerel_silinme_zamani'))
                ->with('yedek')
                ->get()
                ->sortByDesc(fn (YedekKopyasi $k): int => $k->yedek->alinma_zamani?->getTimestamp() ?? 0)
                ->filter(fn (YedekKopyasi $k): bool => is_file($this->yapilandirma->klasor().'/'.$k->yedek->dosya_adi))
                ->take(self::YENIDEN_DENEME_ADET);

            foreach ($basarisizlar as $kopya) {
                $this->kopyalayici->kopyala($kopya->yedek, $hedef, $planIdleri, $kullaniciId);
            }
        }
    }
}
