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
 * Kapsam her zaman entegratör tanımı (sağlayıcı ekranlarında tek tanım; Tüm
 * Faturalar'da sağlayıcı başına aktif tanım) + yöndür (test ve canlı hesabın
 * faturaları karışmaz). Sıralama kolonları allow-list'tir.
 *
 * @phpstan-type Filtre array{baslangic: string, bitis: string, ara?: string|null, durum?: string|null, erp_okundu?: string|null, otel_erp_okundu?: string|null, entegrator?: string|null, emor?: string|null, para_birimi?: string|null, tip?: string|null, istisna_kodu?: string|null, istisnali?: string|null, gizlenenler?: string|null, emor_vkn?: string|null, sinif?: string|null, belge_turu?: string|null}
 */
final class EFaturaSorgusu
{
    /** Tablodan sıralanabilen kolonlar (anahtar => veritabanı kolonu; karşı taraf yöne göre çözülür) */
    public const SIRALAMALAR = [
        'belge_tarihi', 'belge_no', 'tutar', 'karsi_unvan', 'olusturma_zamani',
        'emor', 'erp_okundu', 'karsi_vkn', 'karsi_ad_soyad', 'fatura_tipi', 'para_birimi',
        'irsaliye_no', 'siparis_no', 'durum', 'zarf_durumu', 'yanit_aciklamasi',
        'senaryo', 'vergi_tutari', 'erp_durumu', 'yazdirildi', 'otel_erp_okundu',
        // Tüm Faturalar; SAP ve eMOR VKN Var bütün gelen ekranlarında
        'entegrator', 'sap', 'emor_vkn',
        // e-Arşiv / e-SMM
        'belge_turu', 'brut_tutar', 'stopaj_tutari',
    ];

    /** Satırın sağlayıcısı (Tüm Faturalar'ın Entegratör kolonu) — sabit ifade, girdi içermez */
    private const SAGLAYICI_IFADESI = '(SELECT saglayici FROM entegrator_baglantilari WHERE entegrator_baglantilari.id = efatura_faturalari.entegrator_baglanti_id)';

    public function __construct(private readonly ErpKurumVknleri $kurumlar) {}

