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

    public function testAvailabilityRuleRoundTripChangesTheGrid(): void
    {
        $client = $this->adminClient();

        try {
            self::assertCount(5, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days']);

            $client->request('POST', '/admin/availability', [
                'weekday' => 6,
                'start_time' => '10:00',
                'end_time' => '12:00',
                '_token' => $this->csrfToken($client),
            ]);
            self::assertResponseRedirects();
            self::assertCount(6, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days'], 'Saturday joined the grid');

            $rule = $this->em()->getRepository(AvailabilityRule::class)->findOneBy(['weekday' => 6]);
            $client->request('POST', '/admin/availability/'.$rule->getId().'/delete', ['_token' => $this->csrfToken($client)]);
            $this->em()->clear();
            self::assertCount(5, $this->slots()->buildWeekGrid($this->lastHorizonMonday())['days']);
        } finally {
            // Never leave a stray Saturday rule behind for the other tests.
            $this->em()->createQuery('DELETE FROM '.AvailabilityRule::class.' r WHERE r.weekday = 6')->execute();
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
        self::assertEmailCount(1);
        self::assertStringContainsString('verschoben', self::getMailerMessage()->getSubject());

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
        return $this->slots()->now()->modify('monday this week')
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
