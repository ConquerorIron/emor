<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EFaturaYuklemesi;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\EFaturaElleIslemleri;
use App\Services\Entegrator\EFaturaIslemHatasi;
use App\Services\Entegrator\EFaturaIslemTuru;
use App\Services\Kesinti\IceAktarimTablosu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Statik kaynakların Excel içe aktarımı (EK-06; docs/efatura §4.4): dosya →
 * "Kontrol Et" (önizleme, hatalar nedenine göre gruplu) → hatasızsa "Kaydet"
 * → tek parti. Okuyucu Kesinti modülünün ortak okuyucusudur (IceAktarimTablosu).
 *
 * - Yeniden yükleme güvenlidir: anahtar eşleşirse dosyanın alanları
 *   güncellenir; elle verilen bilgiler (harici RED, İşlendi, gizleme) korunur,
 *   dosyada boş gelen isteğe bağlı rapor alanı mevcut değeri silmez.
 *   Dosyada artık bulunmayan satır silinmez (aylık liste yalnız o ayı taşıyabilir).
 *   Eşleşen her satırın `son_gorulme`'si (değişmeyen dahil) partinin anıdır:
 *   eski partinin geri alınması buna bakar (geriAl).
 * - Tek hata bile varsa hiçbir satır yazılmaz; satır sayısı sınırlıdır
 *   (`efatura.ice_aktarim_azami_satir`).
 * - Aynı kaynağa iki içe aktarım / geri alma aynı anda çalışmaz (kilit); kayıt
 *   anındaki durum kilit altında yeniden okunur (G18).
 */
final class EFaturaIceAktarimi
{
    /** Hata grubunda gösterilen örnek satır sayısı */
    private const ORNEK_SATIR = 10;

    private const YAZMA_PARTISI = 200;

    /** Kilit süresi (sn): 5.000 satırlık dosya ~7 sn'de okunur; işçi ölürse kilit kendiliğinden düşer */
    private const KILIT_SN = 300;

    public function __construct(private readonly EFaturaElleIslemleri $islemler) {}

    /**
     * Önizleme: okunan, yeni, güncellenecek, değişmeyen, RED sayıları ve gruplu hatalar.
     *
     * @return array{hata: string|null, eksik_kolonlar: list<string>, belge_turu: string|null, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<int>}>, ozet: array{okunan: int, yeni: int, guncellenecek: int, degismeyen: int, red: int}}
     */
    public function onizle(EntegratorBaglanti $tanim, StatikListeGirdisi $girdi): array
    {
        $sonuc = $this->incele($tanim, $girdi);
        unset($sonuc['kayitlar'], $sonuc['tur']);

        return $sonuc;
    }

    /**
     * Hatasız dosyayı tek parti olarak yazar. Hatalıysa hiçbir şey yazmaz ve
     * önizlemeyi döndürür (çağıran 422 verir).
     *
     * @return EFaturaYuklemesi|array{hata: string|null, eksik_kolonlar: list<string>, belge_turu: string|null, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<int>}>, ozet: array{okunan: int, yeni: int, guncellenecek: int, degismeyen: int, red: int}}
     */
    public function kaydet(EntegratorBaglanti $tanim, StatikListeGirdisi $girdi, string $dosyaAdi, User $kullanici): EFaturaYuklemesi|array
    {
        return $this->kilitli($tanim, function () use ($tanim, $girdi, $dosyaAdi, $kullanici): EFaturaYuklemesi|array {
            $sonuc = $this->incele($tanim, $girdi);

            if ($sonuc['hata'] !== null || $sonuc['eksik_kolonlar'] !== [] || $sonuc['hatalar'] !== []) {
                unset($sonuc['kayitlar'], $sonuc['tur']);

                return $sonuc;
            }

            return DB::transaction(fn (): EFaturaYuklemesi => $this->yaz($tanim, $sonuc['kayitlar'], $sonuc['tur'], $dosyaAdi, $kullanici));
        });
    }

