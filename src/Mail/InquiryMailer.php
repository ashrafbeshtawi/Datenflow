<?php

namespace App\Mail;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use App\Entity\Inquiry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class InquiryMailer
{
    private const SUBJECTS = [
        'booking' => '[Datenflow] Terminbuchung',
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
            $lines[] = 'Termin:  '.$this->formatWhen($inquiry, 'de');
            $lines[] = 'Art:     '.($inquiry->getCallType() === 'video' ? 'Video (Google Meet)' : 'Telefon');
            if ($inquiry->getCallType() === 'video') {
                $lines[] = 'Meet:    '.($meetLink ?? '');
            }
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

        $isBooking = $inquiry->getType() === Inquiry::TYPE_BOOKING && $inquiry->getStartsAt() !== null;
        $invite = $isBooking ? $this->invite($inquiry, $meetLink) : null;
        if ($invite !== null) {
            $email->addPart($invite);
        }

        $this->mailer->send($email);

        if ($isBooking) {
            $this->mailer->send($this->confirmation($inquiry, $lang, $meetLink)->addPart($invite));
        }
    }

    /**
     * Cancellation notice for an admin-cancelled booking. The visitor's language
     * is not stored, so the client mail is bilingual (DE first, then EN).
     */
    public function sendCancellation(Inquiry $inquiry): void
    {
        $parts = [];
        $subjects = [];
        foreach (['de', 'en'] as $lang) {
            $t = SiteCopy::for($lang)['booking'];
            $subjects[] = $t['mail']['cancel_subject'];
            $parts[] = strtr($t['mail']['cancel_body'], [
                '{name}' => $inquiry->getName(),
                '{when}' => $this->formatWhen($inquiry, $lang),
            ]);
        }

        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->replyTo($this->toContact)
            ->subject(implode(' / ', $subjects))
            ->text(implode("\n\n----\n\n", $parts)));

        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to($this->toContact)
            ->subject('[Datenflow] Termin storniert: '.$inquiry->getName())
            ->text('Der Termin am '.$inquiry->getStartsAt()->format('d.m.Y H:i').' mit '.$inquiry->getName().' ('.$inquiry->getEmail().') wurde storniert. Der Slot ist wieder frei.'));
    }

    /**
     * New confirmation for an admin-moved booking. Like the cancellation, the
     * visitor's language is not stored, so the mail is bilingual (DE, then EN).
     */
    public function sendReschedule(Inquiry $inquiry, ?string $meetLink): void
    {
        $parts = [];
        $subjects = [];
        foreach (['de', 'en'] as $lang) {
            $t = SiteCopy::for($lang)['booking'];
            $subjects[] = $t['mail']['reschedule_subject'];
            $parts[] = strtr($t['mail']['reschedule_body'], [
                '{name}' => $inquiry->getName(),
                '{when}' => $this->formatWhen($inquiry, $lang),
                '{type}' => $t['f']['call_opts'][$inquiry->getCallType()],
                '{extra}' => $this->extraLine($inquiry, $t, $meetLink),
            ]);
        }

        // ponytail: SEQUENCE from the wall clock — monotonic across reschedules
        // without persisting a counter; upgrade to a stored counter if it ever matters.
        $invite = $this->invite($inquiry, $meetLink, time());

        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->replyTo($this->toContact)
            ->subject(implode(' / ', $subjects))
            ->text(implode("\n\n----\n\n", $parts))
            ->addPart($invite));

        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to($this->toContact)
            ->subject('[Datenflow] Termin verschoben: '.$inquiry->getName())
            ->text('Der Termin mit '.$inquiry->getName().' ('.$inquiry->getEmail().') wurde verschoben.'
                ."\nNeuer Termin: ".$this->formatWhen($inquiry, 'de')
                .($meetLink !== null ? "\nMeet: ".$meetLink : ''))
            ->addPart($invite));
    }

    private function confirmation(Inquiry $inquiry, string $lang, ?string $meetLink): Email
    {
        $t = SiteCopy::for($lang)['booking'];

        $body = strtr($t['mail']['body'], [
            '{name}' => $inquiry->getName(),
            '{when}' => $this->formatWhen($inquiry, $lang),
            '{type}' => $t['f']['call_opts'][$inquiry->getCallType()],
            '{extra}' => $this->extraLine($inquiry, $t, $meetLink),
        ]);

        return (new Email())
            ->from(Address::create($this->from))
            ->to(new Address($inquiry->getEmail(), $inquiry->getName()))
            ->replyTo($this->toContact)
            ->subject($t['mail']['subject'])
            ->text($body);
    }

    /**
     * iCalendar invite (METHOD:REQUEST) so Gmail & Co. render the appointment
     * as a real event. The UID is stable per booking; a higher SEQUENCE makes
     * mail clients move the existing event instead of creating a second one.
     */
    private function invite(Inquiry $inquiry, ?string $meetLink, int $sequence = 0): DataPart
    {
        $utc = new \DateTimeZone('UTC');
        // startsAt is naive Europe/Berlin wall-clock time; pin the zone before converting.
        $start = new \DateTimeImmutable($inquiry->getStartsAt()->format('Y-m-d H:i'), new \DateTimeZone(SlotFinder::TZ));
        $stamp = fn (\DateTimeImmutable $d) => $d->setTimezone($utc)->format('Ymd\THis\Z');
        $esc = fn (string $v) => addcslashes($v, ',;\\');

        $ics = implode("\r\n", array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Datenflow//Booking//DE',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:booking-'.($inquiry->getId() ?? 0).'@datenflow.de',
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.$stamp(new \DateTimeImmutable()),
            'DTSTART:'.$stamp($start),
            'DTEND:'.$stamp($start->modify('+'.SlotFinder::SLOT_MINUTES.' minutes')),
            'SUMMARY:'.$esc('Erstgespräch Datenflow: '.$inquiry->getName()),
            'LOCATION:'.$esc($meetLink ?? 'Telefon'),
            $meetLink !== null ? 'URL:'.$meetLink : null,
            'ORGANIZER;CN=Datenflow:mailto:'.$this->toContact,
            'ATTENDEE;RSVP=TRUE:mailto:'.$inquiry->getEmail(),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ]));

        $part = new DataPart($ics, 'einladung.ics', 'text/calendar');
        $part->getHeaders()->addParameterizedHeader('Content-Type', 'text/calendar', ['method' => 'REQUEST', 'charset' => 'utf-8']);

        return $part;
    }

    /** Meet link for video calls, the client's phone number otherwise. */
    private function extraLine(Inquiry $inquiry, array $t, ?string $meetLink): string
    {
        return $inquiry->getCallType() === 'video'
            ? strtr($t['mail']['extra_video'], ['{meet}' => (string) $meetLink])
            : strtr($t['mail']['extra_phone'], ['{phone}' => $inquiry->getPayload()['phone'] ?? '']);
    }

    /** "Montag, 07.09.2026, 10:00 Uhr" resp. "Monday, 07.09.2026, 10:00". */
    private function formatWhen(Inquiry $inquiry, string $lang): string
    {
        $t = SiteCopy::for($lang)['booking'];
        $at = $inquiry->getStartsAt();

        return strtr($t['mail']['when'], [
            '{weekday}' => $t['slot']['weekdays'][(int) $at->format('N') - 1],
            '{date}' => $at->format('d.m.Y'),
            '{time}' => $at->format('H:i'),
        ]);
    }
}
