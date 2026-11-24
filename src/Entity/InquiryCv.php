<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The CV of a karriere application, kept in the database so a failed mail can
 * be resent with it after any deploy. Its own table keeps the blob out of the
 * admin inquiry listing; the row goes away together with the inquiry.
 */
#[ORM\Entity]
class InquiryCv
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Inquiry $inquiry;

    #[ORM\Column(length: 255)]
    private string $fileName;

    /** @var string|resource Doctrine hydrates bytea as a stream */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $content;

    public function __construct(Inquiry $inquiry, string $fileName, string $content)
    {
        $this->inquiry = $inquiry;
        $this->fileName = $fileName;
        $this->content = $content;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getContent(): string
    {
        if (is_resource($this->content)) {
            $this->content = stream_get_contents($this->content);
        }

        return $this->content;
    }
}