    /**
     * Partiyi geri alır: EKLEDİĞİ faturalar silinir, güncellediği satırlar eski
     * hâline dönmez. Hiçbir şey silinmeyen (409) durumlar:
     *
     * - eklediği faturalardan birinde elle işlem var (RED, İşlendi, gizleme);
     * - eklediği faturalardan birine hâlâ duran BAŞKA bir parti dokundu
     *   (güncelledi ya da değişmeyen olarak eşleştirdi; bağlar
     *   `efatura_yukleme_faturalari`'nda) — silinen satır daha yeni bir
     *   yüklemenin verisini taşırdı (inceleme B-4). Zaman karşılaştırması aynı
     *   saniyedeki iki yüklemeyi, tek "son dokunan" alanı aradaki partiyi
     *   kaybediyordu (Codex B-4-R1, R2). Bağ tablosundan önceki satırda dokunan
     *   bilinmez: aynı kaynakta duran sonraki parti varsa engellenir. Dokunan
     *   partilerin hepsi geri alınınca bu parti de geri alınabilir.
     *
     * Parti ve satırları kilit altında yeniden okunur (G18): aynı partinin ikinci
     * geri alma isteği 404 alır; aynı anda RED'e çekilen fatura silinip işlem kaybolmaz.
     */
    public function geriAl(EFaturaYuklemesi $parti, User $kullanici): void
    {
        $tanim = $parti->entegratorBaglanti;

        $this->kilitli($tanim, function () use ($parti, $tanim, $kullanici): void {
            DB::transaction(function () use ($parti, $tanim, $kullanici): void {
                $parti = EFaturaYuklemesi::query()->lockForUpdate()->find($parti->id) ?? throw new NotFoundHttpException;
                $satirlar = EFatura::query()
                    ->where('yukleme_id', $parti->id)
                    ->lockForUpdate()
                    ->get(['id', 'harici_red_zamani', 'sap_islendi', 'gizlenme_zamani']);
                $elleIslenen = $satirlar->filter(fn (EFatura $f): bool => $f->harici_red_zamani !== null || $f->sap_islendi === true || $f->gizlenme_zamani !== null)->count();

                if ($elleIslenen > 0) {
                    throw EFaturaIslemHatasi::yuklemeElleIslenmis($elleIslenen);
                }

                // Faturalara dokunan partiler (bağlar cascade ile yalnız duran partileri tutar)
                /** @var array<int, list<int>> $dokunanlar fatura => parti kimlikleri */
                $dokunanlar = [];
                foreach ($satirlar->pluck('id')->chunk(1000) as $parca) {
                    DB::table('efatura_yukleme_faturalari')->whereIn('efatura_id', $parca->all())
                        ->get(['efatura_id', 'yukleme_id'])
                        ->each(function (object $bag) use (&$dokunanlar): void {
                            $dokunanlar[(int) $bag->efatura_id][] = (int) $bag->yukleme_id;
                        });
                }
                // Bağ tablosundan önceki satırda dokunan bilinmez (D6): aynı kaynakta
                // duran sonraki parti varsa engellenir (kilit altında: içe aktarım `kilitli` ile sıralı)
                $sonrakiPartiVar = EFaturaYuklemesi::query()
                    ->where('entegrator_baglanti_id', $tanim->id)
                    ->where('id', '>', $parti->id)
                    ->exists();
                $yenidenYuklenen = $satirlar->filter(function (EFatura $f) use ($parti, $dokunanlar, $sonrakiPartiVar): bool {
                    $partiler = $dokunanlar[$f->id] ?? [];

                    return $partiler === []
                        ? $sonrakiPartiVar
                        : array_diff($partiler, [$parti->id]) !== [];
                })->count();

                if ($yenidenYuklenen > 0) {
                    throw EFaturaIslemHatasi::yuklemeSonrakiYuklemede($yenidenYuklenen);
                }

                $this->islemler->gecmiseYaz(null, EFaturaIslemTuru::IceAktarimGeriAlindi, $kullanici, ayrinti: [
                    'dosya_adi' => $parti->dosya_adi,
                    'belge_turu' => $parti->belge_turu,
                    'silinen' => $satirlar->count(),
                    'yukleme_zamani' => $parti->created_at->toIso8601String(),
                ], tanim: $tanim, yuklemeId: $parti->id);

                foreach ($satirlar->pluck('id')->chunk(1000) as $parca) {
                    EFatura::query()->whereKey($parca->all())->delete();
                }
                $parti->delete();
            });
        });
    }

