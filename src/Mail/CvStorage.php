<?php

namespace App\Mail;

use App\Entity\Inquiry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps karriere CVs at var/cv/<inquiry id>, so a failed application mail can
 * be resent with the CV attached. The original file name lives in the payload
 * (cv_datei); the file goes away together with the inquiry.
 */
class CvStorage
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/cv')] private readonly string $dir,
    ) {
    }

    public function store(Inquiry $inquiry, string $sourcePath): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Cannot create CV directory '.$this->dir);
        }
        if (!copy($sourcePath, $this->path($inquiry->getId()))) {
            throw new \RuntimeException('Cannot store CV for inquiry '.$inquiry->getId());
        }
    }

    /** @return array{path: string, name: string}|null */
    public function attachment(Inquiry $inquiry): ?array
    {
        $path = $this->path((int) $inquiry->getId());
        if ($inquiry->getId() === null || !is_file($path)) {
            return null;
        }

        return ['path' => $path, 'name' => $inquiry->getPayload()['cv_datei'] ?? 'lebenslauf'];
    }

    public function delete(int $inquiryId): void
    {
        if (is_file($this->path($inquiryId))) {
            unlink($this->path($inquiryId));
        }
    }

    public function path(int $inquiryId): string
    {
        return $this->dir.'/'.$inquiryId;
    }
}
