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
use Illuminate\Support\Facades\DB;
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
 * saklama o hedefi doğrulanmamış görür, yerel dosyayı tutar. Yedeği alan
 * planlar kayıt açılırken kopyaya bağlanır (G1-2): yeniden denenen kopya o
 * planların kuralıyla saklanır, yeniden denemeyi yapan çalışmanın planıyla
 * değil. Hedef sonraki bir çalışmada ulaşılabilir olunca önceki yedeklerin o
 * hedefteki başarısız (ya da hiç denenmemiş `bekliyor`) kopyaları yeniden
 * denenir (YF-04).
 *
 * Kayıtlar `al()` döndükten sonra açılır; süreç o arada (sınama geri
 * yüklemesi sürerken) ölürse ya da yedek sahipsiz tamamlanırsa plan yedeğinin
 * HİÇ kopya kaydı olmaz. Zorunlu kayıtlar tek işlemde açılır; eski sürümden
 * yarım kalmış kayıtlar da olabilir. Aynı planın sonraki çalışması bir hedefte
 * kaydı olmayan son yedekleri bulur (EksikKopyaAdaylari — saklamanın yerel
 * dosyasını koruduğu seçimle aynı), o hedefe kayıt açıp aynı yeniden deneme
 * yoluna sokar (G1-6, Codex G1-6-R1, R5).
 *
 * Kilit çağıranda. Sınama geri yüklemesi (açıksa) yedeğin hemen ardından,
 * kopyadan önce YedekAlici'nin içinde çalışır (BY-026).
 */
final class PlanliYedek
{
    /**
     * Bir çalışmada hedef başına yeniden denenecek önceki kopya sayısı (YF-04);
     * kopya kaydı hiç açılmamış plan yedeklerinden de plan + hedef başına en çok
     * bu kadarı aranır (G1-6; EksikKopyaAdaylari).
     */
    public const YENIDEN_DENEME_ADET = 3;

