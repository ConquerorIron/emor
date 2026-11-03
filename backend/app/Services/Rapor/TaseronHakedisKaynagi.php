<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;

/**
 * ERP'deki taşeron sözleşme hakedişleri — üretimde ERP proc'unu çalıştıran
 * TaseronHakedisSorgusu, testlerde sahte uygulama bağlanır (AppServiceProvider).
 */
interface TaseronHakedisKaynagi
{
    /**
     * Hakediş başına bir ham satır (proc kolon adlarıyla).
     *
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, mixed>>
     *
     * @throws ErpOkumaHatasi ERP okunamazsa
     */
    public function hakedisler(int $erpKullaniciId, array $parametreler): array;
}
