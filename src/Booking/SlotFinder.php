<?php

namespace App\Booking;

use App\Entity\AvailabilityRule;
use App\Entity\Inquiry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates bookable 30-minute slots from the weekly AvailabilityRules and
 * marks slots taken by confirmed bookings/blocks. All times are naive
 * Europe/Berlin — single-timezone business, stored as-is.
 */
class SlotFinder
{
    public const SLOT_MINUTES = 30;
    public const LEAD_HOURS = 24;
    public const HORIZON_WEEKS = 4;
    public const TZ = 'Europe/Berlin';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Builds the week grid for the booking page. $weekParam is any 'Y-m-d' inside
     * the wanted week; out-of-range or invalid values clamp to the booking window.
     *
     * @return array{weekStart: string, days: DateTimeImmutable[], times: string[], slots: array<string, string>, prev: ?string, next: ?string}
     */
    public function buildWeekGrid(?string $weekParam): array
    {
        $now = $this->now();
        $firstMonday = $now->modify('monday this week')->setTime(0, 0);
        $lastMonday = $firstMonday->modify('+'.(self::HORIZON_WEEKS - 1).' weeks');

        if ($weekParam !== null && ($parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $weekParam, new DateTimeZone(self::TZ))) !== false) {
            // Clamp the requested week into [current week, last week of the horizon].
            return $this->assembleWeek(min(max($parsed->modify('monday this week'), $firstMonday), $lastMonday), $firstMonday, $lastMonday, $now);
        }

        // No explicit week: land on the first week that still has a free slot,
        // so visitors never open the page on a fully booked (or past) week.
        $grid = $this->assembleWeek($firstMonday, $firstMonday, $lastMonday, $now);
        while (!in_array('free', $grid['slots'], true) && $grid['next'] !== null) {
            $grid = $this->assembleWeek(new DateTimeImmutable($grid['next'], new DateTimeZone(self::TZ)), $firstMonday, $lastMonday, $now);
        }

        return $grid;
    }

    /** @return array{weekStart: string, days: DateTimeImmutable[], times: string[], slots: array<string, string>, prev: ?string, next: ?string} */
    private function assembleWeek(DateTimeImmutable $monday, DateTimeImmutable $firstMonday, DateTimeImmutable $lastMonday, DateTimeImmutable $now): array
    {
        $rulesByDay = $this->rulesByWeekday();
        $taken = $this->takenSlots($monday, $monday->modify('+7 days'));
        // Slot keys are 'Y-m-d H:i', which sorts chronologically — so a plain
        // string comparison against this cutoff is a time comparison.
        $cutoff = $now->modify('+'.self::LEAD_HOURS.' hours')->format('Y-m-d H:i');

        $days = [];
        $times = []; // used as a set: ksort + array_keys below yield sorted distinct times
        $slots = [];
        foreach ($rulesByDay as $weekday => $rules) {
            $day = $monday->modify('+'.($weekday - 1).' days');
            $days[$weekday] = $day;
            foreach ($rules as $rule) {
                foreach ($this->slotTimes($rule) as $time) {
                    $times[$time] = true;
                    $key = $day->format('Y-m-d').' '.$time;
                    // 'gone' = already booked/blocked, or closer than the lead time.
                    $slots[$key] = isset($taken[$key]) || $key <= $cutoff ? 'gone' : 'free';
                }
            }
        }
        ksort($days);
        ksort($times);

        return [
            'weekStart' => $monday->format('Y-m-d'),
            'days' => array_values($days),
            'times' => array_keys($times),
            'slots' => $slots,
            'prev' => $monday > $firstMonday ? $monday->modify('-7 days')->format('Y-m-d') : null,
            'next' => $monday < $lastMonday ? $monday->modify('+7 days')->format('Y-m-d') : null,
        ];
    }

    /**
     * Is this exact datetime an open slot? The taken-check here is only for a
     * friendly error — the race between two submits is settled by the DB's
     * partial unique index.
     */
    public function isBookable(DateTimeImmutable $at): bool
    {
        $now = $this->now();
        if ($at < $now->modify('+'.self::LEAD_HOURS.' hours')) {
            return false;
        }
        if ($at >= $now->modify('monday this week')->setTime(0, 0)->modify('+'.self::HORIZON_WEEKS.' weeks')) {
            return false;
        }

        $time = $at->format('H:i');
        $onGrid = false;
        foreach ($this->rulesByWeekday()[(int) $at->format('N')] ?? [] as $rule) {
            if (in_array($time, $this->slotTimes($rule), true)) {
                $onGrid = true;
                break;
            }
        }
        if (!$onGrid) {
            return false;
        }

        return $this->takenSlots($at, $at->modify('+1 minute')) === [];
    }

    /** @return array<int, AvailabilityRule[]> keyed by ISO weekday */
    private function rulesByWeekday(): array
    {
        $byDay = [];
        foreach ($this->em->getRepository(AvailabilityRule::class)->findAll() as $rule) {
            $byDay[$rule->getWeekday()][] = $rule;
        }

        return $byDay;
    }

    /** @return string[] 'H:i' slot start times for one rule */
    private function slotTimes(AvailabilityRule $rule): array
    {
        $times = [];
        for ($t = $rule->getStartTime(); $t < $rule->getEndTime(); $t = $t->modify('+'.self::SLOT_MINUTES.' minutes')) {
            $times[] = $t->format('H:i');
        }

        return $times;
    }

    /** @return array<string, true> keyed by 'Y-m-d H:i' */
    private function takenSlots(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->em->createQuery(
            'SELECT i.startsAt FROM '.Inquiry::class.' i
             WHERE i.startsAt >= :from AND i.startsAt < :to AND i.status = :status'
        )->setParameters(['from' => $from, 'to' => $to, 'status' => Inquiry::STATUS_CONFIRMED])
         ->getSingleColumnResult();

        $taken = [];
        foreach ($rows as $at) {
            // Scalar hydration returns 'Y-m-d H:i:s' strings, not datetime objects.
            $taken[substr((string) $at, 0, 16)] = true;
        }

        return $taken;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }
}
