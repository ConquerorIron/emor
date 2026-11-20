<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Yedekleme bildirimi (README §4.8; BY-012). Çalıştırıcı DOĞRUDAN gönderir
 * (kuyruğa koymaz): kuyruk tablosu veritabanındadır, geri yüklemede ya da
 * kuyruk dururken mail kaybolmasın. İçerik sır taşımaz: dosya adı, hata
 * kodu, kısa açıklama.
 */
final class YedeklemeMaili extends Mailable
{
    public string $baglanti;

    /**
     * @param  list<string>  $satirlar
     */
    public function __construct(
        public string $baslik,
        public array $satirlar,
    ) {
        $this->baglanti = rtrim((string) config('app.url'), '/').'/ayarlar/yedekler';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('yedekleme.mail.konu_on_eki').' — '.$this->baslik);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.yedekleme');
    }
}
