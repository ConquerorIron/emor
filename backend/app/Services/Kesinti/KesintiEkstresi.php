<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Support\Metin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kesinti ekstresi (kullanıcı isteği 2026-11-13): Firma Özeti'nde tıklanan
 * rakamın arkasındaki kesinti satırları — taşeron + proje + kesinti türü (ya
 * da Toplam Kalan'da bütün türler), aynı tarih ve para biriminde, yalnız
 * taşerona yansıtılanlar. Kalan toplamı tıklanan rakama eşittir; kalanı
 * kesilmiş satırlar da listelenir (ekstre geçmişi gösterir).
 *
 * Her tür kendi ekranının Excel kolonlarıyla bir bölümdür; ekstrede her
 * satırda aynı olan kolonlar başlığa taşınır.
 */
final class KesintiEkstresi
{
    /** Kesinti ekranı => içe aktarımı (kolon tanımı); her ekran burada (test denetler) */
    public const AKTARIMLAR = [
        IsgucuIceAktarimi::ICMAL => IsgucuIceAktarimi::class,
        KampIceAktarimi::ICMAL => KampIceAktarimi::class,
        KampMalzemeIceAktarimi::ICMAL => KampMalzemeIceAktarimi::class,
        MakinaEkipmanIceAktarimi::ICMAL => MakinaEkipmanIceAktarimi::class,
        TutanakIceAktarimi::ICMAL => TutanakIceAktarimi::class,
        YemekIceAktarimi::ICMAL => YemekIceAktarimi::class,
        OsgbIceAktarimi::ICMAL => OsgbIceAktarimi::class,
        TetkikIceAktarimi::ICMAL => TetkikIceAktarimi::class,
        IsgCezaIceAktarimi::ICMAL => IsgCezaIceAktarimi::class,
        MalzemeIceAktarimi::ICMAL => MalzemeIceAktarimi::class,
        KartBasimIceAktarimi::ICMAL => KartBasimIceAktarimi::class,
    ];

    /** Bölümün toplam satırındaki tutarlar */
    public const TUTARLAR = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

    /** Ekstrede her satırda aynı (başlıkta yazılır) ya da tarihten türeyen kolonlar */
    private const BASLIKTAKI_KOLONLAR = ['kesinti_turu', 'taseron', 'proje', 'para_birimi', 'yansitma_turu', 'yil', 'ay'];

    /** Detayda Mahal'in yerine projenin adı (kullanıcı isteği 2026-11-13) */
    private const PROJE_KOLONU = 'proje_adi';

    /**
     * @param  array{tarih: string, para_birimi_id: int, firma_id: int, proje_id: int, kesinti_turu_id: int|null}  $parametreler
     * @return array{
     *     baslik: string,
     *     tarih: string,
     *     duzenlenme: string,
     *     firma: array{kod: string, ad: string, vergi_kimlik_no: string|null, vergi_dairesi: string|null},
     *     proje: string,
     *     proje_ad: string,
     *     para_birimi: string,
     *     isveren: string|null,
     *     kesinti_turu: string|null,
     *     bolumler: list<array{kod: string, ad: string, kolonlar: list<array{baslik: string, tur: string}>, satirlar: list<list<string>>, toplam: array<string, string>, kolon_alanlari: list<string>}>,
     *     genel_toplam: array<string, string>,
     * }
     */
    public function veri(array $parametreler): array
    {
        IceAktarimTablosu::bellekSiniriniYukselt();
        $firma = Firma::query()->findOrFail($parametreler['firma_id']);
        $proje = Proje::query()->findOrFail($parametreler['proje_id']);
        $tekTur = $parametreler['kesinti_turu_id'] !== null;
        $turler = KesintiTuru::ekranSirasiyla($tekTur ? [$parametreler['kesinti_turu_id']] : null);

        $bolumler = [];
        foreach ($turler as $tur) {
            if ($tur->icmal === null || ! isset(self::AKTARIMLAR[$tur->icmal])) {
                continue;
            }
            $bolum = $this->bolum($tur, $parametreler, $proje->ad !== '' ? $proje->ad : $proje->kod);
            if ($bolum !== null) {
                $bolumler[] = $bolum;
            }
        }

        $genel = array_fill_keys(self::TUTARLAR, 0.0);
        foreach ($bolumler as $bolum) {
            foreach (self::TUTARLAR as $alan) {
                $genel[$alan] += $bolum['toplam'][$alan];
            }
        }
        $tutarlar = fn (array $toplam): array => array_map(fn (float $tutar): string => $this->sayi($tutar, 2), $toplam);

        $turKodu = $tekTur ? ($turler[0]->kod ?? null) : null;

        return [
            'baslik' => $this->baslik($turKodu),
            'tarih' => CarbonImmutable::parse($parametreler['tarih'])->format('d.m.Y'),
            'duzenlenme' => CarbonImmutable::now()->format('d.m.Y H:i'),
            'firma' => [
                'kod' => $firma->kod,
                'ad' => $firma->ad,
                'vergi_kimlik_no' => $firma->vergi_kimlik_no,
                'vergi_dairesi' => $firma->vergi_dairesi,
            ],
            'proje' => $proje->kod,
            'proje_ad' => $proje->ad,
            'para_birimi' => (string) ParaBirimi::query()->whereKey($parametreler['para_birimi_id'])->value('kod'),
            'isveren' => Firma::query()->where('firmamiz', true)->value('kod'),
            'kesinti_turu' => $turKodu,
            'bolumler' => array_map(fn (array $bolum): array => ['toplam' => $tutarlar($bolum['toplam'])] + $bolum, $bolumler),
            'genel_toplam' => $tutarlar($genel),
        ];
    }

    /**
     * "KAMP KESİNTİ EKSTRESİ"; kodu zaten "… KESİNTİSİ" olan tür "MALZEME
     * KESİNTİSİ EKSTRESİ"; bütün türler "TÜM KESİNTİLER EKSTRESİ".
     */
    private function baslik(?string $turKodu): string
    {
        if ($turKodu === null) {
            return __('kesinti.ekstre.baslik_tumu');
        }
        $tur = Metin::buyukHarf($turKodu);

        return str_ends_with($tur, 'KESİNTİSİ')
            ? __('kesinti.ekstre.baslik_kesintisi', ['tur' => $tur])
            : __('kesinti.ekstre.baslik_tur', ['tur' => $tur]);
    }

    /**
     * Bir kesinti türünün satırları, kendi ekranının kolonlarıyla (Mahal yerine
     * proje adı); satırı yoksa null.
     *
     * @param  array{tarih: string, para_birimi_id: int, firma_id: int, proje_id: int, kesinti_turu_id: int|null}  $parametreler
     * @return array{kod: string, ad: string, kolonlar: list<array{baslik: string, tur: string}>, satirlar: list<list<string>>, toplam: array<string, float>, kolon_alanlari: list<string>}|null
     */
    private function bolum(KesintiTuru $tur, array $parametreler, string $projeAdi): ?array
    {
        /** @var IcmalIceAktarimi $aktarim */
        $aktarim = app(self::AKTARIMLAR[$tur->icmal]);
        /** @var class-string<Model> $model */
        $model = TumKesintilerSorgusu::KAYNAKLAR[$tur->icmal];
        $projeKolonu = ['alan' => self::PROJE_KOLONU, 'baslik' => __('kesinti.ekstre.proje_kolonu'), 'tur' => 'metin', 'basamak' => 0];
        $kolonlar = array_values(array_map(
            fn (array $kolon): array => $kolon['alan'] === 'mahal' ? $projeKolonu : $kolon,
            array_filter(
                $aktarim->excelKolonlari(),
                fn (array $kolon): bool => ! in_array($kolon['alan'], self::BASLIKTAKI_KOLONLAR, true),
            ),
        ));
        if (! in_array(self::PROJE_KOLONU, array_column($kolonlar, 'alan'), true)) {
            array_unshift($kolonlar, $projeKolonu);
        }

        $sorgu = fn (): Builder => $model::query()
            ->where('kesinti_turu_id', $tur->id)
            ->where('firma_id', $parametreler['firma_id'])
            ->where('proje_id', $parametreler['proje_id'])
            ->where('para_birimi_id', $parametreler['para_birimi_id'])
            ->where('yansitma_turu', YansitmaTuru::Taseron->value)
            ->whereDate('tarih', '<=', $parametreler['tarih']);

        $satirlar = [];
        foreach ($sorgu()->orderBy('tarih')->orderBy('id')->lazy(1000) as $kayit) {
            $satirlar[] = array_map(
                fn (array $kolon): string => $kolon['alan'] === self::PROJE_KOLONU ? $projeAdi : $this->hucre($kolon, $kayit),
                $kolonlar,
            );
        }

        if ($satirlar === []) {
            return null;
        }

        $toplamlar = $sorgu()->toBase()
            ->selectRaw(implode(', ', array_map(fn (string $alan): string => "SUM({$alan}) AS {$alan}", self::TUTARLAR)))
            ->first();

        return [
            'kod' => $tur->kod,
            'ad' => $tur->ad,
            'kolonlar' => array_map(fn (array $kolon): array => ['baslik' => $kolon['baslik'], 'tur' => $kolon['tur']], $kolonlar),
            'kolon_alanlari' => array_column($kolonlar, 'alan'),
            'satirlar' => $satirlar,
            'toplam' => array_combine(
                self::TUTARLAR,
                array_map(fn (string $alan): float => round((float) ($toplamlar->{$alan} ?? 0), 2), self::TUTARLAR),
            ),
        ];
    }

    /**
     * Ekstre hücresi: ekranın Excel'iyle aynı değer, Türkçe biçimde.
     *
     * @param  array{alan: string, baslik: string, tur: string, basamak: int}  $kolon
     */
    private function hucre(array $kolon, Model $kayit): string
    {
        $deger = $kayit->getAttribute($kolon['alan']);

        return match ($kolon['tur']) {
            'tarih' => $deger?->format('d.m.Y') ?? '',
            'sayi' => $deger === null ? '' : $this->sayi((float) $deger, $kolon['basamak']),
            'secenek' => $deger?->etiket() ?? '',
            default => (string) $deger,
        };
    }

    /** "1.234,56" */
    private function sayi(float $deger, int $basamak): string
    {
        return number_format($deger, $basamak, ',', '.');
    }
}