    /**
     * @return list<StatikBelgeTuru> Tanımın kabul ettiği dosya türleri (ilk eşleşen kullanılır)
     */
    public function turler(EntegratorBaglanti $tanim): array
    {
        return match ($tanim->saglayici) {
            EntegratorBaglanti::SAGLAYICI_LOGO => [new LogoFaturaListesi],
            EntegratorBaglanti::SAGLAYICI_EARSIV => [new EarsivFaturaListesi, new EsmmMakbuzListesi],
            default => throw new \LogicException('İçe aktarım yalnız statik kaynaklarda.'),
        };
    }

    /**
     * Yapıştırma tablosunun kolonları, türe göre: tablo başlıkları ve başlıkla
     * kopyalanınca kolonun tanındığı adlar (ilki Excel'deki standart ad).
     * Sıra, başlıksız gönderilen metnin standart sırasıdır.
     *
     * @return list<array{belge_turu: string|null, kolonlar: list<array{alan: string, basliklar: list<string>}>}>
     */
    public function kolonlar(EntegratorBaglanti $tanim): array
    {
        return array_map(fn (StatikBelgeTuru $tur): array => [
            'belge_turu' => $tur->belgeTuru(),
            'kolonlar' => array_map(
                fn (string $alan, array $basliklar): array => ['alan' => $alan, 'basliklar' => $basliklar],
                array_keys($tur->kolonlar()),
                array_values($tur->kolonlar()),
            ),
        ], $this->turler($tanim));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $is
     * @return T
     */
    private function kilitli(EntegratorBaglanti $tanim, callable $is): mixed
    {
        $kilit = Cache::lock('efatura-ice-aktarim:'.$tanim->id, self::KILIT_SN);

        if (! $kilit->get()) {
            throw EFaturaIslemHatasi::iceAktarimSuruyor();
        }

        try {
            return $is();
        } finally {
            $kilit->release();
        }
    }

    /**
     * @return array{hata: string|null, eksik_kolonlar: list<string>, belge_turu: string|null, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<int>}>, ozet: array{okunan: int, yeni: int, guncellenecek: int, degismeyen: int, red: int}, kayitlar: list<array<string, mixed>>, tur: StatikBelgeTuru|null}
     */
    private function incele(EntegratorBaglanti $tanim, StatikListeGirdisi $girdi): array
    {
        $bos = [
            'hata' => null, 'eksik_kolonlar' => [], 'belge_turu' => null, 'hatalar' => [],
            'ozet' => ['okunan' => 0, 'yeni' => 0, 'guncellenecek' => 0, 'degismeyen' => 0, 'red' => 0],
            'kayitlar' => [], 'tur' => null,
        ];

        [$tur, $tablo, $eksik] = $girdi->metin !== null
            ? $this->metniOku($tanim, $girdi->metin, $girdi->belgeTuru)
            : $this->oku($tanim, (string) $girdi->yol);

        if ($tablo['hata'] !== null) {
            return [...$bos, 'hata' => $tablo['hata']];
        }
        if ($tur === null) {
            return [...$bos, 'eksik_kolonlar' => $eksik];
        }
        if ($tablo['satirlar'] === []) {
            return [...$bos, 'belge_turu' => $tur->belgeTuru(), 'hata' => 'satir_yok'];
        }
        if (count($tablo['satirlar']) > $this->azamiSatir()) {
            return [
                ...$bos,
                'belge_turu' => $tur->belgeTuru(),
                'hata' => 'cok_fazla_satir',
                'ozet' => [...$bos['ozet'], 'okunan' => count($tablo['satirlar'])],
                'azami_satir' => $this->azamiSatir(),
            ];
        }

        $sirketVknleri = EntegratorBaglanti::query()
            ->whereIn('saglayici', [EntegratorBaglanti::SAGLAYICI_IZIBIZ, EntegratorBaglanti::SAGLAYICI_PROTEL])
            ->pluck('vkn')->filter()->unique()->values()->all();

        $hatalar = [];
        $kayitlar = [];
        $gorulen = [];

        foreach ($tablo['satirlar'] as ['satir' => $no, 'degerler' => $degerler]) {
            [$alanlar, $satirHatalari] = $tur->donustur($degerler, $sirketVknleri);
            $anahtar = $alanlar['belge_anahtari'];

            if ($anahtar !== null && isset($gorulen[$anahtar])) {
                $satirHatalari[] = ['belge_no', (string) $alanlar['belge_no'], 'tekrar_eden'];
            }
            if ($anahtar !== null) {
                $gorulen[$anahtar] = true;
            }

            foreach ($satirHatalari as [$alan, $deger, $kod]) {
                $grup = "{$alan}|{$kod}|{$deger}";
                $hatalar[$grup] ??= ['alan' => $alan, 'deger' => $deger, 'kod' => $kod, 'adet' => 0, 'satirlar' => []];
                $hatalar[$grup]['adet']++;
                if (count($hatalar[$grup]['satirlar']) < self::ORNEK_SATIR) {
                    $hatalar[$grup]['satirlar'][] = $no;
                }
            }

            $kayitlar[] = $alanlar;
        }

        $hataListesi = array_values($hatalar);
        usort($hataListesi, fn (array $a, array $b): int => $b['adet'] <=> $a['adet']);
        $ozet = ['okunan' => count($kayitlar), 'yeni' => 0, 'guncellenecek' => 0, 'degismeyen' => 0, 'red' => 0];

        if ($hataListesi === []) {
            $mevcut = $this->mevcutlar($tanim, array_column($kayitlar, 'belge_anahtari'));
            foreach ($kayitlar as $kayit) {
                $fatura = $mevcut[$kayit['belge_anahtari']] ?? null;
                $durum = $fatura === null ? 'yeni' : ($this->degisirMi($fatura, $kayit) ? 'guncellenecek' : 'degismeyen');
                $ozet[$durum]++;
                $ozet['red'] += in_array($kayit['durum'], EFatura::KAYNAK_RED_DURUMLARI, true) ? 1 : 0;
            }
        }

        return [
            ...$bos,
            'belge_turu' => $tur->belgeTuru(),
            'hatalar' => $hataListesi,
            'ozet' => $ozet,
            'kayitlar' => $hataListesi === [] ? $kayitlar : [],
            'tur' => $tur,
        ];
    }

    /**
     * Tanımın türlerinden zorunlu kolonlarının hepsi bulunan ilki. Hiçbiri
     * yetmiyorsa en çok kolonu tanınan türün eksik kolonları döner.
     *
     * @return array{0: StatikBelgeTuru|null, 1: array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}, 2: list<string>}
     */
    private function oku(EntegratorBaglanti $tanim, string $yol): array
    {
        $enYakin = null;

        foreach ($this->turler($tanim) as $tur) {
            $tablo = IceAktarimTablosu::excel($yol, $tur->kolonlar(), $tur->zorunlular());

            if ($tablo['hata'] === 'dosya_okunamadi') {
                return [null, $tablo, []];
            }

            $eksik = array_values(array_diff($tur->zorunlular(), $tablo['kolonlar']));
            if ($tablo['hata'] === null && $eksik === []) {
                return [$tur, $tablo, []];
            }

            if ($tablo['hata'] === null && ($enYakin === null || count($tablo['kolonlar']) > $enYakin[0])) {
                $enYakin = [count($tablo['kolonlar']), $eksik];
            }
        }

        return $enYakin === null
            ? [null, ['satirlar' => [], 'kolonlar' => [], 'hata' => 'baslik_bulunamadi'], []]
            : [null, ['satirlar' => [], 'kolonlar' => [], 'hata' => null], $enYakin[1]];
    }

    /**
     * Yapıştırılan metin seçilen türün kolonlarıyla okunur (tek türlü tanımda
     * o tür). Başlık satırı da yapıştırıldıysa kolonlar adlarından tanınır;
     * yoksa standart sıra esas alınır. Satır numaraları ekran tablosununkilerle
     * aynıdır.
     *
     * @return array{0: StatikBelgeTuru|null, 1: array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}, 2: list<string>}
     */
    private function metniOku(EntegratorBaglanti $tanim, string $metin, ?string $belgeTuru): array
    {
        $turler = $this->turler($tanim);
        $tur = $turler[0];
        foreach ($turler as $aday) {
            if ($aday->belgeTuru() === $belgeTuru) {
                $tur = $aday;
            }
        }

        $tablo = IceAktarimTablosu::metin($metin, $tur->kolonlar());
        $eksik = array_values(array_diff($tur->zorunlular(), $tablo['kolonlar']));

        return $eksik === [] ? [$tur, $tablo, []] : [null, $tablo, $eksik];
    }

    /**
     * @param  list<string|null>  $anahtarlar
     * @return array<string, EFatura>
     */
    private function mevcutlar(EntegratorBaglanti $tanim, array $anahtarlar): array
    {
        $mevcut = [];

        foreach (array_chunk(array_values(array_filter($anahtarlar)), 1000) as $parca) {
            EFatura::query()
                ->where('entegrator_baglanti_id', $tanim->id)
                ->whereIn('belge_anahtari', $parca)
                ->get(['id', 'belge_anahtari', ...StatikBelgeTuru::KAYNAK_ALANLARI])
                ->each(function (EFatura $f) use (&$mevcut): void {
                    $mevcut[(string) $f->belge_anahtari] = $f;
                });
        }

        return $mevcut;
    }

    /**
     * Satır üst sınırı (G9: yalnız pozitif tam sayı; geçersiz ayar açık hatayla durur).
     */
    private function azamiSatir(): int
    {
        $sinir = config('efatura.ice_aktarim_azami_satir');

        if (! is_int($sinir) || $sinir < 1) {
            throw new \LogicException('efatura.ice_aktarim_azami_satir pozitif bir tam sayı olmalı.');
        }

        return $sinir;
    }

    /**
     * Eloquent'in kirli denetimi cast'leri hesaba katar (decimal:4, tarih).
     * Kayıtla aynı alanlar (StatikBelgeTuru::guncellemeAlanlari).
     *
     * @param  array<string, mixed>  $kayit
     */
    private function degisirMi(EFatura $fatura, array $kayit): bool
    {
        return (clone $fatura)->fill(StatikBelgeTuru::guncellemeAlanlari($kayit))->isDirty();
    }

    /**
     * @param  list<array<string, mixed>>  $kayitlar
     */
    private function yaz(EntegratorBaglanti $tanim, array $kayitlar, StatikBelgeTuru $tur, string $dosyaAdi, User $kullanici): EFaturaYuklemesi
    {
        $simdi = CarbonImmutable::now();
        $parti = EFaturaYuklemesi::query()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'belge_turu' => $tur->belgeTuru(),
            'dosya_adi' => mb_substr($dosyaAdi, 0, 255),
            'kullanici_id' => $kullanici->id,
            'kullanici_adi' => (string) $kullanici->ad,
            'yeni' => 0,
            'guncellenen' => 0,
            'degismeyen' => 0,
        ]);

