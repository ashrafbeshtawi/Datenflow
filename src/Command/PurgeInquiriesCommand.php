<?php

namespace App\Command;

use App\Booking\SlotFinder;
use App\Entity\Inquiry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps the retention promises of the privacy policy (SiteCopy "datenschutz",
 * sections 4 and 5). Runs nightly from cron inside the prod container
 * (docker/php/purge-inquiries.cron). Stored CVs go with their inquiry through
 * the ON DELETE CASCADE foreign key of inquiry_cv.
 */
#[AsCommand(name: 'app:purge-inquiries', description: 'Deletes inquiries past their retention period (privacy policy).')]
class PurgeInquiriesCommand extends Command
{
    /** Keep in line with the Datenschutz copy in SiteCopy. */
    public const RETENTION = '-6 months';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SlotFinder $slots,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count what would be deleted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->slots->getCurrentTime();
        $cutoff = $now->modify(self::RETENTION);
        $dryRun = (bool) $input->getOption('dry-run');

        $groups = [
            // Contact messages and applications: six months after they came in.
            'messages' => fn (QueryBuilder $qb) => $qb
                ->where('i.startsAt IS NULL AND i.createdAt < :cutoff')
                ->setParameter('cutoff', $cutoff),
            // Bookings: six months after the call took place or was cancelled.
            'bookings' => fn (QueryBuilder $qb) => $qb
                ->where('i.type = :booking AND (i.startsAt < :cutoff OR (i.status = :cancelled AND i.createdAt < :cutoff))')
                ->setParameter('booking', Inquiry::TYPE_BOOKING)
                ->setParameter('cancelled', Inquiry::STATUS_CANCELLED)
                ->setParameter('cutoff', $cutoff),
            // Admin blocks hold no personal data, they only matter while in the future.
            'blocks' => fn (QueryBuilder $qb) => $qb
                ->where('i.type = :block AND (i.startsAt < :now OR i.status = :cancelled)')
                ->setParameter('block', Inquiry::TYPE_BLOCK)
                ->setParameter('cancelled', Inquiry::STATUS_CANCELLED)
                ->setParameter('now', $now),
        ];

        $counts = [];
        foreach ($groups as $label => $filter) {
            $qb = $filter($this->em->createQueryBuilder()->from(Inquiry::class, 'i'));
            $counts[] = $label.': '.($dryRun
                ? $qb->select('COUNT(i.id)')->getQuery()->getSingleScalarResult()
                : $qb->delete()->getQuery()->execute());
        }

        $output->writeln(($dryRun ? '[dry-run] would delete ' : 'Deleted ').implode(', ', $counts));

        return Command::SUCCESS;
    }
}
