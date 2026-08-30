<?php

namespace App\Controller;

use App\Entity\Inquiry;
use App\Form\FormErrorResponder;
use App\Form\FormGuard;
use App\Form\InquiryValidator;
use App\Mail\InquiryMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

class ContactSubmitController extends AbstractController
{
    private const TEMPLATE = 'page/contact.html.twig';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InquiryMailer $mailer,
        private readonly FormGuard $guard,
        private readonly InquiryValidator $validator,
        private readonly FormErrorResponder $errorPage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/contact', name: 'contact_submit', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $rejection = $this->guard->reject($request);
        if ($rejection === FormGuard::HONEYPOT) {
            return $this->redirectToRoute('contact', ['sent' => 1]);
        }
        if ($rejection !== null) {
            return $this->errorPage->respond($request, self::TEMPLATE, $rejection, $rejection === FormGuard::RATE_LIMIT ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $errors = $this->validator->validate($request, ['name', 'email', 'message']);
        if ($errors !== []) {
            return $this->errorPage->respond($request, self::TEMPLATE, 'validation', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        $inquiry = new Inquiry(
            'contact',
            trim($request->request->getString('name')),
            trim($request->request->getString('email')),
            trim($request->request->getString('message')),
            ['company' => trim($request->request->getString('company'))],
        );
        $this->em->persist($inquiry);
        $this->em->flush();

        try {
            $this->mailer->send($inquiry);
        } catch (Throwable $e) {
            // Lead is already persisted — surface the failure so the visitor can call instead.
            $this->logger->error('Inquiry mail failed', ['type' => 'contact', 'inquiry' => $inquiry->getId(), 'error' => $e->getMessage()]);

            return $this->errorPage->respond($request, self::TEMPLATE, 'send_failed', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->redirectToRoute('contact', ['sent' => 1]);
    }
}
