<?php

namespace App\Tests\Unit;

use App\Form\FormGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class FormGuardTest extends TestCase
{
    public function testCleanRequestPasses(): void
    {
        self::assertNull($this->guard()->reject(self::post(['_token' => 'ok'])));
    }

    public function testFilledHoneypotIsRejectedAsHoneypot(): void
    {
        self::assertSame(FormGuard::HONEYPOT, $this->guard()->reject(self::post(['_hp' => 'i am a bot', '_token' => 'ok'])));
    }

    public function testHoneypotShortCircuitsBeforeTheRateLimiter(): void
    {
        $guard = $this->guard(limit: 1);

        // The bot hit must not consume the single token of a real visitor.
        self::assertSame(FormGuard::HONEYPOT, $guard->reject(self::post(['_hp' => 'bot', '_token' => 'ok'])));
        self::assertNull($guard->reject(self::post(['_token' => 'ok'])));
    }

    public function testRequestsOverTheLimitAreRejected(): void
    {
        $guard = $this->guard(limit: 2);

        self::assertNull($guard->reject(self::post(['_token' => 'ok'])));
        self::assertNull($guard->reject(self::post(['_token' => 'ok'])));
        self::assertSame(FormGuard::RATE_LIMIT, $guard->reject(self::post(['_token' => 'ok'])));
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        self::assertSame(FormGuard::CSRF, $this->guard(csrfValid: false)->reject(self::post(['_token' => 'forged'])));
    }

    private function guard(bool $csrfValid = true, int $limit = 5): FormGuard
    {
        $csrf = self::createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);

        $limiter = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        return new FormGuard($limiter, $csrf);
    }

    private static function post(array $fields): Request
    {
        return new Request(request: $fields);
    }
}