        $mevcut = $this->mevcutlar($tanim, array_column($kayitlar, 'belge_anahtari'));
        $yeniler = [];
        /** @var list<int> $degismeyenler */
        $degismeyenler = [];
        /** @var list<int> $guncellenenler */
        $guncellenenler = [];
        $sayac = ['yeni' => 0, 'guncellenen' => 0, 'degismeyen' => 0];
        /** @var array<string, list<array<string, mixed>>> $kalemler belge anahtarı => kalemler */
        $kalemler = [];

        foreach ($kayitlar as $kayit) {
            if (array_key_exists(StatikBelgeTuru::VERGI_KALEMLERI, $kayit)) {
                $kalemler[$kayit['belge_anahtari']] = $kayit[StatikBelgeTuru::VERGI_KALEMLERI];
                unset($kayit[StatikBelgeTuru::VERGI_KALEMLERI]);
            }

            $fatura = $mevcut[$kayit['belge_anahtari']] ?? null;

            if ($fatura === null) {
                $yeniler[] = [
                    ...$kayit,
                    'entegrator_baglanti_id' => $tanim->id,
                    'yon' => 'gelen',
                    'kaynak_id' => null,
                    'yukleme_id' => $parti->id,
                    'ilk_gorulme' => $simdi,
                    'son_gorulme' => $simdi,
                    'created_at' => $simdi,
                    'updated_at' => $simdi,
                ];
                $sayac['yeni']++;

                continue;
            }

            $fatura->fill(StatikBelgeTuru::guncellemeAlanlari($kayit));
            if ($fatura->isDirty()) {
                $fatura->son_gorulme = $simdi;
                $fatura->save();
                $guncellenenler[] = $fatura->id;
                $sayac['guncellenen']++;
            } else {
                $degismeyenler[] = $fatura->id;
                $sayac['degismeyen']++;
            }
        }

