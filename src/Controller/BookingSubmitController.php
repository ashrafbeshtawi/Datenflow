<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Entity\Inquiry;
use App\Entity\Setting;
use App\Form\FormErrorResponder;
use App\Form\FormGuard;
use App\Form\InquiryValidator;
use App\Mail\InquiryMailer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class BookingSubmitController extends AbstractController
{
    private const TEMPLATE = 'page/booking.html.twig';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryMailer $mailer,
        private readonly FormGuard $guard,
        private readonly InquiryValidator $validator,
        private readonly FormErrorResponder $errorPage,
        private readonly SlotFinder $slots,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/termin', name: 'booking_submit', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $rejection = $this->guard->reject($request);
        if ($rejection === FormGuard::HONEYPOT) {
            return $this->redirectToRoute('booking', ['sent' => 1]);
        }
        if ($rejection !== null) {
            return $this->respondError($request, $rejection, $rejection === FormGuard::RATE_LIMIT ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Message is optional here — the picked slot is the point of the form.
        $errors = $this->validator->validate($request, ['name', 'email']);
        $startsAt = null;
        $callType = null;
        $errors = array_merge($errors, $this->validateBooking($request, $startsAt, $callType));

        if ($errors !== []) {
            // Errors with their own summary text win over the generic one.
            $special = array_intersect($errors, ['slot_taken', 'phone_required']);

            return $this->respondError($request, $special !== [] ? reset($special) : 'validation', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        $inquiry = new Inquiry(
            Inquiry::TYPE_BOOKING,
            trim($request->request->getString('name')),
            trim($request->request->getString('email')),
            trim($request->request->getString('message')),
            [
                'company' => trim($request->request->getString('company')),
                'phone' => trim($request->request->getString('phone')),
            ],
            $startsAt,
            $callType,
        );

        try {
            $this->em->persist($inquiry);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Someone else grabbed the slot between render and submit.
            return $this->respondError($request, 'slot_taken', Response::HTTP_UNPROCESSABLE_ENTITY, ['starts_at' => 'slot_taken']);
        }

        try {
            $meetLink = $callType === 'video' ? $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue() : null;
            $this->mailer->send($inquiry, null, PageController::localeOf($request), $meetLink);
        } catch (Throwable $e) {
            // Booking is already persisted — surface the failure so the visitor can call instead.
            $this->logger->error('Inquiry mail failed', ['type' => 'booking', 'inquiry' => $inquiry->getId(), 'error' => $e->getMessage()]);

            return $this->respondError($request, 'send_failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->redirectToRoute('booking', ['sent' => 1]);
    }

    /** Booking-only rules: valid open slot, call type, phone number for phone calls. */
    private function validateBooking(Request $request, ?DateTimeImmutable &$startsAt, ?string &$callType): array
    {
        $errors = [];

        $callType = $request->request->getString('call_type');
        if (!in_array($callType, ['video', 'phone'], true)) {
            $errors['call_type'] = 'required';
            $callType = null;
        } elseif ($callType === 'phone' && trim($request->request->getString('phone')) === '') {
            $errors['phone'] = 'phone_required';
        }

        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $request->request->getString('starts_at'), new DateTimeZone(SlotFinder::TZ));
        if ($at === false || !$this->slots->isBookable($at)) {
            $errors['starts_at'] = 'slot_taken';
        } else {
            $startsAt = $at;
        }

        return $errors;
    }

    /** The booking page always needs the slot grid, whatever went wrong. */
    private function respondError(Request $request, string $errorKey, int $status, array $errors = []): Response
    {
        return $this->errorPage->respond($request, self::TEMPLATE, $errorKey, $status, $errors, [
            'grid' => $this->slots->buildWeekGrid($request->request->getString('week') ?: null),
        ]);
    }
}
