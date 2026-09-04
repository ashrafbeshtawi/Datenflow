<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use App\EventListener\AdminAuthListener;
use App\Entity\AvailabilityRule;
use App\Entity\Inquiry;
use App\Entity\Setting;
use App\Mail\InquiryMailer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal admin panel (German only). Auth happens in AdminAuthListener,
 * which sends anonymous visitors to login() below.
 */
#[Route('/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryMailer $mailer,
        private readonly SlotFinder $slots,
    ) {
    }

    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        #[Autowire(env: 'ADMIN_USER')] string $user,
        #[Autowire(env: 'ADMIN_PASSWORD')] string $password,
    ): Response {
        $error = false;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);
            // Evaluate both comparisons before deciding, so a wrong user name
            // costs the same time as a wrong password.
            $userOk = hash_equals($user, $request->request->getString('username'));
            $passOk = hash_equals($password, $request->request->getString('password'));

            if ($user !== '' && $password !== '' && $userOk && $passOk) {
                $request->getSession()->migrate(true);
                $request->getSession()->set(AdminAuthListener::SESSION_KEY, true);

                return $this->redirectToRoute('admin');
            }
            $error = true;
        }

        return $this->render('admin/login.html.twig', $this->buildBaseContext() + ['error' => $error]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $this->assertCsrf($request);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('admin_login');
    }

    #[Route('', name: 'admin', methods: ['GET'])]
    public function showDashboard(Request $request): Response
    {
        $all = $this->em->getRepository(Inquiry::class)->findBy([], ['createdAt' => 'DESC'], 300);
        $grid = $this->slots->buildWeekGrid(null);

        return $this->render('admin/dashboard.html.twig', $this->buildBaseContext() + $this->partitionInquiries($all, $this->slots->getCurrentTime()) + [
            'free_slots' => count(array_keys($grid['slots'], 'free', true)),
            'free_week' => $grid['weekStart'],
            'rules' => $this->loadRulesByWeekday(),
            'meet_link' => $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue(),
            'notice' => $request->query->getString('notice') ?: null,
        ]);
    }

    #[Route('/inquiry/{id}', name: 'admin_inquiry', methods: ['GET'])]
    public function showInquiry(Inquiry $inquiry): Response
    {
        return $this->render('admin/view.html.twig', $this->buildBaseContext() + ['inquiry' => $inquiry]);
    }

    #[Route('/inquiry/{id}/reschedule', name: 'admin_reschedule', methods: ['POST'])]
    public function reschedule(Request $request, Inquiry $inquiry): Response
    {
        $this->assertCsrf($request);

        $at = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $request->request->getString('date').' '.$request->request->getString('time'),
            new DateTimeZone(SlotFinder::TZ),
        );
        if ($inquiry->getType() !== Inquiry::TYPE_BOOKING || $inquiry->getStatus() !== Inquiry::STATUS_CONFIRMED
            || $at === false || $at < $this->slots->getCurrentTime()) {
            return $this->redirectToRoute('admin', ['notice' => 'Ungültiger Zeitpunkt, Termin nicht verschoben.']);
        }

        $inquiry->setStartsAt($at);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->redirectToRoute('admin', ['notice' => 'Slot ist bereits belegt, Termin nicht verschoben.']);
        }

        $this->mailer->sendReschedule($inquiry, $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue());

        return $this->redirectToRoute('admin', ['notice' => 'Termin verschoben, der Kunde hat den neuen Termin per Mail.']);
    }

    #[Route('/inquiry/{id}/delete', name: 'admin_inquiry_delete', methods: ['POST'])]
    public function deleteInquiry(Request $request, Inquiry $inquiry): Response
    {
        $this->assertCsrf($request);

        // Appointments are cancelled (mail + history), never deleted.
        if ($inquiry->getStartsAt() !== null) {
            return $this->redirectToRoute('admin', ['notice' => 'Termine bitte stornieren, nicht löschen.']);
        }

        $this->em->remove($inquiry);
        $this->em->flush();

        return $this->redirectToRoute('admin', ['notice' => 'Nachricht gelöscht.']);
    }

    #[Route('/inquiry/{id}/cancel', name: 'admin_cancel', methods: ['POST'])]
    public function cancel(Request $request, Inquiry $inquiry): Response
    {
        $this->assertCsrf($request);

        if ($inquiry->getStatus() === Inquiry::STATUS_CONFIRMED) {
            $inquiry->cancel();
            $this->em->flush();

            // Blocks are internal: freeing them notifies nobody.
            if ($inquiry->getType() === Inquiry::TYPE_BOOKING && $inquiry->getStartsAt() !== null) {
                $this->mailer->sendCancellation($inquiry);
            }
        }

        return $this->redirectToRoute('admin', ['notice' => 'Storniert, der Slot ist wieder frei.']);
    }

    /** One form for the whole week: checked day = open with the given times, unchecked = closed. */
    #[Route('/availability', name: 'admin_availability_save', methods: ['POST'])]
    public function saveAvailability(Request $request): Response
    {
        $this->assertCsrf($request);

        $rules = [];
        foreach (range(1, 7) as $weekday) {
            if (!$request->request->getBoolean('open_'.$weekday)) {
                continue;
            }
            $start = DateTimeImmutable::createFromFormat('!H:i', $request->request->getString('start_'.$weekday));
            $end = DateTimeImmutable::createFromFormat('!H:i', $request->request->getString('end_'.$weekday));
            if ($start === false || $end === false || $start >= $end) {
                return $this->redirectToRoute('admin', ['notice' => 'Ungültige Zeiten, nichts gespeichert.']);
            }
            $rules[] = new AvailabilityRule($weekday, $start, $end);
        }

        // Full rewrite of at most 7 rows — duplicate weekdays are structurally impossible.
        $this->em->createQuery('DELETE FROM '.AvailabilityRule::class)->execute();
        foreach ($rules as $rule) {
            $this->em->persist($rule);
        }
        $this->em->flush();

        return $this->redirectToRoute('admin', ['notice' => 'Verfügbarkeit gespeichert.']);
    }

    #[Route('/block', name: 'admin_block', methods: ['POST'])]
    public function blockSlot(Request $request): Response
    {
        $this->assertCsrf($request);

        $date = $request->request->getString('date');
        $time = $request->request->getString('time');
        if ($time === '') {
            return $this->blockWholeDay($date);
        }

        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, new DateTimeZone(SlotFinder::TZ));
        if ($at === false) {
            return $this->redirectToRoute('admin', ['notice' => 'Ungültiger Zeitpunkt.']);
        }

        try {
            $this->em->persist(Inquiry::block($at));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->redirectToRoute('admin', ['notice' => 'Slot ist bereits belegt.']);
        }

        return $this->redirectToRoute('admin', ['notice' => 'Slot blockiert.']);
    }

    /**
     * ponytail: a day block is N slot blocks, shown as N rows under "Kommende
     * Termine" and freed one by one — a day-level entity only if that ever hurts.
     */
    private function blockWholeDay(string $date): Response
    {
        $grid = $this->slots->buildWeekGrid($date);
        $free = array_filter(
            array_keys($grid['slots'], 'free', true),
            fn (string $key) => str_starts_with($key, $date.' '),
        );
        if ($free === []) {
            return $this->redirectToRoute('admin', ['notice' => 'Keine freien Slots an diesem Tag.']);
        }

        foreach ($free as $key) {
            $this->em->persist(Inquiry::block(new DateTimeImmutable($key, new DateTimeZone(SlotFinder::TZ))));
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->redirectToRoute('admin', ['notice' => 'Slot ist bereits belegt, bitte neu versuchen.']);
        }

        return $this->redirectToRoute('admin', ['notice' => count($free).' Slots blockiert.']);
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

    /**
     * Upcoming confirmed appointments (bookings + blocks), soonest first;
     * messages have no slot; everything else is history.
     *
     * @param Inquiry[] $all
     * @return array{upcoming: Inquiry[], messages: Inquiry[], history: Inquiry[]}
     */
    private function partitionInquiries(array $all, DateTimeImmutable $now): array
    {
        $upcoming = array_filter($all, fn (Inquiry $i) => $i->getStartsAt() !== null
            && $i->getStartsAt() >= $now && $i->getStatus() === Inquiry::STATUS_CONFIRMED);
        usort($upcoming, fn (Inquiry $a, Inquiry $b) => $a->getStartsAt() <=> $b->getStartsAt());

        return [
            'upcoming' => $upcoming,
            'messages' => array_filter($all, fn (Inquiry $i) => $i->getStartsAt() === null),
            'history' => array_filter($all, fn (Inquiry $i) => $i->getStartsAt() !== null
                && ($i->getStartsAt() < $now || $i->getStatus() !== Inquiry::STATUS_CONFIRMED)),
        ];
    }

    /** @return array<int, AvailabilityRule> keyed by ISO weekday, at most one per day */
    private function loadRulesByWeekday(): array
    {
        $rules = [];
        foreach ($this->em->getRepository(AvailabilityRule::class)->findAll() as $rule) {
            $rules[$rule->getWeekday()] = $rule;
        }

        return $rules;
    }

    /** The base layout needs t/lang; the admin panel itself is German only. */
    private function buildBaseContext(): array
    {
        return ['t' => SiteCopy::get('de'), 'lang' => 'de'];
    }
}
