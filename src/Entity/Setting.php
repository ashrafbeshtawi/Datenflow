<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Key-value app settings editable from the admin panel (e.g. the Google Meet link). */
#[ORM\Entity]
class Setting
{
    public const MEET_LINK = 'meet_link';

    #[ORM\Id]
    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