        foreach (array_chunk($yeniler, self::YAZMA_PARTISI) as $parca) {
            EFatura::query()->insert($parca);
        }

        foreach (array_chunk($degismeyenler, 1000) as $parca) {
            EFatura::query()->whereKey($parca)->update(['son_gorulme' => $simdi]);
        }

        // Bu partinin dokunduğu her fatura (eklenen, güncellenen, değişmeyen olarak
        // eşleşen) bağ tablosuna: eski partisi geri alınırken bu partinin
        // dayandığı bilinir (geriAl, B-4)
        $dokunulanlar = [
            ...$guncellenenler,
            ...$degismeyenler,
            ...EFatura::query()->where('yukleme_id', $parti->id)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
        foreach (array_chunk($dokunulanlar, self::YAZMA_PARTISI) as $parca) {
            DB::table('efatura_yukleme_faturalari')->insertOrIgnore(
                array_map(fn (int $id): array => ['yukleme_id' => $parti->id, 'efatura_id' => $id], $parca),
            );
        }

        $this->kalemleriYaz($tanim, $kalemler);

        $parti->update($sayac);
        $this->islemler->gecmiseYaz(null, EFaturaIslemTuru::IceAktarildi, $kullanici, ayrinti: [
            'dosya_adi' => $parti->dosya_adi,
            'belge_turu' => $parti->belge_turu,
            ...$sayac,
        ], tanim: $tanim, yuklemeId: $parti->id);

        return $parti;
    }

