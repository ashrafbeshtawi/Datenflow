<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use App\Entity\AvailabilityRule;
use App\Entity\Inquiry;
use App\Entity\Setting;
use App\Mail\InquiryMailer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal admin panel (German only). Auth happens in AdminBasicAuthListener.
 */
#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryMailer $mailer,
    ) {
    }

    #[Route('', name: 'admin', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        return $this->render('admin/dashboard.html.twig', $this->baseContext() + [
            'inquiries' => $this->em->getRepository(Inquiry::class)->findBy([], ['createdAt' => 'DESC'], 200),
            'rules' => $this->em->getRepository(AvailabilityRule::class)->findBy([], ['weekday' => 'ASC', 'startTime' => 'ASC']),
            'meet_link' => $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue(),
            'notice' => $request->query->getString('notice') ?: null,
        ]);
    }

    #[Route('/inquiry/{id}', name: 'admin_inquiry', methods: ['GET', 'POST'])]
    public function edit(Request $request, Inquiry $inquiry): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            $inquiry->setName(trim($request->request->getString('name')));
            $inquiry->setEmail(trim($request->request->getString('email')));
            $inquiry->setMessage(trim($request->request->getString('message')));
            $this->em->flush();

            return $this->redirectToRoute('admin', ['notice' => 'Anfrage gespeichert.']);
        }

        return $this->render('admin/edit.html.twig', $this->baseContext() + ['inquiry' => $inquiry]);
    }

    #[Route('/inquiry/{id}/cancel', name: 'admin_cancel', methods: ['POST'])]
    public function cancel(Request $request, Inquiry $inquiry): Response
    {
        $this->assertCsrf($request);

        if ($inquiry->getStatus() === Inquiry::STATUS_CONFIRMED) {
            $inquiry->cancel();
            $this->em->flush();

            // Blocks are internal: freeing them notifies nobody.
            if ($inquiry->getType() === 'booking' && $inquiry->getStartsAt() !== null) {
                $this->mailer->sendCancellation($inquiry);
            }
        }

        return $this->redirectToRoute('admin', ['notice' => 'Storniert, der Slot ist wieder frei.']);
    }

    #[Route('/availability', name: 'admin_availability_add', methods: ['POST'])]
    public function addAvailability(Request $request): Response
    {
        $this->assertCsrf($request);

        $weekday = $request->request->getInt('weekday');
        $start = DateTimeImmutable::createFromFormat('!H:i', $request->request->getString('start_time'));
        $end = DateTimeImmutable::createFromFormat('!H:i', $request->request->getString('end_time'));

        if ($weekday < 1 || $weekday > 7 || $start === false || $end === false || $start >= $end) {
            return $this->redirectToRoute('admin', ['notice' => 'Ungültige Zeiten, Regel nicht gespeichert.']);
        }

        $this->em->persist(new AvailabilityRule($weekday, $start, $end));
        $this->em->flush();

        return $this->redirectToRoute('admin', ['notice' => 'Verfügbarkeit gespeichert.']);
    }

    #[Route('/availability/{id}/delete', name: 'admin_availability_delete', methods: ['POST'])]
    public function deleteAvailability(Request $request, AvailabilityRule $rule): Response
    {
        $this->assertCsrf($request);
        $this->em->remove($rule);
        $this->em->flush();

        return $this->redirectToRoute('admin', ['notice' => 'Verfügbarkeit gelöscht.']);
    }

    #[Route('/block', name: 'admin_block', methods: ['POST'])]
    public function blockSlot(Request $request): Response
    {
        $this->assertCsrf($request);

        $at = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $request->request->getString('date').' '.$request->request->getString('time'),
            new DateTimeZone(SlotFinder::TZ),
        );
        if ($at === false) {
            return $this->redirectToRoute('admin', ['notice' => 'Ungültiger Zeitpunkt.']);
        }

        try {
            $this->em->persist(new Inquiry('block', 'Blockiert', 'block@datenflow.internal', '', [], $at, null));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->redirectToRoute('admin', ['notice' => 'Slot ist bereits belegt.']);
        }

        return $this->redirectToRoute('admin', ['notice' => 'Slot blockiert.']);
    }

    #[Route('/settings', name: 'admin_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->assertCsrf($request);

        $setting = $this->em->find(Setting::class, Setting::MEET_LINK)
            ?? new Setting(Setting::MEET_LINK, '');
        $setting->setValue(trim($request->request->getString('meet_link')));
        $this->em->persist($setting);
        $this->em->flush();

        return $this->redirectToRoute('admin', ['notice' => 'Einstellungen gespeichert.']);
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /** The base layout needs t/lang; the admin panel itself is German only. */
    private function baseContext(): array
    {
        return ['t' => SiteCopy::for('de'), 'lang' => 'de'];
    }
}
