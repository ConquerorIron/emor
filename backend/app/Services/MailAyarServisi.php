<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailAyari;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;

/**
 * Giden mail (SMTP) tanımı. Tanım PostgreSQL'de tutulur; gönderimde Laravel'in
 * `uygulama` mailer'ı bu tanımla çalışma zamanında kurulur (MssqlBaglantiServisi
 * kalıbı). Uygulamadan giden TÜM mailler `gonder()` üzerinden çıkar —
 * yönlendirme adresi burada uygulanır.
 */
final class MailAyarServisi
{
    public const MAILER = 'uygulama';

    public function ayar(): ?MailAyari
    {
        return MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->first();
    }

    /**
     * Şifre yalnız gönderildiyse değişir; sunucu/port/kullanıcı değişiyorsa ve
     * kayıtlı şifre varsa şifre yeniden girilmelidir.
     *
     * @param  array{sunucu: string, port: int, sifreleme: string, kullanici_adi?: string|null, sifre?: string|null, gonderen_adres: string, gonderen_ad: string, yonlendirme_adresi?: string|null}  $veri
     */
    public function guncelle(array $veri): MailAyari
    {
        return DB::transaction(function () use ($veri): MailAyari {
            $ayar = MailAyari::query()->where('anahtar', MailAyari::ANAHTAR)->lockForUpdate()->first()
                ?? new MailAyari(['anahtar' => MailAyari::ANAHTAR]);

            $sifre = $veri['sifre'] ?? null;
            $sifreBos = $sifre === null || $sifre === '';
            $kullaniciAdi = ($veri['kullanici_adi'] ?? '') === '' ? null : $veri['kullanici_adi'];
            // `integer` kuralı "587" metnini de geçirir; strict_types altında int gerekir
            $port = (int) $veri['port'];

            if ($ayar->exists && $sifreBos && $ayar->sifre !== null
                && $ayar->hedefFarkli($veri['sunucu'], $port, $kullaniciAdi, $veri['sifreleme'])) {
                throw ValidationException::withMessages([
                    'sifre' => __('hata.mail_sifre_hedef_degisti'),
                ]);
            }

            $ayar->fill([
                'sunucu' => $veri['sunucu'],
                'port' => $port,
                'sifreleme' => $veri['sifreleme'],
                'kullanici_adi' => $kullaniciAdi,
                'gonderen_adres' => $veri['gonderen_adres'],
                'gonderen_ad' => $veri['gonderen_ad'],
                'yonlendirme_adresi' => ($veri['yonlendirme_adresi'] ?? '') === '' ? null : $veri['yonlendirme_adresi'],
            ]);

            if (! $sifreBos) {
                $ayar->sifre = $sifre;
            }

            // Kimlik doğrulaması kaldırıldıysa eski şifre saklanmaz
            if ($kullaniciAdi === null) {
                $ayar->sifre = null;
            }

            $ayar->save();

            return $ayar;
        });
    }

    /**
     * Maili tanımdaki SMTP ile gönderir. Yönlendirme adresi doluysa asıl
     * alıcılar (To, CC, BCC) yerine yalnız o adrese To olarak gider; asıl
     * alıcılar başlıklarda belirtilir, CC / BCC'ye hiçbir şey çıkmaz.
     *
     * @param  list<string>  $alicilar  To
     * @param  list<string>  $kopya  CC
     * @param  list<string>  $gizliKopya  BCC
     */
    public function gonder(array $alicilar, Mailable $mail, array $kopya = [], array $gizliKopya = []): void
    {
        $ayar = $this->kullanilabilirAyar();
        $this->uygula($ayar);

        if ($ayar->yonlendirme_adresi !== null) {
            $basliklar = array_filter([
                'X-Emor-Asil-Alicilar' => implode(', ', $alicilar),
                'X-Emor-Asil-Cc' => implode(', ', $kopya),
                'X-Emor-Asil-Bcc' => implode(', ', $gizliKopya),
            ], fn (string $deger): bool => $deger !== '');
            $mail->withSymfonyMessage(function (Email $mesaj) use ($basliklar): void {
                foreach ($basliklar as $ad => $deger) {
                    $mesaj->getHeaders()->addTextHeader($ad, $deger);
                }
            });
            $alicilar = [$ayar->yonlendirme_adresi];
            $kopya = [];
            $gizliKopya = [];
        }

        Mail::mailer(self::MAILER)->to($alicilar)->cc($kopya)->bcc($gizliKopya)->send($mail);
    }

    private function kullanilabilirAyar(): MailAyari
    {
        $ayar = $this->ayar();

        if ($ayar === null) {
            throw ValidationException::withMessages([
                'mail' => __('hata.mail_ayari_yok'),
            ]);
        }

        if ($ayar->kullanici_adi !== null && ($ayar->sifre === null || $ayar->sifre === '')) {
            throw ValidationException::withMessages([
                'sifre' => __('hata.mail_sifre_eksik'),
            ]);
        }

        return $ayar;
    }

    private function uygula(MailAyari $ayar): void
    {
        self::mailerKur($ayar);
    }

    /**
     * `uygulama` mailer'ını verilen tanımla kurar. Yedekleme bildirimcisi de
     * kullanır: veritabanına ulaşılamazken ayarın dosya kopyasından
     * kurulmuş (kaydedilmemiş) MailAyari nesnesiyle (README §4.8, BY-012).
     */
    public static function mailerKur(MailAyari $ayar): void
    {
        Config::set('mail.mailers.'.self::MAILER, [
            'transport' => 'smtp',
            'scheme' => $ayar->sifreleme === MailAyari::SIFRELEME_SSL ? 'smtps' : 'smtp',
            'host' => $ayar->sunucu,
            'port' => $ayar->port,
            'username' => $ayar->kullanici_adi,
            'password' => $ayar->sifre,
            // STARTTLS seçiliyse şifrelemesiz bağlantıya düşülmez
            'require_tls' => $ayar->sifreleme === MailAyari::SIFRELEME_TLS,
            'auto_tls' => $ayar->sifreleme !== MailAyari::SIFRELEME_YOK,
            'timeout' => 15,
            'from' => ['address' => $ayar->gonderen_adres, 'name' => $ayar->gonderen_ad],
        ]);

        // Önceki istekte/işte kurulmuş mailer eski tanımı taşımasın
        Mail::purge(self::MAILER);
    }
}
