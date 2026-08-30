<?php

namespace App\Tests\Functional;

use App\Booking\SlotFinder;
use App\Entity\Inquiry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the migrated test DB (seed: Mon-Fri 09:00-17:00, 30-min slots).
 */
class SlotFinderTest extends KernelTestCase
{
    private SlotFinder $slots;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->slots = static::getContainer()->get(SlotFinder::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testGridMatchesSeededAvailability(): void
    {
        $grid = $this->slots->buildWeekGrid(null);

        $weekStart = new \DateTimeImmutable($grid['weekStart']);
        self::assertSame('1', $weekStart->format('N'), 'weekStart must be a Monday');
        self::assertSame($this->currentMonday()->format('Y-m-d'), $grid['weekStart']);

        self::assertCount(5, $grid['days'], 'seed covers Mon-Fri');
        self::assertCount(16, $grid['times'], '09:00-17:00 in 30-min steps');
        self::assertSame('09:00', $grid['times'][0]);
        self::assertSame('16:30', $grid['times'][15]);
        self::assertCount(80, $grid['slots']);
        self::assertNull($grid['prev'], 'no navigation into the past');
        self::assertNotNull($grid['next']);
    }

    public function testWeekParamIsClampedToTheBookingWindow(): void
    {
        $lastMonday = $this->currentMonday()->modify('+'.(SlotFinder::HORIZON_WEEKS - 1).' weeks');

        $farFuture = $this->slots->buildWeekGrid('2035-06-11');
        self::assertSame($lastMonday->format('Y-m-d'), $farFuture['weekStart']);
        self::assertNull($farFuture['next'], 'last week of the horizon has no next');

        $past = $this->slots->buildWeekGrid('2020-01-06');
        self::assertSame($this->currentMonday()->format('Y-m-d'), $past['weekStart']);

        $garbage = $this->slots->buildWeekGrid('not-a-date');
        self::assertSame($this->currentMonday()->format('Y-m-d'), $garbage['weekStart']);
    }

    public function testSlotsInsideTheLeadTimeAreGone(): void
    {
        $grid = $this->slots->buildWeekGrid(null);
        $cutoff = $this->slots->now()->modify('+'.SlotFinder::LEAD_HOURS.' hours')->format('Y-m-d H:i');

        $insideLead = array_filter($grid['slots'], fn ($state, $key) => $key <= $cutoff, ARRAY_FILTER_USE_BOTH);
        self::assertNotEmpty($insideLead, 'current week always contains slots inside the lead time');
        self::assertSame(['gone'], array_values(array_unique($insideLead)));
    }

    public function testBookingASlotMakesItGoneAndUnbookable(): void
    {
        // Use the last horizon week so earlier runs' bookings roll out of scope.
        $week = $this->currentMonday()->modify('+'.(SlotFinder::HORIZON_WEEKS - 1).' weeks')->format('Y-m-d');
        $grid = $this->slots->buildWeekGrid($week);

        $free = array_keys($grid['slots'], 'free', true);
        self::assertNotEmpty($free, 'no free slot left in the last horizon week');
        $key = $free[array_rand($free)];
        $at = new \DateTimeImmutable($key, new \DateTimeZone(SlotFinder::TZ));

        self::assertTrue($this->slots->isBookable($at));

        $this->em->persist(new Inquiry('booking', 'Slot Test', uniqid('slot-').'@example.com', '', [], $at, 'video'));
        $this->em->flush();

        self::assertSame('gone', $this->slots->buildWeekGrid($week)['slots'][$key]);
        self::assertFalse($this->slots->isBookable($at));
    }

    public function testOffGridTimesAreNotBookable(): void
    {
        $monday = $this->currentMonday()->modify('+'.(SlotFinder::HORIZON_WEEKS - 1).' weeks');

        self::assertFalse($this->slots->isBookable($monday->setTime(9, 15)), 'not on the 30-min grid');
        self::assertFalse($this->slots->isBookable($monday->setTime(8, 0)), 'before working hours');
        self::assertFalse($this->slots->isBookable($monday->setTime(17, 0)), 'end of working hours is exclusive');
        self::assertFalse($this->slots->isBookable($monday->modify('+5 days')->setTime(10, 0)), 'Saturday has no rule');
        self::assertFalse($this->slots->isBookable($monday->modify('+7 days')->setTime(10, 0)), 'beyond the horizon');
        self::assertFalse($this->slots->isBookable($this->currentMonday()->modify('-7 days')->setTime(10, 0)), 'in the past');
    }

    private function currentMonday(): \DateTimeImmutable
    {
        return $this->slots->now()->modify('monday this week')->setTime(0, 0);
    }
}
