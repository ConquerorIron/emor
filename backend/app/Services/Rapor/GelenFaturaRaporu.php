<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Raporlar → Gelen Fatura Raporu (kullanıcı isteği 2026-11-26): bütün gelen
 * faturalar (Tüm Faturalar'ın kaynakları) Logo'nun gelen fatura Excel'inin
 * kolonlarıyla. Hareket No ve Kesinti yok (kullanıcı: entegratörden alınmaz).
 *
 * ERP raporlarının ekranı, Excel'i ve özet sekmeleri ortaktır; yalnız satırlar
 * ERP proc'u yerine uygulamanın tablolarından gelir (satirlar() ezilir):
 * fatura + UBL'den / Logo dosyasından okunan vergi kalemleri.
 *
 * Hesap (belge para biriminde):
 * - Net Tutar = vergi hariç tutar; Orijinal Tutar = UBL'deki mal/hizmet toplamı,
 *   yoksa net + indirim (Logo dosyasında indirim öncesi toplam yok).
 * - KDV = KDV kalemleri (0015); Fatura Toplamı = net + bütün vergi kalemleri
 *   (satıcının "vergi dahil" alanı kullanılmaz: kimi satıcı tutarsız yazıyor).
 * - Net KDV = KDV − tevkifat. TL = orijinal × kur; TRY'de kur 1, dövizde kur
 *   yoksa TL boş.
 * - Vergi kalemi olmayan fatura (UBL'i henüz okunmamış, e-Arşiv / e-SMM):
 *   listedeki tutar ve vergiden; oran kolonları boş.
 * Gizlenen (yanlış posta kutusu) fatura rapora girmez; RED fatura girer,
 * ekrandaki süzgeçle çıkarılabilir.
 */
final class GelenFaturaRaporu extends ErpRaporu
{
    public const DIL = 'gelen_fatura_raporu';

    public const ANAHTAR = 'id';

    public const DOSYA_ADI = 'gelen-fatura-raporu.xlsx';

    /** Raporun KDV oranları (Logo Excel'i); diğer oranlar Matrah Toplamı'nda */
    private const ORANLAR = ['0', '1', '10', '20'];

    public const KOLONLAR = [
        'fatura_tarihi' => 'tarih',
        'fatura_no' => 'metin',
        'fatura_profili' => 'metin',
        'entegrator' => 'metin',
        'firma_unvani' => 'metin',
        'vkn' => 'metin',
        'vergi_dairesi' => 'metin',
        'para_kodu' => 'metin',
        'orijinal_tutar' => 'tutar',
        'orijinal_indirim' => 'tutar',
        'net_tutar' => 'tutar',
        'orijinal_kdv' => 'tutar',
        'fatura_toplami' => 'tutar',
        'orijinal_tevkifat' => 'tutar',
        'net_kdv' => 'tutar',
        'kur' => 'metin',
        'tutar_tl' => 'tutar',
        'indirim_tl' => 'tutar',
        'kdv_matrahi_tl' => 'tutar',
        'kdv_tl' => 'tutar',
        'fatura_toplami_tl' => 'tutar',
        'tevkifat_tl' => 'tutar',
        'net_kdv_tl' => 'tutar',
        'oran_0' => 'metin', 'kdv_0' => 'tutar', 'matrah_0' => 'tutar',
        'oran_1' => 'metin', 'kdv_1' => 'tutar', 'matrah_1' => 'tutar',
        'oran_10' => 'metin', 'kdv_10' => 'tutar', 'matrah_10' => 'tutar',
        'oran_20' => 'metin', 'kdv_20' => 'tutar', 'matrah_20' => 'tutar',
        'matrah_toplami' => 'tutar',
        'istisna_kodu' => 'metin',
        'istisna_adi' => 'metin',
        'tevkifat_kodu' => 'metin',
        'tevkifat_aciklama' => 'metin',
    ];

    public const PIVOTLAR = [
        'firma' => ['firma_unvani', 'para_kodu'],
        'entegrator' => ['entegrator', 'para_kodu'],
        'tarih' => ['yil', 'ay', 'para_kodu'],
    ];

    public const TARIH_ALANI = 'fatura_tarihi';

    /** Kolon dışı alan: ekrandaki "RED'leri çıkar" süzgeci */
    public const RED_ALANI = 'red';

    private const KDV_KODU = '0015';

    private const FATURA_KOLONLARI = [
        'id', 'entegrator_baglanti_id', 'belge_tarihi', 'belge_no', 'senaryo', 'gonderici_unvan', 'gonderici_ad_soyad',
        'gonderici_vkn', 'satici_vergi_dairesi', 'para_birimi', 'tutar', 'vergi_tutari', 'satir_toplami',
        'indirim_tutari', 'vergi_haric_tutar', 'kur', 'durum', 'harici_red_zamani', 'izibiz_istisna_kodu', 'vergi_istisna_kodu',
    ];

    public function __construct(
        ErpRaporKaynagi $kaynak,
        private readonly EntegratorBaglantiServisi $baglantilar,
    ) {
        parent::__construct($kaynak);
    }

    /**
     * ERP'ye gidilmez: kullanıcının ERP kimliği ve proje süzgeci kullanılmaz;
     * firma adı verilirse ünvanda geçen faturalar.
     *
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     */
    public function satirlar(int $erpKullaniciId, array $parametreler): array
    {
        /** @var array<int, string> $entegratorler bağlantı kimliği => sağlayıcının adı */
        $entegratorler = collect($this->baglantilar->gelenTanimlari())
            ->mapWithKeys(fn (EntegratorBaglanti $tanim): array => [$tanim->id => __('efatura.saglayici.'.$tanim->saglayici)])
            ->all();

        $sorgu = EFatura::query()
            ->whereIn('entegrator_baglanti_id', array_keys($entegratorler))
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('gizlenme_zamani')
            ->where('belge_tarihi', '>=', $parametreler['ilk_tarih'])
            // Ertesi günden küçük: SQLite tarihi saatle saklar (T6)
            ->where('belge_tarihi', '<', CarbonImmutable::parse($parametreler['son_tarih'])->addDay()->toDateString())
            ->when($parametreler['firma_adi'] !== null, fn ($q) => $q->whereLike('gonderici_unvan', '%'.$parametreler['firma_adi'].'%'));

        $kalemler = $this->kalemler($sorgu);

        // Model kurulmaz (tam yılda ~12.000 fatura): düz satırlar, yalnız gereken kolonlar
        $satirlar = [];
        foreach ($sorgu->toBase()->get(self::FATURA_KOLONLARI) as $fatura) {
            $satirlar[] = $this->hesapla($fatura, $kalemler[(int) $fatura->id] ?? [], $entegratorler[(int) $fatura->entegrator_baglanti_id] ?? '');
        }
        usort($satirlar, $this->karsilastir(...));

        return $satirlar;
    }

    public function toplamSatirlari(array $satirlar): array
    {
        if ($satirlar === []) {
            return [];
        }

        $tutarlar = array_keys(array_filter(self::KOLONLAR, fn (string $tur): bool => $tur === 'tutar'));
        $tl = array_values(array_filter($tutarlar, fn (string $alan): bool => str_ends_with($alan, '_tl')));
        $orijinal = array_values(array_diff($tutarlar, $tl));

        return [
            ['etiket' => __('rapor.genel_toplam_tl'), 'degerler' => $this->topla($satirlar, $tl)],
            ...$this->etiketle(__('rapor.toplam'), $this->paraKodunaGoreTopla($satirlar, $orijinal)),
        ];
    }

    /** satirlar() ezildiği için kullanılmaz (proc satırı yok) */
    protected function satir(array $ham): array
    {
        return $ham;
    }

    /** En yeni fatura önce; aynı günde fatura numarası büyük olan önce */
    protected function karsilastir(array $a, array $b): int
    {
        return [$b['fatura_tarihi'], $b['fatura_no']] <=> [$a['fatura_tarihi'], $a['fatura_no']];
    }

    /**
     * Raporun faturalarının vergi kalemleri tek sorguda (alt sorgu), sırasıyla.
     *
     * @param  Builder<EFatura>  $faturalar
     * @return array<int, list<object{efatura_id: int, tur: string, vergi_kodu: string|null, vergi_adi: string|null, oran: string|null, matrah: string|null, tutar: string|null, istisna_kodu: string|null, istisna_adi: string|null}>>
     */
    private function kalemler(Builder $faturalar): array
    {
        $gruplu = [];
        $kalemler = DB::table('efatura_vergi_kalemleri')
            ->whereIn('efatura_id', (clone $faturalar)->select('id'))
            ->orderBy('efatura_id')
            ->orderBy('sira')
            ->get(['efatura_id', 'tur', 'vergi_kodu', 'vergi_adi', 'oran', 'matrah', 'tutar', 'istisna_kodu', 'istisna_adi']);

        foreach ($kalemler as $kalem) {
            $gruplu[(int) $kalem->efatura_id][] = $kalem;
        }

        return $gruplu;
    }

    /** Raporun oran kolonlarından biriyse onun anahtarı ("20"), değilse null */
    private function oranAnahtari(mixed $oran): ?string
    {
        if ($oran === null || ! is_numeric($oran)) {
            return null;
        }

        foreach (self::ORANLAR as $aday) {
            if (bccomp((string) $oran, $aday, 4) === 0) {
                return $aday;
            }
        }

        return null;
    }

    /**
     * @param  object  $f  efatura_faturalari satırı (FATURA_KOLONLARI)
     * @param  list<object>  $kalemler
     * @return array<string, int|string|float|null>
     */
    private function hesapla(object $f, array $kalemler, string $entegrator): array
    {
        $kalemliMi = $kalemler !== [];
        $kdvKalemi = 0.0;
        $digerVergi = 0.0;
        $tevkifat = 0.0;
        $kdvMatrahi = 0.0;
        $istisna = null;
        $ilkTevkifat = null;
        /** @var array<string, array{kdv: float, matrah: float, var: bool}> $oranlar */
        $oranlar = array_fill_keys(self::ORANLAR, ['kdv' => 0.0, 'matrah' => 0.0, 'var' => false]);

        foreach ($kalemler as $k) {
            $tutar = (float) $k->tutar;

            if ($k->tur === EFaturaVergiKalemi::TUR_TEVKIFAT) {
                $tevkifat += $tutar;
                $ilkTevkifat ??= $k;

                continue;
            }
            if ($k->vergi_kodu !== self::KDV_KODU) {
                $digerVergi += $tutar;

                continue;
            }

            $kdvKalemi += $tutar;
            $kdvMatrahi += (float) $k->matrah;
            if ($istisna === null && $k->istisna_kodu !== null) {
                $istisna = $k;
            }
            $oran = $this->oranAnahtari($k->oran);
            if ($oran !== null) {
                $oranlar[$oran]['kdv'] += $tutar;
                $oranlar[$oran]['matrah'] += (float) $k->matrah;
                $oranlar[$oran]['var'] = true;
            }
        }

        $kdv = $kalemliMi ? $kdvKalemi : (float) ($f->vergi_tutari ?? 0);
        $net = $f->vergi_haric_tutar !== null ? (float) $f->vergi_haric_tutar : (float) $f->tutar - $kdv;
        $indirim = (float) ($f->indirim_tutari ?? 0);
        $orijinal = $f->satir_toplami !== null ? (float) $f->satir_toplami : $net + $indirim;
        $faturaToplami = $kalemliMi ? $net + $kdv + $digerVergi : (float) $f->tutar;
        $kur = $f->para_birimi === 'TRY' ? 1.0 : ($f->kur !== null ? (float) $f->kur : null);
        $tl = fn (float $deger): ?float => $kur === null ? null : $this->tutar($deger * $kur);

        $satir = [
            'id' => (int) $f->id,
            // PostgreSQL 'Y-m-d', SQLite saatli saklar
            'fatura_tarihi' => substr((string) $f->belge_tarihi, 0, 10),
            'fatura_no' => (string) $f->belge_no,
            'fatura_profili' => $this->metin($f->senaryo),
            'entegrator' => $entegrator,
            'firma_unvani' => $this->metin($f->gonderici_unvan ?? $f->gonderici_ad_soyad),
            'vkn' => $this->metin($f->gonderici_vkn),
            'vergi_dairesi' => $this->metin($f->satici_vergi_dairesi),
            'para_kodu' => (string) $f->para_birimi,
            'orijinal_tutar' => $this->tutar($orijinal),
            'orijinal_indirim' => $this->tutar($indirim),
            'net_tutar' => $this->tutar($net),
            'orijinal_kdv' => $this->tutar($kdv),
            'fatura_toplami' => $this->tutar($faturaToplami),
            'orijinal_tevkifat' => $this->tutar($tevkifat),
            'net_kdv' => $this->tutar($kdv - $tevkifat),
            'kur' => $kur === null ? '' : rtrim(rtrim(number_format($kur, 6, ',', ''), '0'), ','),
            'tutar_tl' => $tl($orijinal),
            'indirim_tl' => $tl($indirim),
            'kdv_matrahi_tl' => $tl($net),
            'kdv_tl' => $tl($kdv),
            'fatura_toplami_tl' => $tl($faturaToplami),
            'tevkifat_tl' => $tl($tevkifat),
            'net_kdv_tl' => $tl($kdv - $tevkifat),
            'matrah_toplami' => $this->tutar($kalemliMi ? $kdvMatrahi : $net),
            'istisna_kodu' => $this->metin($istisna?->istisna_kodu ?? $f->izibiz_istisna_kodu ?? $f->vergi_istisna_kodu),
            'istisna_adi' => $this->metin($istisna?->istisna_adi),
            'tevkifat_kodu' => $this->metin($ilkTevkifat?->vergi_kodu),
            'tevkifat_aciklama' => $this->metin($ilkTevkifat?->vergi_adi),
            self::RED_ALANI => EFatura::redDurumu($f->durum, $f->harici_red_zamani) ? 'evet' : 'hayir',
        ];

        foreach ($oranlar as $oran => $toplam) {
            $satir["kdv_{$oran}"] = $kalemliMi ? $this->tutar($toplam['kdv']) : null;
            $satir["matrah_{$oran}"] = $kalemliMi ? $this->tutar($toplam['matrah']) : null;
            $satir["oran_{$oran}"] = $toplam['var'] ? "%{$oran}" : '';
        }

        return $satir;
    }
}
