<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\MailAyari;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekPlani;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Üstüne geri yüklemeden sonra ayar uzlaştırması (README §5.3, §6.4 8–10;
 * BY-028-R2). Veritabanındaki ayarlar yedeğin anına döner; dosya kopyası
 * (AyarKopyasi) bugünkü hâli tutar. Farkı olan her grup için yönetici açıkça
 * seçer: `guncel` (dosyadaki kopya) ya da `geri_yuklenen` (veritabanındaki).
 * Varsayılan seçim yoktur; seçimi eksik onay reddedilir (UstuneGeriYukleyici).
 *
 * `guncel` seçilen gruplar, sistem açılmadan ÖNCE tek veritabanı işleminde
 * geri yüklenen veritabanına yazılır; aynı seçimle yeniden çalıştırmak aynı
 * sonucu verir. Sırlar:
 * - Mail parolası dosyada APP_KEY ile şifrelidir; çözülüp yazılır.
 * - Hedef sırları (parola, anahtar) dosyaya HİÇ yazılmaz. Geri yüklenen
 *   veritabanında bulunan hedefin sırları olduğu gibi (yedeğin anındaki hâli)
 *   kalır; yedekten sonra eklenmiş hedef sırsız ve PASİF açılır — yönetici
 *   sırlarını yeniden girip etkinleştirir (uyarı döner). Mevcut hedefte
 *   normal güncellemenin konum ve sır kuralları geçerlidir (hedefleriYaz).
 * - Açık şifreleme anahtarı dosyada yoktur (yalnız parmak izi): yedek
 *   ayarlarında anahtar alanlarına dokunulmaz, parmak izi farklıysa uyarı.
 *
 * Bugün olmayan (yedekten sonra silinmiş) plan / hedef: kullanılmıyorsa
 * silinir; kopyası ya da bağı varsa (RESTRICT) pasife alınıp adına "silinmiş"
 * eklenir — kayıt ve kopya geçmişi kaybolmaz.
 */
final class AyarSecimi
{
    public const GUNCEL = 'guncel';

    public const GERI_YUKLENEN = 'geri_yuklenen';

    /** Dosyadan yazılan yedek ayarı alanları (anahtar alanları hariç — sınıf açıklaması) */
    private const YEDEK_AYARI_ALANLARI = [
        'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun',
        'enaz_adet', 'enaz_bos_alan_yuzde', 'bildirim_alicilari',
        'sinyal_yerel_adresi', 'sinyal_uzak_adresi', 'sinyal_sinama_adresi',
    ];

    public function __construct(
        private readonly AyarKopyasi $kopya,
        private readonly HedefKonumu $konum,
    ) {}

