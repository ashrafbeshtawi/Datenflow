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
    public function testAdminRequiresPassword(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(401);
    }

    public function testDashboardListsInquiries(): void
    {
        $client = $this->adminClient();
        $email = uniqid('admin-list-').'@example.com';
        $this->em()->persist(new Inquiry('contact', 'List Test', $email, 'Hallo'));
        $this->em()->flush();

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin-table', $email);
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

    public function testInquiryCanBeEdited(): void
    {
        $client = $this->adminClient();
        $inquiry = new Inquiry('contact', 'Old Name', uniqid('edit-').'@example.com', 'Alte Nachricht');
        $this->em()->persist($inquiry);
        $this->em()->flush();

        $client->request('POST', '/admin/inquiry/'.$inquiry->getId(), [
            'name' => 'New Name',
            'email' => $inquiry->getEmail(),
            'message' => 'Neue Nachricht',
            '_token' => $this->csrfToken($client),
        ]);

        self::assertResponseRedirects();
        $this->em()->clear();
        $fresh = $this->em()->find(Inquiry::class, $inquiry->getId());
        self::assertSame('New Name', $fresh->getName());
        self::assertSame('Neue Nachricht', $fresh->getMessage());
    }

    private function adminClient(): KernelBrowser
    {
        return static::createClient(server: ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'test-admin']);
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
