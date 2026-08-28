<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use App\Entity\Inquiry;
use App\Entity\Setting;
use App\Mail\InquiryMailer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

class InquiryController extends AbstractController
{
    private const MAX_LENGTHS = [
        'name' => 200,
        'company' => 200,
        'email' => 320,
        'phone' => 50,
        'role' => 100,
        'portfolio' => 500,
        'message' => 5000,
    ];

    private const CV_MAX_BYTES = 8 * 1024 * 1024;
    private const CV_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryMailer $mailer,
        private readonly RateLimiterFactory $formSubmitLimiter,
        private readonly LoggerInterface $logger,
        private readonly SlotFinder $slots,
    ) {
    }

    #[Route('/termin', name: 'booking_submit', methods: ['POST'])]
    public function booking(Request $request): Response
    {
        // Message is optional here — the picked slot is the point of the form.
        return $this->handle($request, 'booking', 'page/booking.html.twig', ['company', 'phone'], ['name', 'email']);
    }

    #[Route('/contact', name: 'contact_submit', methods: ['POST'])]
    public function contact(Request $request): Response
    {
        return $this->handle($request, 'contact', 'page/contact.html.twig', ['company']);
    }

    #[Route('/karriere', name: 'karriere_submit', methods: ['POST'])]
    public function karriere(Request $request): Response
    {
        return $this->handle($request, 'karriere', 'page/karriere.html.twig', ['role', 'portfolio']);
    }

    private function handle(Request $request, string $type, string $template, array $extraFields, array $required = ['name', 'email', 'message']): Response
    {
        // Honeypot: bots that fill the hidden field get a fake success and no signal.
        if (trim($request->request->getString('_hp')) !== '') {
            return $this->redirectSent($type);
        }

        if (!$this->formSubmitLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->renderError($request, $template, 'rate_limit', Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$this->isCsrfTokenValid('inquiry', $request->request->getString('_token'))) {
            return $this->renderError($request, $template, 'validation', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $errors = $this->validate($request, $required);

        $attachment = null;
        if ($type === 'karriere') {
            [$attachment, $cvError] = $this->checkCv($request->files->get('cv'));
            if ($cvError !== null) {
                $errors['cv'] = $cvError;
            }
        }

        $startsAt = null;
        $callType = null;
        if ($type === 'booking') {
            $errors = array_merge($errors, $this->validateBooking($request, $startsAt, $callType));
        }

        if ($errors !== []) {
            // Errors with their own summary text win over the generic one.
            $special = array_intersect($errors, ['slot_taken', 'phone_required', 'cv_too_large', 'cv_invalid_type']);

            return $this->renderError($request, $template, $special !== [] ? reset($special) : 'validation', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        $payload = [];
        foreach ($extraFields as $field) {
            $payload[$field] = trim($request->request->getString($field));
        }
        if ($attachment !== null) {
            $payload['cv_datei'] = $attachment['name'];
        }

        $inquiry = new Inquiry(
            $type,
            trim($request->request->getString('name')),
            trim($request->request->getString('email')),
            trim($request->request->getString('message')),
            $payload,
            $startsAt,
            $callType,
        );

        try {
            $this->em->persist($inquiry);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Someone else grabbed the slot between render and submit.
            return $this->renderError($request, $template, 'slot_taken', Response::HTTP_UNPROCESSABLE_ENTITY, ['starts_at' => 'slot_taken']);
        }

        try {
            $meetLink = $callType === 'video' ? $this->em->find(Setting::class, Setting::MEET_LINK)?->getValue() : null;
            $this->mailer->send($inquiry, $attachment, PageController::localeOf($request), $meetLink);
        } catch (\Throwable $e) {
            // Lead is already persisted — surface the failure so the visitor can call instead.
            $this->logger->error('Inquiry mail failed', ['type' => $type, 'inquiry' => $inquiry->getId(), 'error' => $e->getMessage()]);

            return $this->renderError($request, $template, 'send_failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->redirectSent($type);
    }

    /** @return array<string, string> field => reason */
    private function validate(Request $request, array $required): array
    {
        $errors = [];
        foreach ($required as $field) {
            if (trim($request->request->getString($field)) === '') {
                $errors[$field] = 'required';
            }
        }
        $email = trim($request->request->getString('email'));
        if ($email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid';
        }
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($request->request->getString($field)) > $max) {
                $errors[$field] = 'too_long';
            }
        }

        return $errors;
    }

    /** Booking-only rules: valid open slot, call type, phone number for phone calls. */
    private function validateBooking(Request $request, ?\DateTimeImmutable &$startsAt, ?string &$callType): array
    {
        $errors = [];

        $callType = $request->request->getString('call_type');
        if (!in_array($callType, ['video', 'phone'], true)) {
            $errors['call_type'] = 'required';
            $callType = null;
        } elseif ($callType === 'phone' && trim($request->request->getString('phone')) === '') {
            $errors['phone'] = 'phone_required';
        }

        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $request->request->getString('starts_at'), new \DateTimeZone(SlotFinder::TZ));
        if ($at === false || !$this->slots->isBookable($at)) {
            $errors['starts_at'] = 'slot_taken';
        } else {
            $startsAt = $at;
        }

        return $errors;
    }

    /** @return array{0: array{path: string, name: string}|null, 1: string|null} */
    private function checkCv(?UploadedFile $cv): array
    {
        if ($cv === null) {
            return [null, null];
        }
        if (!$cv->isValid() || $cv->getSize() > self::CV_MAX_BYTES) {
            return [null, 'cv_too_large'];
        }
        if (!in_array($cv->getMimeType(), self::CV_MIME_TYPES, true)) {
            return [null, 'cv_invalid_type'];
        }

        return [['path' => $cv->getPathname(), 'name' => $cv->getClientOriginalName() ?: 'lebenslauf'], null];
    }

    private function redirectSent(string $type): Response
    {
        $route = ['booking' => 'booking', 'contact' => 'contact', 'karriere' => 'karriere'][$type];

        return $this->redirectToRoute($route, ['sent' => 1]);
    }

    private function renderError(Request $request, string $template, string $errorKey, int $status, array $errors = []): Response
    {
        $lang = PageController::localeOf($request);

        $context = [
            't' => SiteCopy::for($lang),
            'lang' => $lang,
            'old' => $request->request->all(),
            'errors' => $errors ?: ['form' => $errorKey],
            'error_key' => $errorKey,
            'section' => null,
        ];
        if ($template === 'page/booking.html.twig') {
            $context['grid'] = $this->slots->week($request->request->getString('week') ?: null);
        }

        return $this->render($template, $context, new Response(status: $status));
    }
}
