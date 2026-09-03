<?php

namespace App\Tests\Functional;

use App\Entity\Inquiry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class InquiryFormsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testBookingSubmissionPersistsAndMails(): void
    {
        $email = uniqid('booking-').'@example.com';
        [, $slot] = $this->openBookingWeek();

        $this->client->submitForm('Termin buchen', [
            'name' => 'Maria Muster',
            'company' => 'Muster Logistik GmbH',
            'email' => $email,
            'starts_at' => $slot,
            'call_type' => 'video',
            'message' => 'Wir schreiben alle Lieferscheine noch von Hand.',
        ]);

        // Internal notification + client confirmation.
        self::assertEmailCount(2);
        [$internal, $confirmation] = self::getMailerMessages();
        self::assertStringContainsString('Terminbuchung', $internal->getSubject());
        self::assertStringContainsString('Terminbestätigung', $confirmation->getSubject());
        self::assertStringContainsString('meet.google.com', $confirmation->getTextBody());

        self::assertResponseRedirects('/termin?sent=1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-success', 'Termin gebucht');

        $inquiry = $this->findInquiry($email);
        self::assertSame('booking', $inquiry->getType());
        self::assertSame($slot, $inquiry->getStartsAt()->format('Y-m-d H:i'));
        self::assertSame('video', $inquiry->getCallType());
        self::assertSame(Inquiry::STATUS_CONFIRMED, $inquiry->getStatus());
    }

    public function testBookedSlotRendersDisabledAndCannotBeRebooked(): void
    {
        [$crawler, $slot] = $this->openBookingWeek();
        $this->bookDirectly($slot);

        // The slot is still rendered, but no longer selectable.
        $crawler2 = $this->client->request('GET', '/termin?week='.$this->futureWeek());
        self::assertSame(0, $crawler2->filter(sprintf('input[name="starts_at"][value="%s"]', $slot))->count());
        self::assertGreaterThan(0, $crawler2->filter('.slot-gone')->count());

        // Forcing the taken slot through anyway is rejected.
        $this->client->request('POST', '/termin', [
            'name' => 'Zu Spät',
            'email' => 'spaet@example.com',
            'starts_at' => $slot,
            'call_type' => 'video',
            'message' => '',
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'nicht mehr verfügbar');
        self::assertEmailCount(0);
    }

    public function testPhoneCallRequiresPhoneNumber(): void
    {
        [, $slot] = $this->openBookingWeek();

        $this->client->submitForm('Termin buchen', [
            'name' => 'Maria Muster',
            'email' => 'maria@example.com',
            'starts_at' => $slot,
            'call_type' => 'phone',
            'phone' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'Telefonnummer');
        self::assertSelectorExists('input[name="phone"][aria-invalid="true"]');
        self::assertEmailCount(0);
    }

    public function testBookingWithoutSlotIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/termin');
        $this->client->request('POST', '/termin', [
            'name' => 'Maria Muster',
            'email' => 'maria@example.com',
            'call_type' => 'video',
            'message' => 'Hallo',
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error-summary');
        // Old values survive the round trip.
        self::assertSelectorExists('input[name="name"][value="Maria Muster"]');
        self::assertEmailCount(0);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/termin');
        $this->client->request('POST', '/termin', [
            'name' => 'Maria Muster',
            'email' => 'keine-email',
            'message' => 'Hallo',
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="email"][aria-invalid="true"]');
        self::assertEmailCount(0);
    }

    public function testHoneypotGetsFakeSuccessWithoutSideEffects(): void
    {
        $this->client->request('GET', '/termin');
        $this->client->submitForm('Termin buchen', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'spam',
            '_hp' => 'i am a bot',
        ]);

        self::assertResponseRedirects('/termin?sent=1');
        self::assertEmailCount(0);
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/termin', [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'message' => 'Hallo',
            '_token' => 'forged',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmailCount(0);
    }

    public function testKarriereSubmissionAttachesCv(): void
    {
        $email = uniqid('karriere-').'@example.com';

        // BrowserKit derives the client filename from the basename, so name it properly.
        $cvPath = sys_get_temp_dir().'/lebenslauf.pdf';
        file_put_contents($cvPath, "%PDF-1.4\n%fake minimal pdf\n");

        $this->client->request('GET', '/karriere');
        $this->client->submitForm('Bewerbung senden', [
            'name' => 'Devi Developer',
            'email' => $email,
            'message' => 'Ich baue gern Dinge, die funktionieren.',
            'cv' => new UploadedFile($cvPath, 'lebenslauf.pdf', 'application/pdf', test: true),
        ]);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertCount(1, $mail->getAttachments());

        self::assertResponseRedirects('/karriere?sent=1');
        self::assertSame('lebenslauf.pdf', $this->findInquiry($email)->getPayload()['cv_datei']);
    }

    public function testKarriereRejectsWrongCvType(): void
    {
        $cvPath = tempnam(sys_get_temp_dir(), 'cv').'.txt';
        file_put_contents($cvPath, 'plain text, not a cv format we accept');

        $this->client->request('GET', '/karriere');
        $this->client->submitForm('Bewerbung senden', [
            'name' => 'Devi Developer',
            'email' => 'devi@example.com',
            'message' => 'Hallo',
            'cv' => new UploadedFile($cvPath, 'lebenslauf.txt', 'text/plain', test: true),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error-summary', 'PDF');
        self::assertEmailCount(0);
    }

    /** Monday of a week comfortably past the 24h lead time and inside the 4-week horizon. */
    private function futureWeek(): string
    {
        return (new \DateTimeImmutable('monday next week', new \DateTimeZone('Europe/Berlin')))
            ->modify('+1 week')->format('Y-m-d');
    }

    /**
     * Opens the booking page on a future week and picks a random free slot.
     * Random because the test DB is not reset between runs — earlier runs'
     * bookings stay taken until the horizon rolls past them.
     *
     * @return array{0: Crawler, 1: string}
     */
    private function openBookingWeek(): array
    {
        $crawler = $this->client->request('GET', '/termin?week='.$this->futureWeek());
        $radios = $crawler->filter('input[name="starts_at"]');
        self::assertGreaterThan(0, $radios->count(), 'No free slots rendered');

        return [$crawler, $radios->eq(random_int(0, $radios->count() - 1))->attr('value')];
    }

    private function bookDirectly(string $slot): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Inquiry(
            'booking',
            'Erste Bucherin',
            uniqid('first-').'@example.com',
            '',
            [],
            new \DateTimeImmutable($slot, new \DateTimeZone('Europe/Berlin')),
            'video',
        ));
        $em->flush();
    }

    private function findInquiry(string $email): Inquiry
    {
        $inquiry = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Inquiry::class)
            ->findOneBy(['email' => $email]);

        self::assertNotNull($inquiry, 'Inquiry was not persisted');

        return $inquiry;
    }
}
