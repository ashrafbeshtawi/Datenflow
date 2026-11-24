<?php

namespace App\Controller;

use App\Entity\Inquiry;
use App\Entity\InquiryCv;
use App\Form\FormErrorResponder;
use App\Form\FormGuard;
use App\Form\InquiryValidator;
use App\Mail\InquiryNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class KarriereSubmitController extends AbstractController
{
    private const TEMPLATE = 'page/karriere.html.twig';

    private const CV_MAX_BYTES = 8 * 1024 * 1024;
    private const CV_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryNotifier $notifier,
        private readonly FormGuard $guard,
        private readonly InquiryValidator $validator,
        private readonly FormErrorResponder $errorPage,
    ) {
    }

    #[Route(['de' => '/karriere', 'en' => '/en/careers'], name: 'karriere_submit', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $rejection = $this->guard->reject($request);
        if ($rejection === FormGuard::HONEYPOT) {
            return $this->redirectToRoute('karriere', ['sent' => 1]);
        }
        if ($rejection !== null) {
            return $this->errorPage->respond($request, self::TEMPLATE, $rejection, $rejection === FormGuard::RATE_LIMIT ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $errors = $this->validator->validate($request, ['name', 'email', 'message']);

        [$attachment, $cvError] = $this->checkCv($request->files->get('cv'));
        if ($cvError !== null) {
            $errors['cv'] = $cvError;
        }

        if ($errors !== []) {
            // CV errors have their own summary text; everything else gets the generic one.
            return $this->errorPage->respond($request, self::TEMPLATE, $cvError ?? 'validation', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        $payload = [
            'role' => trim($request->request->getString('role')),
            'portfolio' => trim($request->request->getString('portfolio')),
        ];
        if ($attachment !== null) {
            $payload['cv_datei'] = $attachment['name'];
        }

        $inquiry = new Inquiry(
            'karriere',
            trim($request->request->getString('name')),
            trim($request->request->getString('email')),
            trim($request->request->getString('message')),
            $payload,
        );
        $this->em->persist($inquiry);
        // Saved with the application in one flush, so a failed mail can be resent with it.
        if ($attachment !== null) {
            $this->em->persist(new InquiryCv($inquiry, $attachment['name'], file_get_contents($attachment['path'])));
        }
        $this->em->flush();

        // The application is saved now; a mail failure is flagged for a
        // resend from /admin instead of asking for a duplicate submit.
        $this->notifier->notify($inquiry);

        return $this->redirectToRoute('karriere', ['sent' => 1]);
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
}