    /**
     * @param  EntegratorBaglanti|list<EntegratorBaglanti>  $tanimlar
     * @param  Filtre  $filtre
     * @return Builder<EFatura>
     */
    public function filtrele(EntegratorBaglanti|array $tanimlar, FaturaYonu $yon, array $filtre): Builder
    {
        $tanimlar = $tanimlar instanceof EntegratorBaglanti ? [$tanimlar] : $tanimlar;
        $sorgu = EFatura::query()
            ->whereIn('entegrator_baglanti_id', $this->tanimIdleri($tanimlar))
            ->where('yon', $yon->value)
            ->whereBetween('belge_tarihi', [$filtre['baslangic'], $filtre['bitis']])
            // Gizlenen (bize ait olmayan) faturalar istenmedikçe gelmez; özet ve Excel de.
            // 'yalniz': yalnız gizlenenler (pano)
            ->when(($filtre['gizlenenler'] ?? null) === null, fn (Builder $q) => $q->whereNull('gizlenme_zamani'))
            ->when(($filtre['gizlenenler'] ?? null) === 'yalniz', fn (Builder $q) => $q->whereNotNull('gizlenme_zamani'));

        $ara = trim((string) ($filtre['ara'] ?? ''));
        if ($ara !== '') {
            [$karsiVkn, $karsiUnvan] = $this->karsiKolonlar($yon);
            $karsiAdSoyad = $yon === FaturaYonu::Gelen ? 'gonderici_ad_soyad' : 'alici_ad_soyad';
            $desen = '%'.$ara.'%';
            // Tüm Faturalar: Entegratör kolonu (tanımın sağlayıcısı) da aranır
            $saglayiciTanimlari = count($tanimlar) > 1 ? $this->adiEslesenTanimlar($tanimlar, $ara) : [];

            // Değer bağlanır; joker karakterler yalnız aramayı genişletir
            $sorgu->where(function (Builder $q) use ($desen, $karsiVkn, $karsiUnvan, $karsiAdSoyad, $saglayiciTanimlari): void {
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
                    ->orWhereRaw('CAST(gib_durum_kodu AS TEXT) LIKE ?', [$desen])
                    ->when($saglayiciTanimlari !== [], fn (Builder $q) => $q->orWhereIn('entegrator_baglanti_id', $saglayiciTanimlari));
            });
        }

        // Tüm Faturalar: yalnız bir sağlayıcının (İzibiz / Protel / Logo / e-Arşiv) faturaları
        if (($filtre['entegrator'] ?? null) !== null) {
            $sorgu->whereIn('entegrator_baglanti_id', array_map(
                fn (EntegratorBaglanti $t): int => $t->id,
                array_filter($tanimlar, fn (EntegratorBaglanti $t): bool => $t->saglayici === $filtre['entegrator']),
            ));
        }

        if (($filtre['durum'] ?? null) !== null) {
            $sorgu->where('durum', $filtre['durum']);
        }

        if (($filtre['para_birimi'] ?? null) !== null) {
            $sorgu->where('para_birimi', $filtre['para_birimi']);
        }

        // eMOR'un okuması (İzibiz) ve otelin ERP'sinin alması (Protel) ayrı kolonlar
        foreach (['erp_okundu', 'otel_erp_okundu'] as $bayrak) {
            match ($filtre[$bayrak] ?? null) {
                'evet' => $sorgu->where($bayrak, true),
                'hayir' => $sorgu->where($bayrak, false),
                'bilinmiyor' => $sorgu->whereNull($bayrak),
                default => null,
            };
        }

        // eMOR: islendi | elle_islendi | havuzda | yok (EmorDurumu), bilinmiyor = henüz kontrol edilmedi
        match ($filtre['emor'] ?? null) {
            null => null,
            'bilinmiyor' => $sorgu->whereNull('emor_durumu'),
            default => $sorgu->where('emor_durumu', $filtre['emor']),
        };

        // Faturanın sınıfı (EK-01; tanım EFatura kapsamlarında, pano da onu kullanır):
        // hızlı filtreler "ERP İşlenmeyenler / İşlenenler" RED faturayı getirmez (kullanıcı isteği 2026-11-25)
        match ($filtre['sinif'] ?? null) {
            'islendi' => $sorgu->islenen(),
            'islenecek' => $sorgu->islenecek(),
            'red' => $sorgu->reddedilen(),
            'red_islenmis' => $sorgu->redAmaIslenen(),
            default => null,
        };

        // e-Arşiv / e-SMM ekranı: fatura ya da makbuz
        if (($filtre['belge_turu'] ?? null) !== null) {
            $sorgu->where('belge_turu', $filtre['belge_turu']);
        }

        if (($filtre['tip'] ?? null) !== null) {
            $sorgu->where('fatura_tipi', $filtre['tip']);
        }

        // İstisna kodu entegratörde (virgüllü olabilir) ya da ERP'de
        if (($filtre['istisna_kodu'] ?? null) !== null) {
            $kod = $filtre['istisna_kodu'];
            $sorgu->where(fn (Builder $q) => $q->where('vergi_istisna_kodu', $kod)
                ->orWhereLike('vergi_istisna_kodu', $kod.',%')
                ->orWhereLike('vergi_istisna_kodu', '%,'.$kod)
                ->orWhereLike('vergi_istisna_kodu', '%,'.$kod.',%')
                ->orWhere('izibiz_istisna_kodu', $kod)
                ->orWhereLike('izibiz_istisna_kodu', $kod.',%')
                ->orWhereLike('izibiz_istisna_kodu', '%,'.$kod)
                ->orWhereLike('izibiz_istisna_kodu', '%,'.$kod.',%'));
        }

        // Hızlı filtre: iki kaynaktan birinde vergi istisna kodu olanlar
        if (($filtre['istisnali'] ?? null) === 'evet') {
            $sorgu->where(fn (Builder $q) => $q->whereNotNull('vergi_istisna_kodu')->orWhereNotNull('izibiz_istisna_kodu'));
        }

        // "eMOR VKN Var": karşı tarafın VKN'si eMOR'daki bir kurum kartında. Liste
        // ERP'den gelir; okunamazsa süzgeç sessizce boş / tam dönmez, hata verir
        $emorVkn = $filtre['emor_vkn'] ?? null;
        if ($emorVkn !== null) {
            $vknler = array_map('strval', array_keys($this->kurumlar->gerekli()));
            [$karsiVkn] = $this->karsiKolonlar($yon);
            $emorVkn === 'evet'
                ? $sorgu->whereIn($karsiVkn, $vknler)
                : $sorgu->where(fn (Builder $q) => $q->whereNull($karsiVkn)->orWhereNotIn($karsiVkn, $vknler));
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

        if ($kolon === 'emor_vkn') {
            return $this->emorVknIleSirala($sorgu, $karsiVkn, $yonu);
        }

        // Allow-list: ham ifadelere yalnız buradaki sabit kolon adları girer
        $sutun = match ($kolon) {
            'belge_no', 'tutar', 'olusturma_zamani', 'erp_okundu', 'fatura_tipi', 'para_birimi',
            'irsaliye_no', 'siparis_no', 'yanit_aciklamasi', 'senaryo', 'vergi_tutari',
            'erp_durumu', 'yazdirildi', 'otel_erp_okundu', 'belge_turu', 'brut_tutar', 'stopaj_tutari' => $kolon,
            'karsi_unvan' => $karsiUnvan,
            'karsi_vkn' => $karsiVkn,
            'karsi_ad_soyad' => $yon === FaturaYonu::Gelen ? 'gonderici_ad_soyad' : 'alici_ad_soyad',
            // Ekrandaki metinlerle aynı sıra: durum açıklaması, zarf durumunda GİB kodu
            'durum' => 'durum_aciklamasi',
            'zarf_durumu' => 'gib_durum_kodu',
            'emor' => 'emor_durumu',
            'entegrator' => self::SAGLAYICI_IFADESI,
            'sap' => 'sap_islendi',
            default => 'belge_tarihi',
        };

        // Boş değerler yönden bağımsız en sonda (PostgreSQL ile SQLite'ın NULL sırası farklı)
        $sorgu->orderByRaw("CASE WHEN {$sutun} IS NULL THEN 1 ELSE 0 END");

        if ($sutun === 'emor_durumu') {
            // Aşama sırası (alfabetik değil): işlendi > havuzda > yok
            $sorgu->orderByRaw("CASE emor_durumu WHEN 'islendi' THEN 4 WHEN 'elle_islendi' THEN 3 WHEN 'havuzda' THEN 2 ELSE 1 END {$yonu}");
        } elseif ($sutun === self::SAGLAYICI_IFADESI) {
            $sorgu->orderByRaw("{$sutun} {$yonu}");
        } else {
            $sorgu->orderBy($sutun, $yonu);
        }

        // id: eşit değerlerde sayfalar arası kararlı sıra
        return $sorgu->orderBy('id', $yonu);
    }

    /**
     * "eMOR VKN Var" sıralaması (kullanıcı isteği 2026-11-26): karşı tarafın VKN'si
     * eMOR kurum kartlarında olanlar bir grupta, sonra belge tarihi. Liste ERP'den;
     * okunamazsa (bilinmiyor) yalnız belge tarihiyle sıralanır — kalıcı sıralama
     * ERP erişilemezken ekranı kilitlemesin. VKN'ler bağlı parametredir (girdi
     * sorgu metnine girmez).
     *
     * @param  Builder<EFatura>  $sorgu
     * @param  'asc'|'desc'  $yonu
     * @return Builder<EFatura>
     */
    private function emorVknIleSirala(Builder $sorgu, string $karsiVkn, string $yonu): Builder
    {
        $vknler = array_map('strval', array_keys($this->kurumlar->harita() ?? []));

        if ($vknler !== []) {
            $yerler = implode(',', array_fill(0, count($vknler), '?'));
            $sorgu->orderByRaw("CASE WHEN {$karsiVkn} IN ({$yerler}) THEN 1 ELSE 0 END {$yonu}", $vknler);
        }

        return $sorgu->orderBy('belge_tarihi', $yonu)->orderBy('id', $yonu);
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
     * @param  EntegratorBaglanti|list<EntegratorBaglanti>  $tanimlar
     * @return array{durumlar: list<array{deger: string, aciklama: string|null}>, para_birimleri: list<string>, tipler: list<string>, istisna_kodlari: list<string>}
     */
    public function secenekler(EntegratorBaglanti|array $tanimlar, FaturaYonu $yon, string $baslangic, string $bitis): array
    {
        $tanimlar = $tanimlar instanceof EntegratorBaglanti ? [$tanimlar] : $tanimlar;
        $taban = EFatura::query()
            ->whereIn('entegrator_baglanti_id', $this->tanimIdleri($tanimlar))
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
        // İki kaynağın kodları birleşik; her ikisindeki virgüllü değerler ayrılır.
        // İstisna kodu yalnız İzibiz faturalarında var: diğer ekranlarda sorgu hiç çalışmaz (PRT-R7)
        $izibizYok = collect($tanimlar)->every(fn (EntegratorBaglanti $t): bool => $t->saglayici !== EntegratorBaglanti::SAGLAYICI_IZIBIZ);
        $istisnaKodlari = $izibizYok ? [] : collect([
            ...$taban->clone()->whereNotNull('vergi_istisna_kodu')->distinct()->pluck('vergi_istisna_kodu'),
            ...$taban->clone()->whereNotNull('izibiz_istisna_kodu')->distinct()->pluck('izibiz_istisna_kodu'),
        ])->flatMap(fn (string $kodlar): array => explode(',', $kodlar))
            ->map(fn (string $kod): string => trim($kod))->filter()->unique()->sort()->values()->all();

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
     * Ekrandaki sağlayıcı adı (İzibiz / Protel) aranan metni içeren tanımlar.
     * Karşılaştırma büyük/küçük harf ve Türkçe I/İ duyarsız ("izibiz", "IZIBIZ").
     *
     * @param  list<EntegratorBaglanti>  $tanimlar
     * @return list<int>
     */
    private function adiEslesenTanimlar(array $tanimlar, string $ara): array
    {
        $sade = fn (string $metin): string => mb_strtolower(str_replace(['İ', 'I', 'ı'], 'i', $metin));
        $aranan = $sade($ara);

        return array_values(array_map(
            fn (EntegratorBaglanti $t): int => $t->id,
            array_filter($tanimlar, fn (EntegratorBaglanti $t): bool => str_contains($sade(__('efatura.saglayici.'.$t->saglayici)), $aranan)),
        ));
    }

    /**
     * @param  EntegratorBaglanti|list<EntegratorBaglanti>  $tanimlar
     * @return list<int>
     */
    private function tanimIdleri(EntegratorBaglanti|array $tanimlar): array
    {
        $tanimlar = $tanimlar instanceof EntegratorBaglanti ? [$tanimlar] : $tanimlar;

        return array_map(fn (EntegratorBaglanti $t): int => $t->id, $tanimlar);
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
