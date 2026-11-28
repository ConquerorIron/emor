<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekPlani;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bir hedefte kopya kaydı hiç açılmamış plan yedeklerinin TEK seçimi
 * (G1-6; Codex G1-6-R4…R6). Plan çalışması bu yedeklere kayıt açıp
 * kopyalar (PlanliYedek), saklama aynı seçimin yerel dosyasını korur
 * (SaklamaPolitikasi, `kopya_bekliyor`): ikisi aynı kümeyi görür.
 *
 * Aday: kaynağı plan, `tamam`, imzalı, yerel dosyası ve doğrulanan manifesti
 * duran, içeriği manifestle aynı (boyut + SHA-256 — kopyalayıcının
 * reddedeceği dosya aday değildir), hedefte hiçbir kopya kaydı olmayan yedek.
 * Pencere PLAN + HEDEF başınadır: soran her plan için yedeği bu hedefe o
 * planla gönderecek en yeni PlanliYedek::YENIDEN_DENEME_ADET aday. Sınır
 * bütün denetimlerden SONRA sayılır (bozuk yeni yedek eski geçerli arşivin
 * önünü kesmez). Bir planın penceresi hangi planlarla birlikte sorulduğuna
 * bağlı değildir: aynı hedefi paylaşan B'nin yeni yedekleri A'nın
 * penceresinden yer almaz. Saklama bütün etkin planlarla sorar, plan
 * çalışması yalnız o an çalışanlarla; çalışmanın seçtiği her yedek
 * saklamanın koruduğu kümededir.
 *
 * Dönen `planlar` yalnız soranlar değil, yedeği bu hedefe gönderen ve
 * hedefe bugün de bağlı BÜTÜN planlardır (Codex G1-6-R6): kayıt açılırken
 * hepsinin saklama bağı aynı işlemde yazılır. Kayıt bir kez açıldıktan sonra
 * yedek aday olmadığı için eksik bağ sonradan tamamlanamazdı; yalnız A'ya
 * bağlı kopya A'nın kuralıyla silinir, B'nin kuralı (F6) çiğnenirdi.
 *
 * Yedeğin bu hedefe hangi planlarla gideceği:
 * - `plan_hedefleri` doluysa (yeni yedek) yalnız ondan: yedeğin alındığı
 *   andaki plan / hedef çiftleri.
 * - Boşsa (eski yedek) imzalı manifestteki plan adlarından; hedef o plana
 *   yedekten KESİN önce eklenmiş olmalı (aynı saniye üyelik kanıtı değildir —
 *   Codex G1-6-R2; eklenme anı bilinmeyen bağ sayılmaz). Eski yedekler yeni
 *   hedefe taşınmaz.
 */
