<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PagesTest extends WebTestCase
{
    #[DataProvider('pageProvider')]
    public function testPageLoads(string $path, string $expectedH1Part): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expectedH1Part);
    }

    public static function pageProvider(): iterable
    {
        yield 'home' => ['/', 'kompliziert'];
        yield 'services' => ['/services', 'Wir kennen Ihren Alltag'];
        yield 'process' => ['/process', 'Vom ersten Gespräch'];
        yield 'pricing' => ['/preise', 'Ein passendes Modell'];
        yield 'faq' => ['/faq', 'Was Kunden uns'];
        yield 'booking' => ['/termin', 'kostenloses Erstgespräch'];
        yield 'contact' => ['/contact', 'So erreichen Sie uns'];
        yield 'karriere' => ['/karriere', 'Bei Datenflow arbeiten'];
        yield 'impressum' => ['/impressum', 'Impressum'];
        yield 'datenschutz' => ['/datenschutz', 'Datenschutz'];
    }

    public function testEveryPageCarriesTheBookingCta(): void
    {
        $client = static::createClient();
        foreach (self::pageProvider() as [$path]) {
            $crawler = $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(
                0,
                $crawler->filter('a[href="/termin"]')->count(),
                sprintf('Page %s is missing the booking CTA link', $path),
            );
        }
    }

    public function testBookingFormShowsSlotGrid(): void
    {
        $client = static::createClient();
        $monday = (new \DateTimeImmutable('monday next week', new \DateTimeZone('Europe/Berlin')))->modify('+1 week');
        $crawler = $client->request('GET', '/termin?week='.$monday->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form .slot-nav');
        self::assertSelectorExists('form input[name="call_type"][value="video"]');

        // Regression: Twig must render the naive Berlin dates unshifted. With the
        // server on UTC and no twig date timezone, every header moved back a day.
        $firstHeader = $crawler->filter('.slot-grid th')->first()->text();
        self::assertStringContainsString('Mo', $firstHeader);
        self::assertStringContainsString($monday->format('d.m.'), $firstHeader);
    }

    public function testPagesDeclareTheirCanonicalUrlWithoutQuery(): void
    {
        $client = static::createClient();
        $client->request('GET', '/termin?week=2026-01-05');

        self::assertSelectorExists('link[rel="canonical"][href="http://localhost/termin"]');
        self::assertSelectorNotExists('meta[name="robots"]');
    }

    public function testThankYouPageIsNoindex(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contact?sent=1');

        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
    }

    public function testSitemapListsEveryPublicPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');
        $locs = $crawler->filterXPath('//*[local-name()="loc"]')->each(fn ($n) => $n->text());
        foreach (self::pageProvider() as [$path]) {
            self::assertContains('http://localhost'.$path, $locs);
        }
        self::assertContains('http://localhost/en', $locs);
        self::assertContains('http://localhost/en/careers', $locs);
        self::assertCount(20, $locs);
    }

    public function testTrailingSlashRedirectKeepsHttpsBehindProxy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/services/', [], [], [
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'www.datenflow.de',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);

        self::assertResponseRedirects('https://www.datenflow.de/services', 301);
    }

    public function testLegacyToolsUrlRedirectsToServices(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools');

        self::assertResponseRedirects('/services', 301);
    }

    #[DataProvider('englishPageProvider')]
    public function testEnglishPageLoadsUnderItsOwnUrl(string $path, string $expectedH1Part): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', $expectedH1Part);
        self::assertSelectorExists('link[rel="canonical"][href="http://localhost'.$path.'"]');
    }

    public static function englishPageProvider(): iterable
    {
        yield 'home' => ['/en', 'complicated'];
        yield 'booking' => ['/en/booking', 'free'];
    }

    public function testPagesLinkTheirOtherLanguageVersion(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/pricing');

        self::assertSelectorExists('link[rel="alternate"][hreflang="de"][href="http://localhost/preise"]');
        self::assertSelectorExists('link[rel="alternate"][hreflang="en"][href="http://localhost/en/pricing"]');
        self::assertSelectorExists('link[rel="alternate"][hreflang="x-default"][href="http://localhost/preise"]');
        self::assertSelectorExists('.lang-switch a[href="/preise"]');
        self::assertSelectorExists('.lang-switch a[href="/en/pricing"].active');
    }
}
