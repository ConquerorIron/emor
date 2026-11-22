<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Yedek klasöründen dizini kurar / günceller (`yedek:tara`; README §5.2,
 * §7.3, §4.5; BY-011). Doğruluk kaynağı dosyalardır: imzalı manifest +
 * olay günlüğü.
 *
 * - Kayıtlar manifest KİMLİĞİYLE güncellenir, silinip yeniden açılmaz
 *   (kopyalar ve plan bağları CASCADE ile gitmesin, geri yükleme geçmişi
 *   RESTRICT ile engellemesin — Fable F19).
 * - İmzası tutmayan ya da manifestsiz `.dump` "güvenilmeyen" kayıttır
 *   (`imzali` false): listede görünür, geri yüklenemez.
 * - Diskte olmayan kayıt silinmez: `yerel_silinme_zamani` dolar; başka bir
 *   yerde kopyası (`tamam` ya da `konum_belirsiz`, hedefi tanımsız olanlar
 *   dahil) yoksa durumu `silindi` olur.
 * - Yerel dosyası silinmiş ama manifesti duran yedek, olay günlüğündeki uzak
 *   kopyalarıyla bulunur (F7).
 * - Olaydaki tamam kopya hedefe ancak konum izi hedefin bugünkü iziyle
 *   aynıysa `tamam` bağlanır; değilse (ya da iz yoksa) `konum_belirsiz`
 *   olur: geri yükleme, silme ve saklamada kopya sayılmaz
 *   (HedefKonumu::konumdaMi; BY-028-R5), ama dosyası bir yerde durabileceği
 *   için yedeği `silindi` yaptırmaz; yedek listede uyarısıyla görünür
 *   (KopyaDurumu::yedegiTutanlar; BY-028-R7). Hedefin bugünkü tanımı ana
 *   klasör olsa da: yeri doğrulanmamış kopyanın yerini o tanım göstermez
 *   (yedegiTutarMi; BY-028-R8).
 * - Olaydaki tamam kopyanın hedefi kimlik / adla bulunamazsa ya da bulunan
 *   hedefin izi uyuşmazsa, izi aynı olan hedef aranır (yeniden eklenmiş aynı
 *   yer; izdekiHedef). Hiçbiri yoksa kopya atlanmaz: hedefsiz `konum_belirsiz`
 *   kayıt olur (`KOPYA_HEDEFI_TANIMSIZ`), yedeği tutar, hiçbir işlemin kaynağı
 *   olmaz; konumu aynı hedef eklenince sonraki taramada ona bağlanır
 *   (BY-028-R9). Hedef kimliği / konumu günlükten tahmin edilip dosya işlemi
 *   yapılmaz: tarama yalnız katalog yazar.
 * - Birden çok olay hedefi aynı güncel hedefe düşerse (konum iziyle yeniden
 *   eşleme) biri ötekinin kaydını ezmez: silinmiş nesne yaşayanı ezemez, aynı
 *   hedefteki ikinci yaşayan nesne hedefsiz kayıt olarak korunur
 *   (`KOPYA_HEDEFTE_BASKA_KOPYA`; hedefinKopyasi). Silme olayı yalnız ait
 *   olduğu nesneye uygulanır (GuncelDurum::silinenler; BY-028-R10).
 * - Tek bir bozuk dosya taramayı durdurmaz.
 * - Yedek klasörü yoksa ya da okunamıyorsa (bağlanmamış disk, izin) tarama
 *   dizine DOKUNMADAN hata verir: "erişemiyorum" boş klasör sayılıp kayıtlar
 *   "dosya yok" yapılmaz (D6; BY-011-R1). Okunabilen klasör ancak depo
 *   işaretiyle (YedekDeposu) depo sayılır: diski ayrılmış boş bağlama noktası
 *   da okunabilir (BY-011-R3). İşaretli boş klasör olağan taramadır.
 * - Kilidi tutan çağıran kilidi verirse ölen süreçten kalan `calisiyor`
 *   yedek kayıtları önce sonuçlanır (SahipsizYedekler; BY-007-R1).
 * - Ana klasör hedefi YerelDiskBaglayicisi::anaKlasorHedefiMi ile tanınır
 *   (kopyalama ve saklamayla aynı ölçüt; BY-008-R1); bu eşitlik yalnız yeri
 *   doğrulanmış (`tamam`) kopyaya uygulanır (BY-028-R8).
 */
