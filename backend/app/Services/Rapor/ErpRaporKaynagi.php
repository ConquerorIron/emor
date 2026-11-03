<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;

/**
 * ERP rapor proc'ları — üretimde aktif ortamda çalıştıran ErpRaporSorgusu,
 * testlerde sahte uygulama bağlanır (AppServiceProvider).
 */
interface ErpRaporKaynagi
{
    /**
     * Proc'un ham satırları (proc kolon adlarıyla). Raporlar menüsündeki
     * proc'lar ortak imzayı kullanır: `@KullaniciId, @IlkTarih, @SonTarih, @ProjeKodu, @FirmaAdi`.
     *
     * @param  string  $proc  rapor sınıfının sabiti — kullanıcı girdisi değil
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, mixed>>
     *
     * @throws ErpOkumaHatasi ERP okunamazsa
     */
    public function satirlar(string $proc, int $erpKullaniciId, array $parametreler): array;
}
