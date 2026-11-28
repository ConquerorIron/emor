<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UBL'den okunan veriyi faturaya ve vergi kalemlerine yazar; okuma durumunu
 * (ubl_okundu / ubl_hata / ubl_son_deneme) ve yeniden deneme politikasını
 * tutar. Sağlayıcıdan bağımsızdır (İzibiz toplu indirme, Protel tek belge);
 * bir turun hata kararları UblOkumaTuru'nda.
 *
 * Yeniden deneme: okunamayan fatura bir gün sonra yeniden denenir, AZAMI_HATA
 * denemeden sonra bırakılır (günlüğe yazılır; `efatura:ubl-oku --yeniden-dene`
 * sayacı sıfırlar). Erişim hatasında fatura ertelenir: bir gün sonra yeniden,
 * sayaç artmaz.
 */
final class EFaturaUblKaydi
{
    public const AZAMI_HATA = 3;

    /**
     * Okunacak gelen faturalar, en yeniden: okunmamış, AZAMI_HATA'ya ulaşmamış,
     * son denemesi bir günden eski.
     *
     * @return Builder<EFatura>
     */
    public function adaylar(EntegratorBaglanti $tanim, int $limit): Builder
    {
        return EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('ubl_okundu')
            ->where('ubl_hata', '<', self::AZAMI_HATA)
            ->where(fn (Builder $q) => $q->whereNull('ubl_son_deneme')
                ->orWhere('ubl_son_deneme', '<', CarbonImmutable::now()->subDay()))
            ->orderByDesc('id')
            ->limit($limit);
    }

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
     * AZAMI_HATA'ya ulaşan (bırakılan) faturalar kimlikleriyle günlüğe yazılır.
     *
     * @param  list<int>  $faturaIdleri
     */
    public function hataliIsaretle(array $faturaIdleri): void
    {
        EFatura::query()->whereKey($faturaIdleri)->update([
            'ubl_son_deneme' => CarbonImmutable::now(),
            'ubl_hata' => DB::raw('ubl_hata + 1'),
        ]);

        $birakilanlar = EFatura::query()->whereKey($faturaIdleri)
            ->where('ubl_hata', '>=', self::AZAMI_HATA)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($birakilanlar !== []) {
            Log::warning('UBL okunamadı, fatura bırakıldı (yeniden denemek için: efatura:ubl-oku --yeniden-dene)', ['fatura_idleri' => $birakilanlar]);
        }
    }

    /**
     * Erişim hatası (zaman aşımı, 5xx, okunamayan yanıt): bir gün sonra yeniden
     * denenir, sayaç ARTMAZ — sorunlu parti kuyruğun başını kilitlemez,
     * kalıcı da bırakılmaz (C-5).
     *
     * @param  list<int>  $faturaIdleri
     */
    public function ertele(array $faturaIdleri): void
    {
        EFatura::query()->whereKey($faturaIdleri)->update(['ubl_son_deneme' => CarbonImmutable::now()]);
    }

    /**
     * Tanımın okunmamış gelen faturalarında sayaç ve bekleme sıfırlanır
     * (`efatura:ubl-oku --yeniden-dene`): bırakılan ve ertelenen faturalar
     * bir sonraki turda yeniden istenir.
     *
     * @return int sıfırlanan fatura sayısı
     */
    public function sayaclariSifirla(EntegratorBaglanti $tanim): int
    {
        return EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('ubl_okundu')
            ->where(fn (Builder $q) => $q->where('ubl_hata', '>', 0)->orWhereNotNull('ubl_son_deneme'))
            ->update(['ubl_hata' => 0, 'ubl_son_deneme' => null]);
    }
}
