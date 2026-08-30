<?php

namespace App\Form;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Spam/abuse gate shared by all public forms: honeypot, rate limit, CSRF.
 */
class FormGuard
{
    /** Bot filled the hidden field: answer with a fake success and no signal. */
    public const HONEYPOT = 'honeypot';
    /** Values double as t.form_errors keys for the error page. */
    public const RATE_LIMIT = 'rate_limit';
    public const CSRF = 'validation';

    public function __construct(
        private readonly RateLimiterFactory $formSubmitLimiter,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    /** @return string|null one of the class constants, or null to proceed */
    public function reject(Request $request): ?string
    {
        if (trim($request->request->getString('_hp')) !== '') {
            return self::HONEYPOT;
        }

        if (!$this->formSubmitLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return self::RATE_LIMIT;
        }

        if (!$this->csrf->isTokenValid(new CsrfToken('inquiry', $request->request->getString('_token')))) {
            return self::CSRF;
        }

        return null;
    }
}
