<?php

namespace App\Mail;

use App\Entity\Inquiry;
use App\Entity\InquiryCv;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place that sends an already saved inquiry's mails (form submit and
 * admin resend). It never throws: a failure is logged and flagged on the row,
 * the visitor's booking or application stands either way.
 *
 * Built so a queue can take over later: a Messenger handler would load the
 * inquiry by id and call notify().
 */
class InquiryNotifier
{
    public function __construct(
        private readonly InquiryMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return bool whether every mail went out */
    public function notify(Inquiry $inquiry, string $lang = 'de'): bool
    {
        try {
            $meetLink = $inquiry->getCallType() === 'video'
                ? $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue()
                : null;
            $this->mailer->send($inquiry, $this->em->find(InquiryCv::class, $inquiry->getId()), $lang, $meetLink);
            $inquiry->setMailSent(true);
        } catch (Throwable $e) {
            $this->logger->error('Inquiry mail failed', ['type' => $inquiry->getType(), 'inquiry' => $inquiry->getId(), 'error' => $e->getMessage()]);
            $inquiry->setMailSent(false);
        }
        $this->em->flush();

        return $inquiry->isMailSent();
    }
}
