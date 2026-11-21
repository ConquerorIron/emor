<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\MailAyari;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekPlani;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Throwable;

/**
 * Ayarların dosya kopyası (README §5.3 "Ayarlar da geri döner", §4.8): mail
 * ayarı, yedek ayarları, planlar ve hedefler (sırlar hariç). Veritabanı geri
 * yüklenince eski hâline döner; bu dosya güncel kalır:
 *
 * - bildirimci veritabanına ulaşamazken maili buradaki ayarla gönderir,
 * - üstüne geri yüklemeden sonra fark yöneticiye gösterilir (BY-029),
 * - kurtarma için sunucu dışına gider (BY-024).
 *
 * SMTP parolası `APP_KEY` ile şifreli durur (mail gönderebilmek için
 * gerekir); hedef parolaları ve anahtarları dosyaya girmez. Dosya imzalıdır;
 * imzası tutmayan kopya okunmaz.
 */
final class AyarKopyasi
{
    public const SURUM = 1;

    /**
     * Kopyanın grupları: üstüne geri yüklemeden sonra yönetici her farklı grup
     * için "güncel" (bu dosya) ya da "geri yüklenen" seçer (README §5.3; BY-028-R2).
     */
    public const GRUPLAR = ['mail', 'yedek_ayarlari', 'planlar', 'hedefler'];

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly YedekImzasi $imza,
    ) {}

    /**
     * @throws DecryptException Veritabanındaki SMTP parolası çözülemiyor (kopya yazılmaz)
     */
    public function yaz(): void
    {
        $mail = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();
        $sifre = $mail?->sifre;
        $icerik = $this->icerik($mail, $sifre);
        $veri = [
            'surum' => self::SURUM,
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
            'parmak_izi' => $this->parmakIzi($icerik, $sifre),
            ...$icerik,
        ];

        $yol = $this->yapilandirma->ayarKopyasiYolu();
        $klasor = dirname($yol);
        if (! is_dir($klasor)) {
            KaliciKlasor::ac($klasor, $this->yapilandirma->kaliciKok());
        }

        AtomikDosya::yaz($yol, json_encode(['ayarlar' => $veri, 'imza' => $this->imza->imzala(json_decode(json_encode($veri, JSON_THROW_ON_ERROR), true))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * Kopya veritabanındaki ayarlardan farklıysa (ya da yoksa / bozuksa) yazar.
     * Çalıştırıcı her turda çağırır: ayar ekranları web sürecinde değişir,
     * servis kipinde web süreci kopyayı yazamaz (README §5.5); mail ayarı
     * ekranı da kopyayı bilmez. Doğrudan kipte ayar ekranının commit'ten sonra
     * yazamadığı kopyayı da bu uzlaştırır (BY-018-R2).
     *
     * @return bool Yazıldı mı
     *
     * @throws DecryptException Veritabanındaki SMTP parolası çözülemiyor (çağıran günlüğe yazar, turu sürdürür)
     */
    public function guncelDegilseYaz(): bool
    {
        if ($this->veritabanindakiyleAyniMi()) {
            return false;
        }

        $this->yaz();

        return true;
    }

    /**
     * Ekranın "kopya güncel mi" bilgisi (BY-018-R2): imzası tutan kopya
     * veritabanındaki ayarlarla aynı mı. İmza anahtarı bu süreçte okunamıyorsa
     * (servis kipinde web süreci — README §5.5) bilinemez: null. Güncel
     * değilse çalıştırıcının her turu yazar (guncelDegilseYaz).
     *
     * Veritabanındaki SMTP parolası çözülemiyorsa (bozuk değer, APP_KEY
     * değişmiş) kopya o ayardan üretilemez, dolayısıyla güncel değildir:
     * false. Okuma ucu bu yüzden düşmez; yazımdaki hata denetime ayar ekranında
     * ve çalıştırıcı günlüğünde düşer (BY-018-R3).
     */
    public function guncelMi(): ?bool
    {
        if (! $this->imza->anahtarVarMi()) {
            return null;
        }

        try {
            return $this->veritabanindakiyleAyniMi();
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * @throws DecryptException
     */
    private function veritabanindakiyleAyniMi(): bool
    {
        $mail = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();
        $sifre = $mail?->sifre;

        return ($this->oku()['parmak_izi'] ?? null) === $this->parmakIzi($this->icerik($mail, $sifre), $sifre);
    }

    /**
     * Şifreli parola her yazımda farklı çıktığından parmak izine parolanın
     * kendisi değil APP_KEY'li HMAC'i girer.
     *
     * @param  array<string, mixed>  $icerik
     */
    private function parmakIzi(array $icerik, ?string $sifre): string
    {
        $icerik['mail'] = $icerik['mail'] === null ? null : [
            ...array_diff_key($icerik['mail'], ['sifre_sifreli' => true]),
            'sifre' => $sifre === null ? null : hash_hmac('sha256', $sifre, (string) config('app.key')),
        ];

        return hash('sha256', KanonikJson::kodla(json_decode(json_encode($icerik, JSON_THROW_ON_ERROR), true)));
    }

    /**
     * @param  string|null  $sifre  Mail ayarının çözülmüş parolası (şifrelenip dosyaya girer)
     * @return array<string, mixed>
     */
    private function icerik(?MailAyari $mail, ?string $sifre): array
    {
        $ayar = YedekAyari::guncel();

        return [
            'mail' => $mail === null ? null : [
                'sunucu' => $mail->sunucu,
                'port' => $mail->port,
                'sifreleme' => $mail->sifreleme,
                'kullanici_adi' => $mail->kullanici_adi,
                'sifre_sifreli' => $sifre === null ? null : Crypt::encryptString($sifre),
                'gonderen_adres' => $mail->gonderen_adres,
                'gonderen_ad' => $mail->gonderen_ad,
                'yonlendirme_adresi' => $mail->yonlendirme_adresi,
            ],
            'yedek_ayarlari' => $ayar->only([
                'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun',
                'enaz_adet', 'enaz_bos_alan_yuzde', 'bildirim_alicilari',
                'sinyal_yerel_adresi', 'sinyal_uzak_adresi', 'sinyal_sinama_adresi',
                // Hangi açık anahtarla şifrelendiği (kurtarmada hangi özel anahtarın gerektiği)
                'sifreleme_parmak_izi',
            ]),
            'planlar' => YedekPlani::query()->with('hedefler:id')->orderBy('id')->get()->map(fn (YedekPlani $p): array => [
                'id' => $p->id, 'ad' => $p->ad, 'aktif' => $p->aktif, 'siklik_turu' => $p->siklik_turu->value,
                'saat' => $p->saat, 'siklik' => $p->siklik,
                'hedefler' => $p->hedefler->map(fn (YedekHedefi $h): array => [
                    'hedef_id' => $h->id, 'sakla_son_adet' => $h->pivot->sakla_son_adet, 'sakla_gun' => $h->pivot->sakla_gun,
                ])->all(),
            ])->all(),
            'hedefler' => YedekHedefi::query()->orderBy('id')->get()->map(fn (YedekHedefi $h): array => [
                'id' => $h->id, 'ad' => $h->ad, 'tur' => $h->tur->value, 'aktif' => $h->aktif, 'baglanti' => $h->baglanti,
            ])->all(),
        ];
    }

    /**
     * Grup başına karşılaştırılabilir içerik (GRUPLAR): `$kopya` verilirse o
     * dosya kopyasının (oku()), verilmezse veritabanındaki ayarların. Sır
     * taşımaz: mail parolası yerine APP_KEY'li HMAC'i (`sifre`) durur; plan
     * hedefleri hedef kimliğine göre sıralıdır.
     *
     * @param  array<string, mixed>|null  $kopya
     * @return array{mail: array<string, mixed>|null, yedek_ayarlari: array<string, mixed>, planlar: list<array<string, mixed>>, hedefler: list<array<string, mixed>>}
     */
    public function gruplar(?array $kopya = null): array
    {
        if ($kopya === null) {
            $mail = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();
            // Şifreli parola karşılaştırmaya girmez (aşağıda HMAC'i girer)
            $icerik = $this->icerik($mail, null);
            try {
                $sifre = $mail?->sifre;
            } catch (DecryptException) {
                // Veritabanında çözülemeyen parola (geri yüklenen yedek başka APP_KEY'le)
                // hiçbir parolayla — kopyanın çözülemeyeniyle de — eşleşmesin (BY-018-R3)
                $sifre = "\0cozulemedi:veritabani";
            }
        } else {
            $icerik = $kopya;
            try {
                $sifre = is_string($kopya['mail']['sifre_sifreli'] ?? null) ? Crypt::decryptString($kopya['mail']['sifre_sifreli']) : null;
            } catch (Throwable) {
                // Çözülemeyen parola (APP_KEY değişmiş) hiçbir parolayla eşleşmesin
                $sifre = "\0cozulemedi";
            }
        }

        /** @var array<string, mixed> $dizi */
        $dizi = json_decode(json_encode(array_intersect_key($icerik, array_flip(self::GRUPLAR)), JSON_THROW_ON_ERROR), true);
        $mail = is_array($dizi['mail'] ?? null) ? $dizi['mail'] : null;
        if ($mail !== null) {
            unset($mail['sifre_sifreli']);
            $mail['sifre'] = $sifre === null ? null : hash_hmac('sha256', $sifre, (string) config('app.key'));
        }

        $planlar = [];
        foreach ((array) ($dizi['planlar'] ?? []) as $plan) {
            $hedefler = (array) ($plan['hedefler'] ?? []);
            usort($hedefler, fn (array $a, array $b): int => ($a['hedef_id'] ?? 0) <=> ($b['hedef_id'] ?? 0));
            $planlar[] = [...$plan, 'hedefler' => $hedefler];
        }

        return [
            'mail' => $mail,
            'yedek_ayarlari' => (array) ($dizi['yedek_ayarlari'] ?? []),
            'planlar' => $planlar,
            'hedefler' => array_values((array) ($dizi['hedefler'] ?? [])),
        ];
    }

    /**
     * İmzası tutan kopya; yoksa / bozuksa null.
     *
     * @return array<string, mixed>|null
     */
    public function oku(): ?array
    {
        $icerik = @file_get_contents($this->yapilandirma->ayarKopyasiYolu());
        if (! is_string($icerik)) {
            return null;
        }

        try {
            $veri = json_decode($icerik, true, 64, JSON_THROW_ON_ERROR);

            return is_array($veri) && is_array($veri['ayarlar'] ?? null) && $this->imza->dogrula($veri['ayarlar'], $veri['imza'] ?? null)
                ? $veri['ayarlar'] : null;
        } catch (JsonException|ImzaAnahtariYok) {
            return null;
        }
    }

    /** Dosyadaki mail ayarı (kaydedilmemiş nesne); yoksa null. */
    public function mailAyari(): ?MailAyari
    {
        $mail = $this->oku()['mail'] ?? null;
        if (! is_array($mail)) {
            return null;
        }

        try {
            $sifre = is_string($mail['sifre_sifreli'] ?? null) ? Crypt::decryptString($mail['sifre_sifreli']) : null;
        } catch (Throwable) {
            $sifre = null;
        }

        $ayar = new MailAyari;
        $ayar->forceFill([
            'anahtar' => MailAyari::ANAHTAR,
            'sunucu' => $mail['sunucu'] ?? null,
            'port' => $mail['port'] ?? null,
            'sifreleme' => $mail['sifreleme'] ?? MailAyari::SIFRELEME_TLS,
            'kullanici_adi' => $mail['kullanici_adi'] ?? null,
            'gonderen_adres' => $mail['gonderen_adres'] ?? null,
            'gonderen_ad' => $mail['gonderen_ad'] ?? null,
            'yonlendirme_adresi' => $mail['yonlendirme_adresi'] ?? null,
        ]);
        $ayar->sifre = $sifre;

        return $ayar;
    }
}
