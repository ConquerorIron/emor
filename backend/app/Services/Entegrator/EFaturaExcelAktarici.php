<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * e-Fatura listesinin Excel (.xlsx) çıktısı (EFAT-11).
 *
 * - Ekrandaki tablonun AYNISI (kullanıcı isteği 2026-09-24): listeyle aynı
 *   filtre, sıralama ve sayfa; ekranda görünen kolonlar ekrandaki başlık ve
 *   sırayla. Sayfa verilmezse filtrenin tamamı; satır sınırını çağıran denetler.
 * - Metin hücreleri açıkça metin tipiyle yazılır: "=", "+", "-", "@" ile
 *   başlayan unvan/açıklama formül olarak yorumlanmaz (CSV/Excel enjeksiyonu).
 * - Tarihler Excel tarihidir. Güvenle temsil edilen tutarlar sayı, Excel'in
 *   15 anlamlı basamak sınırını aşan tutarlar kuruş kaybını önlemek için metindir.
 */
final class EFaturaExcelAktarici
{
    /**
     * Ekrandaki seçilebilir kolonlar: anahtar => [tür, Excel genişliği].
     * Anahtarlar frontend'deki kolon anahtarlarıyla aynıdır.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    public const KOLONLAR = [
        // Tüm Faturalar: satırın posta kutusu (İzibiz / Protel)
        'entegrator' => ['metin', 10],
        'erp_okundu' => ['metin', 10],
        'emor' => ['metin', 14],
        'belge_no' => ['metin', 20],
        'belge_tarihi' => ['tarih', 12],
        'karsi_vkn' => ['metin', 14],
        'karsi_unvan' => ['metin', 45],
        'karsi_ad_soyad' => ['metin', 24],
        'fatura_tipi' => ['metin', 14],
        'izibiz_istisna_kodu' => ['metin', 14],
        'vergi_istisna_kodu' => ['metin', 14],
        'tutar' => ['tutar', 16],
        'para_birimi' => ['metin', 8],
        'olusturma_zamani' => ['zaman', 19],
        'irsaliye_no' => ['metin', 20],
        'siparis_no' => ['metin', 16],
        'durum' => ['metin', 22],
        'zarf_durumu' => ['metin', 32],
        'yanit_aciklamasi' => ['metin', 30],
        'gtb_ref_no' => ['metin', 18],
        'gcb_tescil_no' => ['metin', 18],
        'gcb_tarihi' => ['tarih', 12],
        'gonderici_etiketi' => ['metin', 30],
        'alici_etiketi' => ['metin', 30],
        'portal_notu' => ['metin', 30],
        'ettn' => ['metin', 38],
        'senaryo' => ['metin', 18],
        'vergi_tutari' => ['tutar', 14],
        // Protel ekranı (PRT-12)
        'erp_durumu' => ['metin', 14],
        'yazdirildi' => ['metin', 10],
        'otel_erp_okundu' => ['metin', 12],
        // Tüm Faturalar: hangi sisteme girildi — kaynak uçlar gelene kadar boş (null)
        'sap' => ['metin', 10],
        // Protel ve Tüm Faturalar: karşı tarafın VKN'si eMOR'daki bir kurum kartında mı
        'emor_vkn' => ['metin', 12],
        // e-Arşiv / e-SMM ekranı (EK-08): belge türü, makbuzun brüt ücreti ve stopajı
        'belge_turu' => ['metin', 10],
        'brut_tutar' => ['tutar', 16],
        'stopaj_tutari' => ['tutar', 14],
    ];

    public function __construct(private readonly ErpKurumVknleri $kurumlar) {}

    /**
     * @param  Builder<EFatura>  $sorgu  filtrelenmiş ve sıralanmış sorgu
     * @param  EntegratorBaglanti|list<EntegratorBaglanti>  $tanimlar  listenin kapsamı (özet sayfasındaki ortam)
     * @param  list<array{para_birimi: string, adet: int, tutar: string, vergi_tutari: string}>  $ozet
     * @param  array{baslangic: string, bitis: string}  $aralik
     * @param  list<array{anahtar: string, baslik: string}>  $kolonlar  ekrandaki görünür kolonlar, sırasıyla
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(Builder $sorgu, FaturaYonu $yon, EntegratorBaglanti|array $tanimlar, array $ozet, array $aralik, array $kolonlar, ?string $baslik = null): string
    {
        // Yalnız bu istek için (bkz. config/efatura.php ölçümü)
        $this->bellekSiniriniYukselt((string) config('efatura.excel_bellek_siniri'));
        $tanimlar = $tanimlar instanceof EntegratorBaglanti ? [$tanimlar] : $tanimlar;
        $baslik ??= __('efatura.excel.baslik_'.$yon->value);
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);

        $sayfa = $kitap->getActiveSheet();
        $sayfa->setTitle(__('efatura.excel.faturalar'));
        // cursor() eager load yapmaz: sağlayıcı kapsamdaki tanımlardan eşlenir
        $saglayicilar = array_column(array_map(fn (EntegratorBaglanti $t): array => [$t->id, $t->saglayici], $tanimlar), 1, 0);
        $this->faturalariYaz($sayfa, $sorgu, $yon, $kolonlar, $saglayicilar);

        $this->ozetYaz($kitap->createSheet(), $baslik, $tanimlar, $ozet, $aralik);

        $yol = tempnam(sys_get_temp_dir(), 'efatura-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('e-Fatura Excel geçici dosyası oluşturulamadı.');
        }

        try {
            (new Xlsx($kitap))->save($yol);
        } catch (\Throwable $hata) {
            unlink($yol);

            throw $hata;
        } finally {
            $kitap->disconnectWorksheets();
        }

        return $yol;
    }

    /**
     * Ekrandaki tablonun kolonları, ekrandaki başlık ve sırayla (kullanıcı
     * isteği 2026-09-24). Değerler ekrandakiyle aynı kaynaktan.
     *
     * @param  Builder<EFatura>  $sorgu
     * @param  list<array{anahtar: string, baslik: string}>  $kolonlar
     * @param  array<int, string>  $saglayicilar  tanım kimliği => sağlayıcı
     */
    private function faturalariYaz(Worksheet $sayfa, Builder $sorgu, FaturaYonu $yon, array $kolonlar, array $saglayicilar): void
    {
        foreach ($kolonlar as $i => $kolon) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $kolon['baslik'], DataType::TYPE_STRING);
        }

        $sonKolon = Coordinate::stringFromColumnIndex(max(1, count($kolonlar)));
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');

        $satir = 2;
        foreach ($sorgu->cursor() as $f) {
            /** @var EFatura $f */
            foreach ($kolonlar as $i => $kolon) {
                [$tur, $deger] = $this->hucre($kolon['anahtar'], $f, $yon, $saglayicilar);
                $this->yaz($sayfa, $i + 1, $satir, $tur, $deger);
            }
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);
        foreach ($kolonlar as $i => $kolon) {
            $harf = Coordinate::stringFromColumnIndex($i + 1);
            [$tur, $genislik] = self::KOLONLAR[$kolon['anahtar']];
            $bicim = match ($tur) {
                'tarih' => 'dd.mm.yyyy',
                'zaman' => 'dd.mm.yyyy hh:mm:ss',
                'tutar' => '#,##0.00',
                default => null,
            };
            if ($bicim !== null) {
                $sayfa->getStyle("{$harf}2:{$harf}{$sonSatir}")->getNumberFormat()->setFormatCode($bicim);
            }
            $sayfa->getColumnDimension($harf)->setWidth($genislik);
        }
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonSatir}");
    }

    /**
     * Ekrandaki kolonun hücre değeri: [tür, değer]. Karşı taraf gelen faturada
     * gönderici, giden faturada alıcıdır (ekrandaki gibi).
     *
     * @param  array<int, string>  $saglayicilar  tanım kimliği => sağlayıcı
     * @return array{0: string, 1: mixed}
     */
    private function hucre(string $anahtar, EFatura $f, FaturaYonu $yon, array $saglayicilar): array
    {
        $karsi = $yon === FaturaYonu::Gelen ? 'gonderici' : 'alici';
        $kod = $f->getAttribute('gib_durum_kodu');

        $saglayici = $saglayicilar[$f->entegrator_baglanti_id] ?? null;

        return match ($anahtar) {
            'entegrator' => ['metin', $saglayici === null ? null : __('efatura.saglayici.'.$saglayici)],
            'erp_okundu' => ['metin', match ($f->erp_okundu) {
                true => __('efatura.evet'),
                false => __('efatura.hayir'),
                null => null,
            }],
            'emor' => ['metin', $f->emor_durumu === null ? null : __('efatura.emor.'.$f->emor_durumu->value)],
            'otel_erp_okundu' => ['metin', $this->evetHayir($f->otel_erp_okundu)],
            // ERP okunamazsa boş (bilinmiyor); ekrandaki gibi
            'emor_vkn' => ['metin', $this->kurumlar->harita() === null ? null : $this->evetHayir($this->kurumlar->kurum($f->getAttribute($karsi.'_vkn')) !== null)],
            // Ekrandaki gibi: RED fatura (kaynakta ya da harici) "Red" (önce gelir),
            // işlenen "İşlendi" (Protel / statik kaynakta anahtarla işaretlenir)
            'sap' => ['metin', match (true) {
                $f->reddedildi() => __('efatura.sap_red'),
                $f->sap_islendi === true => __('efatura.sap_islendi'),
                default => $this->evetHayir($f->sap_islendi),
            }],
            'yazdirildi' => ['metin', match ($f->yazdirildi) {
                true => __('efatura.evet'),
                false => __('efatura.hayir'),
                null => null,
            }],
            'belge_tarihi' => ['tarih', $f->belge_tarihi],
            'karsi_vkn' => ['metin', $f->getAttribute($karsi.'_vkn')],
            'karsi_unvan' => ['metin', $f->getAttribute($karsi.'_unvan')],
            'karsi_ad_soyad' => ['metin', $f->getAttribute($karsi.'_ad_soyad')],
            'tutar' => ['tutar', $f->tutar],
            'vergi_tutari' => ['tutar', $f->getAttribute('vergi_tutari')],
            'brut_tutar' => ['tutar', $f->getAttribute('brut_tutar')],
            'stopaj_tutari' => ['tutar', $f->getAttribute('stopaj_tutari')],
            'belge_turu' => ['metin', $f->belge_turu === null ? null : __('efatura.belge_turu.'.$f->belge_turu)],
            'olusturma_zamani' => ['zaman', $f->getAttribute('olusturma_zamani')],
            'durum' => ['metin', $f->getAttribute('durum_aciklamasi') ?? $f->getAttribute('durum')],
            'zarf_durumu' => ['metin', $kod === null
                ? $f->getAttribute('gib_durum_aciklamasi')
                : trim($kod.' '.$f->getAttribute('gib_durum_aciklamasi'))],
            'gcb_tarihi' => ['tarih', $this->tarihMi($f->getAttribute('gcb_tarihi'))],
            // İzibiz etiketi yoksa ERP havuzundaki karşılığı (ekrandaki gibi)
            'gonderici_etiketi' => ['metin', $f->getAttribute('gonderici_etiketi') ?? $f->getAttribute('erp_gonderici_etiketi')],
            'alici_etiketi' => ['metin', $f->getAttribute('alici_etiketi') ?? $f->getAttribute('erp_alici_etiketi')],
            default => ['metin', $f->getAttribute($anahtar)],
        };
    }

    private function yaz(Worksheet $sayfa, int $kolon, int $satir, string $tur, mixed $deger): void
    {
        if ($deger === null || $deger === '') {
            return;
        }

        match ($tur) {
            'tarih' => $sayfa->setCellValueExplicit([$kolon, $satir], ExcelTarihi::PHPToExcel(CarbonImmutable::parse($deger)->toDateString()), DataType::TYPE_NUMERIC),
            // Excel saat dilimi bilmez: İstanbul yerel saati yazılır
            'zaman' => $sayfa->setCellValueExplicit(
                [$kolon, $satir],
                ExcelTarihi::PHPToExcel(CarbonImmutable::parse($deger)->setTimezone((string) config('entegrator.izibiz.saat_dilimi'))->toDateTimeString()),
                DataType::TYPE_NUMERIC,
            ),
            'tutar' => $this->tutar($sayfa, $kolon, $satir, (string) $deger),
            default => $this->metin($sayfa, $kolon, $satir, $deger),
        };
    }

    /** Sınır yalnız yükseltilir: sunucu daha yüksek/sınırsız verdiyse düşürülmez. */
    private function bellekSiniriniYukselt(string $hedef): void
    {
        $mevcut = (string) ini_get('memory_limit');

        if ($mevcut !== '-1' && $this->bayt($mevcut) < $this->bayt($hedef)) {
            ini_set('memory_limit', $hedef);
        }
    }

    /** php.ini kısaltmalı boyutu (512M, 1G) bayta çevirir. */
    private function bayt(string $deger): int
    {
        $deger = trim($deger);
        $sayi = (int) $deger;

        return match (strtoupper(substr($deger, -1))) {
            'G' => $sayi * 1024 ** 3,
            'M' => $sayi * 1024 ** 2,
            'K' => $sayi * 1024,
            default => $sayi,
        };
    }

    private function evetHayir(?bool $deger): ?string
    {
        return match ($deger) {
            true => __('efatura.evet'),
            false => __('efatura.hayir'),
            null => null,
        };
    }

    /** Serbest metin alanı tarih değilse yazılmaz (hücre boş kalır). */
    private function tarihMi(mixed $deger): ?string
    {
        if (! is_string($deger) || preg_match('/^\d{4}-\d{2}-\d{2}/', $deger) !== 1) {
            return null;
        }

        return substr($deger, 0, 10);
    }

    /**
     * @param  list<EntegratorBaglanti>  $tanimlar
     * @param  list<array{para_birimi: string, adet: int, tutar: string, vergi_tutari: string}>  $ozet
     * @param  array{baslangic: string, bitis: string}  $aralik
     */
    private function ozetYaz(Worksheet $sayfa, string $baslik, array $tanimlar, array $ozet, array $aralik): void
    {
        $sayfa->setTitle(__('efatura.excel.ozet'));
        // Birleşik listede tanımların ortamı farklı olabilir: hepsi yazılır
        $ortamlar = collect($tanimlar)->map(fn (EntegratorBaglanti $t): string => __('efatura.ortam.'.$t->ortam))->unique()->implode(', ');

        $bilgiler = [
            [__('efatura.excel.liste'), $baslik],
            [__('efatura.excel.ortam'), $ortamlar],
            [__('efatura.excel.tarih_araligi'), CarbonImmutable::parse($aralik['baslangic'])->format('d.m.Y').' – '.CarbonImmutable::parse($aralik['bitis'])->format('d.m.Y')],
            [__('efatura.excel.olusturuldu'), CarbonImmutable::now((string) config('entegrator.izibiz.saat_dilimi'))->format('d.m.Y H:i')],
        ];

        $satir = 1;
        foreach ($bilgiler as [$etiket, $deger]) {
            $this->metin($sayfa, 1, $satir, (string) $etiket);
            $this->metin($sayfa, 2, $satir, (string) $deger);
            $sayfa->getStyle("A{$satir}")->getFont()->setBold(true);
            $satir++;
        }

        $satir++;
        foreach ([__('efatura.alan.para_birimi'), __('efatura.excel.adet'), __('efatura.alan.tutar'), __('efatura.alan.vergi_tutari')] as $i => $baslik) {
            $this->metin($sayfa, $i + 1, $satir, (string) $baslik);
        }
        $sayfa->getStyle("A{$satir}:D{$satir}")->getFont()->setBold(true);

        foreach ($ozet as $grup) {
            $satir++;
            $this->metin($sayfa, 1, $satir, $grup['para_birimi']);
            $sayfa->setCellValueExplicit([2, $satir], $grup['adet'], DataType::TYPE_NUMERIC);
            $this->tutar($sayfa, 3, $satir, $grup['tutar']);
            $this->tutar($sayfa, 4, $satir, $grup['vergi_tutari']);
            $sayfa->getStyle("C{$satir}:D{$satir}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $sayfa->getColumnDimension('A')->setWidth(18);
        $sayfa->getColumnDimension('B')->setWidth(28);
        $sayfa->getColumnDimension('C')->setWidth(18);
        $sayfa->getColumnDimension('D')->setWidth(18);
    }

    private function metin(Worksheet $sayfa, int $kolon, int $satir, mixed $deger): void
    {
        if ($deger === null || $deger === '') {
            return;
        }

        $sayfa->setCellValueExplicit([$kolon, $satir], (string) $deger, DataType::TYPE_STRING);
    }

    /** Excel'in 15 anlamlı basamak sınırından sonra sayıya çevirme veri kaybettirir. */
    private function tutar(Worksheet $sayfa, int $kolon, int $satir, string $deger): void
    {
        $basamaklar = ltrim(str_replace(['-', '.'], '', $deger), '0');

        if (strlen($basamaklar) > 15) {
            $sayfa->setCellValueExplicit([$kolon, $satir], $deger, DataType::TYPE_STRING);

            return;
        }

        $sayfa->setCellValueExplicit([$kolon, $satir], (float) $deger, DataType::TYPE_NUMERIC);
    }
}
