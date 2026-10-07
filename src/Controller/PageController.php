<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class PageController extends AbstractController
{
    #[Route('/', name: 'home', options: ['sitemap' => true])]
    public function showHome(Request $request): Response
    {
        return $this->renderPage($request, 'page/home.html.twig');
    }

    #[Route('/services', name: 'services', options: ['sitemap' => true])]
    public function showServices(Request $request): Response
    {
        return $this->renderPage($request, 'page/services.html.twig');
    }

    #[Route('/process', name: 'process', options: ['sitemap' => true])]
    public function showProcess(Request $request): Response
    {
        return $this->renderPage($request, 'page/process.html.twig');
    }

    #[Route('/preise', name: 'pricing', options: ['sitemap' => true])]
    public function showPricing(Request $request): Response
    {
        return $this->renderPage($request, 'page/pricing.html.twig');
    }

    #[Route('/faq', name: 'faq', options: ['sitemap' => true])]
    public function showFaq(Request $request): Response
    {
        return $this->renderPage($request, 'page/faq.html.twig');
    }

    #[Route('/termin', name: 'booking', options: ['sitemap' => true])]
    public function showBooking(Request $request, SlotFinder $slots): Response
    {
        return $this->renderPage($request, 'page/booking.html.twig', [
            'grid' => $slots->buildWeekGrid($request->query->getString('week') ?: null),
        ]);
    }

    #[Route('/contact', name: 'contact', options: ['sitemap' => true])]
    public function showContact(Request $request): Response
    {
        return $this->renderPage($request, 'page/contact.html.twig');
    }

    #[Route('/karriere', name: 'karriere', options: ['sitemap' => true])]
    public function showKarriere(Request $request): Response
    {
        return $this->renderPage($request, 'page/karriere.html.twig');
    }

    #[Route('/impressum', name: 'impressum', options: ['sitemap' => true])]
    public function showImpressum(Request $request): Response
    {
        return $this->renderPage($request, 'page/legal.html.twig', ['section' => 'impressum']);
    }

    #[Route('/datenschutz', name: 'datenschutz', options: ['sitemap' => true])]
    public function showDatenschutz(Request $request): Response
    {
        return $this->renderPage($request, 'page/legal.html.twig', ['section' => 'datenschutz']);
    }

    /** Lists every route marked with options: ['sitemap' => true]. */
    #[Route('/sitemap.xml', name: 'sitemap', format: 'xml')]
    public function showSitemap(RouterInterface $router): Response
    {
        $urls = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if ($route->getOption('sitemap') === true) {
                $urls[] = $this->generateUrl($name, [], UrlGeneratorInterface::ABSOLUTE_URL);
            }
        }

        return $this->render('sitemap.xml.twig', ['urls' => $urls]);
    }

    // Old URLs from the previous site — keep them alive.
    #[Route('/tools', name: 'legacy_tools')]
    public function redirectLegacyTools(): Response
    {
        return $this->redirectToRoute('services', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/lang/{locale}', name: 'lang_switch', requirements: ['locale' => 'de|en'])]
    public function switchLang(string $locale, Request $request): Response
    {
        $target = $request->headers->get('referer') ?: $this->generateUrl('home');
        $response = new Response('', Response::HTTP_FOUND, ['Location' => $target]);
        $response->headers->setCookie(Cookie::create('lang', $locale, strtotime('+1 year')));

        return $response;
    }

    public static function resolveLocale(Request $request): string
    {
        return $request->cookies->get('lang') === 'en' ? 'en' : 'de';
    }

    private function renderPage(Request $request, string $template, array $context = []): Response
    {
        $lang = self::resolveLocale($request);

        return $this->render($template, $context + [
            't' => SiteCopy::get($lang),
            'lang' => $lang,
            'sent' => $request->query->getBoolean('sent'),
        ]);
    }
}
