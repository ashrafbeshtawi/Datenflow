<?php

namespace App\Tests\Unit;

use App\Entity\Inquiry;
use App\Mail\InquiryMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class InquiryMailerTest extends TestCase
{
    /** @var Email[] */
    private array $sent = [];
    private InquiryMailer $mailer;

    protected function setUp(): void
    {
        $this->sent = [];
        $transport = self::createStub(MailerInterface::class);
        $transport->method('send')->willReturnCallback(function (Email $email): void {
            $this->sent[] = $email;
        });

        $this->mailer = new InquiryMailer(
            $transport,
            'Datenflow <noreply@test.local>',
            'contact@test.local',
            'jobs@test.local',
        );
    }

    public function testKarriereInquiryGoesToJobsWithAttachment(): void
    {
        $cvPath = tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($cvPath, '%PDF-1.4 fake');

        $inquiry = new Inquiry('karriere', 'Devi Developer', 'devi@example.com', 'Hallo');
        $this->mailer->send($inquiry, ['path' => $cvPath, 'name' => 'lebenslauf.pdf']);

        self::assertCount(1, $this->sent);
        self::assertSame('jobs@test.local', $this->sent[0]->getTo()[0]->getAddress());
        self::assertCount(1, $this->sent[0]->getAttachments());
    }

    public function testVideoBookingSendsConfirmationWithMeetLink(): void
    {
        // 2026-09-07 is a Monday.
        $inquiry = new Inquiry(
            'booking',
            'Maria Muster',
            'maria@example.com',
            '',
            [],
            new \DateTimeImmutable('2026-09-07 10:00'),
            'video',
        );

        $this->mailer->send($inquiry, null, 'de', 'https://meet.google.com/jre-kcoc-swk');

        self::assertCount(2, $this->sent);
        [$internal, $confirmation] = $this->sent;

        self::assertSame('contact@test.local', $internal->getTo()[0]->getAddress());
        self::assertStringContainsString('Termin:  Montag, 07.09.2026, 10:00 Uhr', $internal->getTextBody());
        self::assertStringContainsString('Meet:    https://meet.google.com/jre-kcoc-swk', $internal->getTextBody());

        self::assertSame('maria@example.com', $confirmation->getTo()[0]->getAddress());
        self::assertSame('Ihre Terminbestätigung bei Datenflow', $confirmation->getSubject());
        self::assertStringContainsString('Montag, 07.09.2026, 10:00 Uhr', $confirmation->getTextBody());
        self::assertStringContainsString('Per Video (Google Meet)', $confirmation->getTextBody());
        self::assertStringContainsString('https://meet.google.com/jre-kcoc-swk', $confirmation->getTextBody());

        // Both mails carry the calendar invite; 10:00 Berlin summer time = 08:00 UTC.
        foreach ($this->sent as $mail) {
            $ics = $mail->getAttachments()[0]->getBody();
            self::assertStringContainsString('METHOD:REQUEST', $ics);
            self::assertStringContainsString('DTSTART:20260907T080000Z', $ics);
            self::assertStringContainsString('DTEND:20260907T083000Z', $ics);
            self::assertStringContainsString('URL:https://meet.google.com/jre-kcoc-swk', $ics);
            self::assertStringContainsString('ATTENDEE;RSVP=TRUE:mailto:maria@example.com', $ics);
        }
    }

    public function testRescheduleMailsClientAndInternalWithInviteUpdate(): void
    {
        $inquiry = new Inquiry(
            'booking',
            'Maria Muster',
            'maria@example.com',
            '',
            [],
            new \DateTimeImmutable('2026-09-07 10:00'),
            'video',
        );

        $this->mailer->sendReschedule($inquiry, 'https://meet.google.com/jre-kcoc-swk');

        self::assertCount(2, $this->sent);
        [$client, $internal] = $this->sent;
        self::assertSame('maria@example.com', $client->getTo()[0]->getAddress());
        self::assertSame('Ihr Termin wurde verschoben / Your appointment was moved', $client->getSubject());
        self::assertSame('contact@test.local', $internal->getTo()[0]->getAddress());
        self::assertStringContainsString('Montag, 07.09.2026, 10:00 Uhr', $internal->getTextBody());

        foreach ($this->sent as $mail) {
            $ics = $mail->getAttachments()[0]->getBody();
            self::assertStringContainsString('METHOD:REQUEST', $ics);
            // A moved event needs a SEQUENCE above the original 0.
            self::assertMatchesRegularExpression('/SEQUENCE:[1-9]\d*/', $ics);
        }
    }

    public function testPhoneBookingConfirmationIsLocalizedAndNamesTheNumber(): void
    {
        $inquiry = new Inquiry(
            'booking',
            'Maria Muster',
            'maria@example.com',
            '',
            ['phone' => '030 1234567'],
            new \DateTimeImmutable('2026-09-08 14:30'),
            'phone',
        );

        $this->mailer->send($inquiry, null, 'en');

        self::assertCount(2, $this->sent);
        $confirmation = $this->sent[1];
        self::assertSame('Your booking confirmation at Datenflow', $confirmation->getSubject());
        self::assertStringContainsString('Tuesday, 08.09.2026, 14:30', $confirmation->getTextBody());
        self::assertStringContainsString('By phone', $confirmation->getTextBody());
        self::assertStringContainsString('We will call you at: 030 1234567', $confirmation->getTextBody());
    }
}
