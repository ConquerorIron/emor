<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Mail\YedeklemeMaili;
use App\Models\MailAyari;
use App\Models\YedekAyari;
use App\Services\MailAyarServisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use JsonException;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Yedekleme bildirimleri (README §4.8; BY-012).
 *
 * - Mail KUYRUĞA KONMAZ, doğrudan gönderilir: kuyruk tablosu veritabanında;
 *   geri yükleme sırasında ya da kuyruk dururken bildirim kaybolmasın.
 * - Mail ayarı ve alıcılar önce veritabanından, okunamazsa ayarların dosya
 *   kopyasından (AyarKopyasi) alınır.
 * - Aynı anahtar için bir saat içinde ikinci mail gitmez (yineleme sınırı;
 *   son gönderimler durum klasöründe dosyada — veritabanına dayanmaz).
 * - Bildirim hatası işi bozmaz: günlüğe yazılır.
 */
final class YedekBildirimcisi
{
    public const YINELEME_DAKIKA = 60;

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly AyarKopyasi $ayarKopyasi,
    ) {}

    /**
     * @param  string  $tur  yedekleme.mail.* anahtarı (yedek_basarisiz, kopya_basarisiz …)
     * @param  list<string>  $satirlar  Sır taşımayan açıklama satırları
     * @param  string|null  $anahtar  Yineleme anahtarı (varsayılan: tür)
     * @return bool Mail gönderildi mi
     */
    public function bildir(string $tur, array $satirlar, ?string $anahtar = null): bool
    {
        $anahtar ??= $tur;

        try {
            if ($this->yakindaGonderildi($anahtar)) {
                return false;
            }

            [$ayar, $alicilar] = $this->ayarVeAlicilar();
            if ($ayar === null || $alicilar === []) {
                Log::channel('yedekleme')->warning('Yedekleme bildirimi gönderilemedi: mail ayarı ya da alıcı yok', ['tur' => $tur]);

                return false;
            }

            MailAyarServisi::mailerKur($ayar);
            $mesaj = new YedeklemeMaili(__('yedekleme.mail.'.$tur), $satirlar);

            if ($ayar->yonlendirme_adresi !== null) {
                $asil = implode(', ', $alicilar);
                $mesaj->withSymfonyMessage(fn (Email $m) => $m->getHeaders()->addTextHeader('X-Emor-Asil-Alicilar', $asil));
                $alicilar = [$ayar->yonlendirme_adresi];
            }

            Mail::mailer(MailAyarServisi::MAILER)->to($alicilar)->send($mesaj);
            $this->gonderildi($anahtar);

            return true;
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->error('Yedekleme bildirimi gönderilemedi', ['tur' => $tur, 'hata' => $hata::class]);

            return false;
        }
    }

    /**
     * @return array{0: MailAyari|null, 1: list<string>}
     */
    private function ayarVeAlicilar(): array
    {
        try {
            $ayar = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();
            // Kaydedilmemiş varsayılan nesne boş liste taşır; o zaman dosya kopyasına bakılır
            $alicilar = YedekAyari::query()->where('anahtar', YedekAyari::ANAHTAR)->first()?->bildirim_alicilari;
        } catch (Throwable) {
            $ayar = null;
            $alicilar = null;
        }

        if ($ayar === null || $alicilar === null) {
            $ayar ??= $this->ayarKopyasi->mailAyari();
            $alicilar ??= $this->ayarKopyasi->oku()['yedek_ayarlari']['bildirim_alicilari'] ?? [];
        }

        $alicilar = array_values(array_filter((array) $alicilar, fn (mixed $a): bool => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL) !== false));

        return [$ayar, $alicilar];
    }

    private function yakindaGonderildi(string $anahtar): bool
    {
        $son = $this->gonderimler()[$anahtar] ?? null;

        return is_string($son) && CarbonImmutable::parse($son)->greaterThan(CarbonImmutable::now()->subMinutes(self::YINELEME_DAKIKA));
    }

    private function gonderildi(string $anahtar): void
    {
        $kayit = $this->gonderimler();
        $kayit[$anahtar] = CarbonImmutable::now('UTC')->toIso8601String();
        // Eski girdiler birikmesin
        $kayit = array_filter($kayit, fn (mixed $z): bool => is_string($z) && CarbonImmutable::parse($z)->greaterThan(CarbonImmutable::now()->subDays(2)));

        $yol = $this->yol();
        if (! is_dir(dirname($yol))) {
            KaliciKlasor::ac(dirname($yol), $this->yapilandirma->kaliciKok());
        }
        AtomikDosya::yaz($yol, json_encode($kayit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, string>
     */
    private function gonderimler(): array
    {
        $icerik = @file_get_contents($this->yol());
        if (! is_string($icerik)) {
            return [];
        }

        try {
            $veri = json_decode($icerik, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($veri) ? array_filter($veri, 'is_string') : [];
    }

    private function yol(): string
    {
        return $this->yapilandirma->durumKlasoru().'/bildirimler.json';
    }
}
