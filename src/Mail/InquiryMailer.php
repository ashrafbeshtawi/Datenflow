<?php

namespace App\Mail;

use App\Content\SiteCopy;
use App\Entity\Inquiry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class InquiryMailer
{
    private const SUBJECTS = [
        'booking' => '[Datenflow] Terminbuchung',
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
     * Internal notification for every inquiry; bookings additionally get a
     * confirmation mail to the client (in the language they used the site in).
     *
     * @param array{path: string, name: string}|null $attachment
     */
    public function send(Inquiry $inquiry, ?array $attachment = null, string $lang = 'de', ?string $meetLink = null): void
    {
        $lines = [
            'Name:    '.$inquiry->getName(),
            'E-Mail:  '.$inquiry->getEmail(),
        ];
        if ($inquiry->getStartsAt() !== null) {
            $lines[] = 'Termin:  '.$inquiry->getStartsAt()->format('d.m.Y H:i');
            $lines[] = 'Art:     '.($inquiry->getCallType() === 'video' ? 'Video (Google Meet)' : 'Telefon');
        }
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

        if ($inquiry->getType() === 'booking' && $inquiry->getStartsAt() !== null) {
            $this->mailer->send($this->confirmation($inquiry, $lang, $meetLink));
        }
    }

    private function confirmation(Inquiry $inquiry, string $lang, ?string $meetLink): Email
    {
        $t = SiteCopy::for($lang)['booking'];
        $at = $inquiry->getStartsAt();

        $when = strtr($t['mail']['when'], [
            '{weekday}' => $t['slot']['weekdays'][(int) $at->format('N') - 1],
            '{date}' => $at->format('d.m.Y'),
            '{time}' => $at->format('H:i'),
        ]);
        $extra = $inquiry->getCallType() === 'video'
            ? strtr($t['mail']['extra_video'], ['{meet}' => (string) $meetLink])
            : strtr($t['mail']['extra_phone'], ['{phone}' => $inquiry->getPayload()['phone'] ?? '']);

        $body = strtr($t['mail']['body'], [
            '{name}' => $inquiry->getName(),
            '{when}' => $when,
            '{type}' => $t['f']['call_opts'][$inquiry->getCallType()],
            '{extra}' => $extra,
        ]);

        return (new Email())
            ->from(Address::create($this->from))
            ->to(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->replyTo($this->toContact)
            ->subject($t['mail']['subject'])
            ->text($body);
    }
}
