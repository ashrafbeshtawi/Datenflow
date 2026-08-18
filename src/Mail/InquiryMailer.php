<?php

namespace App\Mail;

use App\Entity\Inquiry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class InquiryMailer
{
    private const SUBJECTS = [
        'booking' => '[Datenflow] Terminanfrage',
        'contact' => '[Datenflow] Kontaktanfrage',
        'karriere' => '[Datenflow] Bewerbung',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'MAIL_FROM')] private readonly string $from,
        #[Autowire(env: 'MAIL_TO_CONTACT')] private readonly string $toContact,
        #[Autowire(env: 'MAIL_TO_KARRIERE')] private readonly string $toKarriere,
    ) {
    }

    /**
     * @param array{path: string, name: string}|null $attachment
     */
    public function send(Inquiry $inquiry, ?array $attachment = null): void
    {
        $lines = [
            'Name:    '.$inquiry->getName(),
            'E-Mail:  '.$inquiry->getEmail(),
        ];
        foreach ($inquiry->getPayload() as $key => $value) {
            $lines[] = str_pad(ucfirst(str_replace('_', ' ', $key)).':', 8).' '.$value;
        }
        $lines[] = '';
        $lines[] = 'Nachricht:';
        $lines[] = $inquiry->getMessage();

        $email = (new Email())
            ->from(Address::create($this->from))
            ->to($inquiry->getType() === 'karriere' ? $this->toKarriere : $this->toContact)
            ->replyTo(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->subject(self::SUBJECTS[$inquiry->getType()].' von '.$inquiry->getName())
            ->text(implode("\n", $lines));

        if ($attachment !== null) {
            $email->attachFromPath($attachment['path'], $attachment['name']);
        }

        $this->mailer->send($email);
    }
}
