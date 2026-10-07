<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class PageController extends AbstractController
{
    #[Route(['de' => '/', 'en' => '/en'], name: 'home', options: ['sitemap' => true])]
    public function showHome(Request $request): Response
    {
        return $this->renderPage($request, 'page/home.html.twig');
    }

    #[Route(['de' => '/services', 'en' => '/en/services'], name: 'services', options: ['sitemap' => true])]
    public function showServices(Request $request): Response
    {
        return $this->renderPage($request, 'page/services.html.twig');
    }

    #[Route(['de' => '/process', 'en' => '/en/process'], name: 'process', options: ['sitemap' => true])]
    public function showProcess(Request $request): Response
    {
        return $this->renderPage($request, 'page/process.html.twig');
    }

    #[Route(['de' => '/preise', 'en' => '/en/pricing'], name: 'pricing', options: ['sitemap' => true])]
    public function showPricing(Request $request): Response
    {
        return $this->renderPage($request, 'page/pricing.html.twig');
    }

    #[Route(['de' => '/faq', 'en' => '/en/faq'], name: 'faq', options: ['sitemap' => true])]
    public function showFaq(Request $request): Response
    {
        return $this->renderPage($request, 'page/faq.html.twig');
    }

    #[Route(['de' => '/termin', 'en' => '/en/booking'], name: 'booking', options: ['sitemap' => true])]
    public function showBooking(Request $request, SlotFinder $slots): Response
    {
        return $this->renderPage($request, 'page/booking.html.twig', [
            'grid' => $slots->buildWeekGrid($request->query->getString('week') ?: null),
        ]);
    }

    #[Route(['de' => '/contact', 'en' => '/en/contact'], name: 'contact', options: ['sitemap' => true])]
    public function showContact(Request $request): Response
    {
        return $this->renderPage($request, 'page/contact.html.twig');
    }

    #[Route(['de' => '/karriere', 'en' => '/en/careers'], name: 'karriere', options: ['sitemap' => true])]
    public function showKarriere(Request $request): Response
    {
        return $this->renderPage($request, 'page/karriere.html.twig');
    }

    #[Route(['de' => '/impressum', 'en' => '/en/imprint'], name: 'impressum', options: ['sitemap' => true])]
    public function showImpressum(Request $request): Response
    {
        return $this->renderPage($request, 'page/legal.html.twig', ['section' => 'impressum']);
    }

    #[Route(['de' => '/datenschutz', 'en' => '/en/privacy'], name: 'datenschutz', options: ['sitemap' => true])]
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

    public static function resolveLocale(Request $request): string
    {
        // Set from the localized route (/en/... -> en); unlocalized routes fall back to default_locale.
        return $request->getLocale() === 'en' ? 'en' : 'de';
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
