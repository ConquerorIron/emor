<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saklama ve temizlik (README §4.3, §6.2; BY-010). Önce hesaplanır
 * (`hesapla`, ekrandaki önizleme), sonra AYNI kararlar uygulanır (`uygula`).
 *
 * Gruplar: her hedef ayrı; ayrıca "yedek klasörü" (hiçbir hedefin kopyası
 * olmayan yerel dosyalar — zamanlanmamış yedekler). Yedek klasörünün kendisi
 * olan yerel hedefin kopyaları o hedefin grubundadır.
 *
 * 1. Plan kuralı (plan + hedef): son N ve / veya N gün. Kopya, kuralın
 *    herhangi bir ölçütünü sağlıyorsa tutulur; sağlamıyorsa planın BAĞI
 *    kalkar (F6: her plan yalnız kendi bağını kaldırır).
 * 2. Dosya adayı: plan yedeğinin kopyası bağsız kaldıysa; zamanlanmamış
 *    yedek kendi kuralını aşmışsa (kural `yedekler.kaynak`'tan seçilir, bağ
 *    sayısından değil — BY-004-R9): elle N gün; yayın öncesi son N ya da N gün
 *    (ikisini de aşan silinir); geri yükleme öncesi N gün ve ilgili
 *    `erp_onceki_*` silinmişse.
 * 3. Koruma (asla silinmez): son başarılı yedek; sabitlenmiş; sınama geri
 *    yüklemesi geçmiş en yeni; kullanımdaki (kopyalanan / işlem dosyasındaki).
 *    "Son başarılı" ve "doğrulanmış en yeni", yerel arşivi ya da tamam kopyası
 *    olan (kullanılabilir) yedekler arasından seçilir.
 * 4. En az sayı: her grupta en az `enaz_adet` başarılı kopya kalır (en
 *    eskiler önce silinir). Grubun en az sayısı ve yerel dosya korumaları
 *    yalnız o grubu süzer (BY-010-R6).
 * 5. Temkin: en son yedek denemesi başarısızsa HİÇBİR ŞEY silinmez — yeni
 *    başarılı yedek gelmedikçe eldekiler tutulur. İşlem durumu dosyası
 *    okunamıyorsa da (kullanımdakiler bilinmiyor) silinmez.
 * 6. Planlı yedeğin yerel dosyası (planlarında yedek klasörü hedefi yoksa)
 *    otomatik olarak YALNIZ burada silinir: bütün zorunlu hedefler doğrulanmış
 *    olmalı, sabit / kullanımdaki yedeğin dosyası kalır (BY-010-R1). En az bir
 *    uzak kopya KURTARMAYA HAZIR olmalı (YedekKopyasi::kurtarmayaHazirMi;
 *    BY-010-R2): şifreli kopyanın aktarımı doğrulanmış ama o hedefe yedeğin
 *    manifestini taşıyan kurtarma paketi gitmemişse yerel arşiv kalır
 *    (`kurtarma_paketi_eksik`). Dayanak bu temizlikten SONRA kalan kopyalardır;
 *    aynı turda silinecek hazır kopya sayılmaz (BY-010-R4). Konumu belirsiz
 *    kopya kalan kopyadır ama dayanak değildir: yalnız o kalırsa yerel dosya
 *    kalır (G21). Hiç uzak kopya kalmıyorsa saklama kararı geçerlidir. uygula() uzak silmeleri önce yapar,
 *    yerel dosyayı silmeden önce kalan kopyaları veritabanından yeniden okuyup
 *    aynı eşiği uygular (BY-010-R5). Aynı eşik, yerel dosya planda ana klasör
 *    hedefi olarak yazıldığında o hedefin kuralına da uygulanır
 *    (kurtarmaDayanagiYok). PlanliYedek her yedekten sonra burayı çalıştırır.
 * 7. Hedefler kayıt kimliğiyle değil fiziksel depoyla bağımsızdır (BY-010-R7,
 *    R8): aynı depoyu gösteren iki hedefte (aynı klasör; uzakta aynı sunucu +
 *    kullanıcı + klasör ya da S3 uç noktası + kova + önek —
 *    HedefKonumu::ayniDepoMu) aynı yedeğin kopyaları AYNI dosyadır.
 *    Eskiden kalmış böyle tanımlarda kuralın silmek istediği ortak dosya
 *    silinmez (koruma nedeni `ayni_depo`, uygulamada `HEDEF_AYNI_DEPO`
 *    denetim kaydı); yalnız tek hedefte kopyası olan dosya kuralla silinir.
 */
final class SaklamaPolitikasi
{
    /** Kararın beklenmeyen (HedefHatasi dışı) hatası; denetim ayrıntısında (YF-35) */
    public const BEKLENMEYEN_HATA = 'SAKLAMA_BEKLENMEYEN_HATA';

