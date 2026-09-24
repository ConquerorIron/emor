<?php

declare(strict_types=1);

namespace App\Services\Alarm;

use App\Models\AlarmBildirimi;
use App\Models\AlarmKurali;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ayarlar → Alarm Kuralları (EFAT-13). Kurallar tür başına tektir ve ilk
 * okumada kapalı olarak oluşturulur (seeder gerekmez).
 */
final class AlarmKuraliServisi
{
    /**
     * @return list<AlarmKurali> AlarmKurali::TURLER sırasıyla
     */
    public function listele(): array
    {
        return array_map(
            fn (string $tur): AlarmKurali => AlarmKurali::query()->firstOrCreate(
                ['tur' => $tur],
                ['aktif' => false, 'alicilar' => [], 'parametreler' => AlarmKurali::varsayilanParametreler($tur)],
            ),
            AlarmKurali::TURLER,
        );
    }

    /**
     * @param  array{aktif: bool, alicilar?: list<string>|null, parametreler: array<string, int|string>}  $veri
     */
    public function guncelle(string $tur, array $veri): AlarmKurali
    {
        $kural = AlarmKurali::query()->firstOrNew(['tur' => $tur]);

        // Yalnız türün tanımlı parametreleri saklanır (fazla anahtar yok sayılır)
        $parametreler = array_intersect_key(
            [...AlarmKurali::varsayilanParametreler($tur), ...$veri['parametreler']],
            AlarmKurali::varsayilanParametreler($tur),
        );

        $alicilar = array_values(array_unique(array_map(
            fn (string $adres): string => mb_strtolower(trim($adres)),
            $veri['alicilar'] ?? [],
        )));

        $kural->fill([
            'aktif' => $veri['aktif'],
            'alicilar' => $alicilar,
            'parametreler' => $parametreler,
        ])->save();

        return $kural;
    }

    /**
     * @return Collection<int, AlarmBildirimi>
     */
    public function sonBildirimler(int $adet = 50): Collection
    {
        return AlarmBildirimi::query()
            ->with(['kural:id,tur', 'entegratorBaglanti:id,ortam'])
            ->latest('id')
            ->limit($adet)
            ->get();
    }
}
