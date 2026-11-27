<?php

namespace App\Tests\Functional;

use App\Entity\Inquiry;
use App\Entity\InquiryCv;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The retention promised in the privacy policy (SiteCopy "datenschutz", sections 4 and 5). */
class PurgeInquiriesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPurgesEverythingPastItsRetentionAndKeepsTheRest(): void
    {
        $oldApplication = $this->message('karriere', '-7 months', withCv: true);
        self::assertNotNull($this->em->find(InquiryCv::class, $oldApplication));
        $oldContact = $this->message('contact', '-7 months');
        $recentContact = $this->message('contact', '-1 month');

        $oldBooking = $this->slot(Inquiry::TYPE_BOOKING, '-7 months', '-7 months');
        $recentBooking = $this->slot(Inquiry::TYPE_BOOKING, '-1 month', '-1 month');
        $oldCancelled = $this->slot(Inquiry::TYPE_BOOKING, '-7 months', '-5 months', cancelled: true);
        $recentCancelled = $this->slot(Inquiry::TYPE_BOOKING, '-1 month', '-3 weeks', cancelled: true);
        $upcomingBooking = $this->slot(Inquiry::TYPE_BOOKING, '-8 months', '+5 years');

        $pastBlock = $this->slot(Inquiry::TYPE_BLOCK, '-1 week', '-1 day');
        $freedBlock = $this->slot(Inquiry::TYPE_BLOCK, '-1 week', '+5 years', cancelled: true);
        $futureBlock = $this->slot(Inquiry::TYPE_BLOCK, '-1 week', '+5 years');

        $tester = $this->runPurge([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted', $tester->getDisplay());
        $this->em->clear();

        foreach (['old application' => $oldApplication, 'old contact message' => $oldContact, 'old booking' => $oldBooking,
            'old cancelled booking' => $oldCancelled, 'past block' => $pastBlock, 'freed block' => $freedBlock] as $label => $id) {
            self::assertNull($this->em->find(Inquiry::class, $id), $label.' must be purged');
        }
        self::assertNull($this->em->find(InquiryCv::class, $oldApplication), 'the stored CV goes with its application');

        foreach (['recent contact message' => $recentContact, 'recent booking' => $recentBooking,
            'recently cancelled booking' => $recentCancelled, 'upcoming booking' => $upcomingBooking, 'future block' => $futureBlock] as $label => $id) {
            self::assertNotNull($this->em->find(Inquiry::class, $id), $label.' must be kept');
        }

        // Leave no far-future slots behind for later runs.
        $this->em->createQuery('DELETE FROM '.Inquiry::class.' i WHERE i.id IN (:ids)')
            ->setParameter('ids', [$upcomingBooking, $futureBlock])->execute();
    }

    public function testDryRunDeletesNothing(): void
    {
        $id = $this->message('contact', '-7 months');

        $tester = $this->runPurge(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('[dry-run]', $tester->getDisplay());
        $this->em->clear();
        self::assertNotNull($this->em->find(Inquiry::class, $id));
    }

    private function runPurge(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:purge-inquiries'));
        $tester->execute($input);

        return $tester;
    }

    /** A form message (no slot) that came in $age ago; returns its id. */
    private function message(string $type, string $age, bool $withCv = false): int
    {
        $inquiry = new Inquiry($type, 'Purge Test', uniqid('purge-').'@example.com', 'Hallo');
        $this->em->persist($inquiry);
        if ($withCv) {
            $this->em->persist(new InquiryCv($inquiry, 'lebenslauf.pdf', '%PDF-1.4 fake'));
        }
        $this->em->flush();
        $this->backdate($inquiry, $age);

        return $inquiry->getId();
    }

    /** A booking or block created $age ago for a slot at $startsIn (random seconds keep slots unique); returns its id. */
    private function slot(string $type, string $age, string $startsIn, bool $cancelled = false): int
    {
        $at = (new \DateTimeImmutable($startsIn))->setTime(3, random_int(0, 59), random_int(0, 59));
        $inquiry = $type === Inquiry::TYPE_BLOCK
            ? Inquiry::block($at)
            : new Inquiry($type, 'Purge Test', uniqid('purge-').'@example.com', '', [], $at, 'video');
        if ($cancelled) {
            $inquiry->cancel();
        }
        $this->em->persist($inquiry);
        $this->em->flush();
        $this->backdate($inquiry, $age);

        return $inquiry->getId();
    }

    private function backdate(Inquiry $inquiry, string $age): void
    {
        $this->em->createQuery('UPDATE '.Inquiry::class.' i SET i.createdAt = :at WHERE i.id = :id')
            ->setParameters(['at' => new \DateTimeImmutable($age), 'id' => $inquiry->getId()])
            ->execute();
    }
}
