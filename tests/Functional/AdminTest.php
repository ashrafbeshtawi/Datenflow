<?php

namespace App\Tests\Functional;

use App\Booking\SlotFinder;
use App\Entity\AvailabilityRule;
use App\Entity\Inquiry;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminTest extends WebTestCase
{
    public function testAdminRedirectsAnonymousToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    /**
     * The router matches the rawurldecoded path, so the auth listener must
     * compare decoded too — otherwise /%61dmin reaches the dashboard unguarded.
     */
    public function testPercentEncodedAdminPathIsGuardedToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/%61dmin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testLoginRejectsWrongUsernameEvenWithCorrectPassword(): void
    {
        $client = static::createClient();
        $this->login($client, 'wrong-user', 'test-admin');

        self::assertSelectorExists('.form-error-summary');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $client = static::createClient();
        $this->login($client, 'test', 'wrong-password');

        self::assertSelectorExists('.form-error-summary');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = $this->adminClient();

        $client->request('POST', '/admin/logout', ['_token' => $this->csrfToken($client)]);

        self::assertResponseRedirects('/admin/login');
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function testDashboardListsInquiries(): void
    {
        $client = $this->adminClient();
        $email = uniqid('admin-list-').'@example.com';
        $this->em()->persist(new Inquiry('karriere', 'List Test', $email, 'Hallo'));
        $this->em()->flush();

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#admin-messages', $email);
    }

    /**
     * Guards the app-wide convention: all times are naive Europe/Berlin wall-clock.
     * If PHP's default timezone (docker/php timezone.ini) and Twig's date filter
     * disagree, rendered times drift by the UTC offset — this is what it looks like.
     */
    public function testDashboardRendersTheBookedWallClockTime(): void
    {
        self::assertSame('Europe/Berlin', date_default_timezone_get(), 'app convention: naive Europe/Berlin everywhere, set in docker/php');

        $client = $this->adminClient();
        [$at, $inquiry] = $this->bookFreeSlot('video');

        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#admin-upcoming tr')
            ->reduce(fn ($node) => str_contains($node->text(), $inquiry->getEmail()));
        self::assertCount(1, $row, 'booking must appear exactly once');
        self::assertStringContainsString($at->format('d.m.Y'), $row->text());
        self::assertStringContainsString($at->format('H:i'), $row->text(), 'admin must show the slot at its booked wall-clock time');
    }

    public function testCancelBookingMailsBothPartiesAndFreesTheSlot(): void
    {
        $client = $this->adminClient();
        [$at, $inquiry] = $this->bookFreeSlot('video');

        $client->request('POST', '/admin/inquiry/'.$inquiry->getId().'/cancel', ['_token' => $this->csrfToken($client)]);

        // Client cancellation (bilingual) + internal notice.
        self::assertEmailCount(2);
        [$toClient, $internal] = self::getMailerMessages();
        self::assertStringContainsString('abgesagt', $toClient->getSubject());
        self::assertStringContainsString('cancelled', $toClient->getSubject());
        self::assertStringContainsString('storniert', $internal->getSubject());

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertTrue($this->slots()->isBookable($at), 'cancelled slot must be bookable again');
    }

    public function testBlockedSlotIsGoneAndCancellingTheBlockFreesItSilently(): void
    {
        $client = $this->adminClient();
        $grid = $this->slots()->buildWeekGrid($this->lastHorizonMonday());
        $free = array_keys($grid['slots'], 'free', true);
        self::assertNotEmpty($free);
        $key = $free[array_rand($free)];
        $at = new \DateTimeImmutable($key, new \DateTimeZone(SlotFinder::TZ));

        $client->request('POST', '/admin/block', [
            'date' => $at->format('Y-m-d'),
            'time' => $at->format('H:i'),
            '_token' => $this->csrfToken($client),
        ]);

        self::assertResponseRedirects();
        self::assertEmailCount(0);
        self::assertFalse($this->slots()->isBookable($at));

        $block = $this->em()->getRepository(Inquiry::class)->findOneBy(['startsAt' => $at, 'status' => Inquiry::STATUS_CONFIRMED]);
        $client->request('POST', '/admin/inquiry/'.$block->getId().'/cancel', ['_token' => $this->csrfToken($client)]);

        self::assertEmailCount(0, 'freeing a block must not mail anyone');
        $this->em()->clear();
        self::assertTrue($this->slots()->isBookable($at));
    }

    public function testAvailabilityFormRewritesTheWholeWeek(): void
    {
        $client = $this->adminClient();

        try {
            self::assertCount(5, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days']);

            // Open Saturday too; a checked day appears, an unchecked one closes.
            $client->request('POST', '/admin/availability', $this->availabilityParams([1, 2, 3, 4, 5, 6], $client));
            self::assertResponseRedirects();
            $this->em()->clear();
            self::assertCount(6, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days'], 'Saturday joined the grid');
            self::assertCount(6, $this->em()->getRepository(AvailabilityRule::class)->findAll(), 'one rule per open day, never more');

            $client->request('POST', '/admin/availability', $this->availabilityParams([1, 2, 3, 4, 5], $client));
            $this->em()->clear();
            self::assertCount(5, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days']);
        } finally {
            // Restore the seeded Mon-Fri 09-17 week for the other tests.
            $this->em()->createQuery('DELETE FROM '.AvailabilityRule::class.' r')->execute();
            foreach ([1, 2, 3, 4, 5] as $weekday) {
                $this->em()->persist(new AvailabilityRule($weekday, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('17:00')));
            }
            $this->em()->flush();
            $this->em()->clear();
        }
    }

    public function testBlockingAWholeDayTakesEveryFreeSlotSilently(): void
    {
        $client = $this->adminClient();
        $monday = $this->lastHorizonMonday();
        $freeByDay = [];
        foreach (array_keys($this->slots()->buildWeekGrid($monday)['slots'], 'free', true) as $key) {
            $freeByDay[substr($key, 0, 10)][] = $key;
        }
        self::assertNotEmpty($freeByDay);
        $date = array_key_first($freeByDay);

        try {
            $client->request('POST', '/admin/block', ['date' => $date, 'time' => '', '_token' => $this->csrfToken($client)]);

            self::assertResponseRedirects();
            self::assertEmailCount(0);
            $this->em()->clear();
            $after = $this->slots()->buildWeekGrid($monday)['slots'];
            foreach (array_keys($after) as $key) {
                if (str_starts_with($key, $date.' ')) {
                    self::assertSame('gone', $after[$key], $key.' must be blocked');
                }
            }
        } finally {
            // Free the day again so later tests still find open slots.
            $this->em()->createQuery(
                'DELETE FROM '.Inquiry::class." i WHERE i.type = 'block' AND i.startsAt >= :from AND i.startsAt < :to"
            )->setParameters(['from' => $date.' 00:00', 'to' => $date.' 23:59'])->execute();
            $this->em()->clear();
        }
    }

    public function testMeetLinkCanBeUpdated(): void
    {
        $client = $this->adminClient();
        $original = $this->em()->find(Setting::class, Setting::MEET_LINK)->getValue();

        try {
            $client->request('POST', '/admin/settings', [
                'meet_link' => 'https://meet.google.com/xxx-test-xxx',
                '_token' => $this->csrfToken($client),
            ]);

            self::assertResponseRedirects();
            $this->em()->clear();
            self::assertSame('https://meet.google.com/xxx-test-xxx', $this->em()->find(Setting::class, Setting::MEET_LINK)->getValue());
        } finally {
            $setting = $this->em()->find(Setting::class, Setting::MEET_LINK);
            $setting->setValue($original);
            $this->em()->flush();
        }
    }

    public function testMessageCanBeDeletedButAppointmentsCannot(): void
    {
        $client = $this->adminClient();
        $message = new Inquiry('karriere', 'Delete Test', uniqid('delete-').'@example.com', 'Weg damit');
        $this->em()->persist($message);
        $this->em()->flush();
        [, $booking] = $this->bookFreeSlot('video');

        $client->request('POST', '/admin/inquiry/'.$message->getId().'/delete', ['_token' => $this->csrfToken($client)]);
        self::assertResponseRedirects();

        $client->request('POST', '/admin/inquiry/'.$booking->getId().'/delete', ['_token' => $this->csrfToken($client)]);
        self::assertResponseRedirects();

        $this->em()->clear();
        self::assertNull($this->em()->find(Inquiry::class, $message->getId()));
        self::assertNotNull($this->em()->find(Inquiry::class, $booking->getId()), 'appointments must survive delete attempts');
    }

    public function testRescheduleMovesTheBookingAndMailsTheClient(): void
    {
        $client = $this->adminClient();
        [$old, $inquiry] = $this->bookFreeSlot('video');
        $grid = $this->slots()->buildWeekGrid($this->lastHorizonMonday());
        $free = array_values(array_filter(
            array_keys($grid['slots'], 'free', true),
            fn (string $key) => $key !== $old->format('Y-m-d H:i'),
        ));
        self::assertNotEmpty($free);
        $new = new \DateTimeImmutable($free[array_rand($free)], new \DateTimeZone(SlotFinder::TZ));

        $client->request('POST', '/admin/inquiry/'.$inquiry->getId().'/reschedule', [
            'date' => $new->format('Y-m-d'),
            'time' => $new->format('H:i'),
            '_token' => $this->csrfToken($client),
        ]);

        self::assertResponseRedirects();
        // Client notice + internal notice, both carrying the calendar invite.
        self::assertEmailCount(2);
        foreach (self::getMailerMessages() as $mail) {
            self::assertStringContainsString('verschoben', $mail->getSubject());
            self::assertStringContainsString('METHOD:REQUEST', $mail->getAttachments()[0]->getBody());
        }

        $this->em()->clear();
        // startsAt is naive (no TZ persisted), so compare wall-clock time.
        self::assertSame($new->format('Y-m-d H:i'), $this->em()->find(Inquiry::class, $inquiry->getId())->getStartsAt()->format('Y-m-d H:i'));
        self::assertTrue($this->slots()->isBookable($old), 'old slot must be free again');
        self::assertFalse($this->slots()->isBookable($new));
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        $this->login($client, 'test', 'test-admin');
        self::assertResponseRedirects('/admin');

        return $client;
    }

    /** Full availability form: the given weekdays open 10-12, everything else closed. */
    private function availabilityParams(array $openWeekdays, KernelBrowser $client): array
    {
        $params = ['_token' => $this->csrfToken($client)];
        foreach ($openWeekdays as $weekday) {
            $params['open_'.$weekday] = '1';
            $params['start_'.$weekday] = '10:00';
            $params['end_'.$weekday] = '12:00';
        }

        return $params;
    }

    private function login(KernelBrowser $client, string $username, string $password): void
    {
        $token = $client->request('GET', '/admin/login')->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/admin/login', ['username' => $username, 'password' => $password, '_token' => $token]);
    }

    private function csrfToken(KernelBrowser $client): string
    {
        return $client->request('GET', '/admin')->filter('input[name="_token"]')->first()->attr('value');
    }

    /** @return array{0: \DateTimeImmutable, 1: Inquiry} */
    private function bookFreeSlot(string $callType): array
    {
        $grid = $this->slots()->buildWeekGrid($this->lastHorizonMonday());
        $free = array_keys($grid['slots'], 'free', true);
        self::assertNotEmpty($free);
        $at = new \DateTimeImmutable($free[array_rand($free)], new \DateTimeZone(SlotFinder::TZ));

        $inquiry = new Inquiry('booking', 'Cancel Test', uniqid('cancel-').'@example.com', '', [], $at, $callType);
        $this->em()->persist($inquiry);
        $this->em()->flush();

        return [$at, $inquiry];
    }

    private function lastHorizonMonday(): string
    {
        return $this->slots()->getCurrentTime()->modify('monday this week')
            ->modify('+'.(SlotFinder::HORIZON_WEEKS - 1).' weeks')->format('Y-m-d');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function slots(): SlotFinder
    {
        return static::getContainer()->get(SlotFinder::class);
    }
}
