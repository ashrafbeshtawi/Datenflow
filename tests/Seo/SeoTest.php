<?php

namespace App\Tests\Seo;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Search-engine basics for every page the sitemap announces: unique title and
 * description, canonical + hreflang, link-preview tags and schema.org data.
 */
class SeoTest extends WebTestCase
{
    public function testEveryIndexedPageHasCompleteSeoTags(): void
    {
        $client = static::createClient();
        $urls = $this->sitemapUrls($client);
        self::assertNotEmpty($urls, 'sitemap.xml lists no pages');

        $titles = [];
        $descriptions = [];
        foreach ($urls as $url) {
            $crawler = $client->request('GET', parse_url($url, PHP_URL_PATH));
            self::assertResponseIsSuccessful($url);

            self::assertCount(0, $crawler->filter('meta[name="robots"]'), "$url is in the sitemap but has a robots meta tag");
            self::assertContains($crawler->filter('html')->attr('lang'), ['de', 'en'], "$url: html lang");
            self::assertCount(1, $crawler->filter('h1'), "$url: exactly one h1");

            $title = $crawler->filter('title')->text();
            $description = $this->meta($crawler, 'meta[name="description"]');
            self::assertGreaterThanOrEqual(10, mb_strlen($title), "$url: title too short");
            self::assertLessThanOrEqual(65, mb_strlen($title), "$url: title over 65 characters gets cut in results: $title");
            self::assertGreaterThanOrEqual(50, mb_strlen($description), "$url: description too short");
            self::assertLessThanOrEqual(160, mb_strlen($description), "$url: description over 160 characters gets cut in results");
            $titles[$url] = $title;
            $descriptions[$url] = $description;

            self::assertSame($url, $crawler->filter('link[rel="canonical"]')->attr('href'), "$url: canonical must point to itself");
            foreach (['de', 'en', 'x-default'] as $hreflang) {
                $alternate = $crawler->filter(sprintf('link[rel="alternate"][hreflang="%s"]', $hreflang));
                self::assertCount(1, $alternate, "$url: hreflang $hreflang");
                self::assertContains($alternate->attr('href'), $urls, "$url: hreflang $hreflang points outside the sitemap");
            }

            self::assertSame($title, $this->meta($crawler, 'meta[property="og:title"]'), "$url: og:title");
            self::assertSame($description, $this->meta($crawler, 'meta[property="og:description"]'), "$url: og:description");
            self::assertSame($url, $this->meta($crawler, 'meta[property="og:url"]'), "$url: og:url");
            $image = $this->meta($crawler, 'meta[property="og:image"]');
            self::assertStringStartsWith('http', $image, "$url: og:image must be absolute");
            self::assertFileExists($this->publicPath(parse_url($image, PHP_URL_PATH)), "$url: og:image file");

            $business = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('ProfessionalService', $business['@type'], "$url: schema.org type");
            self::assertNotEmpty($business['telephone'], "$url: schema.org telephone");
            self::assertNotEmpty($business['address']['postalCode'], "$url: schema.org postal code");
            self::assertNotEmpty($business['address']['addressLocality'], "$url: schema.org city");
        }

        self::assertSame(array_unique($titles), $titles, 'every page needs its own title');
        self::assertSame(array_unique($descriptions), $descriptions, 'every page needs its own description');
    }

    public function testAdminPagesAreNoindexWithoutPreviewTags(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/admin/login');

        self::assertSame('noindex', $this->meta($crawler, 'meta[name="robots"]'));
        self::assertCount(0, $crawler->filter('meta[property="og:title"]'));
        self::assertCount(0, $crawler->filter('script[type="application/ld+json"]'));
    }

    public function testRobotsTxtBlocksAdminAndPointsToTheSitemap(): void
    {
        $robots = file_get_contents($this->publicPath('/robots.txt'));

        self::assertStringContainsString('Disallow: /admin', $robots);
        self::assertMatchesRegularExpression('#^Sitemap: https://\S+/sitemap\.xml$#m', $robots);
    }

    public function testShareImageHasTheRecommendedSize(): void
    {
        [$width, $height] = getimagesize($this->publicPath('/img/og-image.png'));

        self::assertSame([1200, 630], [$width, $height]);
    }

    /** @return list<string> absolute page URLs from /sitemap.xml */
    private function sitemapUrls(KernelBrowser $client): array
    {
        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        $urls = [];
        foreach ((new \SimpleXMLElement($client->getResponse()->getContent()))->url as $entry) {
            $urls[] = (string) $entry->loc;
        }

        return $urls;
    }

    private function meta(Crawler $crawler, string $selector): string
    {
        $node = $crawler->filter($selector);
        self::assertCount(1, $node, "missing $selector");

        return (string) $node->attr('content');
    }

    private function publicPath(string $path): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/public'.$path;
    }
}
