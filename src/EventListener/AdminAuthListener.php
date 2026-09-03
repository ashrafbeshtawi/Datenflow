<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Session gate for everything under /admin except the login page itself.
 * The session flag is set by AdminController::login after checking
 * ADMIN_USER/ADMIN_PASSWORD.
 */
#[AsEventListener]
class AdminAuthListener
{
    public const SESSION_KEY = 'admin_authenticated';

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !str_starts_with($path, '/admin') || $path === '/admin/login') {
            return;
        }

        if ($request->getSession()->get(self::SESSION_KEY) !== true) {
            $event->setResponse(new RedirectResponse('/admin/login'));
        }
    }
}
