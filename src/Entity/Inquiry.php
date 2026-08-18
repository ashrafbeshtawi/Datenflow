<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every form submission (booking, contact, karriere) is persisted before the
 * notification mail goes out, so a lead is never lost to an SMTP hiccup.
 */
#[ORM\Entity]
class Inquiry
{
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

    public function __construct(string $type, string $name, string $email, string $message, array $payload = [])
    {
        $this->type = $type;
        $this->name = $name;
        $this->email = $email;
        $this->message = $message;
        $this->payload = array_filter($payload, fn ($v) => $v !== '' && $v !== null);
        $this->createdAt = new \DateTimeImmutable();
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
}
