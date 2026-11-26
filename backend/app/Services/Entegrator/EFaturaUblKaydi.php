<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * UBL'den okunan veriyi faturaya ve vergi kalemlerine yazar; okuma durumunu
 * (ubl_okundu / ubl_hata / ubl_son_deneme) tutar. Sağlayıcıdan bağımsızdır
 * (İzibiz toplu indirme, Protel tek belge).
 */
final class EFaturaUblKaydi
{
    /**
     * Fatura alanları ve kalemler tek işlemde: yarım veri kalmaz. Kalemler
     * baştan yazılır (yeniden okuma aynı sonucu verir). `izibiz_istisna_kodu`
     * ekranda "İstisna Kodu (Entegratör)" — adına rağmen sağlayıcıdan bağımsızdır.
     */
    public function yaz(int $faturaId, UblVerisi $veri): void
    {
        DB::transaction(function () use ($faturaId, $veri): void {
            EFaturaVergiKalemi::query()->where('efatura_id', $faturaId)->delete();

            $sira = 0;
            EFaturaVergiKalemi::query()->insert(array_map(
                function (array $kalem) use ($faturaId, &$sira): array {
                    return ['efatura_id' => $faturaId, 'sira' => ++$sira, ...$kalem];
                },
                $veri->kalemler,
            ));

            EFatura::query()->whereKey($faturaId)->update([
                'satir_toplami' => $veri->satirToplami,
                'indirim_tutari' => $veri->indirimTutari,
                'vergi_haric_tutar' => $veri->vergiHaricTutar,
                'vergi_dahil_tutar' => $veri->vergiDahilTutar,
                'kur' => $veri->kur,
                'satici_vergi_dairesi' => $veri->saticiVergiDairesi,
                'izibiz_istisna_kodu' => $veri->istisnaKodlari,
                'ubl_okundu' => CarbonImmutable::now(),
            ]);
        });
    }

    /**
     * Okunamayan faturalar: bir gün sonra yeniden denenir, sayaç artar.
     *
     * @param  list<int>  $faturaIdleri
     */
    public function hataliIsaretle(array $faturaIdleri): void
    {
        EFatura::query()->whereKey($faturaIdleri)->update([
            'ubl_son_deneme' => CarbonImmutable::now(),
            'ubl_hata' => DB::raw('ubl_hata + 1'),
        ]);
    }
}