    /**
     * Dosyanın oran bazında vergileri (Logo): her faturanın kalemleri baştan
     * yazılır — yeni, güncellenen ve değişmeyen faturada aynı (yeniden yükleme
     * aynı sonucu verir). Aynı işlem içinde: yarım kalırsa parti de geri döner.
     *
     * @param  array<string, list<array<string, mixed>>>  $kalemler  belge anahtarı => kalemler
     */
    private function kalemleriYaz(EntegratorBaglanti $tanim, array $kalemler): void
    {
        if ($kalemler === []) {
            return;
        }

        $kimlikler = array_map(fn (EFatura $f): int => $f->id, $this->mevcutlar($tanim, array_keys($kalemler)));

        foreach (array_chunk(array_values($kimlikler), self::YAZMA_PARTISI) as $parca) {
            EFaturaVergiKalemi::query()->whereIn('efatura_id', $parca)->delete();
        }

        $satirlar = [];
        foreach ($kalemler as $anahtar => $liste) {
            foreach (array_values($liste) as $sira => $kalem) {
                $satirlar[] = ['efatura_id' => $kimlikler[$anahtar], 'sira' => $sira + 1, ...$kalem];
            }
        }

        foreach (array_chunk($satirlar, self::YAZMA_PARTISI) as $parca) {
            EFaturaVergiKalemi::query()->insert($parca);
        }
    }
}
