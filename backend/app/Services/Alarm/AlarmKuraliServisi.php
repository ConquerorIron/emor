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
     * CC / BCC gönderilmediyse kayıtlı liste korunur (eski istemci kopyaları
     * sessizce silmesin); To her istekte gelir.
     *
     * @param  array{aktif: bool, alicilar?: list<string>|null, alicilar_cc?: list<string>, alicilar_bcc?: list<string>, parametreler: array<string, int|string>}  $veri
     */
    public function guncelle(string $tur, array $veri): AlarmKurali
    {
        $kural = AlarmKurali::query()->firstOrNew(['tur' => $tur]);

        // Yalnız türün tanımlı parametreleri saklanır (fazla anahtar yok sayılır)
        $parametreler = array_intersect_key(
            [...AlarmKurali::varsayilanParametreler($tur), ...$veri['parametreler']],
            AlarmKurali::varsayilanParametreler($tur),
        );

        $kural->fill([
            'aktif' => $veri['aktif'],
            'alicilar' => $this->adresler($veri['alicilar'] ?? []),
            'alicilar_cc' => array_key_exists('alicilar_cc', $veri) ? $this->adresler($veri['alicilar_cc']) : $kural->alicilar_cc,
            'alicilar_bcc' => array_key_exists('alicilar_bcc', $veri) ? $this->adresler($veri['alicilar_bcc']) : $kural->alicilar_bcc,
            'parametreler' => $parametreler,
        ])->save();

        return $kural;
    }

    /**
     * Küçük harf, boşluksuz, tekil.
     *
     * @param  list<string>  $adresler
     * @return list<string>
     */
    private function adresler(array $adresler): array
    {
        return array_values(array_unique(array_map(
            fn (string $adres): string => mb_strtolower(trim($adres)),
            $adresler,
        )));
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