    /**
     * @param  array<mixed>  $secimler
     */
    public static function gecerliMi(array $secimler): bool
    {
        foreach ($secimler as $grup => $secim) {
            if (! in_array($grup, AyarKopyasi::GRUPLAR, true) || ! in_array($secim, [self::GUNCEL, self::GERI_YUKLENEN], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tek biçimli metin (karar kanıtının iletisine ve istek dosyasına girer):
     * GRUPLAR sırasıyla `grup=secim`, virgülle; seçim yoksa boş metin.
     *
     * @param  array<string, string>  $secimler
     *
     * @throws InvalidArgumentException
     */
    public static function metne(array $secimler): string
    {
        if (! self::gecerliMi($secimler)) {
            throw new InvalidArgumentException('Geçersiz ayar seçimi.');
        }

        $parcalar = [];
        foreach (AyarKopyasi::GRUPLAR as $grup) {
            if (isset($secimler[$grup])) {
                $parcalar[] = $grup.'='.$secimler[$grup];
            }
        }

        return implode(',', $parcalar);
    }

    /**
     * metne()'nin tersi; yalnız tek biçimli metni kabul eder (sıra, tekrar).
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    public static function metindenDiziye(string $metin): array
    {
        $secimler = [];
        foreach ($metin === '' ? [] : explode(',', $metin) as $parca) {
            $ikili = explode('=', $parca);
            if (count($ikili) !== 2 || isset($secimler[$ikili[0]])) {
                throw new InvalidArgumentException('Geçersiz ayar seçimi.');
            }
            $secimler[$ikili[0]] = $ikili[1];
        }

        if (! self::gecerliMi($secimler) || self::metne($secimler) !== $metin) {
            throw new InvalidArgumentException('Geçersiz ayar seçimi.');
        }

        return $secimler;
    }

    /**
     * Dosya kopyası (güncel) ile veritabanındaki (geri yüklenen) ayarların
     * grup başına farkı. Kopya okunamıyorsa her grup "farklı" sayılır (bilinmiyor).
     *
     * @return array{kopya_yok: bool, gruplar: list<array{grup: string, farkli: bool}>, farklar: list<array{bolum: string, ad: string|null, degisiklik: string, alanlar: list<string>}>}
     */
    public function karsilastir(): array
    {
        $kopya = $this->kopya->oku();
        $geri = $this->kopya->gruplar();

        if ($kopya === null) {
            return [
                'kopya_yok' => true,
                'gruplar' => array_map(fn (string $g): array => ['grup' => $g, 'farkli' => true], AyarKopyasi::GRUPLAR),
                'farklar' => [],
            ];
        }

        $guncel = $this->kopya->gruplar($kopya);
        $gruplar = [];
        $farklar = [];
        foreach (AyarKopyasi::GRUPLAR as $grup) {
            $fark = in_array($grup, ['planlar', 'hedefler'], true)
                ? $this->listeFarki($grup, $guncel[$grup], $geri[$grup])
                : $this->tekilFarki($grup, $guncel[$grup], $geri[$grup]);
            $gruplar[] = ['grup' => $grup, 'farkli' => $fark !== []];
            array_push($farklar, ...$fark);
        }

        return ['kopya_yok' => false, 'gruplar' => $gruplar, 'farklar' => $farklar];
    }

    /**
     * `guncel` seçilen grupları dosya kopyasından veritabanına yazar (tek işlem).
     *
     * @param  array<string, string>  $secimler
     * @return list<string> Yöneticinin bilmesi gerekenler (sırsız açılan hedef, şifreleme anahtarı …)
     *
     * @throws GeriYuklemeHatasi AYAR_SECIMI_UYGULANAMADI (hiçbir şey yazılmadı)
     */
    public function uygula(array $secimler): array
    {
        $guncel = array_keys(array_filter($secimler, fn (string $s): bool => $s === self::GUNCEL));
        if ($guncel === []) {
            return [];
        }

        $kopya = $this->kopya->oku()
            ?? throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_SECIMI_UYGULANAMADI, 'Ayarların dosya kopyası okunamadı (yok ya da imzası tutmuyor); "güncel" uygulanamaz. "Geri yüklenen"i seçin ya da kopyayı onarın.');

        try {
            return DB::transaction(function () use ($guncel, $kopya): array {
                $uyarilar = [];
                $secili = fn (string $grup): bool => in_array($grup, $guncel, true);
                $hedefler = $this->liste($kopya['hedefler'] ?? []);
                $planlar = $this->liste($kopya['planlar'] ?? []);

                if ($secili('mail')) {
                    $this->mailYaz(is_array($kopya['mail'] ?? null) ? $kopya['mail'] : null);
                }
                if ($secili('yedek_ayarlari')) {
                    array_push($uyarilar, ...$this->yedekAyariYaz((array) ($kopya['yedek_ayarlari'] ?? [])));
                }
                // Önce hedefler (planların bağları onları gösterir), en son fazla hedefler
                if ($secili('hedefler')) {
                    array_push($uyarilar, ...$this->hedefleriYaz($hedefler));
                }
                if ($secili('planlar')) {
                    array_push($uyarilar, ...$this->planlariYaz($planlar));
                }
                if ($secili('hedefler')) {
                    $this->fazlalariKaldir('yedek_hedefleri', array_column($hedefler, 'id'), fn (int $id): bool => DB::table('yedek_plan_hedefleri')->where('yedek_hedefi_id', $id)->exists() || DB::table('yedek_kopyalari')->where('yedek_hedefi_id', $id)->exists());
                }

                return $uyarilar;
            });
        } catch (GeriYuklemeHatasi $hata) {
            throw $hata;
        } catch (Throwable $hata) {
            // İleti veri taşıyabilir: yalnız sınıf adı (D2)
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_SECIMI_UYGULANAMADI, 'Güncel ayarlar yazılamadı ('.$hata::class.'); hiçbir ayar değişmedi.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $mail
     */
    private function mailYaz(?array $mail): void
    {
        if ($mail === null) {
            MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->delete();

            return;
        }

        try {
            $sifre = is_string($mail['sifre_sifreli'] ?? null) ? Crypt::decryptString($mail['sifre_sifreli']) : null;
        } catch (Throwable) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_SECIMI_UYGULANAMADI, 'Dosya kopyasındaki mail parolası çözülemedi (APP_KEY değişmiş olabilir); mail için "geri yüklenen"i seçin.');
        }

        $ayar = MailAyari::query()->firstOrNew(['anahtar' => MailAyari::ANAHTAR]);
        $ayar->forceFill(array_intersect_key($mail, array_flip(['sunucu', 'port', 'sifreleme', 'kullanici_adi', 'gonderen_adres', 'gonderen_ad', 'yonlendirme_adresi'])));
        $ayar->sifre = $sifre;
        $ayar->save();
    }

    /**
     * @param  array<string, mixed>  $degerler
     * @return list<string>
     */
    private function yedekAyariYaz(array $degerler): array
    {
        $ayar = YedekAyari::query()->firstOrNew(['anahtar' => YedekAyari::ANAHTAR]);
        $ayar->forceFill(array_intersect_key($degerler, array_flip(self::YEDEK_AYARI_ALANLARI)));
        $ayar->save();

        return ($degerler['sifreleme_parmak_izi'] ?? null) === $ayar->sifreleme_parmak_izi ? [] : [
            'Sunucu dışı kopyaların şifreleme anahtarı yedekten sonra değişmiş; açık anahtar dosya kopyasında olmadığı için geri yüklenen (eski) anahtar duruyor. Yedekleme ayarlarından güncel açık anahtarı yeniden yükleyin.',
        ];
    }

    /**
     * Dosyadaki hedefler: yoksa aynı kimlikle sırsız ve pasif açılır; varsa
     * normal hedef güncellemesinin konum ve sır kuralları (HedefKonumu,
     * BY-028-R3) uygulanır:
     * - Kopyası olan hedefin yeri (sunucu, port, klasör, hesap…) değişmişse
     *   hedef geri yüklenen yerinde ve sırlarıyla kalır — geri yüklenen kopya
     *   kayıtları o yerdeki dosyaları gösterir — ama PASİF açılır (bugün o yer
     *   kullanılmıyor); yeni yer için yönetici yeni hedef ekler. "Kopyası
     *   olan" yalnız yeri doğrulanmış kopyadır: dizin kurulurken olay
     *   günlüğünden gelen başka yerdeki kopya `konum_belirsiz` olur, kilidi
     *   tutmaz; hedef bugünkü yerine dönünce sonraki tarama onu bağlar (BY-028-R5).
     * - Sırrın gideceği adres değişmişse geri yüklenen (eski) sırlar yeni
     *   adrese gönderilmesin diye silinir, hedef PASİF açılır.
     * - Aksi hâlde bugünkü bağlantı yazılır, sırlar geri yüklenen hâliyle kalır.
     *
     * @param  list<array<string, mixed>>  $hedefler
     * @return list<string>
     */
    private function hedefleriYaz(array $hedefler): array
    {
        $this->bugunOlmayanlariAyir('yedek_hedefleri', array_column($hedefler, 'id'));
        $this->degisecekAdlariBosalt('yedek_hedefleri', $hedefler);
        $uyarilar = [];
        $eklendi = false;

        foreach ($hedefler as $h) {
            $hedef = YedekHedefi::query()->find($h['id']);
            $baglanti = is_array($h['baglanti'] ?? null) ? $h['baglanti'] : [];

            if ($hedef === null) {
                $hedef = (new YedekHedefi)->forceFill(['ad' => $h['ad'], 'tur' => $h['tur'], 'aktif' => false, 'baglanti' => $baglanti, 'gizli_bilgiler' => null]);
                $hedef->id = (int) $h['id'];
                $hedef->save();
                $eklendi = true;
                $uyarilar[] = "Yedek hedefi \"{$h['ad']}\" yedekten sonra eklenmiş: sırları (parola / anahtar) dosya kopyasında olmadığı için PASİF açıldı. Sırlarını yeniden girip etkinleştirin.";

                continue;
            }

            $turDegisti = $hedef->tur->value !== $h['tur'];
            $konumFarki = $turDegisti ? $this->konum->kilitliAlanlar($hedef) : $this->konum->degisenKilitliAlanlar($hedef, $baglanti);

            if ($konumFarki !== []) {
                $hedef->forceFill(['ad' => $h['ad'], 'aktif' => false])->save();
                $uyarilar[] = "Yedek hedefi \"{$h['ad']}\": kopyaların durduğu yer (".implode(', ', $konumFarki).') yedekten sonra değişmiş; geri yüklenen kopya kayıtları eski yerdeki dosyaları gösterdiği için hedef eski yerinde bırakıldı ve PASİF açıldı. Yeni yer için yeni bir hedef ekleyin.';

                continue;
            }

            $adresDegisti = $turDegisti || $this->konum->adresDegistiMi($hedef, $baglanti);
            $baglantiDegisti = KanonikJson::kodla($hedef->baglanti) !== KanonikJson::kodla($baglanti);
            $hedef->forceFill(['ad' => $h['ad'], 'tur' => $h['tur'], 'aktif' => $adresDegisti ? false : (bool) $h['aktif'], 'baglanti' => $baglanti]);

            if ($adresDegisti) {
                $hedef->gizli_bilgiler = null;
                $uyarilar[] = "Yedek hedefi \"{$h['ad']}\" bağlantı adresi yedekten sonra değişmiş: geri yüklenen (eski) sırları yeni adrese gönderilmesin diye silindi ve PASİF açıldı. Sırlarını yeniden girip etkinleştirin.";
            } elseif ($baglantiDegisti) {
                $uyarilar[] = "Yedek hedefi \"{$h['ad']}\" bağlantı bilgisi güncellendi; sırları geri yüklenen (yedeğin anındaki) hâlinde. Değiştiyse yeniden girin.";
            }
            $hedef->save();
        }

        if ($eklendi) {
            $this->siraNumarasiniIlerlet('yedek_hedefleri');
        }

        return $uyarilar;
    }

    /**
     * @param  list<array<string, mixed>>  $planlar
     * @return list<string>
     */
    private function planlariYaz(array $planlar): array
    {
        $this->bugunOlmayanlariAyir('yedek_planlari', array_column($planlar, 'id'));
        $this->degisecekAdlariBosalt('yedek_planlari', $planlar);
        $uyarilar = [];
        $eklendi = false;

        foreach ($planlar as $p) {
            $plan = YedekPlani::query()->find($p['id']);
            $yeni = $plan === null;
            $plan ??= new YedekPlani;
            $takvimDegisti = $yeni || $plan->siklik_turu->value !== $p['siklik_turu'] || $plan->saat !== $p['saat'] || KanonikJson::kodla($plan->siklik) !== KanonikJson::kodla($p['siklik'] ?? []);
            $plan->forceFill(['ad' => $p['ad'], 'aktif' => (bool) $p['aktif'], 'siklik_turu' => $p['siklik_turu'], 'saat' => $p['saat'], 'siklik' => $p['siklik'] ?? []]);
            if ($yeni) {
                $plan->id = (int) $p['id'];
                $eklendi = true;
            }
            if ($takvimDegisti) {
                // Zamanlayıcı boş sonraki çalışmayı yeni takvimden hesaplar (YedekZamanla)
                $plan->sonraki_calisma = null;
            }
            $plan->save();

            array_push($uyarilar, ...$this->planHedefleriniYaz($plan, $this->liste($p['hedefler'] ?? [])));
        }

        if ($eklendi) {
            $this->siraNumarasiniIlerlet('yedek_planlari');
        }

        // Yalnız yedeği tutan kopyanın bağı planı tutar; diğerleri silmeden önce kalkar (G1-2)
        $this->fazlalariKaldir('yedek_planlari', array_column($planlar, 'id'), function (int $id): bool {
            KopyaPlanBaglari::tutmayanlariKaldir($id);

            return KopyaPlanBaglari::tutanlar()->where('yedek_kopya_planlari.yedek_plani_id', $id)->exists();
        });

        return $uyarilar;
    }

    /**
     * Planın hedef bağları ve saklama kuralları dosyadaki gibi. Yedeği tutan
     * kopyanın bağı olan kural silinemez (RESTRICT): kalır, uyarı döner.
     * Başarısız / bekleyen kopyanın bağı önce kaldırılır (KopyaPlanBaglari; G1-2).
     *
     * @param  list<array<string, mixed>>  $istenen
     * @return list<string>
     */
    private function planHedefleriniYaz(YedekPlani $plan, array $istenen): array
    {
        $simdi = now();
        $istenenler = [];
        foreach ($istenen as $bag) {
            $hedefId = (int) $bag['hedef_id'];
            if (! YedekHedefi::query()->whereKey($hedefId)->exists()) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_SECIMI_UYGULANAMADI, "Güncel plan \"{$plan->ad}\" geri yüklenen veritabanında olmayan bir hedefi (#{$hedefId}) kullanıyor; hedefler için de \"güncel\"i seçin.");
            }
            $istenenler[$hedefId] = ['sakla_son_adet' => $bag['sakla_son_adet'] ?? null, 'sakla_gun' => $bag['sakla_gun'] ?? null];
        }

        $mevcut = DB::table('yedek_plan_hedefleri')->where('yedek_plani_id', $plan->id)->pluck('yedek_hedefi_id')->map(fn (mixed $id): int => (int) $id)->all();
        foreach ($istenenler as $hedefId => $kural) {
            in_array($hedefId, $mevcut, true)
                ? DB::table('yedek_plan_hedefleri')->where('yedek_plani_id', $plan->id)->where('yedek_hedefi_id', $hedefId)->update([...$kural, 'updated_at' => $simdi])
                : DB::table('yedek_plan_hedefleri')->insert([...$kural, 'yedek_plani_id' => $plan->id, 'yedek_hedefi_id' => $hedefId, 'created_at' => $simdi, 'updated_at' => $simdi]);
        }

        $uyarilar = [];
        foreach (array_diff($mevcut, array_keys($istenenler)) as $hedefId) {
            KopyaPlanBaglari::tutmayanlariKaldir($plan->id, [$hedefId]);
            if (KopyaPlanBaglari::tutanlar()->where('yedek_kopya_planlari.yedek_plani_id', $plan->id)->where('yedek_kopya_planlari.yedek_hedefi_id', $hedefId)->exists()) {
                $uyarilar[] = "Plan \"{$plan->ad}\": bugün kaldırılmış hedef bağı (#{$hedefId}) kopyaları tuttuğu için silinemedi; ekrandan inceleyin.";

                continue;
            }
            DB::table('yedek_plan_hedefleri')->where('yedek_plani_id', $plan->id)->where('yedek_hedefi_id', $hedefId)->delete();
        }

        return $uyarilar;
    }

    /**
     * Bugün olmayan satırlar ad çakışmasın diye önce pasife alınıp yeniden
     * adlandırılır (silinip yeniden açılmış aynı adlı kayıt olabilir).
     *
     * @param  'yedek_hedefleri'|'yedek_planlari'  $tablo
     * @param  list<mixed>  $bugunkuler
     */
    private function bugunOlmayanlariAyir(string $tablo, array $bugunkuler): void
    {
        foreach (DB::table($tablo)->whereNotIn('id', array_map('intval', $bugunkuler))->get(['id', 'ad']) as $satir) {
            $ek = " (silinmiş #{$satir->id})";
            DB::table($tablo)->where('id', $satir->id)->update([
                // Yeniden çalıştırmada ek ikinci kez eklenmez
                'ad' => str_ends_with((string) $satir->ad, $ek) ? (string) $satir->ad : Str::limit((string) $satir->ad, 70, '').$ek,
                'aktif' => false,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Ad tekildir ve satırlar kimlik sırasıyla yazılır: #1'in yeni adı henüz
     * yazılmamış #2'nin eski adına çarpardı (YF-44). Adı değişecek mevcut
     * satırlar önce kimliği taşıyan geçici, tekil bir ada alınır; döngü
     * gerçek adı yazar. Aynı işlemdedir: hata olursa geçici adlar da geri alınır.
     *
     * @param  'yedek_hedefleri'|'yedek_planlari'  $tablo
     * @param  list<array<string, mixed>>  $kayitlar  Dosyadaki (güncel) satırlar
     */
    private function degisecekAdlariBosalt(string $tablo, array $kayitlar): void
    {
        foreach ($kayitlar as $k) {
            $ad = (string) ($k['ad'] ?? '');
            DB::table($tablo)->where('id', (int) $k['id'])->where('ad', '!=', $ad)
                ->update(['ad' => mb_substr('~'.(int) $k['id'].'~'.$ad, 0, 100), 'updated_at' => now()]);
        }
    }

    /**
     * Bugün olmayan, kullanılmayan satırları siler; kullanılanlar pasif kalır.
     *
     * @param  'yedek_hedefleri'|'yedek_planlari'  $tablo
     * @param  list<mixed>  $bugunkuler
     * @param  \Closure(int): bool  $kullaniliyor
     */
    private function fazlalariKaldir(string $tablo, array $bugunkuler, \Closure $kullaniliyor): void
    {
        foreach (DB::table($tablo)->whereNotIn('id', array_map('intval', $bugunkuler))->pluck('id') as $id) {
            if (! $kullaniliyor((int) $id)) {
                DB::table($tablo)->where('id', $id)->delete();
            }
        }
    }

    /** Kimliği elle verilen satırdan sonra PostgreSQL dizisi geride kalmasın */
    private function siraNumarasiniIlerlet(string $tablo): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$tablo}', 'id'), (SELECT max(id) FROM {$tablo}))");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liste(mixed $deger): array
    {
        return array_values(array_filter(is_array($deger) ? $deger : [], fn (mixed $s): bool => is_array($s) && is_int($s['id'] ?? $s['hedef_id'] ?? null)));
    }

    /**
     * @param  array<string, mixed>|null  $guncel
     * @param  array<string, mixed>|null  $geri
     * @return list<array{bolum: string, ad: string|null, degisiklik: string, alanlar: list<string>}>
     */
    private function tekilFarki(string $grup, ?array $guncel, ?array $geri): array
    {
        $degisiklik = match (true) {
            $guncel === null && $geri === null => null,
            $guncel === null => 'yalniz_geri_yuklenende',
            $geri === null => 'geri_yuklenende_yok',
            default => $this->alanlar($guncel, $geri) === [] ? null : 'degisti',
        };

        return $degisiklik === null ? [] : [[
            'bolum' => $grup, 'ad' => null, 'degisiklik' => $degisiklik,
            'alanlar' => $degisiklik === 'degisti' ? $this->alanlar((array) $guncel, (array) $geri) : [],
        ]];
    }

    /**
     * Kimliğe göre (ad değişse de aynı kayıt).
     *
     * @param  list<array<string, mixed>>  $guncel
     * @param  list<array<string, mixed>>  $geri
     * @return list<array{bolum: string, ad: string|null, degisiklik: string, alanlar: list<string>}>
     */
    private function listeFarki(string $grup, array $guncel, array $geri): array
    {
        $g = array_column($guncel, null, 'id');
        $r = array_column($geri, null, 'id');
        $farklar = [];

        foreach (array_unique([...array_keys($g), ...array_keys($r)]) as $id) {
            $alanlar = isset($g[$id], $r[$id]) ? $this->alanlar($g[$id], $r[$id]) : [];
            $degisiklik = match (true) {
                ! isset($r[$id]) => 'geri_yuklenende_yok',
                ! isset($g[$id]) => 'yalniz_geri_yuklenende',
                $alanlar !== [] => 'degisti',
                default => null,
            };

            if ($degisiklik !== null) {
                $farklar[] = ['bolum' => $grup, 'ad' => (string) (($g[$id] ?? $r[$id])['ad'] ?? $id), 'degisiklik' => $degisiklik, 'alanlar' => $alanlar];
            }
        }

        return $farklar;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<string>
     */
    private function alanlar(array $a, array $b): array
    {
        $alanlar = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $alan) {
            if ($alan !== 'id' && KanonikJson::kodla($a[$alan] ?? null) !== KanonikJson::kodla($b[$alan] ?? null)) {
                $alanlar[] = (string) $alan;
            }
        }
        sort($alanlar);

        return $alanlar;
    }
}
