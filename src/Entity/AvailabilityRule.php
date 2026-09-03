<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Weekly working hours: "on this weekday we take calls from X to Y".
 * At most one rule per weekday (no rule = closed day); the unique index enforces it.
 * Slots are generated from these rules; one-off blocks are Inquiry rows of type 'block'.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_availability_weekday', columns: ['weekday'])]
class AvailabilityRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO weekday: 1 = Monday … 7 = Sunday */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $weekday;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endTime;

    public function __construct(int $weekday, \DateTimeImmutable $startTime, \DateTimeImmutable $endTime)
    {
        $this->weekday = $weekday;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function getStartTime(): \DateTimeImmutable
    {
        return $this->startTime;
    }

    public function getEndTime(): \DateTimeImmutable
    {
        return $this->endTime;
    }
}
