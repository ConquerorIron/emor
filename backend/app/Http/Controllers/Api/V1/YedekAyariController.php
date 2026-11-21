<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\YedekAyari;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KalicilikDogrulanamadi;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\SifrelemeHatasi;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekImzasi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ayarlar → Yedekleme Ayarları (BY-018; README §4.3, §4.8, §4.7). Tek satır.
 * Her değişiklik denetim kaydına (eski → yeni değerler) ve ayar dosya
 * kopyasına yazılır (`AyarKopyasi`: veritabanı geri yüklenince ayarlar
 * kaybolmasın; çalıştırıcı mail ayarını oradan da okur). Kopya yalnız commit
 * olmuş ayarlardan üretilir; yazılamazsa izlenir ve çalıştırıcı uzlaştırır (kaydet()).
 *
 * Şifreleme açık anahtarı ayrı uçla yüklenir; özel anahtar içeren metin
 * reddedilir (sunucu özel anahtar saklamaz — BY-019).
 */
final class YedekAyariController extends Controller
{
    /** Ekrandan değişen alanlar */
    private const ALANLAR = [
        'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun', 'enaz_adet',
        'enaz_bos_alan_yuzde', 'bildirim_alicilari', 'sinyal_yerel_adresi', 'sinyal_uzak_adresi', 'sinyal_sinama_adresi',
    ];

