<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * e-Fatura panosu (EK-09; kullanıcı isteği m, 2026-11-25): portal bazında
 * gelen / işlenen / iptal (RED) / işlenecek sayıları ve işlenecek faturaların
 * aylara göre dağılımı.
 *
 * Sayılar Tüm Faturalar'ın kapsamından (EntegratorBaglantiServisi::gelenTanimlari)
 * ve sınıf tanımından (EFatura kapsamları) gelir: panodaki her sayı, aynı
 * tarih aralığı + `entegrator` + `sinif` süzgeciyle açılan listenin toplamına
 * eşittir. Gizlenen (bize ait olmayan) faturalar sınıflara girmez, ayrı sayılır.
 * Her sayı tek gruplu sorgudan (portal ya da ay başına ayrı sorgu yok).
 */
final class EFaturaPanosu
{
    /** @var array<string, string> sayı => EFatura kapsamı */
    private const SINIFLAR = [
        'islenen' => 'islenen',
        'red' => 'reddedilen',
        'islenecek' => 'islenecek',
        'red_islenmis' => 'redAmaIslenen',
    ];

    /**
     * @param  list<EntegratorBaglanti>  $tanimlar
     * @return array{portallar: list<array{saglayici: string, gelen: int, islenen: int, red: int, islenecek: int, red_islenmis: int, gizlenen: int}>, toplam: array{gelen: int, islenen: int, red: int, islenecek: int, red_islenmis: int, gizlenen: int}, aylik: list<array{saglayici: string, aylar: array<string, int>}>}
     */
    public function ozet(array $tanimlar, string $baslangic, string $bitis): array
    {
        $saglayicilar = array_column(array_map(fn (EntegratorBaglanti $t): array => [$t->id, $t->saglayici], $tanimlar), 1, 0);
        $taban = fn (): Builder => EFatura::query()
            ->whereIn('entegrator_baglanti_id', array_keys($saglayicilar))
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereBetween('belge_tarihi', [$baslangic, $bitis]);
        $gorunur = fn (): Builder => $taban()->whereNull('gizlenme_zamani');

        $sayilar = [
            'gelen' => $this->tanimBasina($gorunur()),
            'gizlenen' => $this->tanimBasina($taban()->whereNotNull('gizlenme_zamani')),
        ];
        foreach (self::SINIFLAR as $sayi => $kapsam) {
            $sayilar[$sayi] = $this->tanimBasina($gorunur()->{$kapsam}());
        }

        $portallar = [];
        // Pano sırası: Logo, Portal Plus (Protel), İzibiz, E-Arşiv (kullanıcının örneği)
        foreach (EntegratorBaglanti::GELEN_SAGLAYICILARI as $saglayici) {
            $id = array_search($saglayici, $saglayicilar, true);
            if ($id === false) {
                continue;
            }
            $portallar[] = ['saglayici' => $saglayici, ...array_map(fn (array $s): int => $s[$id] ?? 0, $sayilar)];
        }

        $toplam = array_map(fn (array $s): int => array_sum($s), $sayilar);

        return [
            'portallar' => array_map(fn (array $p): array => $this->sirala($p), $portallar),
            'toplam' => $this->sirala($toplam),
            'aylik' => $this->aylik($gorunur()->islenecek(), $saglayicilar),
        ];
    }

    /**
     * Gönderen firma bazlı döküm (EK-09): aynı süzgeçle, firma + para birimi
     * başına adet ve tutar; en çok fatura üstte. Farklı para birimleri toplanmaz.
     *
     * @param  Builder<EFatura>  $sorgu  EFaturaSorgusu ile süzülmüş
     * @return list<array{vkn: string|null, unvan: string|null, para_birimi: string, adet: int, tutar: string}>
     */
    public function firmalar(Builder $sorgu): array
    {
        /** @var list<object{gonderici_vkn: string|null, unvan: string|null, para_birimi: string, adet: int|string, tutar: int|float|string}> $satirlar */
        $satirlar = $sorgu->clone()
            ->reorder()
            ->toBase()
            ->selectRaw('gonderici_vkn, max(gonderici_unvan) as unvan, para_birimi, count(*) as adet, round(sum(tutar), 2) as tutar')
            ->groupBy('gonderici_vkn', 'para_birimi')
            ->orderByDesc('adet')
            ->orderBy('gonderici_vkn')
            ->orderBy('para_birimi')
            ->get()
            ->all();

        return array_map(fn (object $s): array => [
            'vkn' => $s->gonderici_vkn,
            'unvan' => $s->unvan,
            'para_birimi' => $s->para_birimi,
            'adet' => (int) $s->adet,
            // PostgreSQL numeric metin döner; SQLite (testler) sayı
            'tutar' => is_string($s->tutar) ? $s->tutar : number_format((float) $s->tutar, 2, '.', ''),
        ], $satirlar);
    }

    /**
     * @param  Builder<EFatura>  $sorgu
     * @return array<int, int> tanım kimliği => adet
     */
    private function tanimBasina(Builder $sorgu): array
    {
        return $sorgu->toBase()
            ->selectRaw('entegrator_baglanti_id, count(*) as adet')
            ->groupBy('entegrator_baglanti_id')
            ->pluck('adet', 'entegrator_baglanti_id')
            ->map(fn (mixed $adet): int => (int) $adet)
            ->all();
    }

    /**
     * İşlenecek faturaların belge tarihi ayına göre dağılımı.
     *
     * @param  Builder<EFatura>  $sorgu
     * @param  array<int, string>  $saglayicilar  tanım kimliği => sağlayıcı
     * @return list<array{saglayici: string, aylar: array<string, int>}>
     */
    private function aylik(Builder $sorgu, array $saglayicilar): array
    {
        $ay = DB::getDriverName() === 'pgsql' ? "to_char(belge_tarihi, 'YYYY-MM')" : "strftime('%Y-%m', belge_tarihi)";
        $satirlar = $sorgu->toBase()
            ->selectRaw("entegrator_baglanti_id, {$ay} as ay, count(*) as adet")
            ->groupBy('entegrator_baglanti_id', DB::raw($ay))
            ->get();

        $aylik = [];
        foreach ($satirlar as $satir) {
            $saglayici = $saglayicilar[(int) $satir->entegrator_baglanti_id];
            $aylik[$saglayici][(string) $satir->ay] = (int) $satir->adet;
        }

        $sonuc = [];
        foreach (EntegratorBaglanti::GELEN_SAGLAYICILARI as $saglayici) {
            if (in_array($saglayici, $saglayicilar, true)) {
                $aylar = $aylik[$saglayici] ?? [];
                ksort($aylar);
                $sonuc[] = ['saglayici' => $saglayici, 'aylar' => $aylar];
            }
        }

        return $sonuc;
    }

    /**
     * @param  array<string, mixed>  $sayilar
     * @return array<string, mixed>
     */
    private function sirala(array $sayilar): array
    {
        $sira = ['saglayici', 'gelen', 'islenen', 'red', 'islenecek', 'red_islenmis', 'gizlenen'];

        return array_merge(array_intersect_key(array_flip($sira), $sayilar), $sayilar);
    }
}
