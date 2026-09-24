<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\Eloquent\Builder;

/**
 * e-Fatura ekranlarının (EFAT-11) ortak filtre/sıralama sorgusu. Liste, özet
 * ve Excel çıktısı AYNI sorgudan beslenir: sayılar ile satırlar ayrışmaz.
 *
 * Kapsam her zaman tek entegratör tanımı + yöndür (test ve canlı hesabın
 * faturaları karışmaz). Sıralama kolonları allow-list'tir.
 *
 * @phpstan-type Filtre array{baslangic: string, bitis: string, ara?: string|null, durum?: string|null, erp_okundu?: string|null, para_birimi?: string|null}
 */
final class EFaturaSorgusu
{
    public const SIRALAMALAR = ['belge_tarihi', 'belge_no', 'tutar', 'karsi_unvan', 'olusturma_zamani'];

    /**
     * @param  Filtre  $filtre
     * @return Builder<EFatura>
     */
    public function filtrele(EntegratorBaglanti $tanim, FaturaYonu $yon, array $filtre): Builder
    {
        $sorgu = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value)
            ->whereBetween('belge_tarihi', [$filtre['baslangic'], $filtre['bitis']]);

        $ara = trim((string) ($filtre['ara'] ?? ''));
        if ($ara !== '') {
            [$karsiVkn, $karsiUnvan] = $this->karsiKolonlar($yon);
            $karsiAdSoyad = $yon === FaturaYonu::Gelen ? 'gonderici_ad_soyad' : 'alici_ad_soyad';
            $desen = '%'.$ara.'%';

            // Değer bağlanır; joker karakterler yalnız aramayı genişletir
            $sorgu->where(function (Builder $q) use ($desen, $karsiVkn, $karsiUnvan, $karsiAdSoyad): void {
                $q->whereLike('belge_no', $desen)
                    ->orWhereLike('ettn', $desen)
                    ->orWhereLike($karsiVkn, $desen)
                    ->orWhereLike($karsiUnvan, $desen)
                    ->orWhereLike($karsiAdSoyad, $desen)
                    ->orWhereLike('fatura_tipi', $desen);
            });
        }

        if (($filtre['durum'] ?? null) !== null) {
            $sorgu->where('durum', $filtre['durum']);
        }

        if (($filtre['para_birimi'] ?? null) !== null) {
            $sorgu->where('para_birimi', $filtre['para_birimi']);
        }

        match ($filtre['erp_okundu'] ?? null) {
            'evet' => $sorgu->where('erp_okundu', true),
            'hayir' => $sorgu->where('erp_okundu', false),
            'bilinmiyor' => $sorgu->whereNull('erp_okundu'),
            default => null,
        };

        return $sorgu;
    }

    /**
     * @param  Builder<EFatura>  $sorgu
     * @return Builder<EFatura>
     */
    public function sirala(Builder $sorgu, FaturaYonu $yon, ?string $kolon, ?string $yonu): Builder
    {
        $yonu = $yonu === 'asc' ? 'asc' : 'desc';

        $sutun = match ($kolon) {
            'belge_no' => 'belge_no',
            'tutar' => 'tutar',
            'karsi_unvan' => $this->karsiKolonlar($yon)[1],
            'olusturma_zamani' => 'olusturma_zamani',
            default => 'belge_tarihi',
        };

        // id: eşit değerlerde sayfalar arası kararlı sıra
        return $sorgu->orderBy($sutun, $yonu)->orderBy('id', $yonu);
    }

    /**
     * Para birimi bazında adet ve tutar toplamları (farklı para birimleri
     * birbirine eklenmez). Tutarlar numeric toplamdan metin olarak döner.
     *
     * @param  Builder<EFatura>  $sorgu
     * @return list<array{para_birimi: string, adet: int, tutar: string, vergi_tutari: string}>
     */
    public function ozet(Builder $sorgu): array
    {
        /** @var list<object{para_birimi: string, adet: int|string, tutar: int|float|string|null, vergi_tutari: int|float|string|null}> $satirlar */
        $satirlar = $sorgu->clone()
            ->reorder()
            ->toBase()
            // Yuvarlama veritabanında (numeric): PHP float'a çevrilmez
            ->selectRaw('para_birimi, count(*) as adet, round(sum(tutar), 2) as tutar, round(coalesce(sum(vergi_tutari), 0), 2) as vergi_tutari')
            ->groupBy('para_birimi')
            ->orderBy('para_birimi')
            ->get()
            ->all();

        return array_map(fn (object $s): array => [
            'para_birimi' => $s->para_birimi,
            'adet' => (int) $s->adet,
            'tutar' => $this->tutarMetni($s->tutar),
            'vergi_tutari' => $this->tutarMetni($s->vergi_tutari),
        ], $satirlar);
    }

    /**
     * Filtre seçenekleri: seçilen tarih aralığında görülen durum ve para birimleri.
     *
     * Durum, İzibiz'in kodu ve Türkçe açıklamasıyla döner (her kodun tek
     * açıklaması var; boşsa null).
     *
     * @return array{durumlar: list<array{deger: string, aciklama: string|null}>, para_birimleri: list<string>}
     */
    public function secenekler(EntegratorBaglanti $tanim, FaturaYonu $yon, string $baslangic, string $bitis): array
    {
        $taban = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value)
            ->whereBetween('belge_tarihi', [$baslangic, $bitis]);

        /** @var list<object{durum: string, aciklama: string|null}> $durumSatirlari */
        $durumSatirlari = $taban->clone()
            ->toBase()
            ->whereNotNull('durum')
            ->selectRaw('durum, max(durum_aciklamasi) as aciklama')
            ->groupBy('durum')
            ->orderBy('durum')
            ->get()
            ->all();
        $durumlar = array_map(
            fn (object $s): array => ['deger' => $s->durum, 'aciklama' => $s->aciklama],
            $durumSatirlari,
        );
        /** @var list<string> $paraBirimleri */
        $paraBirimleri = $taban->clone()->distinct()->orderBy('para_birimi')->pluck('para_birimi')->all();

        return ['durumlar' => $durumlar, 'para_birimleri' => $paraBirimleri];
    }

    /**
     * Gelen faturada karşı taraf göndericidir, giden faturada alıcı.
     *
     * @return array{0: string, 1: string} [vkn kolonu, unvan kolonu]
     */
    public function karsiKolonlar(FaturaYonu $yon): array
    {
        return $yon === FaturaYonu::Gelen
            ? ['gonderici_vkn', 'gonderici_unvan']
            : ['alici_vkn', 'alici_unvan'];
    }

    /**
     * PostgreSQL numeric'i metin döndürür ve olduğu gibi kullanılır; yalnız
     * SQLite (testler) sayı döndürür.
     */
    private function tutarMetni(int|float|string|null $deger): string
    {
        return match (true) {
            $deger === null => '0.00',
            is_string($deger) => $deger,
            default => number_format($deger, 2, '.', ''),
        };
    }
}
