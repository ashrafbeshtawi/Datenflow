<?php

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Content\SiteCopy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PageController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function showHome(Request $request): Response
    {
        return $this->renderPage($request, 'page/home.html.twig');
    }

    #[Route('/services', name: 'services')]
    public function showServices(Request $request): Response
    {
        return $this->renderPage($request, 'page/services.html.twig');
    }

    #[Route('/process', name: 'process')]
    public function showProcess(Request $request): Response
    {
        return $this->renderPage($request, 'page/process.html.twig');
    }

    #[Route('/preise', name: 'pricing')]
    public function showPricing(Request $request): Response
    {
        return $this->renderPage($request, 'page/pricing.html.twig');
    }

    #[Route('/faq', name: 'faq')]
    public function showFaq(Request $request): Response
    {
        return $this->renderPage($request, 'page/faq.html.twig');
    }

    #[Route('/termin', name: 'booking')]
    public function showBooking(Request $request, SlotFinder $slots): Response
    {
        return $this->renderPage($request, 'page/booking.html.twig', [
            'grid' => $slots->buildWeekGrid($request->query->getString('week') ?: null),
        ]);
    }

    #[Route('/contact', name: 'contact')]
    public function showContact(Request $request): Response
    {
        return $this->renderPage($request, 'page/contact.html.twig');
    }

    #[Route('/karriere', name: 'karriere')]
    public function showKarriere(Request $request): Response
    {
        return $this->renderPage($request, 'page/karriere.html.twig');
    }

    #[Route('/impressum', name: 'impressum')]
    public function showImpressum(Request $request): Response
    {
        return $this->renderPage($request, 'page/legal.html.twig', ['section' => 'impressum']);
    }

    #[Route('/datenschutz', name: 'datenschutz')]
    public function showDatenschutz(Request $request): Response
    {
        return $this->renderPage($request, 'page/legal.html.twig', ['section' => 'datenschutz']);
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