final class EksikKopyaAdaylari
{
    /** @var array<int, array{iz: string, sonuc: bool}> yedek → içerik denetimi (aynı dosya için yeniden hesaplanmaz) */
    private array $icerik = [];

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly ManifestDeposu $manifestler,
    ) {}

    /**
     * @param  Collection<int, YedekPlani>  $planlar  soran planlar (`hedefler` yüklü); pencereleri birleşir
     * @return list<array{yedek: Yedek, planlar: list<int>}> yedek ve onu bu hedefe gönderen, hedefe bağlı bütün planlar; en yeniden
     */
    public function sec(int $hedefId, Collection $planlar, ?int $haricYedekId = null): array
    {
        $soranlar = $planlar
            ->filter(fn (YedekPlani $p): bool => $p->hedefler->contains('id', $hedefId))
            ->map(fn (YedekPlani $p): int => $p->id)->values()->all();
        if ($soranlar === []) {
            return [];
        }

        /** @var array<string, array{id: int, eklenme: CarbonImmutable|null}> $planBilgisi hedefe bağlı her plan: adı => kimlik, hedefin plana eklenme anı */
        $planBilgisi = [];
        foreach (DB::table('yedek_plan_hedefleri as b')
            ->join('yedek_planlari as p', 'p.id', '=', 'b.yedek_plani_id')
            ->where('b.yedek_hedefi_id', $hedefId)
            ->get(['p.id', 'p.ad', 'b.created_at']) as $bag) {
            $planBilgisi[(string) $bag->ad] = ['id' => (int) $bag->id, 'eklenme' => $bag->created_at === null ? null : CarbonImmutable::parse($bag->created_at)];
        }
        $bagliPlanlar = array_column($planBilgisi, 'id');

        /** @var array<int, int> $kalan soran plan → penceresinde kalan yer */
        $kalan = array_fill_keys($soranlar, PlanliYedek::YENIDEN_DENEME_ADET);

        $adaylar = Yedek::query()
            ->where('kaynak', YedekKaynagi::Plan->value)
            ->where('durum', YedekDurumu::Tamam->value)
            ->where('imzali', true)
            ->whereNull('yerel_silinme_zamani')
            ->when($haricYedekId !== null, fn ($q) => $q->whereKeyNot($haricYedekId))
            ->whereDoesntHave('kopyalar', fn ($q) => $q->where('yedek_hedefi_id', $hedefId))
            ->lazyByIdDesc(50);

        $sonuc = [];
        foreach ($adaylar as $yedek) {
            if (max($kalan) === 0) {
                break;
            }

            $okuma = $this->manifest($yedek);
            if ($okuma === null) {
                continue;
            }
            $bag = $this->hedefeGidenPlanlar($yedek, $okuma, $hedefId, $bagliPlanlar, $planBilgisi);
            $pencereler = array_values(array_filter($bag, fn (int $planId): bool => ($kalan[$planId] ?? 0) > 0));
            // İçerik (özet) yalnız bir pencereye girecek yedekte hesaplanır: büyük dosya
            if ($pencereler === [] || ! $this->icerikTutuyorMu($yedek, $okuma)) {
                continue;
            }

            foreach ($pencereler as $planId) {
                $kalan[$planId]--;
            }
            $sonuc[] = ['yedek' => $yedek, 'planlar' => $bag];
        }

        return $sonuc;
    }

    /** Yerel dosya ve kimliği yedekle aynı, güvenilir manifest; yoksa null. */
    private function manifest(Yedek $yedek): ?YedekManifesti
    {
        $dosya = $this->dosya($yedek);
        if (! is_file($dosya)) {
            return null;
        }

        $okuma = $this->manifestler->oku(ManifestDeposu::jsonYolu($dosya));

        return $okuma->guvenilirMi() && $okuma->manifest?->kimlik === $yedek->kimlik ? $okuma->manifest : null;
    }

    /**
     * Dosyanın içeriği manifestle aynı mı (boyut + SHA-256; Codex G1-6-R6).
     * Aynı nesne içinde (bir temizlik turunun bütün hedefleri) dosya
     * değişmedikçe yeniden hesaplanmaz; iz boyut + değişme anı + düğümdür.
     */
    private function icerikTutuyorMu(Yedek $yedek, YedekManifesti $manifest): bool
    {
        $dosya = $this->dosya($yedek);
        clearstatcache(true, $dosya);
        $bilgi = @stat($dosya);
        if ($bilgi === false) {
            return false;
        }
        $iz = $bilgi['size'].'|'.$bilgi['mtime'].'|'.$bilgi['ino'];
        if (($this->icerik[$yedek->id]['iz'] ?? null) !== $iz) {
            $this->icerik[$yedek->id] = ['iz' => $iz, 'sonuc' => ManifestDeposu::dosyaTutuyorMu($manifest, $dosya)];
        }

        return $this->icerik[$yedek->id]['sonuc'];
    }

    private function dosya(Yedek $yedek): string
    {
        return $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;
    }

    /**
     * Yedeği bu hedefe gönderen, hedefe bugün bağlı planlar (boşsa yedek bu
     * hedefe gitmez).
     *
     * @param  list<int>  $bagliPlanlar
     * @param  array<string, array{id: int, eklenme: CarbonImmutable|null}>  $planBilgisi
     * @return list<int>
     */
    private function hedefeGidenPlanlar(Yedek $yedek, YedekManifesti $manifest, int $hedefId, array $bagliPlanlar, array $planBilgisi): array
    {
        if ($yedek->plan_hedefleri !== null) {
            $bag = [];
            foreach ($yedek->plan_hedefleri as $cift) {
                $planId = (int) ($cift['plan'] ?? 0);
                if ((int) ($cift['hedef'] ?? 0) === $hedefId && in_array($planId, $bagliPlanlar, true)) {
                    $bag[] = $planId;
                }
            }

            return array_values(array_unique($bag));
        }

        $alinma = $yedek->alinma_zamani;
        if ($alinma === null) {
            return [];
        }

        $bag = [];
        foreach ($manifest->planAdlari as $ad) {
            $eklenme = $planBilgisi[$ad]['eklenme'] ?? null;
            if ($eklenme !== null && $eklenme->lessThan($alinma)) {
                $bag[] = $planBilgisi[$ad]['id'];
            }
        }

        return array_values(array_unique($bag));
    }
}