    /** Plan yedeğinin yerel (aktarım) dosyasını tutan korumalar — bkz. hesapla() */
    private const YEREL_AKTARIM_KORUMALARI = ['sabit', 'kullanimda', 'uzak_kopya_dogrulanmadi', 'kurtarma_paketi_eksik'];

    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly KullanimDenetimi $kullanim,
        private readonly YerelDosyaSilici $yerelSilici,
        private readonly KopyaSilmeKaydi $silmeKaydi,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly YedekBildirimcisi $bildirimci,
        private readonly YedekDeposu $depo,
        private readonly HedefKonumu $konum,
    ) {}

    /**
     * `engellenen`: kuralların silmek istediği ama aynı dosyayı başka bir
     * hedefin kopyası da tuttuğu için silinmeyen kopyalar ve o hedefler
     * (BY-010-R7; uygula() denetim kaydına `HEDEF_AYNI_DEPO` yazar).
     *
     * @return array{silinecek: list<SilmeKarari>, korunan: array<int, list<string>>, temkin: string|null, engellenen: list<array{karar: SilmeKarari, hedefler: list<int>}>}
     */
    public function hesapla(?CarbonImmutable $simdi = null): array
    {
        $simdi ??= CarbonImmutable::now();
        $ayar = YedekAyari::guncel();
        $korunan = [];

        $kullanimda = $this->kullanim->kullanimdakiler();
        if ($kullanimda === null) {
            return ['silinecek' => [], 'korunan' => [], 'temkin' => 'durum_bilinmiyor', 'engellenen' => []];
        }

        $sonDeneme = Yedek::query()->whereIn('durum', [YedekDurumu::Tamam->value, YedekDurumu::Basarisiz->value])->orderByDesc('id')->first();
        if ($sonDeneme !== null && $sonDeneme->durum === YedekDurumu::Basarisiz) {
            return ['silinecek' => [], 'korunan' => [], 'temkin' => 'son_yedek_basarisiz', 'engellenen' => []];
        }

        /** @var Collection<int, Yedek> $yedekler */
        $yedekler = Yedek::query()->where('durum', YedekDurumu::Tamam->value)->get()->keyBy('id');
        $kopyalar = YedekKopyasi::query()
            ->where('durum', KopyaDurumu::Tamam->value)
            ->whereIn('yedek_id', $yedekler->keys())
            ->with('planlar:id')
            ->get();

        // Koruma kuralları (genel). "Son başarılı" ve "doğrulanmış en yeni" bugün
        // KULLANILABİLEN yedeğe verilir: yerel arşivi ya da tamam kopyası olan
        // (F7: yerel silinmiş, uzak kopyalı yedek dahil). Yalnız yeri doğrulanamayan
        // kopyası kalan yedek geçmişte başarılıdır (durum `tamam`, temkin onu
        // sayar) ama korumayı daha eski kullanılabilir yedekten almaz (BY-028 notu)
        $kopyasiOlan = array_fill_keys($kopyalar->pluck('yedek_id')->map(fn ($id): int => (int) $id)->all(), true);
        // İmzasız (güvenilmeyen) yedek geri yüklenemez: "son başarılı", "doğrulanmış en
        // yeni" ve en az sayı yalnız imzalı yedeklerden sayılır (D4, YF-06)
        $kullanilabilir = $yedekler->filter(fn (Yedek $y): bool => $y->imzali && ($y->yerel_silinme_zamani === null || isset($kopyasiOlan[$y->id])));
        $sonBasarili = $kullanilabilir->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)->first();
        $dogrulanmis = $kullanilabilir->filter(fn (Yedek $y): bool => $y->dogrulama_durumu === DogrulamaDurumu::SinamaGecti)
            ->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)->first();
        foreach ($yedekler as $yedek) {
            $nedenler = array_keys(array_filter([
                'son_basarili' => $sonBasarili?->id === $yedek->id,
                'sabit' => $yedek->sabit,
                'dogrulanmis_en_yeni' => $dogrulanmis?->id === $yedek->id,
                'kullanimda' => isset($kullanimda[$yedek->id]),
            ]));
            if ($nedenler !== []) {
                $korunan[$yedek->id] = $nedenler;
            }
        }

        $anaHedefler = $this->anaKlasorHedefleri($kopyalar->pluck('yedek_hedefi_id')->unique()->all());
        $ayniDepo = $this->ayniDepodakiKopyalar($yedekler->keys()->all());
        $engellenen = [];
        $kararlar = [];

        // F7: uzak kopyası doğrulanmamış (başarısız / bekleyen / yarıda kalmış)
        // yedeğin yerel dosyası silinmez — yeniden denemenin kaynağıdır
        $dogrulanmamisUzak = array_fill_keys(YedekKopyasi::query()
            ->whereIn('durum', [KopyaDurumu::Basarisiz->value, KopyaDurumu::Bekliyor->value, KopyaDurumu::Kopyalaniyor->value])
            ->whereIn('yedek_id', $yedekler->keys())
            ->pluck('yedek_id')->map(fn ($id) => (int) $id)->all(), true);
        $yerelKorunan = $korunan;
        foreach (array_keys($dogrulanmamisUzak) as $id) {
            $yerelKorunan[$id][] = 'uzak_kopya_dogrulanmadi';
        }
        $rapor = $korunan;

        // 1. Plan kuralı → bağ kararları
        $kalanBag = $kopyalar->mapWithKeys(fn (YedekKopyasi $k) => [$k->id => $k->planlar->pluck('id')->all()])->all();
        $planlar = YedekPlani::query()->with('hedefler')->get();
        foreach ($planlar as $plan) {
            foreach ($plan->hedefler as $hedef) {
                $son = $hedef->pivot->sakla_son_adet;
                $gun = $hedef->pivot->sakla_gun;
                if ($son === null && $gun === null) {
                    continue;
                }

                $bagli = $kopyalar
                    ->filter(fn (YedekKopyasi $k): bool => $k->yedek_hedefi_id === $hedef->id && in_array($plan->id, $kalanBag[$k->id] ?? [], true))
                    ->sortByDesc(fn (YedekKopyasi $k) => $yedekler[$k->yedek_id]->alinma_zamani?->getTimestamp() ?? 0)
                    ->values();

                foreach ($bagli as $sira => $kopya) {
                    $alinma = $yedekler[$kopya->yedek_id]->alinma_zamani;
                    $sonIcinde = $son !== null && $sira < $son;
                    $gunIcinde = $gun !== null && $alinma !== null && $alinma->greaterThan($simdi->subDays($gun));
                    if ($sonIcinde || $gunIcinde) {
                        continue;
                    }

                    $kararlar[] = new SilmeKarari(SilmeKarari::BAG, $kopya->yedek_id, $hedef->id, $kopya->id, $plan->id, $son !== null && $sira >= $son ? SilmeKarari::NEDEN_SON_ADET : SilmeKarari::NEDEN_GUN);
                    $kalanBag[$kopya->id] = array_values(array_diff($kalanBag[$kopya->id], [$plan->id]));
                }
            }
        }

        // 2–4. Dosya kararları, grup başına
        $gruplar = $kopyalar->groupBy('yedek_hedefi_id')->map(fn (Collection $g) => $g->values());
        $anaKopyasiOlanlar = $kopyalar->filter(fn (YedekKopyasi $k): bool => isset($anaHedefler[$k->yedek_hedefi_id]))->pluck('yedek_id')->all();
        $klasorGrubu = $yedekler->filter(fn (Yedek $y): bool => $y->yerel_silinme_zamani === null && ! in_array($y->id, $anaKopyasiOlanlar, true))->values();
        /** @var Collection<int, Collection<int, YedekKopyasi>> $uzakKopyalar yedek_id → tamam uzak kopyalar */
        $uzakKopyalar = $kopyalar->reject(fn (YedekKopyasi $k): bool => isset($anaHedefler[$k->yedek_hedefi_id]))->groupBy('yedek_id');
        $anaKlasorKararlari = [];

        foreach ($gruplar as $hedefId => $grup) {
            $adaylar = [];
            foreach ($grup as $kopya) {
                $yedek = $yedekler[$kopya->yedek_id];
                $neden = $yedek->kaynak === YedekKaynagi::Plan
                    ? ($kalanBag[$kopya->id] === [] ? SilmeKarari::NEDEN_BAGSIZ : null)
                    : $this->zamanlanmamisNedeni($yedek, $grup->map(fn (YedekKopyasi $k) => $yedekler[$k->yedek_id]), $ayar, $simdi);
                if ($neden !== null) {
                    $adaylar[] = new SilmeKarari(SilmeKarari::DOSYA, $yedek->id, (int) $hedefId, $kopya->id, null, $neden);
                }
            }
            // Her grup korumaların KENDİ kopyasıyla süzülür (BY-010-R6): yerel
            // dosyanın korumaları (`uzak_kopya_dogrulanmadi`) ve bir grubun en az
            // sayısı başka grubun kararına sızmaz; sonuç grupların işlenme
            // sırasına bağlı değildir. Nedenler yalnız rapora birleşir.
            $grupKorunan = isset($anaHedefler[$hedefId]) ? $yerelKorunan : $korunan;
            $secilen = $this->suz($adaylar, $grup->filter(fn (YedekKopyasi $k): bool => $yedekler[$k->yedek_id]->imzali)->count(), $yedekler, $grupKorunan, $ayar->enaz_adet);
            $rapor = $this->birlestir($rapor, $grupKorunan);
            // Aynı dosyayı başka bir hedefin kopyası da tutuyor (BY-010-R7): silinmez,
            // uygula() görünür hata yazar
            foreach ($secilen as $sira => $karar) {
                if (isset($ayniDepo[$karar->kopyaId])) {
                    $engellenen[] = ['karar' => $karar, 'hedefler' => $ayniDepo[$karar->kopyaId]];
                    $rapor = $this->birlestir($rapor, [$karar->yedekId => ['ayni_depo']]);
                    unset($secilen[$sira]);
                }
            }
            $secilen = array_values($secilen);
            if (isset($anaHedefler[$hedefId])) {
                array_push($anaKlasorKararlari, ...$secilen);
            } else {
                array_push($kararlar, ...$secilen);
            }
        }

        // Yerel dosya kararları uzak kopya kararlarından SONRA (BY-010-R4): dayanak,
        // bu turda silinecek kopyalar çıkarıldıktan sonra kalanlardır (önizleme
        // bütün uzak silmelerin başarılı olacağını varsayar; uygula() kalanları
        // silmelerden sonra veritabanından yeniden okur — BY-010-R5)
        $silinecekKopyalar = [];
        foreach ($kararlar as $karar) {
            if ($karar->tur === SilmeKarari::DOSYA && $karar->kopyaId !== null) {
                $silinecekKopyalar[$karar->kopyaId] = true;
            }
        }
        // Yeri doğrulanamayan kopya saklamada silinmez, dayanak da değildir ama
        // "kalan kopya"dır (G21: kullanılamaz ≠ yok): yalnız o kalırsa yerel dosya kalır
        $belirsizKopyalar = YedekKopyasi::query()
            ->where('durum', KopyaDurumu::KonumBelirsiz->value)
            ->whereIn('yedek_id', $yedekler->keys())
            ->get()->groupBy('yedek_id');
        $kalanUzak = fn (int $yedekId): Collection => ($uzakKopyalar->get($yedekId) ?? collect())
            ->reject(fn (YedekKopyasi $k): bool => isset($silinecekKopyalar[$k->id]))
            ->concat($belirsizKopyalar->get($yedekId) ?? [])->values();

        // Ana klasör hedefinin kopyası yerel dosyanın kendisidir: kendi kuralı
        // silmek istese de plan yedeğine aynı yerel dosya eşiği uygulanır
        foreach ($anaKlasorKararlari as $karar) {
            if ($yedekler[$karar->yedekId]->kaynak === YedekKaynagi::Plan && $this->kurtarmaDayanagiYok($kalanUzak($karar->yedekId))) {
                $rapor = $this->birlestir($rapor, [$karar->yedekId => ['kurtarma_paketi_eksik']]);

                continue;
            }
            $kararlar[] = $karar;
        }

        // Yedek klasörü grubu. Plan yedeğinin yerel dosyası (planlarında ana
        // klasör hedefi yok; README §4.2) yalnız burada silinir (BY-010-R1):
        // en az bir uzak kopya doğrulanmış ve zorunlu hedeflerin hiçbiri
        // bekliyor / kopyalanıyor / başarısız değilse (`uzak_kopya_dogrulanmadi`;
        // PlanliYedek kopyalamadan önce her hedefe `bekliyor` kaydı açar).
        // Sabit ve kullanımdaki yedeğin dosyası da kalır. "Son başarılı",
        // "doğrulanmış en yeni" ve en az sayı yedeğin KENDİSİNİ korur: yedek
        // uzak hedefte durduğu için bu korumalar onun grubunda uygulanır,
        // aktarım kaynağı olan yerel dosyayı tutmaz. Uzak kopyalardan en az
        // biri kurtarmaya hazır olmalı (BY-010-R2): yoksa sunucu kaybında yeni
        // şifreli dump'ın imzalı manifesti hiçbir pakette bulunmaz. Bu turda
        // silinecek kopya dayanak değildir (BY-010-R4; kurtarmaDayanagiYok).
        $adaylar = [];
        $klasorKorunan = $yerelKorunan;
        // Hiç kopya kaydı olmayan plan yedeği (planın bütün hedefleri pasifti):
        // uzak kopya hiç olmayacağı için yerel dosyası elle yedek kuralıyla
        // yönetilir; yoksa süresiz birikirdi (YF-04)
        $kopyasizPlan = array_fill_keys(array_map('intval', $yedekler
            ->filter(fn (Yedek $y): bool => $y->kaynak === YedekKaynagi::Plan)
            ->keys()
            ->diff(YedekKopyasi::query()->whereIn('yedek_id', $yedekler->keys())->distinct()->pluck('yedek_id'))
            ->all()), true);
        $zamanlanmamis = $klasorGrubu->filter(fn (Yedek $y): bool => $y->kaynak !== YedekKaynagi::Plan || isset($kopyasizPlan[$y->id]))->values();
        foreach ($klasorGrubu as $yedek) {
            if ($yedek->kaynak === YedekKaynagi::Plan && ! isset($kopyasizPlan[$yedek->id])) {
                $uzakVar = $uzakKopyalar->has($yedek->id);
                if ($this->kurtarmaDayanagiYok($kalanUzak($yedek->id))) {
                    $klasorKorunan[$yedek->id][] = 'kurtarma_paketi_eksik';
                }
                $engel = array_intersect($klasorKorunan[$yedek->id] ?? [], self::YEREL_AKTARIM_KORUMALARI);
                if ($uzakVar && $engel === []) {
                    $kararlar[] = new SilmeKarari(SilmeKarari::DOSYA, $yedek->id, null, null, null, SilmeKarari::NEDEN_UZAK_KOPYA_VAR);
                }

                continue;
            }

            $neden = $this->zamanlanmamisNedeni($yedek, $zamanlanmamis, $ayar, $simdi);
            if ($neden !== null) {
                $adaylar[] = new SilmeKarari(SilmeKarari::DOSYA, $yedek->id, null, null, null, $neden);
            }
        }
        array_push($kararlar, ...$this->suz($adaylar, $zamanlanmamis->filter(fn (Yedek $y): bool => $y->imzali)->count(), $yedekler, $klasorKorunan, $ayar->enaz_adet));
        $rapor = $this->birlestir($rapor, $klasorKorunan);

        return ['silinecek' => $kararlar, 'korunan' => $rapor, 'temkin' => null, 'engellenen' => $engellenen];
    }

    /**
     * Aynı fiziksel depoya yazan başka bir hedefte de kopyası duran kopyalar
     * (BY-010-R7): kopya kimliği → o hedefler. Böyle iki kaydın dosyası aynı
     * dosyadır; biri silinirse diğeri `tamam` kalır ve yerel dosya ona
     * dayanılarak silinirdi. Eskiden kalmış çakışan hedeflerde saklama bu
     * kopyaları silmez (D4); hedef API'si yeni çakışmayı reddeder, kopyalayıcı
     * ikinci kopyayı yazmaz. Ölçüt HedefKonumu::ayniDepoMu; diğer hedefteki
     * kopya dosyası durabilen her kopyadır (yedegiTutanlar, G21).
     *
     * @param  list<int>  $yedekIdleri
     * @return array<int, list<int>>
     */
    private function ayniDepodakiKopyalar(array $yedekIdleri): array
    {
        $ayniMi = [];
        $sonuc = [];
        $gruplar = YedekKopyasi::query()->with('hedef')
            ->whereIn('yedek_id', $yedekIdleri)
            ->whereIn('durum', KopyaDurumu::yedegiTutanlar())
            ->get()
            ->groupBy('yedek_id');

        foreach ($gruplar as $grup) {
            foreach ($grup as $kopya) {
                foreach ($grup as $diger) {
                    if ($kopya->id === $diger->id || $kopya->hedef === null || $diger->hedef === null) {
                        continue;
                    }
                    $cift = min($kopya->yedek_hedefi_id, $diger->yedek_hedefi_id).'-'.max($kopya->yedek_hedefi_id, $diger->yedek_hedefi_id);
                    $ayniMi[$cift] ??= $this->konum->ayniDepoMu($kopya->hedef, $diger->hedef);
                    if ($ayniMi[$cift]) {
                        $sonuc[$kopya->id][] = (int) $diger->yedek_hedefi_id;
                    }
                }
            }
        }

        return $sonuc;
    }

    /**
     * Plan yedeğinin yerel dosyasını silmenin TEK eşiği (yedek klasörü grubu ve
     * ana klasör hedefinin kopyası; önizleme ve uygulama aynı yerden; BY-010-R2,
     * R4, R5). Ölçüt yedeği tutan KALAN uzak kopyalardır
     * (KopyaDurumu::yedegiTutanlar: tamam + konum_belirsiz): yedek bir uzak
     * hedefte durmaya devam ediyorsa (kalan boş değil) en az biri kurtarmaya
     * hazır olmalı — yeri doğrulanamayan kopya hiçbir zaman hazır değildir,
     * yalnız o kalırsa yerel dosya kalır (G21). Hiçbir uzak kopya kalmıyorsa yedek her hedeften silinmiştir;
     * saklama kararı geçerlidir, yerel dosya da gider. Kalanı önizleme bu turun
     * kararlarından (bütün uzak silmeler başarılı varsayımıyla), uygulama ise
     * uzak silmeler uygulandıktan sonra veritabanından yeniden okuyarak verir
     * (kalanUzakKopyalar): kısmi silme hatasında geride hazır olmayan kopya
     * kalırsa yerel dosya korunur.
     *
     * @param  Collection<int, YedekKopyasi>  $kalan  yedeğin kalan (tamam ya da konumu belirsiz) uzak kopyaları
     */
    private function kurtarmaDayanagiYok(Collection $kalan): bool
    {
        return $kalan->isNotEmpty() && ! $kalan->contains(fn (YedekKopyasi $k): bool => $k->kurtarmayaHazirMi());
    }

    /**
     * Yedeği şu an veritabanında tutan uzak kopyalar (yedegiTutanlar) —
     * hesapla()'daki kalan kümesiyle aynı ölçüt. Yedek klasörünün kendisi olan
     * hedefin `tamam` kopyası yerel dosyanın kendisidir, sayılmaz; konumu
     * belirsiz kopya bugünkü tanım ana klasör olsa da sayılır (yeri o değil).
     *
     * @return Collection<int, YedekKopyasi>
     */
    private function kalanUzakKopyalar(int $yedekId): Collection
    {
        $kopyalar = YedekKopyasi::query()->where('yedek_id', $yedekId)->whereIn('durum', KopyaDurumu::yedegiTutanlar())->get();
        $ana = $this->anaKlasorHedefleri($kopyalar->pluck('yedek_hedefi_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $kopyalar->reject(fn (YedekKopyasi $k): bool => $k->durum === KopyaDurumu::Tamam && isset($ana[$k->yedek_hedefi_id]))->values();
    }

    /**
     * @param  array<int, list<string>>  $a
     * @param  array<int, list<string>>  $b
     * @return array<int, list<string>>
     */
    private function birlestir(array $a, array $b): array
    {
        foreach ($b as $id => $nedenler) {
            $a[$id] = array_values(array_unique([...($a[$id] ?? []), ...$nedenler]));
        }

        return $a;
    }

    /**
     * Hesaplanan kararları uygular; karar yoksa depoya (yedek klasörü, işaret)
     * hiç dokunmaz. Karar varsa önce depo doğrulanır. Erişilemeyen hedef atlanır, diğerleri
     * sürer; bir kararın beklenmeyen hatası da yalnız o grubu keser (YF-35). Her silme denetim kaydına ve olay günlüğüne nedeniyle yazılır.
     * Uzak silmeler önce uygulanır; plan yedeğinin yerel dosyası, ardından
     * fiilen kalan uzak kopyalardan hiçbiri kurtarmaya hazır değilse silinmez
     * (denetim kaydı `basarisiz`, kod `kurtarma_paketi_eksik`; `bilgi` satırı).
     * Aynı depodaki ortak dosya (hesapla()'nın `engellenen`i) silinmez: denetim
     * kaydı `basarisiz`, kod `HEDEF_AYNI_DEPO`; `bilgi` satırı (BY-010-R7).
     *
     * @return array{bag: int, dosya: int, atlanan_hedefler: list<int>, bilgi: list<string>, kararlar: list<SilmeKarari>}
     */
    public function uygula(?int $kullaniciId = null): array
    {
        $hesap = $this->hesapla();
        $sonuc = ['bag' => 0, 'dosya' => 0, 'atlanan_hedefler' => [], 'bilgi' => [], 'kararlar' => []];

        // Uygulanacak karar yoksa depoya dokunulmaz: hiç yedek alınmamış kurulumda
        // yedek klasörü henüz yoktur (ilk yedek açar); işaret yazımı orada düşüp
        // her gece temizliği başarısız gösteriyordu (canlı 2026-11-22…27)
        if ($hesap['silinecek'] === [] && $hesap['engellenen'] === []) {
            return $sonuc;
        }

        // Bağlanmamış diskte yerel dosyalar "yok" görünür (yedek katalogdan düşerdi)
        // ve her karar imzalı olay günlüğünü yedek klasörüne yazar (kök diske düşerdi):
        // depo doğrulanmadan hiçbir karar uygulanmaz (BY-011-R3, G1)
        try {
            $this->depo->hazirla();
        } catch (DepoIsaretiYok $hata) {
            $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarisiz, null, $kullaniciId, null, ['kod' => YedekAlmaHatasi::DEPO], $hata->getMessage());

            throw $hata;
        }

        // Aynı fiziksel depoyu gösteren hedeflerin ortak dosyası silinmez; her turda görünür hata (BY-010-R7)
        foreach ($hesap['engellenen'] as ['karar' => $karar, 'hedefler' => $hedefler]) {
            $ad = Yedek::query()->whereKey($karar->yedekId)->value('dosya_adi');
            $sonuc['bilgi'][] = "hedef {$karar->hedefId}: {$ad} silinmedi; hedef başka bir hedefle (".implode(', ', $hedefler).') aynı depoya yazıyor, dosya ortak';
            $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarisiz, $karar->yedekId, $kullaniciId, null, [...$karar->diziye(), 'kod' => HedefHatasi::AYNI_DEPO, 'ayni_depo_hedefleri' => $hedefler],
                'Hedef başka bir hedefle aynı fiziksel depoya yazıyor; dosya iki hedefin ortak kopyası olduğu için silinmedi. Hedef tanımlarını düzeltin.');
        }

        foreach ($hesap['silinecek'] as $karar) {
            if ($karar->tur !== SilmeKarari::BAG) {
                continue;
            }

            try {
                DB::table('yedek_kopya_planlari')->where('yedek_kopyasi_id', $karar->kopyaId)->where('yedek_plani_id', $karar->planId)->delete();
                $kopya = YedekKopyasi::query()->with(['yedek', 'hedef'])->find($karar->kopyaId);
                if ($kopya !== null) {
                    $this->kopyaOlayi($kopya);
                }
                $sonuc['bag']++;
                $sonuc['kararlar'][] = $karar;
                $this->denetim->kaydet('saklama_bag', IslemSonucu::Basarili, $karar->yedekId, $kullaniciId, null, $karar->diziye());
            } catch (Throwable $hata) {
                // Tek kararın beklenmeyen hatası (disk dolu → olay günlüğü) turu
                // kesmez: yer açacak yerel silmelere sıra gelmeli (YF-35)
                $this->beklenmeyenHata('saklama_bag', $karar, $hata, $kullaniciId);
            }
        }

        $dosyaKararlari = array_values(array_filter($hesap['silinecek'], fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA));
        $hedefGruplari = [];
        foreach ($dosyaKararlari as $karar) {
            $hedefGruplari[$karar->hedefId ?? 0][] = $karar;
        }

        // Uzak hedeflerin silmeleri ÖNCE, yerel dosyaya dokunanlar (yedek klasörü
        // ve yedek klasörünün kendisi olan hedef) SONRA (BY-010-R5): yerel silme
        // kararı uzak silmelerin gerçek sonucuna göre yeniden denetlenir
        $uzakGruplar = [];
        $yerelGruplar = [];
        foreach ($hedefGruplari as $hedefId => $grup) {
            $hedef = $hedefId === 0 ? null : YedekHedefi::query()->find($hedefId);
            $baglayici = null;

            try {
                if ($hedef !== null) {
                    $baglayici = $this->katalog->olustur($hedef);
                }
            } catch (Throwable) {
                $sonuc['atlanan_hedefler'][] = (int) $hedefId;

                continue;
            }

            if ($hedefId === 0 || ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu())) {
                $yerelGruplar[] = [$hedefId, $grup, $baglayici, true];
            } else {
                $uzakGruplar[] = [$hedefId, $grup, $baglayici, false];
            }
        }

        foreach ([...$uzakGruplar, ...$yerelGruplar] as [$hedefId, $grup, $baglayici, $yerelDosya]) {
            foreach ($grup as $karar) {
                $yedek = Yedek::query()->find($karar->yedekId);
                if ($yedek === null) {
                    continue;
                }

                // Önizlemenin varsaydığı uzak silmeler düşmüş olabilir: kalan kopyalar
                // veritabanından yeniden okunur, aynı eşik uygulanır (D4)
                if ($yerelDosya && $yedek->kaynak === YedekKaynagi::Plan && $this->kurtarmaDayanagiYok($this->kalanUzakKopyalar($yedek->id))) {
                    $sonuc['bilgi'][] = "{$yedek->dosya_adi}: yerel dosya korundu; uzak silme tamamlanamadı ve kalan kopya kurtarmaya hazır değil";
                    $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, null, [...$karar->diziye(), 'kod' => 'kurtarma_paketi_eksik'],
                        'Kalan uzak kopyaların hiçbiri kurtarmaya hazır değil; yerel dosya silinmedi');

                    continue;
                }

                try {
                    $this->dosyaSil($yedek, $karar, $baglayici);
                    $sonuc['dosya']++;
                    $sonuc['kararlar'][] = $karar;
                    $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarili, $yedek->id, $kullaniciId, null, $karar->diziye());
                } catch (HedefHatasi $hata) {
                    if ($hata->kod === HedefHatasi::SILME_YETKISI_YOK && $karar->kopyaId !== null) {
                        // Değiştirilemez saklama: uygulamanın silme yetkisi yok, hedefin kendi kuralı
                        // temizler (README §4.6). Mantıksal silme de imzalı olaya yazılır (BY-023-R1)
                        $this->silmeKaydi->kaydet(YedekKopyasi::query()->with('hedef')->findOrFail($karar->kopyaId), $hata->kod);
                        $sonuc['bilgi'][] = "hedef {$hedefId}: silme yetkisi yok ({$yedek->dosya_adi}); hedefin kendi saklama kuralı temizler";
                        $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarili, $yedek->id, $kullaniciId, null, [...$karar->diziye(), 'bilgi' => $hata->kod]);

                        continue;
                    }

                    $sonuc['atlanan_hedefler'][] = (int) $hedefId;
                    $this->denetim->kaydet('saklama_sil', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, null, [...$karar->diziye(), 'kod' => $hata->kod], $hata->getMessage());
                    break;
                } catch (Throwable $hata) {
                    // Beklenmeyen hata o grubu keser, sonraki grupları değil (YF-35)
                    $sonuc['atlanan_hedefler'][] = (int) $hedefId;
                    $this->beklenmeyenHata('saklama_sil', $karar, $hata, $kullaniciId);
                    break;
                }
            }
        }

        $sonuc['atlanan_hedefler'] = array_values(array_unique($sonuc['atlanan_hedefler']));

        if ($sonuc['dosya'] > (int) config('yedekleme.beklenmedik_silme', 10)) {
            $this->bildirimci->bildir('beklenmedik_temizlik', ["Bu temizlikte {$sonuc['dosya']} dosya silindi; saklama kurallarını gözden geçirin."]);
        }

        return $sonuc;
    }

    /**
     * Kararın beklenmeyen hatası: raporlanır, denetim kaydına yalnız sınıf adıyla
     * yazılır (hata metni yol / sır taşıyabilir — D2). Denetim kaydının kendisi
     * düşerse de tur sürer.
     */
    private function beklenmeyenHata(string $islem, SilmeKarari $karar, Throwable $hata, ?int $kullaniciId): void
    {
        report($hata);

        try {
            $this->denetim->kaydet($islem, IslemSonucu::Basarisiz, $karar->yedekId, $kullaniciId, null, [...$karar->diziye(), 'kod' => self::BEKLENMEYEN_HATA], $hata::class);
        } catch (Throwable $denetimHatasi) {
            report($denetimHatasi);
        }
    }

    private function dosyaSil(Yedek $yedek, SilmeKarari $karar, mixed $baglayici): void
    {
        if ($karar->hedefId === null) {
            $this->yerelSilici->sil($yedek);

            return;
        }

        $kopya = YedekKopyasi::query()->with('hedef')->findOrFail($karar->kopyaId);

        // Yedek klasörünün kendisi olan hedefin kopyası yerel dosyanın kendisidir
        if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
            $this->silmeKaydi->kaydet($kopya);
            $this->yerelSilici->sil($yedek->refresh());

            return;
        }

        $baglayici->sil((string) $kopya->uzak_kimlik);
        $this->silmeKaydi->kaydet($kopya);
    }

    /** Bağ kalktığında kopyanın güncel plan listesi olay günlüğüne (dizin yeniden kurulurken bağ geri gelmesin). */
    private function kopyaOlayi(YedekKopyasi $kopya): void
    {
        // Kopyalayıcıyla aynı biçim: konum izi düşerse dizin kopyayı yeri doğrulanamamış sayardı (BY-028-R5)
        $this->olaylar->ekle(YedekOlayi::kopya($kopya, $kopya->yedek->kimlik, $kopya->hedef->ad));
    }

    /**
     * Zamanlanmamış yedeğin kuralı (yedek_ayarlari): aşılmışsa neden, değilse null.
     *
     * @param  Collection<int, Yedek>  $grup  Aynı gruptaki yedekler (yayın öncesi "son N" için)
     */
    private function zamanlanmamisNedeni(Yedek $yedek, Collection $grup, YedekAyari $ayar, CarbonImmutable $simdi): ?string
    {
        $alinma = $yedek->alinma_zamani;
        if ($alinma === null) {
            return null;
        }

        return match ($yedek->kaynak) {
            // Plan yedeği buraya yalnız hiç kopya kaydı yokken gelir (hesapla(); YF-04)
            YedekKaynagi::Elle, YedekKaynagi::Plan => $alinma->lessThan($simdi->subDays($ayar->elle_gun)) ? SilmeKarari::NEDEN_ELLE_GUN : null,
            YedekKaynagi::YayinOncesi => (function () use ($yedek, $grup, $ayar, $simdi, $alinma): ?string {
                $sira = $grup->filter(fn (Yedek $y): bool => $y->kaynak === YedekKaynagi::YayinOncesi)
                    ->sortByDesc(fn (Yedek $y) => $y->alinma_zamani?->getTimestamp() ?? 0)
                    ->values()
                    ->search(fn (Yedek $y): bool => $y->id === $yedek->id);

                return $sira !== false && $sira >= $ayar->yayin_oncesi_son_adet && $alinma->lessThan($simdi->subDays($ayar->yayin_oncesi_gun))
                    ? SilmeKarari::NEDEN_YAYIN_ONCESI : null;
            })(),
            YedekKaynagi::GeriYuklemeOncesi => $alinma->lessThan($simdi->subDays($ayar->geri_yukleme_oncesi_gun))
                && ! GeriYukleme::query()->where('oncesi_yedek_id', $yedek->id)->whereNotNull('onceki_veritabani')->whereNull('veritabani_silinme_zamani')->exists()
                ? SilmeKarari::NEDEN_GERI_YUKLEME_ONCESI : null,
        };
    }

    /**
     * Korunanları çıkarır, en az sayıyı uygular (en eskiler önce silinir).
     *
     * @param  list<SilmeKarari>  $adaylar
     * @param  int  $grupSayisi  Gruptaki İMZALI yedek sayısı: en az sayıyı geri yüklenemeyen yedek doldurmaz (YF-06)
     * @param  Collection<int, Yedek>  $yedekler
     * @param  array<int, list<string>>  $korunan
     * @return list<SilmeKarari>
     */
    private function suz(array $adaylar, int $grupSayisi, Collection $yedekler, array &$korunan, int $enAz): array
    {
        $silinebilir = array_values(array_filter($adaylar, fn (SilmeKarari $k): bool => ! isset($korunan[$k->yedekId])));
        usort($silinebilir, fn (SilmeKarari $a, SilmeKarari $b): int => ($yedekler[$a->yedekId]->alinma_zamani?->getTimestamp() ?? 0) <=> ($yedekler[$b->yedekId]->alinma_zamani?->getTimestamp() ?? 0));

        $izin = max(0, $grupSayisi - max(1, $enAz));
        foreach (array_slice($silinebilir, $izin) as $tutulan) {
            $korunan[$tutulan->yedekId][] = 'enaz_adet';
        }

        return array_slice($silinebilir, 0, $izin);
    }

    /**
     * Yedek klasörünün kendisi olan yerel hedefler.
     *
     * @param  list<int>  $hedefIdleri
     * @return array<int, true>
     */
    private function anaKlasorHedefleri(array $hedefIdleri): array
    {
        $ana = [];
        foreach (YedekHedefi::query()->whereIn('id', $hedefIdleri)->get() as $hedef) {
            try {
                $baglayici = $this->katalog->olustur($hedef);
            } catch (Throwable) {
                continue;
            }
            if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
                $ana[$hedef->id] = true;
            }
        }

        return $ana;
    }
}
