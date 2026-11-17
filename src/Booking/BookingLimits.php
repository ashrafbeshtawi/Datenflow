<?php

namespace App\Booking;

use App\Entity\Inquiry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Bookings are confirmed on submit (no email round trip), so these caps keep
 * one visitor from filling the calendar: at most MAX_PER_EMAIL open future
 * bookings per email address, and the booking_submit limiter per client IP.
 *
 * Only stored bookings use up the IP quota: check() peeks, record() spends,
 * so a validation error or a lost slot race costs nothing.
 */
class BookingLimits
{
    public const MAX_PER_EMAIL = 2;

    /** Values double as t.form_errors keys for the error page. */
    public const EMAIL = 'booking_limit_email';
    public const IP = 'booking_limit_ip';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $bookingSubmitLimiter,
    ) {
    }

    /** @return string|null one of the class constants, or null to proceed */
    public function check(Request $request, string $email): ?string
    {
        if ($this->countOpenBookings($email) >= self::MAX_PER_EMAIL) {
            return self::EMAIL;
        }

        if ($this->limiter($request)->consume(0)->getRemainingTokens() < 1) {
            return self::IP;
        }

        return null;
    }

    /** Call once the booking is stored. */
    public function record(Request $request): void
    {
        $this->limiter($request)->consume();
    }

    /** Confirmed bookings that have not started yet, email compared case-insensitively. */
    private function countOpenBookings(string $email): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(i.id) FROM '.Inquiry::class.' i
             WHERE i.type = :type AND i.status = :status AND i.startsAt > :now AND LOWER(i.email) = :email'
        )
            ->setParameter('type', Inquiry::TYPE_BOOKING)
            ->setParameter('status', Inquiry::STATUS_CONFIRMED)
            // starts_at is naive Europe/Berlin time.
            ->setParameter('now', new DateTimeImmutable('now', new DateTimeZone(SlotFinder::TZ)))
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getSingleScalarResult();
    }

    private function limiter(Request $request): LimiterInterface
    {
        return $this->bookingSubmitLimiter->create($request->getClientIp() ?? 'unknown');
    }
}
