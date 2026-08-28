<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

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
        $client->request('GET', '/termin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form .slot-nav');
        self::assertSelectorExists('form input[name="call_type"][value="video"]');
    }

    public function testLegacyToolsUrlRedirectsToServices(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools');

        self::assertResponseRedirects('/services', 301);
    }

    public function testLangSwitchSetsCookieAndRendersEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lang/en');

        $cookie = $client->getResponse()->headers->getCookies()[0];
        self::assertSame('lang', $cookie->getName());
        self::assertSame('en', $cookie->getValue());

        $client->getCookieJar()->set(new Cookie('lang', 'en'));
        $client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'complicated');
        self::assertSelectorExists('html[lang="en"]');
    }
}
