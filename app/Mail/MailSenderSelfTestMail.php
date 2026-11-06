<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** E-mail de autoteste — somente para o próprio remetente configurado. */
class MailSenderSelfTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: 'Teste de remetente SMTP',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Este é um e-mail de teste do remetente configurado. Nenhum convite foi criado.</p>',
        );
    }
}
