<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * /index.php and /index.php/<path> serve the same pages as / and /<path>.
 * Redirects them permanently so search engines index one URL per page.
 */
#[AsEventListener(priority: 64)]
class FrontControllerUrlListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getBaseUrl() === '') {
            return;
        }

        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse(
            $request->getSchemeAndHttpHost().$request->getPathInfo().($query !== null ? '?'.$query : ''),
            RedirectResponse::HTTP_MOVED_PERMANENTLY,
        ));
    }
}
