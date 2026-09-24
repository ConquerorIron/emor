<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * SMTP tanımlama ekranındaki "Test maili gönder" düğmesinin maili.
 * Senkron gönderilir: kullanıcı SMTP'nin kabul edip etmediğini hemen görmeli.
 */
final class TestMaili extends Mailable
{
    public function __construct(
        public readonly string $gonderenKullanici,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.test_konu'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test');
    }
}
