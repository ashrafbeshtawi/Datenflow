<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * HTTP Basic auth for everything under /admin. One password from the env,
 * the user name is ignored. Fails closed when ADMIN_PASSWORD is empty.
 */
#[AsEventListener]
class AdminBasicAuthListener
{
    public function __construct(
        #[Autowire(env: 'ADMIN_PASSWORD')] private readonly string $password,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/admin')) {
            return;
        }

        if ($this->password !== '' && hash_equals($this->password, (string) $request->headers->get('php-auth-pw'))) {
            return;
        }

        $event->setResponse(new Response('', Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Basic realm="Datenflow Admin"']));
    }
}
