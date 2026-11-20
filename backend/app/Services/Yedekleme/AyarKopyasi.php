<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\MailAyari;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekPlani;
use Carbon\CarbonImmutable;
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

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly YedekImzasi $imza,
    ) {}

    public function yaz(): void
    {
        $ayar = YedekAyari::guncel();
        $mail = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();

        $veri = [
            'surum' => self::SURUM,
            'zaman' => CarbonImmutable::now('UTC')->toIso8601String(),
            'mail' => $mail === null ? null : [
                'sunucu' => $mail->sunucu,
                'port' => $mail->port,
                'sifreleme' => $mail->sifreleme,
                'kullanici_adi' => $mail->kullanici_adi,
                'sifre_sifreli' => $mail->sifre === null ? null : Crypt::encryptString($mail->sifre),
                'gonderen_adres' => $mail->gonderen_adres,
                'gonderen_ad' => $mail->gonderen_ad,
                'yonlendirme_adresi' => $mail->yonlendirme_adresi,
            ],
            'yedek_ayarlari' => $ayar->only([
                'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun',
                'enaz_adet', 'enaz_bos_alan_yuzde', 'bildirim_alicilari',
                'sinyal_yerel_adresi', 'sinyal_uzak_adresi', 'sinyal_sinama_adresi',
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

        $yol = $this->yapilandirma->ayarKopyasiYolu();
        $klasor = dirname($yol);
        if (! is_dir($klasor)) {
            KaliciKlasor::ac($klasor, $this->yapilandirma->kaliciKok());
        }

        AtomikDosya::yaz($yol, json_encode(['ayarlar' => $veri, 'imza' => $this->imza->imzala(json_decode(json_encode($veri, JSON_THROW_ON_ERROR), true))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
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
