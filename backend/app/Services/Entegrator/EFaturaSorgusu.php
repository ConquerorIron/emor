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
 * @phpstan-type Filtre array{baslangic: string, bitis: string, ara?: string|null, durum?: string|null, erp_okundu?: string|null, emor?: string|null, para_birimi?: string|null, tip?: string|null, istisna_kodu?: string|null, istisnali?: string|null, gizlenenler?: string|null}
 */
final class EFaturaSorgusu
{
    /** Tablodan sıralanabilen kolonlar (anahtar => veritabanı kolonu; karşı taraf yöne göre çözülür) */
    public const SIRALAMALAR = [
        'belge_tarihi', 'belge_no', 'tutar', 'karsi_unvan', 'olusturma_zamani',
        'emor', 'erp_okundu', 'karsi_vkn', 'karsi_ad_soyad', 'fatura_tipi', 'para_birimi',
        'irsaliye_no', 'siparis_no', 'durum', 'zarf_durumu', 'yanit_aciklamasi',
        'senaryo', 'vergi_tutari',
    ];

    /**
     * @param  Filtre  $filtre
     * @return Builder<EFatura>
     */
    public function filtrele(EntegratorBaglanti $tanim, FaturaYonu $yon, array $filtre): Builder
    {
        $sorgu = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value)
            ->whereBetween('belge_tarihi', [$filtre['baslangic'], $filtre['bitis']])
            // Gizlenen (bize ait olmayan) faturalar istenmedikçe gelmez; özet ve Excel de
            ->when(($filtre['gizlenenler'] ?? null) !== 'dahil', fn (Builder $q) => $q->whereNull('gizlenme_zamani'));

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
                    ->orWhereLike('fatura_tipi', $desen)
                    ->orWhereLike('siparis_no', $desen)
                    ->orWhereLike('irsaliye_no', $desen)
                    // Fatura zarf durumu ekranda "1300 BAŞARIYLA TAMAMLANDI" diye görünür
                    ->orWhereLike('gib_durum_aciklamasi', $desen)
                    ->orWhereRaw('CAST(gib_durum_kodu AS TEXT) LIKE ?', [$desen]);
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

        // eMOR: islendi | elle_islendi | havuzda | yok (EmorDurumu), bilinmiyor = henüz
        // kontrol edilmedi, islenmemis = işlendi (elle dahil) olmayanların hepsi (hızlı filtre)
        match ($filtre['emor'] ?? null) {
            null => null,
            'bilinmiyor' => $sorgu->whereNull('emor_durumu'),
            'islenmemis' => $sorgu->where(fn (Builder $q) => $q->whereNull('emor_durumu')
                ->orWhereNotIn('emor_durumu', [EmorDurumu::Islendi->value, EmorDurumu::ElleIslendi->value])),
            default => $sorgu->where('emor_durumu', $filtre['emor']),
        };

        if (($filtre['tip'] ?? null) !== null) {
            $sorgu->where('fatura_tipi', $filtre['tip']);
        }

        // İstisna kodu entegratörde (virgüllü olabilir) ya da ERP'de
        if (($filtre['istisna_kodu'] ?? null) !== null) {
            $kod = $filtre['istisna_kodu'];
            $sorgu->where(fn (Builder $q) => $q->where('vergi_istisna_kodu', $kod)
                ->orWhere('izibiz_istisna_kodu', $kod)
                ->orWhereLike('izibiz_istisna_kodu', $kod.',%')
                ->orWhereLike('izibiz_istisna_kodu', '%,'.$kod)
                ->orWhereLike('izibiz_istisna_kodu', '%,'.$kod.',%'));
        }

        // Hızlı filtre: iki kaynaktan birinde vergi istisna kodu olanlar
        if (($filtre['istisnali'] ?? null) === 'evet') {
            $sorgu->where(fn (Builder $q) => $q->whereNotNull('vergi_istisna_kodu')->orWhereNotNull('izibiz_istisna_kodu'));
        }

        return $sorgu;
    }

    /**
     * @param  Builder<EFatura>  $sorgu
     * @return Builder<EFatura>
     */
    public function sirala(Builder $sorgu, FaturaYonu $yon, ?string $kolon, ?string $yonu): Builder
    {
        $yonu = $yonu === 'asc' ? 'asc' : 'desc';

        [$karsiVkn, $karsiUnvan] = $this->karsiKolonlar($yon);

        // Allow-list: ham ifadelere yalnız buradaki sabit kolon adları girer
        $sutun = match ($kolon) {
            'belge_no', 'tutar', 'olusturma_zamani', 'erp_okundu', 'fatura_tipi', 'para_birimi',
            'irsaliye_no', 'siparis_no', 'yanit_aciklamasi', 'senaryo', 'vergi_tutari' => $kolon,
            'karsi_unvan' => $karsiUnvan,
            'karsi_vkn' => $karsiVkn,
            'karsi_ad_soyad' => $yon === FaturaYonu::Gelen ? 'gonderici_ad_soyad' : 'alici_ad_soyad',
            // Ekrandaki metinlerle aynı sıra: durum açıklaması, zarf durumunda GİB kodu
            'durum' => 'durum_aciklamasi',
            'zarf_durumu' => 'gib_durum_kodu',
            'emor' => 'emor_durumu',
            default => 'belge_tarihi',
        };

        // Boş değerler yönden bağımsız en sonda (PostgreSQL ile SQLite'ın NULL sırası farklı)
        $sorgu->orderByRaw("CASE WHEN {$sutun} IS NULL THEN 1 ELSE 0 END");

        if ($sutun === 'emor_durumu') {
            // Aşama sırası (alfabetik değil): işlendi > havuzda > yok
            $sorgu->orderByRaw("CASE emor_durumu WHEN 'islendi' THEN 4 WHEN 'elle_islendi' THEN 3 WHEN 'havuzda' THEN 2 ELSE 1 END {$yonu}");
        } else {
            $sorgu->orderBy($sutun, $yonu);
        }

        // id: eşit değerlerde sayfalar arası kararlı sıra
        return $sorgu->orderBy('id', $yonu);
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
     * @return array{durumlar: list<array{deger: string, aciklama: string|null}>, para_birimleri: list<string>, tipler: list<string>, istisna_kodlari: list<string>}
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

        /** @var list<string> $tipler */
        $tipler = $taban->clone()->whereNotNull('fatura_tipi')->distinct()->orderBy('fatura_tipi')->pluck('fatura_tipi')->all();
        // İki kaynağın kodları birleşik; entegratördeki virgüllü değerler ayrılır
        $istisnaKodlari = collect([
            ...$taban->clone()->whereNotNull('vergi_istisna_kodu')->distinct()->pluck('vergi_istisna_kodu'),
            ...$taban->clone()->whereNotNull('izibiz_istisna_kodu')->distinct()->pluck('izibiz_istisna_kodu')
                ->flatMap(fn (string $kodlar): array => explode(',', $kodlar)),
        ])->map(fn (string $kod): string => trim($kod))->filter()->unique()->sort()->values()->all();

        return [
            'durumlar' => $durumlar,
            'para_birimleri' => $paraBirimleri,
            'tipler' => $tipler,
            'istisna_kodlari' => $istisnaKodlari,
            // "Gizlenenleri de göster" anahtarının yanındaki sayı
            'gizlenen_adet' => $taban->clone()->whereNotNull('gizlenme_zamani')->count(),
        ];
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