    public function __construct(
        private readonly YedekDenetimi $denetim,
        private readonly AyarKopyasi $ayarKopyasi,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly YedekImzasi $imza,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->diziye(YedekAyari::guncel())]);
    }

    public function update(Request $request): JsonResponse
    {
        $sinyal = ['nullable', 'string', 'max:500', 'url:https'];
        $veri = $request->validate([
            'yayin_oncesi_son_adet' => ['required', 'integer', 'between:0,1000'],
            'yayin_oncesi_gun' => ['required', 'integer', 'between:0,3650'],
            'elle_gun' => ['required', 'integer', 'between:0,3650'],
            'geri_yukleme_oncesi_gun' => ['required', 'integer', 'between:0,3650'],
            // En az sayı 1'in altına inemez: son başarılı yedek her zaman kalır (README §4.3)
            'enaz_adet' => ['required', 'integer', 'between:1,100'],
            'enaz_bos_alan_yuzde' => ['required', 'integer', 'between:1,90'],
            'bildirim_alicilari' => ['present', 'array', 'max:20'],
            'bildirim_alicilari.*' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'sinyal_yerel_adresi' => $sinyal,
            'sinyal_uzak_adresi' => $sinyal,
            'sinyal_sinama_adresi' => $sinyal,
        ]);

        $ayar = $this->kaydet($request, 'ayar_guncelle', function (YedekAyari $ayar) use ($veri): array {
            $once = Arr::only($ayar->attributesToArray(), self::ALANLAR);
            $ayar->fill(Arr::only($veri, self::ALANLAR))->save();

            return $this->fark($once, Arr::only($ayar->refresh()->attributesToArray(), self::ALANLAR));
        });

        return response()->json(['data' => $this->diziye($ayar)]);
    }

    /** Sunucu dışı kopyaların açık anahtarı (ASCII zırhlı); doğrulanıp kaydedilir */
    public function anahtarYukle(Request $request, KopyaSifreleyici $sifreleyici): JsonResponse
    {
        /** @var array{acik_anahtar: string} $veri */
        $veri = $request->validate(['acik_anahtar' => ['required', 'string', 'max:65536']]);

        try {
            $bilgi = $sifreleyici->acikAnahtarBilgisi($veri['acik_anahtar']);
        } catch (SifrelemeHatasi $hata) {
            return response()->json(['kod' => $hata->kod, 'mesaj' => $hata->getMessage(), 'hatalar' => ['acik_anahtar' => [$hata->getMessage()]]], 422);
        }

        $ayar = $this->kaydet($request, 'sifreleme_anahtari_yukle', function (YedekAyari $ayar) use ($veri, $bilgi): array {
            $onceki = $ayar->sifreleme_parmak_izi;
            $ayar->fill([
                'sifreleme_acik_anahtari' => trim($veri['acik_anahtar']),
                'sifreleme_parmak_izi' => $bilgi['parmak_izi'],
                'sifreleme_anahtar_kullanicisi' => $bilgi['kullanici'],
                'sifreleme_anahtar_bitisi' => $bilgi['bitis'],
            ])->save();

            return ['onceki_parmak_izi' => $onceki, 'parmak_izi' => $bilgi['parmak_izi']];
        });

        return response()->json(['data' => $this->diziye($ayar)]);
    }

    /** Anahtarı kaldır: sunucu dışı kopyalar şifrelenemez, gönderilmez (sessizce şifresiz gitmez) */
    public function anahtarKaldir(Request $request): JsonResponse
    {
        $ayar = $this->kaydet($request, 'sifreleme_anahtari_kaldir', function (YedekAyari $ayar): array {
            $onceki = $ayar->sifreleme_parmak_izi;
            $ayar->fill(['sifreleme_acik_anahtari' => null, 'sifreleme_parmak_izi' => null, 'sifreleme_anahtar_kullanicisi' => null, 'sifreleme_anahtar_bitisi' => null])->save();

            return ['onceki_parmak_izi' => $onceki];
        });

        return response()->json(['data' => $this->diziye($ayar)]);
    }

    /**
     * Değişikliği uygular: veritabanı + denetim kaydı tek işlemde; ayar dosya
     * kopyası (doğrudan kipte) ANCAK commit'ten sonra, veritabanından yeniden
     * okunarak yazılır (BY-018-R2, D3). Böylece imzalı dosya hiçbir zaman
     * kaydedilmemiş bir ayarı göstermez: commit düşerse dosyaya dokunulmaz.
     *
     * - Ön koşul (imza anahtarı) YAZMADAN önce denetlenir: yoksa 409, hiçbir
     *   şey değişmez (BY-018-R1).
     * - Commit'ten sonra kopya yazılamazsa ayar yine kaydedilmiştir: yanıt
     *   başarılıdır, `ayar_kopyasi_guncel: false` ve denetimde `ayar_kopyasi`
     *   `basarisiz` (AYAR_KOPYASI_YAZILAMADI) ile izlenir. Uzlaştırmayı
     *   çalıştırıcının her turu yapar (AyarKopyasi::guncelDegilseYaz — doğrudan
     *   kipte zamanlayıcının dakikalık `yedek:calistir --tek-tur`'u, servis
     *   kipinde servis döngüsü; ikisi de kopyayı yazabilen ve imza anahtarını
     *   okuyabilen hesaptır).
     * - Servis kipinde web süreci durum klasörüne yazamaz ve imza anahtarını
     *   okuyamaz (README §5.5): kopyayı yalnız çalıştırıcı tazeler.
     *
     * @param  Closure(YedekAyari): array<string, mixed>  $degistir  Değişikliği yapar, denetim ayrıntısını döner
     */
    private function kaydet(Request $request, string $islem, Closure $degistir): YedekAyari
    {
        $dogrudan = ! $this->yapilandirma->servisCalistiricisi();
        $kullaniciId = (int) $request->user()->id;

        if ($dogrudan && ! $this->imza->anahtarVarMi()) {
            throw new HttpResponseException(response()->json(['kod' => 'YEDEK_IMZA_ANAHTARI_YOK', 'mesaj' => __('hata.yedek_imza_anahtari_yok')], 409));
        }

        $ayar = DB::transaction(function () use ($islem, $degistir, $kullaniciId): YedekAyari {
            $ayar = YedekAyari::guncel();
            $this->denetim->kaydet($islem, IslemSonucu::Basarili, null, $kullaniciId, null, $degistir($ayar));

            return $ayar;
        });

        if ($dogrudan) {
            $this->kopyayiYaz($islem, $kullaniciId);
        }

        return $ayar;
    }

    /**
     * Commit olmuş ayarlardan kopyayı yazar; hatası kaydı geri almaz, izlenir.
     * Taşımadan sonraki hata (klasör eşitlenemedi) dosyayı veritabanıyla aynı
     * bırakır; kesintide eskisine dönerse çalıştırıcı farkı görüp yeniden yazar.
     */
    private function kopyayiYaz(string $islem, int $kullaniciId): void
    {
        try {
            $this->ayarKopyasi->yaz();

            return;
        } catch (KalicilikDogrulanamadi $hata) {
            [$sonuc, $kod] = [IslemSonucu::Basarili, 'AYAR_KOPYASI_KALICILIGI_DOGRULANAMADI'];
        } catch (Throwable $hata) {
            [$sonuc, $kod] = [IslemSonucu::Basarisiz, 'AYAR_KOPYASI_YAZILAMADI'];
        }

        try {
            report($hata);
            $this->denetim->kaydet('ayar_kopyasi', $sonuc, null, $kullaniciId, null, ['kod' => $kod, 'tetikleyen' => $islem], $hata::class);
        } catch (Throwable) {
            // En iyi çaba: ayar kaydedildi; kopyanın durumu ekranda (ayar_kopyasi_guncel)
        }
    }

    /**
     * @param  array<string, mixed>  $once
     * @param  array<string, mixed>  $sonra
     * @return array<string, array{once: mixed, sonra: mixed}>
     */
    private function fark(array $once, array $sonra): array
    {
        $fark = [];
        foreach ($sonra as $alan => $deger) {
            if (($once[$alan] ?? null) != $deger) {
                $fark[$alan] = ['once' => $once[$alan] ?? null, 'sonra' => $deger];
            }
        }

        return $fark;
    }

    /**
     * @return array<string, mixed>
     */
    private function diziye(YedekAyari $ayar): array
    {
        return [
            ...Arr::only($ayar->attributesToArray(), self::ALANLAR),
            'sifreleme' => $ayar->sifreleme_parmak_izi === null ? null : [
                'parmak_izi' => $ayar->sifreleme_parmak_izi,
                'kullanici' => $ayar->sifreleme_anahtar_kullanicisi,
                'bitis' => $ayar->sifreleme_anahtar_bitisi?->toIso8601String(),
            ],
            // false: dosya kopyası veritabanının gerisinde (çalıştırıcı yazacak); null: bu süreç bilemez (BY-018-R2)
            'ayar_kopyasi_guncel' => $this->ayarKopyasi->guncelMi(),
        ];
    }
}