    /**
     * Geçici hatada bir kopya için en çok `kopya_deneme × EN_COK_TUR` deneme
     * (varsayılan 3 × 3 = 9; ilk çağrı dahil). `deneme` çağrı başına değil deneme
     * başına artar: geçici hatada bu en çok EN_COK_TUR çağrıdır. Kalıcı hata
     * (YedekKopyalayici::KALICI_HATALAR) ilk denemede keser ve sonraki
     * çalışmalarda hedef tanımı değişene kadar hiç yeniden denenmez (G1-9, D8).
     * Sınır dolan kopya elle bakılmayı bekler.
     */
    public const EN_COK_TUR = 3;

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly YedekAlici $alici,
        private readonly YedekKopyalayici $kopyalayici,
        private readonly HedefKatalogu $katalog,
        private readonly PlanTakvimi $takvim,
        private readonly SaklamaPolitikasi $saklama,
        private readonly EksikKopyaAdaylari $eksikKopyalar,
    ) {}

    /**
     * @param  Collection<int, YedekPlani>  $planlar
     */
    public function calistir(Collection $planlar, YedekKilidi $kilit, ?int $kullaniciId = null): Yedek
    {
        $planlar->loadMissing('hedefler');
        $hedefler = $this->hedefPlanlari($planlar);
        // Yedeğin gideceği plan / hedef çiftleri kayıtla birlikte, arşivden ÖNCE
        // yazılır: süreç arşivden sonra ölse de eksik kopya kaydı doğru hedeflerle
        // tamamlanır (Codex G1-6-R2, R3)
        $ciftler = [];
        foreach ($hedefler as $hedefId => ['planlar' => $planIdleri]) {
            foreach ($planIdleri as $planId) {
                $ciftler[] = ['plan' => $planId, 'hedef' => $hedefId];
            }
        }
        $yedek = $this->alici->al($kilit, YedekKaynagi::Plan, null, $kullaniciId, $planlar->pluck('ad')->values()->all(), planHedefleri: $ciftler);

        if ($yedek->durum === YedekDurumu::Tamam) {
            $this->kopyala($yedek, $hedefler, $kullaniciId);
            $this->basarisizlariYenidenDene($yedek, $hedefler, $planlar, $kullaniciId);

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
        // Zorunlu hedefler kopyalamadan önce kayıtlı (BY-010-R1), planlarıyla
        // birlikte (G1-2), tek işlemde: süreç arada ölünce yarım küme kalmaz (Codex
        // G1-6-R1). Plan / hedef çiftleri yedek kaydıyla birlikte yazıldı (calistir)
        DB::transaction(function () use ($yedek, $hedefler): void {
            foreach ($hedefler as $hedefId => ['planlar' => $planIdleri]) {
                $this->kayitAc($yedek, $hedefId, $planIdleri);
            }
        });

        foreach ($hedefler as ['hedef' => $hedef]) {
            $this->kopyalayici->kopyala($yedek, $hedef, [], $kullaniciId);
        }
    }

    /**
     * Kopya kaydını (yoksa `bekliyor`) açar ve yedeği alan planları ona bağlar
     * (G1-2). Saklama yalnız `tamam` kopyaların bağına baktığı için başarısız
     * kopyadaki bağ bir şey silmez; kopya sonradan tamamlanınca doğru planın
     * kuralı uygulanır. F6 engelleri (plan / plan hedefi silme) de yalnız
     * yedeği tutan kopyanın bağına bakar (KopyaPlanBaglari). Başarısızlık olayı
     * bağları taşır: dizin yeniden kurulunca da kaybolmaz.
     *
     * Kayıt ve bağlar TEK işlemde (Codex G1-6-R7): arada kesilen süreç bağsız
     * `bekliyor` kaydı bırakırsa yedek bir daha aday olmaz, bağlar tamamlanmaz
     * ve aktarımdan sonra saklama kopyayı bağsız sayıp silerdi. Aktarım işlemin
     * dışındadır.
     *
     * @param  list<int>  $planIdleri
     */
    private function kayitAc(Yedek $yedek, int $hedefId, array $planIdleri): void
    {
        DB::transaction(function () use ($yedek, $hedefId, $planIdleri): void {
            $kopya = YedekKopyasi::query()->firstOrCreate(
                ['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedefId],
                ['durum' => KopyaDurumu::Bekliyor, 'deneme' => 0],
            );

            if ($planIdleri !== []) {
                $kopya->planlar()->syncWithoutDetaching(array_fill_keys($planIdleri, ['yedek_hedefi_id' => $hedefId]));
            }
        });
    }

    /**
     * Başarısız kopyanın yerel dosyası yeniden denemenin kaynağıdır (README
     * §4.3; YF-04): bu çalışmada yeni yedeğin kopyası tamamlanan (yani şu an
     * ulaşılabilen) her hedefte, yerel dosyası duran önceki imzalı yedeklerin
     * o hedefteki başarısız ya da hiç denenmemiş (`bekliyor`; kilit bizde,
     * başka süreç yazmıyor) kopyaları en yeniden başlayarak en çok
     * YENIDEN_DENEME_ADET kez yeniden denenir. Hedef hâlâ erişilemiyorsa
     * deneme yapılmaz (her çalışmayı zaman aşımlarıyla uzatmasın — D8).
     * Kopya mevcut plan bağlarıyla denenir; bu çalışmanın planlarına
     * bağlanmaz (G1-2).
     *
     * @param  array<int, array{hedef: YedekHedefi, planlar: list<int>}>  $hedefler
     * @param  Collection<int, YedekPlani>  $planlar
     */
    private function basarisizlariYenidenDene(Yedek $yeni, array $hedefler, Collection $planlar, ?int $kullaniciId): void
    {
        foreach ($hedefler as $hedefId => ['hedef' => $hedef, 'planlar' => $planIdleri]) {
            $ulasilabilir = YedekKopyasi::query()
                ->where(['yedek_id' => $yeni->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam->value])
                ->exists();
            if (! $ulasilabilir) {
                continue;
            }

            // G1-6: bu hedefte kopya kaydı açılmamış plan yedeği. Seçim saklamanın
            // koruduğuyla aynı (Codex G1-6-R5); kayıt, yedeği bu hedefe gönderen ve
            // hedefe bağlı BÜTÜN planlara bağlanır, yalnız şu an çalışanlara değil (R6, F6)
            foreach ($this->eksikKopyalar->sec($hedefId, $planlar->whereIn('id', $planIdleri), $yeni->id) as ['yedek' => $eski, 'planlar' => $bag]) {
                $this->kayitAc($eski, $hedefId, $bag);
            }

            $basarisizlar = YedekKopyasi::query()
                ->where('yedek_hedefi_id', $hedef->id)
                ->whereIn('durum', [KopyaDurumu::Basarisiz->value, KopyaDurumu::Bekliyor->value])
                ->where('yedek_id', '!=', $yeni->id)
                // Geçici hatada en çok kopya_deneme × EN_COK_TUR deneme (D8);
                // saklama kuralı aynı sınırı kullanır (G1-3)
                ->where('deneme', '<', $this->yapilandirma->kopyaAzamiDeneme())
                // Kalıcı hatayla (ör. yerel dosya bozuk, kimlik reddi) düşen kopya
                // her çalışmada yeniden yüklenmez; hedef tanımı kopyanın son
                // durumundan sonra değiştiyse yeniden denenir (G1-9, D8)
                ->where(fn ($q) => $q->whereNull('hata_kodu')
                    ->orWhereNotIn('hata_kodu', YedekKopyalayici::KALICI_HATALAR)
                    ->orWhereHas('hedef', fn ($h) => $h->whereColumn('yedek_hedefleri.updated_at', '>', 'yedek_kopyalari.updated_at')))
                ->whereHas('yedek', fn ($q) => $q->where('durum', YedekDurumu::Tamam->value)->where('imzali', true)->whereNull('yerel_silinme_zamani'))
                ->with('yedek')
                ->get()
                ->sortByDesc(fn (YedekKopyasi $k): int => $k->yedek->alinma_zamani?->getTimestamp() ?? 0)
                ->filter(fn (YedekKopyasi $k): bool => is_file($this->yapilandirma->klasor().'/'.$k->yedek->dosya_adi))
                ->take(self::YENIDEN_DENEME_ADET);

            foreach ($basarisizlar as $kopya) {
                $this->kopyalayici->kopyala($kopya->yedek, $hedef, [], $kullaniciId);
            }
        }
    }
}
