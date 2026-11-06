<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $inviteeName,
        public string $acceptUrl,
        public CarbonInterface $expiresAt,
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: 'Convite para acessar '.config('app.name', 'o sistema'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
        );
    }

    private function htmlBody(): string
    {
        $name = e($this->inviteeName);
        $url = e($this->acceptUrl);
        $exp = e($this->expiresAt->timezone('America/Fortaleza')->format('d/m/Y H:i'));

        return <<<HTML
        <p>Olá, {$name}.</p>
        <p>Você recebeu um convite para criar sua conta. Use o link abaixo (válido até {$exp}, horário de Fortaleza):</p>
        <p><a href="{$url}">{$url}</a></p>
        <p>Se você não esperava este e-mail, ignore-o.</p>
        HTML;
    }
}