final class DizinKurucu
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly ManifestDeposu $manifestler,
        private readonly OlayGunlugu $olaylar,
        private readonly SahipsizYedekler $sahipsizler,
        private readonly YedekDeposu $depo,
        private readonly HedefKonumu $konum,
    ) {}

    /**
     * @param  YedekKilidi|null  $kilit  Tutulan yedekleme kilidi; verilmezse sahipsiz kayıtlara dokunulmaz
     * @return array{guvenilir: int, guvenilmeyen: int, eklenen: int, dosyasi_yok: int, bozuk: int, gecersiz_olay: int, bulunamayan_hedef: int, sahipsiz: int}
     *
     * @throws RuntimeException Yedek klasörü yok, okunamıyor ya da depo olarak doğrulanamadı (DepoIsaretiYok; dizin değişmedi)
     * @throws LogicException Verilen kilit tutulmuyor
     */
    public function tara(?YedekKilidi $kilit = null): array
    {
        $klasor = $this->yapilandirma->klasor();
        $dosyalar = is_dir($klasor) ? @scandir($klasor) : false;

        if ($dosyalar === false) {
            throw new RuntimeException("Yedek klasörü bulunamadı ya da okunamıyor; dizin değiştirilmedi: {$klasor}");
        }

        // Okunabilen klasör de depo olmayabilir: diski ayrılmış boş bağlama noktası (BY-011-R3)
        $this->depo->hazirla();

        $sayac = ['guvenilir' => 0, 'guvenilmeyen' => 0, 'eklenen' => 0, 'dosyasi_yok' => 0, 'bozuk' => 0, 'gecersiz_olay' => 0, 'bulunamayan_hedef' => 0, 'sahipsiz' => 0];

        if ($kilit !== null) {
            $sahipsiz = $this->sahipsizler->sonuclandir($kilit);
            $sayac['sahipsiz'] = $sahipsiz['tamamlanan'] + $sahipsiz['yarida_kalan'];
            // Sonuçlandırma geçici / dosyasız manifest silmiş olabilir
            $dosyalar = @scandir($klasor);
            if ($dosyalar === false) {
                throw new RuntimeException("Yedek klasörü okunamıyor; dizin değiştirilmedi: {$klasor}");
            }
        }

        // Günlük bir kez okunur; olaylar ve güncel durumlar aynı listeden (BY-011-R2)
        $okunan = $this->olaylar->oku();
        $sayac['gecersiz_olay'] = $okunan['gecersiz'];
        $durumlar = OlayGunlugu::durumlaraAyir($okunan['olaylar']);
        $gorulenAdlar = [];

        $manifestler = array_filter($dosyalar, fn (string $ad): bool => ! str_starts_with($ad, '.') && str_ends_with($ad, '.json'));
        $dumplar = array_filter($dosyalar, fn (string $ad): bool => ! str_starts_with($ad, '.') && str_ends_with($ad, '.dump'));
        $guvenilirDumplar = [];

        foreach ($manifestler as $jsonAdi) {
            try {
                $sonuc = $this->manifestler->oku($klasor.'/'.$jsonAdi);
                if (! $sonuc->guvenilirMi()) {
                    $sayac['bozuk']++;

                    continue;
                }

                $manifest = $sonuc->manifest;
                $yeni = $this->manifesttenKur($manifest, $durumlar[$manifest->kimlik] ?? GuncelDurum::hesapla([]), $klasor, $sayac);
                $sayac['guvenilir']++;
                $sayac['eklenen'] += $yeni ? 1 : 0;
                $gorulenAdlar[$manifest->dosyaAdi] = true;
                $guvenilirDumplar[$manifest->dosyaAdi] = true;
            } catch (Throwable $hata) {
                // Tek bozuk dosya taramayı durdurmaz
                $sayac['bozuk']++;
                report($hata);
            }
        }

        foreach ($dumplar as $dumpAdi) {
            if (isset($guvenilirDumplar[$dumpAdi])) {
                continue;
            }

            try {
                $sayac['eklenen'] += $this->guvenilmeyenKur($klasor, $dumpAdi) ? 1 : 0;
                $sayac['guvenilmeyen']++;
                $gorulenAdlar[$dumpAdi] = true;
            } catch (Throwable $hata) {
                $sayac['bozuk']++;
                report($hata);
            }
        }

        // Diskte olmayan kayıtlar: silinmez, "dosya yok" olur. Yalnız tamamlanmış
        // yedekler: başarısız denemenin hiç dosyası olmadı, `basarisiz` kalır
        // (saklamanın "son deneme başarısız" temkini onu görür; BY-007-R1)
        foreach (Yedek::query()->where('durum', YedekDurumu::Tamam->value)->get() as $yedek) {
            if (isset($gorulenAdlar[$yedek->dosya_adi]) || is_file($klasor.'/'.$yedek->dosya_adi)) {
                continue;
            }

            $this->dosyasiYok($yedek);
            $sayac['dosyasi_yok']++;
        }

        return $sayac;
    }

    /**
     * Deterministik kimlik: aynı dosya her taramada aynı kayda düşer.
     */
    public static function dosyaKimligi(string $dosyaAdi): string
    {
        $h = md5('emor-yedek-guvenilmeyen:'.$dosyaAdi);

        return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12));
    }

    /**
     * @param  array<string, int>  $sayac
     * @return bool Yeni kayıt mı açıldı
     */
    private function manifesttenKur(YedekManifesti $m, GuncelDurum $durum, string $klasor, array &$sayac): bool
    {
        return DB::transaction(function () use ($m, $durum, $klasor, &$sayac): bool {
            $yedek = Yedek::query()->where('kimlik', $m->kimlik)->first() ?? new Yedek(['kimlik' => $m->kimlik]);
            $yeni = ! $yedek->exists;
            $dump = $klasor.'/'.$m->dosyaAdi;
            $dumpVar = is_file($dump) && @filesize($dump) === $m->boyut;

            $yedek->fill([
                'dosya_adi' => $m->dosyaAdi,
                'kaynak' => $m->kaynak,
                'boyut' => $m->boyut,
                'sha256' => $m->sha256,
                'alinma_zamani' => $m->alinmaZamani,
                'bitti' => $yedek->bitti ?? $m->alinmaZamani,
                'imzali' => true,
                'ice_alindi' => $m->iceAlindi,
                'sabit' => $durum->sabit,
                'aciklama' => $durum->aciklamaDegisti ? $durum->aciklama : $m->aciklama,
                'dogrulama_durumu' => $durum->dogrulama ?? DogrulamaDurumu::OzetTamam,
                'alan_kullanici_id' => $m->alanKullaniciId !== null && User::query()->whereKey($m->alanKullaniciId)->exists() ? $m->alanKullaniciId : null,
                'hata_kodu' => null,
                'hata_mesaji' => null,
                'yerel_silinme_zamani' => $dumpVar ? null : ($yedek->yerel_silinme_zamani ?? $durum->yerelSilinme ?? CarbonImmutable::now()),
            ]);
            $yedek->durum = YedekDurumu::Tamam;
            $yedek->silinme_zamani = null;
            $yedek->save();

            $uzakKopyaVar = false;
            $hedefsizler = [];
            $baskaHedefeBaglanan = [];
            /** @var array<int, array{hedef: YedekHedefi, adaylar: list<array{anahtar: string, k: array<string, mixed>, durum: KopyaDurumu, kendi: bool}>}> $gruplar */
            $gruplar = [];
            foreach ($durum->kopyalar as $anahtar => $k) {
                $anahtar = (string) $anahtar;
                $kopyaDurumu = KopyaDurumu::from($k['durum']);
                $bilinen = ($k['hedef_id'] !== null ? YedekHedefi::query()->find($k['hedef_id']) : null)
                    ?? ($k['hedef_ad'] !== null ? YedekHedefi::query()->where('ad', $k['hedef_ad'])->first() : null);
                $hedef = $kopyaDurumu === KopyaDurumu::Tamam ? $this->izdekiHedef($k['konum_izi'], $bilinen) : $bilinen;

                if ($hedef === null) {
                    $sayac['bulunamayan_hedef']++;
                    // Dosyası duran kopya hedefi tanımsız da olsa katalogda kalır (BY-028-R9)
                    if ($kopyaDurumu === KopyaDurumu::Tamam) {
                        $this->hedefsizKopyaYaz($yedek, $anahtar, $k, HedefKonumu::HEDEFI_TANIMSIZ);
                        $hedefsizler[] = $anahtar;
                        $uzakKopyaVar = true;
                    }

                    continue;
                }

                if ($bilinen !== null && $bilinen->id !== $hedef->id) {
                    $baskaHedefeBaglanan[$bilinen->id] = true;
                }

                // Tamam kopya hedefe ancak olaydaki konum izi hedefin BUGÜNKÜ iziyle aynıysa bağlanır:
                // geri yüklenen veritabanında hedef eski yerinde olabilir (BY-028-R5). İzsiz eski olay da doğrulanamaz.
                if ($kopyaDurumu === KopyaDurumu::Tamam && ! $this->konum->konumdaMi($hedef, $k['konum_izi'])) {
                    $kopyaDurumu = KopyaDurumu::KonumBelirsiz;
                }
                $gruplar[$hedef->id]['hedef'] = $hedef;
                $gruplar[$hedef->id]['adaylar'][] = ['anahtar' => $anahtar, 'k' => $k, 'durum' => $kopyaDurumu, 'kendi' => $bilinen?->id === $hedef->id];
            }

            // Birden çok olay hedefi aynı güncel hedefe düşebilir (konum iziyle yeniden eşleme):
            // her biri kendi fiziksel nesnesidir, biri ötekinin kaydını ezmez (BY-028-R10)
            foreach ($gruplar as ['hedef' => $hedef, 'adaylar' => $adaylar]) {
                [$secilen, $ayrilanlar] = $this->hedefinKopyasi($adaylar);
                foreach ($ayrilanlar as $ayrilan) {
                    $this->hedefsizKopyaYaz($yedek, $ayrilan['anahtar'], $ayrilan['k'], HedefKonumu::HEDEFTE_BASKA_KOPYA);
                    $hedefsizler[] = $ayrilan['anahtar'];
                    $uzakKopyaVar = true;
                }

                $k = $secilen['k'];
                $kopyaDurumu = $secilen['durum'];
                $kopya = YedekKopyasi::query()->firstOrNew(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id]);
                $kopya->fill([
                    'durum' => $kopyaDurumu,
                    'hata_kodu' => match ($kopyaDurumu) {
                        // Silme yetkisi reddiyle bizden çıkmış kopya kodunu korur (BY-023-R1)
                        KopyaDurumu::Silindi => $k['hata_kodu'],
                        KopyaDurumu::KonumBelirsiz => HedefKonumu::DOGRULANAMADI,
                        // Kayda başka bir nesne geldiyse eskisinin kodu ona ait değildir
                        default => $kopya->hata_kodu === HedefKonumu::DOGRULANAMADI || $kopya->uzak_kimlik !== $k['uzak_kimlik'] ? null : $kopya->hata_kodu,
                    },
                    'uzak_kimlik' => $k['uzak_kimlik'],
                    'sifreli' => $k['sifreli'],
                    'sifreli_sha256' => $k['sifreli_sha256'],
                    'konum_izi' => $k['konum_izi'],
                    'deneme' => $kopya->deneme ?? 0,
                ])->save();

                // Bağlar yalnız planın bu hedefle gerçek ilişkisi varsa (bileşik anahtar; BY-004-R8)
                $gecerli = DB::table('yedek_plan_hedefleri')->where('yedek_hedefi_id', $hedef->id)->whereIn('yedek_plani_id', $k['plan_idleri'])->pluck('yedek_plani_id')->all();
                $kopya->planlar()->sync(array_fill_keys(array_map('intval', $gecerli), ['yedek_hedefi_id' => $hedef->id]));

                // Yeri doğrulanamayan kopya da yedeği tutar: dosya bir yerde durabilir (BY-028-R7)
                $uzakKopyaVar = $uzakKopyaVar || $this->yedegiTutarMi($kopyaDurumu, $hedef);
            }

            // Hedefsiz kayıtlar olaylardan türer: olayı artık bir hedefe bağlanan ya da dosyası kalmayan kalkar
            // (aynı hedefe başka kopya bağlandığı için ayrı tutulan nesne de bu taramada yazıldı, kalır).
            // Olay iziyle başka bir hedefe bağlanan kopyanın kimlik / adla bulunan hedefteki eski "konumu
            // belirsiz" izdüşümü de kalkar (o hedefe bu taramada bağlanan başka olay yoksa; sıra bağımsız)
            YedekKopyasi::query()->where('yedek_id', $yedek->id)->whereNull('yedek_hedefi_id')->whereNotIn('olay_hedef_anahtari', $hedefsizler)->delete();
            $eskiIzdusumler = array_keys(array_diff_key($baskaHedefeBaglanan, $gruplar));
            if ($eskiIzdusumler !== []) {
                YedekKopyasi::query()->where('yedek_id', $yedek->id)->whereIn('yedek_hedefi_id', $eskiIzdusumler)
                    ->where('durum', KopyaDurumu::KonumBelirsiz->value)->delete();
            }

            if ($durum->silinme !== null || (! $dumpVar && ! $uzakKopyaVar)) {
                $yedek->forceFill(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => $durum->silinme ?? CarbonImmutable::now()])->save();
            }

            return $yeni;
        });
    }

    /**
     * @return bool Yeni kayıt mı açıldı
     */
    private function guvenilmeyenKur(string $klasor, string $dumpAdi): bool
    {
        $yol = $klasor.'/'.$dumpAdi;
        $kimlik = self::dosyaKimligi($dumpAdi);
        $yedek = Yedek::query()->where('kimlik', $kimlik)->first()
            ?? Yedek::query()->where('dosya_adi', $dumpAdi)->first()
            ?? new Yedek(['kimlik' => $kimlik]);
        $yeni = ! $yedek->exists;
        $mtime = @filemtime($yol);

        $yedek->fill([
            'dosya_adi' => $dumpAdi,
            'kaynak' => $this->adindanKaynak($dumpAdi),
            'durum' => YedekDurumu::Tamam,
            'imzali' => false,
            'dogrulama_durumu' => DogrulamaDurumu::Dogrulanmadi,
            'boyut' => @filesize($yol) ?: null,
            'alinma_zamani' => $yedek->alinma_zamani ?? ($mtime === false ? null : CarbonImmutable::createFromTimestamp($mtime)),
            'yerel_silinme_zamani' => null,
        ])->save();

        return $yeni;
    }

    /**
     * Kopya, yerel dosyası gitmiş yedeği katalogda (`silindi` olmadan) tutar mı
     * — iki tarama yolunun TEK ölçütü (manifesttenKur, dosyasiYok). Küme
     * KopyaDurumu::yedegiTutanlar'dır (BY-028-R7); "ana klasör hedefinin
     * kopyası yerel dosyanın kendisidir" eşitliği yalnız yeri DOĞRULANMIŞ
     * (`tamam`) kopyaya uygulanır. `konum_belirsiz` kopyada iz uyuşmadığı için
     * hedefin BUGÜNKÜ tanımı kopyanın yerini göstermez: tanım ana klasör olsa
     * da kopya başka bir yerde (ör. ikinci disk) durabilir (G21; BY-028-R8).
     * Geri yükleme ve silme kaynağı yine sayılmaz (onlar yalnız `tamam`'a bakar).
     */
    private function yedegiTutarMi(KopyaDurumu $durum, ?YedekHedefi $hedef): bool
    {
        if (! in_array($durum->value, KopyaDurumu::yedegiTutanlar(), true)) {
            return false;
        }

        // Hedefi tanımsız kopya hep `konum_belirsiz`dir (YedekKopyasi::booted): yedeği tutar (BY-028-R9)
        return $durum !== KopyaDurumu::Tamam || $hedef === null || ! YerelDiskBaglayicisi::anaKlasorHedefiMi($hedef, $this->yapilandirma);
    }

    /**
     * Olaydaki tamam kopyanın bağlanacağı hedef (BY-028-R9). Kopyanın yeri
     * YALNIZ konum izinin eşitliğiyle bulunur (G21; tahmin yok): kimlik / adla
     * bulunan hedefin bugünkü izi olaydakiyle aynıysa o; değilse izi aynı olan
     * hedef (ör. geri yüklenen veritabanına yeni kayıtla — başka kimlik ve adla —
     * yeniden eklenmiş aynı yer; aynı yerin birden çok tanımı varsa en küçük
     * kimlik). İzle hedef bulunamazsa kimlik / adla bulunan hedef (kopya ona
     * `konum_belirsiz` bağlanır; BY-028-R5) ya da null (hedefi tanımsız kopya).
     * İzsiz olay yalnız kimlik / adla eşlenir.
     */
    private function izdekiHedef(?string $iz, ?YedekHedefi $bilinen): ?YedekHedefi
    {
        if ($iz === null || ($bilinen !== null && $this->konum->konumdaMi($bilinen, $iz))) {
            return $bilinen;
        }

        return YedekHedefi::query()->orderBy('id')->get()
            ->first(fn (YedekHedefi $h): bool => $this->konum->konumdaMi($h, $iz)) ?? $bilinen;
    }

    /**
     * Aynı güncel hedefe düşen olay hedeflerinden hangisi hedefin kaydına
     * yazılır, hangileri ayrı tutulur (BY-028-R10; G21, F7). Konum iziyle
     * yeniden eşleme farklı olay hedeflerini (ör. tanımı olmayan eski hedef ve
     * aynı yere yeni kayıtla eklenen hedef) tek hedefe bağlayabilir; her biri
     * ayrı bir fiziksel nesne olabilir (Google Drive'da aynı klasörde iki dosya).
     *
     * 1. Aynı fiziksel nesne (aynı konum izi + aynı uzak kimlik) tek adaydır;
     *    durumu en son olayınkidir (aynı dosyanın üstüne yazılan kopya da,
     *    sonradan silinmesi de onundur). İzi ya da uzak kimliği olmayan kopya
     *    kendi olay hedefinin nesnesidir.
     * 2. Silinmiş nesne yaşayanın (KopyaDurumu::yedegiTutanlar) kaydını ezemez.
     *    Yaşayanlardan biri hedefe bağlanır: yeri doğrulanmış (`tamam`) olan,
     *    sonra olayı bu hedefi kendi kimliği / adıyla anan, sonra en son olay,
     *    sonra hedef anahtarı (sıra bağımsız, deterministik). Diğer yaşayanlar
     *    kaybolmaz: hedefsiz `konum_belirsiz` kayıt olarak ayrılır
     *    (`KOPYA_HEDEFTE_BASKA_KOPYA`) — yedeği tutar, hiçbir işlemin kaynağı
     *    olmaz, listede görünür.
     * 3. Yaşayan yoksa en son olaylı silinmiş nesne hedefin kaydı olur.
     *
     * @param  non-empty-list<array{anahtar: string, k: array<string, mixed>, durum: KopyaDurumu, kendi: bool}>  $adaylar
     * @return array{0: array{anahtar: string, k: array<string, mixed>, durum: KopyaDurumu, kendi: bool}, 1: list<array{anahtar: string, k: array<string, mixed>, durum: KopyaDurumu, kendi: bool}>}
     */
    private function hedefinKopyasi(array $adaylar): array
    {
        $sonOlay = fn (array $a, array $b): int => [$b['k']['zaman'], $b['anahtar']] <=> [$a['k']['zaman'], $a['anahtar']];

        $nesneler = [];
        foreach ($adaylar as $aday) {
            $nesne = $aday['k']['konum_izi'] !== null && $aday['k']['uzak_kimlik'] !== null
                ? 'nesne:'.$aday['k']['konum_izi'].'|'.$aday['k']['uzak_kimlik']
                : 'olay:'.$aday['anahtar'];
            if (! isset($nesneler[$nesne]) || $sonOlay($aday, $nesneler[$nesne]) < 0) {
                $nesneler[$nesne] = $aday;
            }
        }

        $yasayanlar = array_values(array_filter($nesneler, fn (array $a): bool => in_array($a['durum']->value, KopyaDurumu::yedegiTutanlar(), true)));
        if ($yasayanlar === []) {
            $olular = array_values($nesneler);
            usort($olular, $sonOlay);

            return [$olular[0], []];
        }

        usort($yasayanlar, fn (array $a, array $b): int => [$b['durum'] === KopyaDurumu::Tamam, $b['kendi']] <=> [$a['durum'] === KopyaDurumu::Tamam, $a['kendi']] ?: $sonOlay($a, $b));

        return [$yasayanlar[0], array_slice($yasayanlar, 1)];
    }

    /**
     * Hedefe bağlanamayan yaşayan kopya (BY-028-R9, R10): hedefsiz,
     * `konum_belirsiz` kayıt olarak katalogda kalır — fiziksel kopyanın
     * kimliği ve konum izi kaybolmaz, yedek `silindi` olmaz, ekranda uyarıyla
     * görünür. Hiçbir işlemin kaynağı değildir (hepsi `tamam`'a ve bir hedefe
     * dayanır). Nedeni hata kodudur:
     * - `KOPYA_HEDEFI_TANIMSIZ`: olaydaki hedef veritabanında yok; konumu aynı
     *   hedef eklenince sonraki taramada izle bağlanır (izdekiHedef) ve bu
     *   kayıt kalkar.
     * - `KOPYA_HEDEFTE_BASKA_KOPYA`: bağlanacağı hedefe bu yedeğin başka bir
     *   yaşayan nesnesi bağlandı (hedefinKopyasi); o kayıt silinince sonraki
     *   taramada bu nesne hedefe bağlanır.
     *
     * @param  array<string, mixed>  $k  GuncelDurum::kopyalar öğesi
     */
    private function hedefsizKopyaYaz(Yedek $yedek, string $anahtar, array $k, string $hataKodu): void
    {
        $kopya = YedekKopyasi::query()->whereNull('yedek_hedefi_id')->firstOrNew(['yedek_id' => $yedek->id, 'olay_hedef_anahtari' => $anahtar]);
        $kopya->fill([
            'durum' => KopyaDurumu::KonumBelirsiz,
            'hata_kodu' => $hataKodu,
            'olay_hedef_ad' => $k['hedef_ad'],
            'uzak_kimlik' => $k['uzak_kimlik'],
            'sifreli' => $k['sifreli'],
            'sifreli_sha256' => $k['sifreli_sha256'],
            'konum_izi' => $k['konum_izi'],
            'deneme' => $kopya->deneme ?? 0,
        ])->save();
    }

    private function adindanKaynak(string $ad): YedekKaynagi
    {
        foreach (YedekKaynagi::cases() as $kaynak) {
            if (str_starts_with($ad, 'erp_'.$kaynak->value.'_')) {
                return $kaynak;
            }
        }

        // deploy.sh'ın eski yedekleri: erp_predeploy_*
        return str_contains($ad, '_predeploy_') ? YedekKaynagi::YayinOncesi : YedekKaynagi::Elle;
    }

    private function dosyasiYok(Yedek $yedek): void
    {
        $yedek->yerel_silinme_zamani ??= CarbonImmutable::now();

        $baskaKopya = YedekKopyasi::query()->with('hedef')->where('yedek_id', $yedek->id)->whereIn('durum', KopyaDurumu::yedegiTutanlar())->get()
            ->contains(fn (YedekKopyasi $k): bool => $this->yedegiTutarMi($k->durum, $k->hedef));
        if (! $baskaKopya) {
            $yedek->durum = YedekDurumu::Silindi;
            $yedek->silinme_zamani ??= CarbonImmutable::now();
        }

        $yedek->save();
    }
}
