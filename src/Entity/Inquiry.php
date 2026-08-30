<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every form submission (booking, contact, karriere) is persisted before the
 * notification mail goes out, so a lead is never lost to an SMTP hiccup.
 *
 * Bookings carry a starts_at slot; the partial unique index makes double
 * booking impossible at the DB level (cancelled rows free the slot again).
 */
#[ORM\Entity]
#[ORM\Table(name: 'inquiry')]
#[ORM\UniqueConstraint(name: 'uniq_inquiry_slot', columns: ['starts_at'], options: ['where' => "((status)::text = 'confirmed'::text)"])]
class Inquiry
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** @var array<string, string> extra fields (company, phone, preferred date …) */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Booked slot (naive Europe/Berlin time), only set for type=booking/block. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    /** 'video' or 'phone', only set for type=booking. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $callType;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_CONFIRMED])]
    private string $status = self::STATUS_CONFIRMED;

    public function __construct(
        string $type,
        string $name,
        string $email,
        string $message,
        array $payload = [],
        ?\DateTimeImmutable $startsAt = null,
        ?string $callType = null,
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->payload = array_filter($payload, fn ($v) => $v !== '' && $v !== null);
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt = $startsAt;
        $this->callType = $callType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getCallType(): ?string
    {
        return $this->callType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** Cancelling frees the slot: the partial unique index only covers confirmed rows. */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }
}
